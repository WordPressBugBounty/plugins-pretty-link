<?php

declare(strict_types=1);

namespace PrettyLinks\Redirect;

// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
// phpcs:disable WordPress.Security.ValidatedSanitizedInput.MissingUnslash
// phpcs:disable WordPress.Security.NonceVerification.Recommended
// $_SERVER values (REMOTE_ADDR, HTTP_USER_AGENT, REQUEST_URI, etc.) are read for
// click tracking / targeting / UI rendering, not form-submission input. State-changing
// operations in this class protect with wp_verify_nonce / check_admin_referer.
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
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
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
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
        $userAgent = isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
        $referer   = isset($_SERVER['HTTP_REFERER']) ? (string) $_SERVER['HTTP_REFERER'] : '';
        $country   = Geo::country($ip);
        $isBot     = BotDetector::isBot($userAgent);

        // IP allow list overrides the bot decision: trust this IP as a human
        // even if the UA pattern tripped the detector. Matches v3 semantics.
        if ($isBot && !$this->isIpAllowed($ip)) {
            self::logBotSkip($linkId, $userAgent);
            return;
        }

        $userId = get_current_user_id();
        $device = $parseUserAgent ? DeviceDetector::detect($userAgent) : [];
        $uri    = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';

        if (
            $parseUserAgent
            && ($device['is_bot'] ?? false)
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
                'host'        => $parseUserAgent ? (string) gethostbyaddr($ip) : '',
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
        $table    = $this->db->prefix . 'prli_link_metas';
        $affected = (int) $this->db->query(
            $this->db->prepare(
                "UPDATE {$table}
                    SET meta_value = CAST(CAST(meta_value AS UNSIGNED) + 1 AS CHAR)
                  WHERE link_id = %d AND meta_key = 'static-clicks'",
                $linkId
            )
        );
        if (!$affected) {
            $this->db->insert($table, [
                'link_id'    => $linkId,
                'meta_key'   => 'static-clicks',
                'meta_value' => '1',
                'created_at' => current_time('mysql', true),
            ]);
        }

        if ($firstClick === 1) {
            $affected = (int) $this->db->query(
                $this->db->prepare(
                    "UPDATE {$table}
                        SET meta_value = CAST(CAST(meta_value AS UNSIGNED) + 1 AS CHAR)
                      WHERE link_id = %d AND meta_key = 'static-uniques'",
                    $linkId
                )
            );
            if (!$affected) {
                $this->db->insert($table, [
                    'link_id'    => $linkId,
                    'meta_key'   => 'static-uniques',
                    'meta_value' => '1',
                    'created_at' => current_time('mysql', true),
                ]);
            }
        }
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
     * Resolve the client IP from CDN headers, with optional anonymization
     * and the `pl_get_current_client_ip` override filter.
     *
     * @return string The resolved client IP, or '' if none.
     */
    private function resolveIp(): string
    {
        $ip = '';
        // Honor CDN headers first.
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
            if (!empty($_SERVER[$key])) {
                $val = (string) $_SERVER[$key];
                $ip  = trim((string) strtok($val, ','));
                break;
            }
        }
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
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Legacy v3 Pretty Links public filter; preserved for back-compat.
        return (string) apply_filters('pl_get_current_client_ip', $ip);
    }
}
