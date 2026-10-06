<?php

declare(strict_types=1);

namespace PrettyLinks\Redirect;

use PrettyLinks\Support\JobDeadline;

use PrettyLinks\GroundLevel\Resque\Models\Job;
use PrettyLinks\Repositories\Links;

// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter
// Custom plugin table (prli_clicks): name interpolates from $wpdb->prefix
// (trusted), values bind through $wpdb->prepare().

/**
 * Fills in `prli_clicks.host` — the reverse-DNS name — on the Resque worker.
 *
 * Extended tracking used to call `gethostbyaddr()` inline while writing the
 * click row. That runs post-response so the visitor never waits, but the
 * PHP-FPM worker does, and resolver timeouts are commonly 5 seconds or more.
 * On a traffic spike against IPs with no PTR record, workers pile up in DNS
 * wait. Every other expensive thing on this path — the geo lookup — was
 * deliberately moved to the background worker; this hadn't been.
 *
 * The click row is inserted with an empty `host` and this job stamps it later.
 * No queue table is needed: on an Extended-mode site an empty `host` IS the
 * "not resolved yet" marker, because nothing else writes the column. (In Normal
 * mode `host` is legitimately empty, but Normal-mode sites never enqueue this
 * job.) The scan is bounded to recent rows so flipping a long-lived site from
 * Normal to Extended can't drag its whole click history in.
 *
 * An IP with no PTR record — or one that doesn't answer within
 * ReverseDns::TIMEOUT — gets the IP itself written, which is what
 * `gethostbyaddr()` returns for no record. That matches v3 and the previous
 * inline behaviour, and takes the row out of the scan so a dead IP isn't
 * retried forever.
 *
 * Lookups are capped at ReverseDns::TIMEOUT (3s) rather than going through
 * `gethostbyaddr()`, which can't be time-limited and waited 10s per dead IP on
 * a stock resolver (#971). An IP already resolved on a recent click row is
 * reused instead of looked up again.
 */
class HostBackfillJob extends Job
{
    /**
     * Rows resolved per run. Each is one potential blocking DNS lookup, so keep
     * the batch small enough to finish inside the worker's budget; the self
     * re-enqueue picks up whatever is left.
     */
    public const BATCH_SIZE = 20;

    /**
     * How far back the scan reaches, in seconds. Bounds the work when a site
     * switches into Extended mode with a large existing `prli_clicks` table.
     */
    public const LOOKBACK = DAY_IN_SECONDS;

    /**
     * How far back a previously resolved host for the same IP is reused.
     */
    public const REUSE_WINDOW = DAY_IN_SECONDS;

    /**
     * Transient guarding how often a drain is queued. Without it every Extended
     * click would enqueue another job — thousands of no-ops on a busy site,
     * since the first one drains the backlog anyway.
     */
    private const DRAIN_LOCK = 'prli_host_drain_queued';

    /**
     * Queue a drain from the Resque jobs cron when there is work waiting.
     *
     * The click path can't be the only trigger: turbo mode writes click rows
     * from a dispatcher that exits before the plugin loads, so it has no way to
     * enqueue anything, and a `ClickWriter` enqueue can fail. Either way the
     * rows would sit there with no host. One indexed query per tick, and
     * nothing at all on a normal page load.
     *
     * @return void
     */
    public static function maybeQueueDrain(): void
    {
        if (!self::isExtendedMode()) {
            return;
        }
        if (self::hasPending()) {
            self::requestDrain();
        }
    }

    /**
     * Queue a drain unless one was queued in the last minute.
     *
     * Called from the click path (post-response), so it stays cheap: a
     * transient read, and at most one job insert per minute.
     *
     * @return void
     */
    public static function requestDrain(): void
    {
        if (get_transient(self::DRAIN_LOCK) !== false) {
            return;
        }

        // Matches the Resque jobs cron interval, so at most one redundant
        // enqueue per tick. Set before enqueueing: if the insert fails we'd
        // rather skip a minute than hammer a failing jobs table.
        set_transient(self::DRAIN_LOCK, 1, MINUTE_IN_SECONDS);

        (new self())->enqueue();
    }

    /**
     * Whether any recent click row is still missing its host.
     *
     * @return boolean
     */
    public static function hasPending(): bool
    {
        global $wpdb;
        $table = $wpdb->prefix . 'prli_clicks';
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned prli_clicks table built from $wpdb->prefix; the cutoff binds through prepare(). No WP API exists for this table and this must read through.
        $found = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT 1 FROM {$table}
                  WHERE host = '' AND ip <> '' AND created_at >= %s
                  LIMIT 1",
                self::cutoff()
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        return $found !== null;
    }

