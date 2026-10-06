<?php

declare(strict_types=1);

namespace PrettyLinks\Redirect;

// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
// $_SERVER values (REMOTE_ADDR, HTTP_USER_AGENT, REQUEST_URI, etc.) are read for
// click tracking / targeting / UI rendering, not form-submission input. State-changing
// operations in this class protect with wp_verify_nonce / check_admin_referer.
// They ARE unslashed on read: wp_magic_quotes() addslashes()es all of $_SERVER
// before `init`, and these values are stored verbatim on the click row, so
// skipping it records `O\'Brien` in the reports (#818). MissingUnslash is
// deliberately left enabled here so a future read that forgets it fails the
// check rather than silently persisting core's escaping. Sanitizing is still
// wrong — see Engine::rawServerString() for why.
// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_key
// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_value
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter
// Custom plugin tables (prli_*): table names interpolated from $wpdb->prefix (trusted),
// user values bind through $wpdb->prepare(). No caching: these tables are the source
// of truth for click/redirect data and must read-through. "meta_key"/"meta_value" here
// refer to our own prli_link_metas table, not wp_postmeta.
use PrettyLinks\Options\Store as OptionsStore;
use wpdb;

/**
 * Writes a click record after the HTTP response has been flushed to the client.
 *
 * Three modes (3.x compat — matches v3 `prli_extended_tracking` values):
 *  - normal:   INSERT a row into prli_clicks with raw fields but skip the
 *              expensive user-agent parsing. btype/bversion/os/device_type
 *              stay empty. Default for new installs.
 *  - extended: Same as normal plus matomo/device-detector UA parsing.
 *  - count:    Increments `static-clicks` in prli_link_metas on every hit,
 *              and `static-uniques` on the visitor's first hit. No per-visit
 *              row in prli_clicks.
 */
class ClickWriter
{
    /**
     * WordPress database handle.
     *
     * @var wpdb
     */
    private wpdb $db;

    /**
     * Pretty Links options accessor.
     *
     * @var OptionsStore
     */
    private OptionsStore $options;

    /**
     * Constructor.
     *
     * @param wpdb         $db      WordPress database handle.
     * @param OptionsStore $options Pretty Links options accessor.
     */
    public function __construct(wpdb $db, OptionsStore $options)
    {
        $this->db      = $db;
        $this->options = $options;
    }

    /**
     * Simple (count) mode entry point. Never inserts a `prli_clicks` row
     * and never bumps `prli_links.clicks` / `prli_links.uniques`. Instead
     * increments `static-clicks` in `prli_link_metas` on every recorded
     * hit, plus `static-uniques` when `$firstClick === 1`. Applies the
     * IP exclusion list via shouldCount() and a simple UA bot check with
     * allow-list override.
     *
     * @param  integer $linkId     The link id being clicked.
     * @param  integer $firstClick One on the visitor's first click of this link, else zero.
     * @return void
     */
    public function writeCount(int $linkId, int $firstClick): void
    {
        if (!$this->shouldCount($linkId)) {
            return;
        }
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? (string) wp_unslash((string) $_SERVER['HTTP_USER_AGENT']) : '';
        if (BotDetector::isBot($ua) && !$this->isIpAllowed($this->resolveIp())) {
            self::logBotSkip($linkId, $ua);
            return;
        }
        $this->bumpStaticClicks($linkId, $firstClick);
    }

    /**
     * Normal mode entry point. INSERTs a `prli_clicks` row with raw fields
     * (no UA parsing — btype/bversion/os/device_type stay empty) and bumps
     * the `prli_links.clicks` / `prli_links.uniques` fast-read counters.
     * Default for new installs.
     *
     * @param  integer $linkId     The link id being clicked.
     * @param  string  $url        Fully assembled outbound URL.
     * @param  string  $vuid       Stable per-visitor id.
     * @param  integer $firstClick One on the visitor's first click of this link, else zero.
     * @return void
     */
    public function writeNormal(int $linkId, string $url, string $vuid, int $firstClick): void
    {
        $this->writeRow($linkId, false, $url, $vuid, $firstClick);
    }

