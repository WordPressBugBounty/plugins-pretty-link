<?php

declare(strict_types=1);

namespace PrettyLinks\Clicks;

use PrettyLinks\Options\Store as OptionsStore;
use RuntimeException;
use wpdb;

/**
 * Clears click rows on demand or via the daily auto-trim cron.
 *
 * Deletes are sized to the work: a full wipe uses TRUNCATE when the host
 * allows it (instant); otherwise, and for filtered deletes, the rows are
 * removed in small batches. Small scopes run inline for instant feedback;
 * large ones are handed to the Resque background worker (ClearClicksJob) so a
 * request never blocks, holds a lock, or times out mid-delete. Every public
 * entry point returns a status array — see self::done() / self::queued().
 */
class Cleaner
{
    public const CRON_EVENT = 'prli_auto_trim_clicks';

    /**
     * Rows deleted per statement. Small enough that each batch grabs the lock
     * only briefly, letting other queries interleave between batches.
     */
    private const BATCH = 5000;

    /**
     * Scopes with at most this many rows run inline (fast, instant feedback);
     * anything larger goes to the background worker.
     */
    private const INLINE_MAX = 50000;

    // Job payload keys.
    private const K_MODE           = 'mode';
    private const K_DAYS           = 'days';
    private const K_LINK           = 'link_id';
    private const K_FIRE_WIPE_HOOK = 'fire_wipe_hook';

    // Delete scopes.
    private const MODE_ALL  = 'all';
    private const MODE_AGE  = 'age';
    private const MODE_LINK = 'link';

    /**
     * Maps retention window keys to their day counts.
     *
     * @var array<string, int>
     */
    private const RETENTION_DAYS = [
        '30d'  => 30,
        '90d'  => 90,
        '180d' => 180,
        '1yr'  => 365,
        '2yr'  => 730,
    ];

    /**
     * WordPress database access object.
     *
     * @var wpdb
     */
    private wpdb $db;

    /**
     * Stores the database dependency.
     *
     * @param wpdb $db WordPress database access object.
     */
    public function __construct(wpdb $db)
    {
        $this->db = $db;
    }

    /**
     * Deletes every click row and resets all link totals to zero.
     *
     * @param boolean $fireWipeHook Fire prli_click_data_wiped once the wipe is
     *                              fully complete (sync or async). Used by the
     *                              tracking-mode-switch wipe so extensions
     *                              clear their own tables at the right time.
     *
     * @return array{done: boolean, deleted: integer, job_id: integer|null}
     */
    public function truncateAll(bool $fireWipeHook = false): array
    {
        $args = [self::K_MODE => self::MODE_ALL];
        if ($fireWipeHook) {
            $args[self::K_FIRE_WIPE_HOOK] = true;
        }
        $count = $this->countScope($args);

        // Fast path: TRUNCATE is near-instant at any size, when the host grants
        // the DROP privilege it needs.
        if ($this->tryTruncateClicks()) {
            $this->finalize($args);
            return $this->done($count);
        }

        return $this->dispatch($args, $count);
    }

    /**
     * Deletes click rows older than the given number of days and recounts totals.
     *
     * @param integer $days Maximum age in days to retain.
     *
     * @return array{done: boolean, deleted: integer, job_id: integer|null}
     */
    public function clearOlderThan(int $days): array
    {
        if ($days < 1) {
            return $this->done(0);
        }

        // Count mode keeps only running totals in the static-clicks/
        // static-uniques metas and writes no per-click rows, so there is
        // nothing to age out — and those metas are the source of truth.
        // Recomputing them from the (unmaintained) prli_links columns here
        // would wipe every count to zero, so skip the trim entirely.
        $store = new OptionsStore();
        if ((string) $store->get('extended_tracking', 'normal') === 'count') {
            return $this->done(0);
        }

        return $this->dispatch([
            self::K_MODE => self::MODE_AGE,
            self::K_DAYS => $days,
        ]);
    }

