<?php

declare(strict_types=1);

namespace PrettyLinks\Database;

use wpdb;

/**
 * Idempotent 3.x → 4.0 schema installer for the free tier.
 *
 * Runs on `plugins_loaded` at priority 5. Guarded by an atomic add_option()
 * mutex (prli_migration_lock_lite) so concurrent requests never run the same
 * step twice. Progress is persisted in the `prli_migration_state` option;
 * every step is safe to re-enter.
 *
 * Owns only Lite's own tables — extensions that need additional tables
 * run their own migrator on the same `plugins_loaded` hook.
 */
class Migrator
{
    public const OPTION_STATE = 'prli_migration_state';

    /**
     * Seconds after which a held lock is treated as stale and reclaimed.
     * Guards against a hard fatal/timeout mid-migration leaving the lock
     * wedged forever (the finally block does not run on a fatal).
     */
    private const LOCK_TIMEOUT = 300;

    /**
     * WordPress database handle.
     *
     * @var wpdb
     */
    private wpdb $db;

    /**
     * Plugin base path.
     *
     * @var string
     */
    private string $basePath;

    /**
     * Target plugin version.
     *
     * @var string
     */
    private string $version;

    /**
     * Constructor.
     *
     * @param wpdb   $db       WordPress database handle.
     * @param string $basePath Plugin base path.
     * @param string $version  Target plugin version.
     */
    public function __construct(wpdb $db, string $basePath, string $version)
    {
        $this->db       = $db;
        $this->basePath = $basePath;
        $this->version  = $version;
    }

    /**
     * Run any pending migration steps, guarded by an atomic lock.
     */
    public function maybeRun(): void
    {
        $state   = (array) (get_option(self::OPTION_STATE, []) ?: []);
        $done    = array_keys((array) ($state['steps'] ?? []));
        $pending = array_diff(array_keys($this->steps()), $done);
        if (($state['version'] ?? '') === $this->version && empty($state['pending']) && empty($pending)) {
            return;
        }

        $lockOption = 'prli_migration_lock_lite';
        $now        = time();
        if (!add_option($lockOption, $now, '', 'no')) {
            // Lock held. Reclaim it only if the previous holder died mid-run
            // (no finally on a hard fatal) and left it older than the timeout.
            // The reclaim itself is not perfectly atomic — two requests could
            // both see a stale lock and proceed — but every step is guarded by
            // its $done flag and re-running one is a no-op, so a concurrent
            // double-run after a fatal is harmless.
            $heldSince = (int) get_option($lockOption, 0);
            if ($now - $heldSince < self::LOCK_TIMEOUT) {
                return;
            }
            update_option($lockOption, $now, false);
        }

        try {
            $this->run($state);
        } finally {
            delete_option($lockOption);
        }
    }

    /**
     * Ordered map of migration step names to their callables.
     *
     * @return array<string, callable>
     */
    private function steps(): array
    {
        return [
            'install_core_tables'        => [$this, 'installCoreTables'],
            'install_link_terms'         => [$this, 'installLinkTermsTable'],
            // Step name is versioned: bumping the suffix re-runs the drop
            // pass on installs that completed an earlier version of it.
            'backfill_indexes_v2'        => [$this, 'backfillIndexes'],
            'clear_legacy_cron_hooks'    => [$this, 'clearLegacyCronHooks'],
            // Backfills clicks/uniques columns from click rows for sites that
            // upgraded from v3 before the columns existed. Idempotent: UPDATE
            // is always safe to re-run. Uses first_click = 1 to match v4's
            // unique-count semantics (#676).
            'backfill_link_click_counts' => [$this, 'backfillLinkClickCounts'],
            'fix_source_column_type'     => [$this, 'fixSourceColumnType'],
        ];
    }