    /**
     * Extended mode entry point. Same as writeNormal() but runs the Matomo
     * device-detector parser to fill in btype/bversion/os/device_type on
     * the `prli_clicks` row. The parser also provides a secondary bot check
     * that catches UAs the simple string matcher misses.
     *
     * @param  integer $linkId     The link id being clicked.
     * @param  string  $url        Fully assembled outbound URL.
     * @param  string  $vuid       Stable per-visitor id.
     * @param  integer $firstClick One on the visitor's first click of this link, else zero.
     * @return void
     */
    public function writeExtended(int $linkId, string $url, string $vuid, int $firstClick): void
    {
        $this->writeRow($linkId, true, $url, $vuid, $firstClick);
    }

    /**
     * Shared implementation for normal/extended modes: INSERT one row into
     * `prli_clicks`, bump the fast-read counters on `prli_links`, fire the
     * `prli_click_written` action. Bot-blocked hits return early and write
     * nothing. `$parseUserAgent=true` enables Matomo UA parsing (extended).
     *
     * @param  integer $linkId         The link id being clicked.
     * @param  boolean $parseUserAgent Whether to run Matomo UA parsing (extended mode).
     * @param  string  $url            Fully assembled outbound URL.
     * @param  string  $vuid           Stable per-visitor id.
     * @param  integer $firstClick     One on the visitor's first click of this link, else zero.
     * @return void
     */
    private function writeRow(int $linkId, bool $parseUserAgent, string $url, string $vuid, int $firstClick): void
    {
        if (!$this->shouldCount($linkId)) {
            return;
        }

        $ip        = $this->resolveIp();
        $userAgent = isset($_SERVER['HTTP_USER_AGENT']) ? (string) wp_unslash((string) $_SERVER['HTTP_USER_AGENT']) : '';
        $referer   = isset($_SERVER['HTTP_REFERER']) ? (string) wp_unslash((string) $_SERVER['HTTP_REFERER']) : '';
        // Extended tracking is the mode that parses the UA; it's also the only
        // mode that queues geolocation.
        $extended  = $parseUserAgent;
        $country   = $this->resolveCountry($ip, $extended);
        $isBot     = BotDetector::isBot($userAgent);

        // IP allow list overrides the bot decision: trust this IP as a human
        // even if the UA pattern tripped the detector. Matches v3 semantics.
        if ($isBot && !$this->isIpAllowed($ip)) {
            self::logBotSkip($linkId, $userAgent);
            return;
        }

        $userId = get_current_user_id();
        $device = $parseUserAgent ? DeviceDetector::detect($userAgent) : [];
        $uri    = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash((string) $_SERVER['REQUEST_URI']) : '';

        if (
            $parseUserAgent
            && self::isDeviceBot($device)
            && !$this->isIpAllowed($ip)
        ) {
            self::logBotSkip($linkId, $userAgent);
            return;
        }

        $table = $this->db->prefix . 'prli_clicks';

        $this->db->insert(
            $table,
            [
                'link_id'     => $linkId,
                'created_at'  => current_time('mysql', true),
                'ip'          => $ip,
                'vuid'        => $vuid,
                // Reverse DNS is NOT resolved here. This runs post-response so
                // the visitor never waits, but the PHP-FPM worker does, and
                // resolver timeouts are commonly 5s+ — on a spike against IPs
                // with no PTR record, workers pile up in DNS wait. Extended-mode
                // rows are stamped later by HostBackfillJob on the Resque
                // worker, the same treatment the geo lookup already gets.
                'host'        => '',
                'uri'         => $uri,
                'referer'     => $referer,
                'country'     => $country,
                'browser'     => $userAgent,
                'btype'       => (string) ($device['btype'] ?? ''),
                'bversion'    => (string) ($device['bversion'] ?? ''),
                'os'          => (string) ($device['os'] ?? ''),
                'device_type' => (string) ($device['type'] ?? ''),
                'user_id'     => $userId > 0 ? $userId : null,
                'robot'       => $isBot ? 1 : 0,
                'first_click' => $firstClick,
            ],
            [
                '%d',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%d',
                '%d',
                '%d',
            ]
        );

        $clickId = (int) $this->db->insert_id;

        // Also bump the fast-read counters on prli_links for list-table
        // display. `clicks` increments on every row; `uniques` only when
        // the visitor hadn't clicked this link before (first_click=1).
        $this->bumpCounter($linkId, $firstClick === 1);

        /**
         * Action: prli_click_written
         *
         * Fires after a click row is written. Subscribers (e.g. rotation
         * click tracking) can use the click id to correlate ancillary
         * analytics rows with the primary click. Does NOT fire for
         * count-only mode since there's no row to correlate to.
         *
         * @param int    $linkId
         * @param int    $clickId Row id just inserted into prli_clicks.
         * @param string $url     Fully assembled outbound URL (after param forwarding).
         */
        if ($clickId > 0) {
            do_action('prli_click_written', $linkId, $clickId, $url);
        }

        // Extended mode wants a reverse-DNS name on the row; the worker
        // resolves it. Throttled to at most one enqueue per minute, and the
        // jobs cron re-checks anyway if this enqueue fails or the row came
        // from the turbo dispatcher, which can't enqueue at all.
        if ($parseUserAgent && $clickId > 0) {
            HostBackfillJob::requestDrain();
        }
    }