    /**
     * Deletes all click rows and totals for a single link.
     *
     * @param integer $linkId Link identifier to reset.
     *
     * @return array{done: boolean, deleted: integer, job_id: integer|null}
     */
    public function resetForLink(int $linkId): array
    {
        if ($linkId < 1) {
            return $this->done(0);
        }

        return $this->dispatch([
            self::K_MODE => self::MODE_LINK,
            self::K_LINK => $linkId,
        ]);
    }

    /**
     * Runs the scheduled auto-trim using the configured retention window.
     *
     * @return void
     */
    public function runAutoTrim(): void
    {
        $days = self::retentionDays();
        if ($days < 1) {
            return;
        }
        $this->clearOlderThan($days);
    }

    /**
     * Deletes one batch for the given scope on behalf of ClearClicksJob.
     *
     * Finalizes (counter resets / recount) once the scope is drained.
     *
     * @param array $args Job payload (mode plus any scope params).
     *
     * @return boolean True when rows remain and the job should re-enqueue.
     *
     * @throws RuntimeException When the delete query fails, so the caller does
     *                         not finalize (zero counters) while rows remain.
     */
    public function processBatch(array $args): bool
    {
        $affected = $this->deleteOneBatch($args);
        if ($affected === -1) {
            throw new RuntimeException('Click deletion query failed.');
        }
        if ($affected < self::BATCH) {
            $this->finalize($args);
            return false;
        }
        return true;
    }

    /**
     * Schedules or clears the daily auto-trim cron based on current settings.
     *
     * @return void
     */
    public static function ensureCronScheduled(): void
    {
        $days      = self::retentionDays();
        $scheduled = wp_next_scheduled(self::CRON_EVENT);

        if ($days < 1) {
            if ($scheduled) {
                wp_unschedule_event($scheduled, self::CRON_EVENT);
            }
            wp_clear_scheduled_hook(self::CRON_EVENT);
            return;
        }

        if (!$scheduled) {
            wp_schedule_event(time() + 300, 'daily', self::CRON_EVENT);
        }
    }

    /**
     * Runs a scope inline when small, or hands it to the background worker.
     *
     * @param array        $args  Job payload (mode plus any scope params).
     * @param integer|null $count Pre-computed scope row count, if known.
     *
     * @return array{done: boolean, deleted: integer, job_id: integer|null}
     */
    private function dispatch(array $args, ?int $count = null): array
    {
        $count = $count ?? $this->countScope($args);

        if ($count <= self::INLINE_MAX) {
            return $this->done($this->runInline($args));
        }

        return $this->enqueue($args);
    }

    /**
     * Enqueues the background clear job, falling back to inline on queue error.
     *
     * @param array $args Job payload (mode plus any scope params).
     *
     * @return array{done: boolean, deleted: integer, job_id: integer|null}
     */
    private function enqueue(array $args): array
    {
        $jobId = (new ClearClicksJob())->enqueue($args);

        // A failed queue write (documented false return) must not silently
        // leave the data unpurged — do it inline instead.
        if ($jobId === false) {
            return $this->done($this->runInline($args));
        }

        return $this->queued((int) $jobId);
    }

    /**
     * Deletes an entire scope inline in batches, then finalizes.
     *
     * @param array $args Job payload (mode plus any scope params).
     *
     * @return integer Total rows deleted.
     *
     * @throws RuntimeException When a delete query fails, so we do not finalize
     *                         (zero counters) while rows remain.
     */
    private function runInline(array $args): int
    {
        $total = 0;
        do {
            $affected = $this->deleteOneBatch($args);
            if ($affected === -1) {
                throw new RuntimeException('Click deletion query failed.');
            }
            $total += $affected;
        } while ($affected === self::BATCH);

        $this->finalize($args);

        return $total;
    }