    /**
     * Execute each pending step in order, persisting progress after each.
     *
     * @param array<string, mixed> $state Current migration state.
     */
    private function run(array $state): void
    {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $steps = $this->steps();

        $done = is_array($state['steps'] ?? null) ? (array) $state['steps'] : [];

        foreach ($steps as $name => $callable) {
            if (!empty($done[$name])) {
                continue;
            }
            $callable();
            $done[$name]      = true;
            $state['steps']   = $done;
            $state['version'] = $this->version;
            update_option(self::OPTION_STATE, $state, false);
        }

        $state['pending'] = false;
        $state['version'] = $this->version;
        update_option(self::OPTION_STATE, $state, false);
    }

    /**
     * Create the core plugin tables via dbDelta.
     */
    private function installCoreTables(): void
    {
        $charset = $this->db->get_charset_collate();

        // Column types, nullability, and defaults match v3's prli_links exactly
        // so dbDelta is a no-op on upgrade. Columns added by 4.0 are purely
        // additive (new_window/clicks/uniques/deleted_at).
        dbDelta("CREATE TABLE {$this->db->prefix}prli_links (
            id INT(11) NOT NULL AUTO_INCREMENT,
            name VARCHAR(255) DEFAULT NULL,
            description TEXT DEFAULT NULL,
            url TEXT DEFAULT NULL,
            slug VARCHAR(255) DEFAULT NULL,
            nofollow TINYINT(1) DEFAULT 0,
            sponsored TINYINT(1) DEFAULT 0,
            track_me TINYINT(1) DEFAULT 1,
            param_forwarding VARCHAR(255) DEFAULT NULL,
            redirect_type VARCHAR(255) DEFAULT '307',
            created_at DATETIME NOT NULL,
            updated_at DATETIME DEFAULT NULL,
            group_id INT(11) DEFAULT NULL,
            link_cpt_id BIGINT(20) UNSIGNED DEFAULT 0,
            prettypay_link TINYINT(1) DEFAULT 0,
            new_window TINYINT(1) DEFAULT 0,
            clicks BIGINT UNSIGNED NOT NULL DEFAULT 0,
            uniques BIGINT UNSIGNED NOT NULL DEFAULT 0,
            deleted_at DATETIME DEFAULT NULL,
            source VARCHAR(16) NOT NULL DEFAULT 'admin',
            PRIMARY KEY  (id),
            KEY slug (slug(191)),
            KEY link_cpt_id (link_cpt_id),
            KEY group_id (group_id),
            KEY prettypay_link (prettypay_link),
            KEY created_at (created_at),
            KEY updated_at (updated_at),
            KEY deleted_at (deleted_at),
            KEY source (source)
        ) {$charset};");

        // Matches v3's prli_clicks exactly for shared columns. Additive 4.0
        // columns: country, device_type, user_id.
        // First-click dedup is cookie-based, not a DB lookup, so the
        // composite indexes exist for analytics paths, not the write path:
        // link_id_vuid        — supports uniques recalculation and acts
        // as a prefix index for link_id-only queries.
        // link_id_first_click — covers v3's unique-count queries after
        // downgrade and uniques recalc after click
        // deletion.
        // Standalone link_id/vuid (present in v3) are dropped via
        // backfillIndexes() on upgrade since dbDelta can't remove indexes.
        dbDelta("CREATE TABLE {$this->db->prefix}prli_clicks (
            id INT(11) NOT NULL AUTO_INCREMENT,
            ip VARCHAR(255) DEFAULT NULL,
            browser VARCHAR(255) DEFAULT NULL,
            btype VARCHAR(255) DEFAULT NULL,
            bversion VARCHAR(255) DEFAULT NULL,
            os VARCHAR(255) DEFAULT NULL,
            referer VARCHAR(255) DEFAULT NULL,
            host VARCHAR(255) DEFAULT NULL,
            uri VARCHAR(255) DEFAULT NULL,
            robot TINYINT DEFAULT 0,
            first_click TINYINT DEFAULT 0,
            created_at DATETIME NOT NULL,
            link_id INT(11) DEFAULT NULL,
            vuid VARCHAR(25) DEFAULT NULL,
            country CHAR(2) NOT NULL DEFAULT '',
            device_type VARCHAR(32) NOT NULL DEFAULT '',
            user_id BIGINT UNSIGNED DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY link_id_vuid (link_id, vuid),
            KEY link_id_first_click (link_id, first_click),
            KEY link_id_created_at (link_id, created_at),
            KEY created_at (created_at),
            KEY created_at_vuid (created_at, vuid),
            KEY country (country),
            KEY ip (ip),
            KEY vuid (vuid)
        ) {$charset};");

        // Matches v3's prli_link_metas exactly — including meta_order and
        // created_at, which 4.0 doesn't read but must preserve so v3 upgrades
        // don't lose columns and strict-mode inserts on fresh installs still
        // populate created_at (LinkMetas::set is responsible).
        //
        // The composite uses a new name (link_id_meta_key) rather than
        // redefining v3's `link_id` index. Reusing the name with a different
        // column list makes dbDelta emit ADD KEY against the existing v3
        // index, which MySQL rejects as a duplicate. The v3 single-column
        // `link_id` index is dropped post-dbDelta in backfillIndexes().
        dbDelta("CREATE TABLE {$this->db->prefix}prli_link_metas (
            id INT(11) NOT NULL AUTO_INCREMENT,
            meta_key VARCHAR(255) DEFAULT NULL,
            meta_value LONGTEXT DEFAULT NULL,
            meta_order INT(4) DEFAULT 0,
            link_id INT(11) NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY meta_key (meta_key(191)),
            KEY link_id_meta_key (link_id, meta_key(191))
        ) {$charset};");
    }