    /**
     * The country to store on this click row.
     *
     * Deliberately never contacts the geolocation endpoint. A click row is a
     * report, not a routing decision — nothing is waiting on it — so a remote
     * lookup here only ever costs latency. Where a CDN header, the cache or
     * the `plp_locate_by_ip` filter can answer, we use it. Otherwise the row
     * is written with an empty country, and in Extended mode only the IP is
     * queued for GeoBackfillJob to resolve and fill in.
     *
     * Standard mode never queues: geolocation, like reverse DNS and UA
     * parsing, is an Extended-mode enrichment (v3 recorded no click country
     * at all), and queueing would send every visitor IP to the remote API
     * from the default tracking mode.
     *
     * (Geo targeting still resolves synchronously, in Evaluator — it has to
     * pick a destination before it can redirect. Where both run, Geo's
     * per-request memo means only one lookup happens.)
     *
     * @param  string  $ip       The resolved client IP.
     * @param  boolean $extended Whether the site is in Extended tracking mode.
     * @return string Uppercase ISO 3166-1 alpha-2 code, or '' if not known.
     */
    private function resolveCountry(string $ip, bool $extended): string
    {
        $country = Geo::cachedCountry($ip);
        if ($country !== null) {
            return $country;
        }
        if (!$extended) {
            return '';
        }

        GeoStore::queue($ip);
        GeoBackfillJob::requestDrain();

        return '';
    }

    /**
     * Counter bumps on prli_links — used by normal/extended modes for
     * fast-read display on the links list. `clicks` always bumps; `uniques`
     * only bumps on the visitor's first click of this link (first_click=1),
     * which matches v3 semantics and the "Uniques" column shown in the
     * Links admin list.
     *
     * @param  integer $linkId   The link id whose counters to bump.
     * @param  boolean $isUnique Whether to also bump the uniques counter.
     * @return void
     */
    private function bumpCounter(int $linkId, bool $isUnique): void
    {
        $table = $this->db->prefix . 'prli_links';
        $sql   = $isUnique
            ? "UPDATE {$table} SET clicks = clicks + 1, uniques = uniques + 1 WHERE id = %d"
            : "UPDATE {$table} SET clicks = clicks + 1 WHERE id = %d";
        $this->db->query(
            $this->db->prepare(
                $sql,
                $linkId
            )
        );
    }

    /**
     * Increments the `static-clicks` (and `static-uniques` on first_click=1)
     * meta keys in prli_link_metas — matches v3 count-mode storage exactly
     * for downgrade compatibility. UPDATE first; INSERT only on the very first
     * click for a given link.
     *
     * @param  integer $linkId     The link id whose meta counters to bump.
     * @param  integer $firstClick One on the visitor's first click of this link, else zero.
     * @return void
     */
    private function bumpStaticClicks(int $linkId, int $firstClick): void
    {
        $this->bumpMetaCounter($linkId, 'static-clicks');
        if ($firstClick === 1) {
            $this->bumpMetaCounter($linkId, 'static-uniques');
        }
    }

