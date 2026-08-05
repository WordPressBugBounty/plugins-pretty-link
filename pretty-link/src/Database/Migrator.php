<?php

declare(strict_types=1);

namespace PrettyLinks\Database;

use PrettyLinks\Options\Store;
use PrettyLinks\Repositories\Links;
use wpdb;

/**
 * Idempotent 3.x → 4.0 schema installer for the free tier.
 *
 * Invoked from Bootstrap::bootDeferred() on `after_setup_theme` (priority 1),
 * before `prli_loaded` fires. Guarded by an atomic add_option()
 * mutex (prli_migration_lock_lite) so concurrent requests never run the same
 * step twice. Progress is persisted in the `prli_migration_state` option;
 * every step is safe to re-enter.
 *
 * Owns only Lite's own tables — extensions that need additional tables
 * run their own migrator on the `prli_loaded` action (which fires
 * immediately after this migrator completes).
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
     * Transient that paces retries of a failed run so a persistently-failing
     * step isn't re-attempted on every page load. Set only on a failed/
     * incomplete run and cleared on full success, so maybeRun() honors it
     * regardless of version (a failed upgrade keeps the stored version below
     * target, so gating on version would skip it).
     */
    private const RETRY_BACKOFF_KEY = 'prli_migration_retry_after';

    /**
     * Seconds between retry attempts of a run that left failing steps.
     */
    private const RETRY_BACKOFF = 900;

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
        $state          = (array) (get_option(self::OPTION_STATE, []) ?: []);
        $done           = array_keys((array) ($state['steps'] ?? []));
        $pending        = array_diff(array_keys($this->steps()), $done);
        $versionMatches = ($state['version'] ?? '') === $this->version;
        if ($versionMatches && empty($state['pending']) && empty($pending)) {
            return;
        }

        // Pace retries whenever the backoff transient is set — it's set only on
        // a failed/incomplete run and cleared on full success, so its presence
        // means "recently attempted, not done" regardless of version. Gating on
        // versionMatches skipped the backoff during a failed upgrade (stored
        // version stays below target), so it re-ran on every request.
        if (get_transient(self::RETRY_BACKOFF_KEY)) {
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
            'install_geo_tables'         => [$this, 'installGeoTables'],
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
            'init_slug_space_compat'     => [$this, 'initSlugSpaceCompat'],
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
        // Seed from persisted failures so each per-step write is accurate, and
        // prune entries for step keys that no longer exist so a failure under a
        // removed key can't linger forever.
        $failures = is_array($state['failures'] ?? null) ? (array) $state['failures'] : [];
        $failures = array_intersect_key($failures, $steps);

        foreach ($steps as $name => $callable) {
            if (!empty($done[$name])) {
                continue;
            }
            // Record a step done only when it finishes without throwing and
            // without leaving a SQL error. A silently-failed step (wpdb::query
            // returns false but never throws) stays pending and is retried
            // rather than being marked complete forever.
            $this->db->last_error = '';
            try {
                $callable();
                $error = (string) $this->db->last_error;
            } catch (\Throwable $e) {
                $error = $e->getMessage() !== '' ? $e->getMessage() : get_class($e);
            }

            if ($error !== '') {
                // Un-done + persist the failure now (parity with Pro; accurate
                // even if the run fatals before settlement).
                unset($done[$name]);
                $failures[$name]   = $error;
                $state['steps']    = $done;
                $state['failures'] = $failures;
                update_option(self::OPTION_STATE, $state, false);
                /**
                 * Fires when a migration step fails, so the real database error
                 * is captured instead of silently swallowed.
                 *
                 * @param string $name  Step key that failed.
                 * @param string $error The DB/exception message.
                 */
                do_action('prli_migration_step_failed', $name, $error);
                continue;
            }

            // Persist progress + clear any stale failure for this step. The
            // target version is stamped only at settlement — never mid-run.
            $done[$name] = true;
            unset($failures[$name]);
            $state['steps']    = $done;
            $state['failures'] = $failures;
            update_option(self::OPTION_STATE, $state, false);
        }

        $allDone           = empty(array_diff(array_keys($steps), array_keys($done)));
        $state['steps']    = $done;
        $state['pending']  = !$allDone;
        $state['failures'] = $failures;
        if ($allDone) {
            $state['version'] = $this->version;
        }
        update_option(self::OPTION_STATE, $state, false);

        if ($allDone) {
            delete_transient(self::RETRY_BACKOFF_KEY);
        } else {
            set_transient(self::RETRY_BACKOFF_KEY, time(), self::RETRY_BACKOFF);
        }
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
     * Geolocation cache and its pending-lookup queue. Both are 4.0-only, so
     * they get their own step rather than joining installCoreTables() — that
     * step is already recorded complete on existing installs and would never
     * re-run.
     *
     * Purely additive: no existing table or column is touched.
     */
    private function installGeoTables(): void
    {
        $charset = $this->db->get_charset_collate();

        // Replaces the two `wp_options` transient rows per cached lookup that
        // v4 used previously — those accumulated into the hundreds of
        // thousands on a busy site and WP never collects them until expiry.
        // `cache_key` is the same md5-based string Geo has always derived, so
        // the block/per-IP tiering carries over. NULL expires_at means "keep
        // indefinitely": a network's country assignment is stable for years.
        // Read by the Turbo Mode dispatcher too, with raw SQL, since that runs
        // before WordPress loads.
        dbDelta("CREATE TABLE {$this->db->prefix}prli_geo_cache (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            cache_key VARCHAR(64) NOT NULL,
            country CHAR(2) NOT NULL DEFAULT '',
            expires_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY cache_key (cache_key),
            KEY expires_at (expires_at)
        ) {$charset};");

        // IPs seen on a redirect that nothing local could geolocate. Drained by
        // GeoBackfillJob on the Resque worker. Unique on `ip` so repeat hits
        // from one visitor collapse to a single row; rows are deleted as they
        // are resolved, so this stays small.
        dbDelta("CREATE TABLE {$this->db->prefix}prli_geo_pending (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            ip VARCHAR(45) NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY ip (ip)
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

        // Aggregate prli_clicks ONCE in a derived table, then join. Two
        // correlated subqueries against the same table are illegal on MySQL
        // TEMPORARY tables ("Can't reopen table") — which the test suite uses —
        // and are also just slower. LEFT JOIN + COALESCE keeps links with no
        // clicks at 0. first_click = 1 matches v4's unique-count semantics (#676).
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $this->db->query(
            "UPDATE {$links} l
             LEFT JOIN (
                 SELECT link_id,
                        COUNT(*) AS total_clicks,
                        SUM(CASE WHEN first_click = 1 THEN 1 ELSE 0 END) AS unique_clicks
                   FROM {$clicks}
               GROUP BY link_id
             ) agg ON agg.link_id = l.id
                SET l.clicks  = COALESCE(agg.total_clicks, 0),
                    l.uniques = COALESCE(agg.unique_clicks, 0)"
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

    /**
     * Enable the v3-parity `allow_slug_spaces` toggle on sites that already
     * have space-containing slugs (#751), so an upgrade keeps those slugs
     * editable/creatable with spaces the way v3 allowed. Clean sites are left
     * on the default (off) and never see the toggle. Runs once via its own
     * step key; the resolver accepts spaces regardless, so this only governs
     * save-time normalisation.
     *
     * @return void
     */
    private function initSlugSpaceCompat(): void
    {
        if (Links::hasSpaceSlug()) {
            (new Store())->set('allow_slug_spaces', true);
        }
    }
}