    /**
     * Taxonomy pivot table. Categories and tags themselves live in pro, but
     * Links.php reads/writes this pivot unconditionally on link delete and in
     * filter queries — so the table must exist on pro-less installs too.
     * Stays empty when pro is absent.
     */
    private function installLinkTermsTable(): void
    {
        $charset = $this->db->get_charset_collate();

        dbDelta("CREATE TABLE {$this->db->prefix}prli_link_terms (
            link_id BIGINT UNSIGNED NOT NULL,
            term_id BIGINT UNSIGNED NOT NULL,
            taxonomy VARCHAR(32) NOT NULL,
            PRIMARY KEY  (link_id, term_id, taxonomy),
            KEY term_id (term_id),
            KEY taxonomy (taxonomy)
        ) {$charset};");
    }

    /**
     * Drop v3 indexes that dbDelta leaves behind. Safe to re-run.
     */
    private function backfillIndexes(): void
    {
        // The dbDelta calls add the v4 indexes but never drop old ones, so we
        // do it here. Safe to re-run: each drop is a no-op if the index is gone.
        //
        // prli_clicks — drop v3 indexes that are either superseded by v4
        // composites or pure write overhead with no v4 read path:
        // link_id     — superseded by (link_id, vuid) / (link_id, first_click)
        // (link_id, created_at).
        // first_click — low-cardinality (0/1); covered by the composites.
        // browser/btype/bversion/os/referer/host/uri/robot — v3 stats pages
        // filtered by these; v4 analytics don't, so they're
        // pure insert-time cost.
        // Standalone `vuid` is intentionally kept — required for the ip/vuid
        // correlation subqueries in Repositories\Clicks::search.
        // Standalone `ip` is kept — v4 declares it and v3 had it (as ip(191));
        // we leave the existing definition alone to avoid prefix-length churn
        // on older MySQL.
        $this->dropIndexes($this->db->prefix . 'prli_clicks', [
            'link_id',
            'first_click',
            'browser',
            'btype',
            'bversion',
            'os',
            'referer',
            'host',
            'uri',
            'robot',
        ]);

        // Table prli_links — drop v3 per-flag indexes. v4 reads these columns by
        // primary key (per-link), never filters/sorts by them at scale, so
        // the indexes are insert/update overhead with no read benefit.
        // link_status is a v3-only column; the index (if present) has no
        // v4 consumer.
        $this->dropIndexes($this->db->prefix . 'prli_links', [
            'link_status',
            'nofollow',
            'sponsored',
            'track_me',
            'param_forwarding',
            'redirect_type',
        ]);

        // Table prli_link_metas — drop v3's single-column `link_id` index.
        // v4 declares a composite `link_id_meta_key (link_id, meta_key(191))`
        // under a new name in installCoreTables(); this drop removes the
        // now-redundant v3 prefix index.
        $this->dropIndexes($this->db->prefix . 'prli_link_metas', [
            'link_id',
        ]);
    }