    /**
     * Add one to a numeric counter stored in `prli_link_metas`, without losing
     * a concurrent first click.
     *
     * The old shape was a read-then-write: UPDATE, and INSERT a row with `1` when
     * the UPDATE matched nothing. Two simultaneous first-ever clicks both found
     * nothing to update and both inserted `1`, and `LinkMetas::get()` reads a
     * single row — so the link was permanently under-counted with a shadow row
     * beside it.
     *
     * A UNIQUE index on (link_id, meta_key) is NOT available as a fix: the table
     * legitimately holds several rows per key for multi-value metas — that is
     * what v3's `meta_order` column is for, and `prli-url-replacements` is
     * written exactly that way. So the insert is made atomic instead, with
     * `INSERT … SELECT … WHERE NOT EXISTS`.
     *
     * How that behaves, because it is not unconditional:
     *
     * Under REPEATABLE READ (the MySQL default) the inner SELECT takes shared
     * next-key locks on the gap where the row would go. Two concurrent statements
     * both take that S lock, then both need an insert-intention X lock, so they
     * DEADLOCK and InnoDB kills one. That is the expected path, not an anomaly,
     * and the loser degrades correctly: `query()` returns false, `(int) false` is
     * 0, and control falls through to the trailing `incrementMeta()`, which finds
     * the winner's row and adds this click to it.
     *
     * Under READ COMMITTED — which some hosts configure — gap locking is largely
     * disabled and both seeds can land. What bounds that is `incrementMeta()`
     * updating EVERY matching row: two seed rows then stay in step, so the count
     * is permanently off by the single click lost in the race rather than drifting
     * further apart on every click after it. That is why it must keep touching
     * every row; narrowing it to one would silently reintroduce the drift.
     *
     * @param  integer $linkId The link id whose counter to bump.
     * @param  string  $key    Counter meta key.
     * @return void
     */
    private function bumpMetaCounter(int $linkId, string $key): void
    {
        if ($this->incrementMeta($linkId, $key) > 0) {
            return;
        }

        $table = $this->db->prefix . 'prli_link_metas';
        // Insert the seed row only if nobody else has. `SELECT … FROM (SELECT 1)`
        // gives the INSERT a one-row source so the WHERE NOT EXISTS decides it.
        $inserted = (int) $this->db->query(
            $this->db->prepare(
                "INSERT INTO {$table} (link_id, meta_key, meta_value, created_at)
                 SELECT %d, %s, '1', %s FROM (SELECT 1) AS seed
                  WHERE NOT EXISTS (
                        SELECT 1 FROM {$table} WHERE link_id = %d AND meta_key = %s
                  )",
                $linkId,
                $key,
                current_time('mysql', true),
                $linkId,
                $key
            )
        );
        if ($inserted > 0) {
            return;
        }

        // Someone inserted the seed row while we were deciding — add our click
        // to theirs instead of dropping it.
        $this->incrementMeta($linkId, $key);
    }

    /**
     * Increment every row holding this counter. Returns rows changed.
     *
     * Every row, not one: on a site already carrying shadow rows from the old
     * race, this keeps them in step so reads stop drifting further apart. It is
     * also what bounds the READ COMMITTED case described on bumpMetaCounter().
     *
     * Note the deliberate asymmetry with `LinkMetas::set()`, which touches ONE row
     * ordered by id. Both are correct for what they hold: counters want every row,
     * multi-value metas want one. Safe because this is only ever called with
     * `static-clicks` and `static-uniques`, which nothing else writes.
     *
     * @param  integer $linkId The link id.
     * @param  string  $key    Counter meta key.
     * @return integer Rows changed.
     */
    private function incrementMeta(int $linkId, string $key): int
    {
        $table = $this->db->prefix . 'prli_link_metas';
        return (int) $this->db->query(
            $this->db->prepare(
                "UPDATE {$table}
                    SET meta_value = CAST(CAST(meta_value AS UNSIGNED) + 1 AS CHAR)
                  WHERE link_id = %d AND meta_key = %s",
                $linkId,
                $key
            )
        );
    }