    /**
     * Resolve one batch of click rows.
     *
     * @return void
     */
    protected function perform(): void
    {
        if (!self::isExtendedMode()) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'prli_clicks';

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned prli_clicks table built from $wpdb->prefix; bound values go through prepare(). No WP API exists for this table and this must read through.
        $rows = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, ip FROM {$table}
                  WHERE host = '' AND ip <> '' AND created_at >= %s
               ORDER BY id DESC
                  LIMIT %d",
                self::cutoff(),
                self::BATCH_SIZE
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

        if ($rows === []) {
            return;
        }

        // One lookup per distinct IP per run. A crawler burst or a repeat
        // visitor is the common case and this collapses it.
        //
        // Stop at the worker's deadline too: 20 lookups at up to
        // ReverseDns::TIMEOUT each would otherwise hold the worker for a
        // minute. Unprocessed rows stay pending and the chain below resumes.
        $deadline = JobDeadline::at();
        $resolved = [];
        foreach ($rows as $row) {
            if (microtime(true) >= $deadline) {
                break;
            }
            $ip = (string) ($row['ip'] ?? '');
            if ($ip === '') {
                continue;
            }
            if (!array_key_exists($ip, $resolved)) {
                $resolved[$ip] = self::resolveHost($ip);
            }
            $host = $resolved[$ip];
            if ($host === '') {
                continue;
            }
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned prli_clicks table built from $wpdb->prefix; bound values go through prepare(). An UPDATE has nothing to cache.
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$table} SET host = %s WHERE id = %d AND host = ''",
                    $host,
                    (int) $row['id']
                )
            );
            // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        }

        // Chain to the next batch rather than waiting for the next cron tick.
        if (self::hasPending()) {
            (new self())->enqueue();
        }
    }

    /**
     * Reverse-resolve one stored IP into something terminal to write.
     *
     * Never returns '' for a row that has an `ip`, because an empty write leaves
     * the row inside the pending scan — and `hasPending()` then re-enqueues the
     * job immediately, forever. The stored value is not necessarily a valid
     * address: `Geo::rawIp()` takes proxy headers as given, so a visitor can put
     * anything in `X-Forwarded-For`, and `gethostbyaddr()` returns false (with a
     * warning) for a malformed one. A single crafted request could otherwise pin
     * the worker in a permanent re-enqueue loop for the whole lookback window.
     *
     * An address with no PTR record, or none within ReverseDns::TIMEOUT,
     * resolves to itself — `gethostbyaddr()`'s own behaviour for no record, and
     * what the inline call stored before — so unresolvable and invalid values
     * both terminate the scan by writing the IP back.
     *
     * @param  string $ip Stored client IP.
     * @return string Host name, or the IP when there is nothing better.
     */
    private static function resolveHost(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return $ip;
        }
        $known = self::knownHost($ip);
        if ($known !== null) {
            return $known;
        }
        return ReverseDns::lookup($ip) ?? $ip;
    }

    /**
     * The host already stamped on a recent click from the same IP, if any.
     *
     * The click table is the cache: `ip` is indexed, and every resolved row
     * carries its answer. Saves a lookup per repeat visitor. Only a valid
     * hostname is reused: a row holding the IP itself recorded a miss or a
     * timeout, which must not stop later clicks from looking it up again, and
     * a pre-4.x row may hold text the new lookup would reject. The window is
     * a day so a reassigned address or changed PTR isn't carried for long.
     *
     * @param  string $ip Stored client IP.
     * @return string|null The hostname, or null if none is known.
     */
    private static function knownHost(string $ip): ?string
    {
        global $wpdb;
        $table = $wpdb->prefix . 'prli_clicks';
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned prli_clicks table built from $wpdb->prefix; the value binds through prepare(). This read IS the cache.
        $host = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT host FROM {$table}
                  WHERE ip = %s AND host <> '' AND host <> %s AND created_at >= %s
               ORDER BY id DESC
                  LIMIT 1",
                $ip,
                $ip,
                gmdate('Y-m-d H:i:s', time() - self::REUSE_WINDOW)
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        return is_string($host) && ReverseDns::isHostname($host) ? $host : null;
    }

    /**
     * Oldest `created_at` the scan considers, as a UTC MySQL datetime — click
     * rows are stamped with `current_time('mysql', true)`.
     *
     * @return string
     */
    private static function cutoff(): string
    {
        return gmdate('Y-m-d H:i:s', time() - self::LOOKBACK);
    }

    /**
     * Whether the site records the `host` column at all. Only Extended tracking
     * does; in Normal mode an empty host is the final value, not a placeholder.
     *
     * @return boolean
     */
    private static function isExtendedMode(): bool
    {
        return Links::trackingMode() === 'extended';
    }
}