    /**
     * Unschedule cron events left behind by v3 whose custom schedules
     * v4 no longer registers — WP-Cron logs `Cron reschedule event error
     * / invalid_schedule` for each one on every cron tick otherwise.
     *
     * - `prli_cleanup_visitor_locks_worker` — v3 Lite's visitor-locks
     *   cleanup. v4's redirect engine doesn't use locks of this shape,
     *   so the hook has no v4 consumer and nothing repopulates it.
     * - `prlidt_resque_jobs_worker` / `prlidt_resque_jobs_cleanup` — v3
     *   Developer Tools add-on hooks. v4 main re-wires GroundLevel
     *   Resque under the default hook names (`resque_run_jobs` /
     *   `resque_cleanup_jobs`) which it self-schedules on every load,
     *   so the v3-named events are pure orphans.
     */
    private function clearLegacyCronHooks(): void
    {
        $legacy = [
            'prli_cleanup_visitor_locks_worker',
            'prlidt_resque_jobs_worker',
            'prlidt_resque_jobs_cleanup',
        ];
        foreach ($legacy as $hook) {
            wp_clear_scheduled_hook($hook);
        }
    }

    /**
     * Populates the v4-only clicks/uniques columns on prli_links for sites that
     * upgraded from v3 while the columns contained only zeros. Safe to re-run.
     */
    private function backfillLinkClickCounts(): void
    {
        $clicks = $this->db->prefix . 'prli_clicks';
        $links  = $this->db->prefix . 'prli_links';

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $this->db->query(
            "UPDATE {$links} SET
                clicks  = (SELECT COUNT(*)    FROM {$clicks} WHERE link_id = {$links}.id),
                uniques = (SELECT COUNT(*)    FROM {$clicks} WHERE link_id = {$links}.id AND first_click = 1)"
        );
    }

    /**
     * Drop the named indexes from a table, ignoring any that don't exist.
     *
     * @param string   $table   Fully-prefixed table name.
     * @param string[] $indexes Index names to drop.
     */
    private function dropIndexes(string $table, array $indexes): void
    {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows    = (array) $this->db->get_results("SHOW INDEX FROM `{$table}`", ARRAY_A);
        $present = [];
        foreach ($rows as $row) {
            if (isset($row['Key_name'])) {
                $present[$row['Key_name']] = true;
            }
        }
        // SHOW INDEX reads live table metadata, bypassing MySQL 8's
        // information_schema.STATISTICS cache (information_schema_stats_expiry).
        foreach ($indexes as $idx) {
            if (isset($present[$idx])) {
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $this->db->query("ALTER TABLE `{$table}` DROP INDEX `{$idx}`");
            }
        }
    }


    /**
     * Converts any existing ENUM('admin','user','public') definition on
     * `source` to VARCHAR(16). dbDelta perpetually tries to ALTER a table
     * whose column was created as ENUM back to ENUM because it cannot
     * reconcile the two type strings, so fresh installs get VARCHAR and
     * existing installs are fixed here.
     */
    private function fixSourceColumnType(): void
    {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $this->db->query(
            "ALTER TABLE {$this->db->prefix}prli_links
             MODIFY COLUMN source VARCHAR(16) NOT NULL DEFAULT 'admin'"
        );
    }
}