    /**
     * Deletes a single batch of click rows for the given scope.
     *
     * @param array $args Job payload (mode plus any scope params).
     *
     * @return integer Rows deleted in this batch, or -1 on query failure.
     */
    private function deleteOneBatch(array $args): int
    {
        $result = $this->db->query($this->scopeDeleteSql($args) . ' LIMIT ' . self::BATCH);

        // Distinguish a failed query (false) from a legitimate zero-row batch;
        // casting false to 0 would look like "scope drained" and finalize early.
        return $result === false ? -1 : (int) $result;
    }

    /**
     * Builds the (prepared) DELETE statement for a scope, without a LIMIT.
     *
     * @param array $args Job payload (mode plus any scope params).
     *
     * @return string
     */
    private function scopeDeleteSql(array $args): string
    {
        $clicks = $this->db->prefix . 'prli_clicks';

        switch ($args[self::K_MODE] ?? '') {
            case self::MODE_AGE:
                return $this->db->prepare(
                    "DELETE FROM {$clicks} WHERE created_at < %s",
                    $this->ageCutoff((int) ($args[self::K_DAYS] ?? 0))
                );
            case self::MODE_LINK:
                return $this->db->prepare(
                    "DELETE FROM {$clicks} WHERE link_id = %d",
                    (int) ($args[self::K_LINK] ?? 0)
                );
            case self::MODE_ALL:
            default:
                return "DELETE FROM {$clicks}";
        }
    }

    /**
     * Counts the click rows in a scope.
     *
     * @param array $args Job payload (mode plus any scope params).
     *
     * @return integer
     */
    private function countScope(array $args): int
    {
        $clicks = $this->db->prefix . 'prli_clicks';

        switch ($args[self::K_MODE] ?? '') {
            case self::MODE_AGE:
                return (int) $this->db->get_var(
                    $this->db->prepare(
                        "SELECT COUNT(*) FROM {$clicks} WHERE created_at < %s",
                        $this->ageCutoff((int) ($args[self::K_DAYS] ?? 0))
                    )
                );
            case self::MODE_LINK:
                return (int) $this->db->get_var(
                    $this->db->prepare(
                        "SELECT COUNT(*) FROM {$clicks} WHERE link_id = %d",
                        (int) ($args[self::K_LINK] ?? 0)
                    )
                );
            case self::MODE_ALL:
            default:
                return (int) $this->db->get_var("SELECT COUNT(*) FROM {$clicks}");
        }
    }

    /**
     * Resets the metas and link counters that survive a scope's row deletion.
     *
     * @param array $args Job payload (mode plus any scope params).
     *
     * @return void
     *
     * @throws RuntimeException When the MODE_ALL straggler sweep query fails,
     *                         so counters are not zeroed while rows may remain.
     */
    private function finalize(array $args): void
    {
        $links = $this->db->prefix . 'prli_links';
        $metas = $this->db->prefix . 'prli_link_metas';

        switch ($args[self::K_MODE] ?? '') {
            case self::MODE_AGE:
                $this->recountAllLinks();
                break;
            case self::MODE_LINK:
                $linkId = (int) ($args[self::K_LINK] ?? 0);
                $this->db->query(
                    $this->db->prepare(
                        "DELETE FROM {$metas} WHERE link_id = %d AND meta_key IN (%s, %s)",
                        $linkId,
                        'static-clicks',
                        'static-uniques'
                    )
                );
                $this->db->query(
                    $this->db->prepare("UPDATE {$links} SET clicks = 0, uniques = 0 WHERE id = %d", $linkId)
                );
                break;
            case self::MODE_ALL:
            default:
                // A long async wipe lets live redirects insert new rows between
                // the last batch and here. Sweep any stragglers before zeroing
                // counters, so we never leave orphaned clicks paired with
                // zeroed link totals. This is a no-op after TRUNCATE or an
                // inline drain, where the table is already empty. A failed
                // sweep throws (like the main delete paths) so counters are
                // not zeroed while rows may still remain.
                $sweep = [self::K_MODE => self::MODE_ALL];
                do {
                    $affected = $this->deleteOneBatch($sweep);
                    if ($affected === -1) {
                        throw new RuntimeException('Click deletion query failed.');
                    }
                } while ($affected === self::BATCH);

                $this->db->query(
                    $this->db->prepare(
                        "DELETE FROM {$metas} WHERE meta_key IN (%s, %s)",
                        'static-clicks',
                        'static-uniques'
                    )
                );
                $this->db->query("UPDATE {$links} SET clicks = 0, uniques = 0");

                if (!empty($args[self::K_FIRE_WIPE_HOOK])) {
                    /**
                     * Fires after every click row and counter is cleared on a
                     * tracking-mode transition across the Simple boundary. For
                     * large clears handled by the background worker this fires
                     * once the scope is fully drained — never before — so
                     * extensions don't purge their own click-derived tables
                     * while core data is still being deleted.
                     */
                    do_action('prli_click_data_wiped');
                }
                break;
        }
    }