    /**
     * Whether this request will be thrown away by the click filters, without
     * writing anything or touching a cookie.
     *
     * Answers the question the redirect-time filters need: "is this hit going
     * to be discarded?" — a bot user agent, an excluded IP, or a repeat inside
     * the dedup window. Callers that carry per-hit state on the redirect path
     * use it so bots and browser retries don't consume it. Sequential rotation
     * is the reason it exists: it advanced its persistent round-robin counter
     * from inside `prli_target_url`, which runs before any of these gates, so
     * crawlers and deduped repeat visits burned slots and skewed the rotation.
     *
     * Says nothing about whether the link tracks clicks at all — a link with
     * tracking off has no filters to fail, and its hits are all real as far as
     * rotation is concerned, but that is the caller's check to make (see
     * `RotationService`, which resolves `prli_track_link` and skips this
     * entirely when tracking is off).
     *
     * Deliberately side-effect free: unlike `resolveDedup()`, this only peeks
     * at the cookie. `Engine` still owns setting it, exactly once.
     *
     * @param  integer $linkId The link id being clicked.
     * @return boolean True when the hit will not be recorded.
     */
    public function isRequestFiltered(int $linkId): bool
    {
        if (!$this->shouldCount($linkId)) {
            return true;
        }
        if (isset($_COOKIE['prli_dedup_' . $linkId])) {
            return true;
        }

        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? (string) wp_unslash((string) $_SERVER['HTTP_USER_AGENT']) : '';
        if ($this->isIpAllowed($this->resolveIp())) {
            // Allow list overrides every bot verdict, same as writeRow().
            return false;
        }
        if (BotDetector::isBot($ua)) {
            return true;
        }

        // Extended mode has a SECOND bot gate: writeRow() also drops the click
        // when Matomo's parser says bot or can't identify the client at all
        // (isDeviceBot()), and that catches crawlers the pattern
        // matcher above misses. Predicting without it would let those advance the
        // rotation and then be discarded — the exact bug this method exists to
        // prevent. Only run in extended mode, since that's the only mode that
        // parses the UA at all.
        if ((string) $this->options->get('extended_tracking', 'normal') === 'extended') {
            return self::isDeviceBot(DeviceDetector::detect($ua));
        }

        return false;
    }

    /**
     * Gate applied to every click write regardless of mode. Returns false
     * when the link id is invalid or the client IP matches the site-wide
     * `prli_exclude_ips` list. Callers short-circuit on false and record
     * nothing.
     *
     * @param  integer $linkId The link id being clicked.
     * @return boolean True when the click should be recorded.
     */
    private function shouldCount(int $linkId): bool
    {
        if ($linkId <= 0) {
            return false;
        }
        $blockedIps = (string) $this->options->get('prli_exclude_ips', '');
        if ($blockedIps !== '' && IpMatcher::matchesAny($this->resolveIp(), $blockedIps)) {
            return false;
        }
        return true;
    }

    /**
     * True when the given IP matches the site-wide `whitelist_ips` allow
     * list. Used to override a positive bot detection so admin testers /
     * monitoring tools on known IPs can still generate clicks.
     *
     * @param  string $ip The client IP to test.
     * @return boolean True when the IP is on the allow list.
     */
    private function isIpAllowed(string $ip): bool
    {
        if ($ip === '') {
            return false;
        }
        $allowList = (string) $this->options->get('whitelist_ips', '');
        return $allowList !== '' && IpMatcher::matchesAny($ip, $allowList);
    }

    /**
     * The extended-mode bot verdict from a parsed user agent: Matomo's own
     * bot flag, or a client it couldn't identify at all (#901). Shared by
     * writeRow() and the rotation prediction so the two can't disagree.
     *
     * @param  array<string, mixed> $device Result of DeviceDetector::detect().
     * @return boolean True when the click should be treated as a bot.
     */
    private static function isDeviceBot(array $device): bool
    {
        return ($device['is_bot'] ?? false) || BotDetector::isUnidentifiedClient($device);
    }