    /**
     * Attempts to empty the clicks table with TRUNCATE.
     *
     * TRUNCATE is near-instant and barely touches the undo log, but needs the
     * DROP privilege some locked-down hosts withhold. Errors are suppressed so
     * the caller can fall back cleanly.
     *
     * @return boolean True on success, false when TRUNCATE is not permitted.
     */
    private function tryTruncateClicks(): bool
    {
        /**
         * Filters whether the clicks table may be emptied with TRUNCATE.
         *
         * TRUNCATE is far faster but issues an implicit COMMIT and needs the
         * DROP privilege. Return false to force the batched DELETE path (e.g.
         * hosts that forbid TRUNCATE, or transactional test environments).
         *
         * @param boolean $useTruncate Whether to attempt TRUNCATE. Default true.
         */
        if (!apply_filters('prli_clicks_use_truncate', true)) {
            return false;
        }

        $clicks = $this->db->prefix . 'prli_clicks';

        $suppressed = $this->db->suppress_errors(true);
        $result     = $this->db->query("TRUNCATE TABLE {$clicks}");
        $this->db->suppress_errors($suppressed);

        return $result !== false;
    }

    /**
     * Builds the cutoff datetime for an age-based delete.
     *
     * @param integer $days Maximum age in days to retain.
     *
     * @return string
     */
    private function ageCutoff(int $days): string
    {
        return gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS);
    }

    /**
     * Builds the "completed synchronously" status array.
     *
     * @param integer $deleted Rows deleted.
     *
     * @return array{done: boolean, deleted: integer, job_id: integer|null}
     */
    private function done(int $deleted): array
    {
        return [
            'done'    => true,
            'deleted' => $deleted,
            'job_id'  => null,
        ];
    }

    /**
     * Builds the "queued to the background worker" status array.
     *
     * @param integer $jobId The enqueued job identifier.
     *
     * @return array{done: boolean, deleted: integer, job_id: integer|null}
     */
    private function queued(int $jobId): array
    {
        return [
            'done'    => false,
            'deleted' => 0,
            'job_id'  => $jobId,
        ];
    }

    /**
     * Resolves the configured retention window into a day count.
     *
     * @return integer Retention period in days, or zero when disabled.
     */
    private static function retentionDays(): int
    {
        $store = new OptionsStore();
        if (!$store->get('auto_trim_clicks', false)) {
            return 0;
        }
        $window = (string) $store->get('auto_trim_window', '90d');
        return self::RETENTION_DAYS[$window] ?? 0;
    }

    /**
     * Recomputes click and unique totals for every link from the click rows.
     *
     * @return void
     */
    private function recountAllLinks(): void
    {
        $clicks = $this->db->prefix . 'prli_clicks';
        $links  = $this->db->prefix . 'prli_links';

        $this->db->query(
            "UPDATE {$links} SET
                clicks = (SELECT COUNT(*) FROM {$clicks} WHERE link_id = {$links}.id),
                uniques = (SELECT COUNT(*) FROM {$clicks} WHERE link_id = {$links}.id AND first_click = 1)"
        );
    }
}