    /**
     * Surface an intentionally-silent bot-filter drop when WP_DEBUG is on, so
     * developers and monitoring tools can see WHY a hit didn't count (the only
     * other signal is reading the docs or the IP allow list). Dev-only by
     * design: bot/crawler hits are frequent, so this must never run in
     * production. The UA is CRLF-stripped and length-capped to stay log-safe.
     *
     * @param  integer $linkId    The link id whose click was skipped.
     * @param  string  $userAgent The request User-Agent string.
     * @return void
     */
    private static function logBotSkip(int $linkId, string $userAgent): void
    {
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return;
        }
        $safeUa = str_replace(["\r", "\n"], ' ', substr($userAgent, 0, 120));
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- WP_DEBUG-gated diagnostic for an otherwise-silent bot-filter drop.
        error_log(sprintf('[PrettyLinks] click skipped: bot UA detected for link %d (UA: %s)', $linkId, $safeUa));
    }

    /**
     * Returns 1 on the visitor's first click of this link (30-day per-link
     * cookie window), 0 thereafter. Drives the `uniques` counter in all
     * three modes — `prli_links.uniques` in normal/extended, `static-uniques`
     * meta in count. Must be called before the response is flushed;
     * setcookie() inside the shutdown function is a no-op after
     * fastcgi_finish_request().
     *
     * @param  integer $linkId The link id being clicked.
     * @return integer One on the visitor's first click of this link, else zero.
     */
    public static function resolveFirstClick(int $linkId): int
    {
        $cookie = 'prli_click_' . $linkId;
        if (isset($_COOKIE[$cookie])) {
            return 0;
        }
        setcookie($cookie, '1', time() + 30 * DAY_IN_SECONDS, '/', '', is_ssl(), true);
        $_COOKIE[$cookie] = '1';
        return 1;
    }

    /**
     * Returns true if this request is a duplicate within the 10-second dedup
     * window, false on the first request (and sets the short-lived cookie).
     * Must be called before headers are flushed — same constraint as
     * resolveFirstClick() and resolveVisitorId(). Applies to all three
     * tracking modes so rapid browser retries / prefetch requests are
     * suppressed regardless of mode, matching v3 transient behaviour.
     *
     * @param  integer $linkId The link id being clicked.
     * @return boolean True when this is a duplicate within the dedup window.
     */
    public static function resolveDedup(int $linkId): bool
    {
        $cookie = 'prli_dedup_' . $linkId;
        if (isset($_COOKIE[$cookie])) {
            return true;
        }
        setcookie($cookie, '1', time() + 10, '/', '', is_ssl(), true);
        $_COOKIE[$cookie] = '1';
        return false;
    }

    /**
     * Stable per-visitor id (1-year cookie) stored as `vuid` on each
     * `prli_clicks` row in normal/extended modes and used by analytics to
     * count distinct visitors. Count mode doesn't read the value but the
     * cookie is still set on every click so a later mode switch has a
     * visitor id ready. Must be called before the response is flushed.
     */
    public static function resolveVisitorId(): string
    {
        $cookie = 'prli_visitor';
        if (!empty($_COOKIE[$cookie])) {
            return sanitize_key((string) $_COOKIE[$cookie]);
        }
        $uid = uniqid();
        setcookie($cookie, $uid, time() + YEAR_IN_SECONDS, '/', '', is_ssl(), true);
        $_COOKIE[$cookie] = $uid;
        return $uid;
    }

    /**
     * Resolve the client IP for click storage: the shared Geo::rawIp()
     * ladder, then optional anonymization, then the
     * `pl_get_current_client_ip` firing so hook consumers see the value
     * as stored.
     *
     * @return string The resolved client IP, or '' if none.
     */
    private function resolveIp(): string
    {
        $ip = Geo::rawIp();
        if ($ip !== '' && (bool) $this->options->get('anonymize_ips', false)) {
            $ip = IpUtil::anonymize($ip);
        }

        /**
         * Filter: pl_get_current_client_ip
         *
         * V3 compatibility hook (defined in v3's PrliUtils::get_current_client_ip
         * at `current-version/pretty-link/app/models/PrliUtils.php:384`). Third
         * parties can override the detected client IP — useful for unusual
         * proxy/CDN setups where the default `HTTP_CF_CONNECTING_IP` →
         * `HTTP_X_REAL_IP` → `HTTP_X_FORWARDED_FOR` → `REMOTE_ADDR` order
         * doesn't fit. The filter fires AFTER optional anonymization, so
         * hook consumers see the final value v4 would have stored.
         *
         * @param string $ip Detected client IP.
         */
        // Legacy v3 Pretty Links public filter; preserved for back-compat.
        return (string) apply_filters('pl_get_current_client_ip', $ip);
    }
}
