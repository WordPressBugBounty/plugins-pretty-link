<?php

declare(strict_types=1);

namespace PrettyLinks\Redirect;

use PrettyLinks\Options\Store as OptionsStore;

/**
 * Geolocation lookup: CDN headers first, cspf-locate API fallback.
 */
class Geo
{
    public const CSPF_ENDPOINT = 'https://cspf-locate.herokuapp.com';

    /**
     * How long a lookup that produced no country is remembered for. Without a
     * negative entry a host that can't reach the endpoint pays the full
     * request timeout on every single click, forever; v3 cached the empty
     * result too.
     *
     * Kept deliberately short. An unreachable endpoint negative-caches
     * block-wide (see lookupViaApi), and geo *targeting* reads the same cache —
     * so a momentary outage would route every visitor in that /24 as
     * country-unknown until the entry lapses. Five minutes still collapses a
     * retry storm by ~3 orders of magnitude at real click rates while keeping
     * the mis-routing window to minutes rather than an hour.
     */
    public const EMPTY_TTL = 5 * MINUTE_IN_SECONDS;

    /**
     * Resolved lookups for this request, keyed by IP.
     *
     * Targeting (Evaluator) and click recording (ClickWriter) both need the
     * visitor's country and each used to resolve it independently, so a geo
     * link cost two lookups per click. Neither has visibility of the other,
     * so the shared memo lives here.
     *
     * Keyed on the IP as passed in, deliberately. With `anonymize_ips` on the
     * two callers pass different values — Evaluator the full IP (routing must
     * not be degraded by a storage setting), ClickWriter the anonymized one —
     * so they legitimately miss each other's memo entry. Don't "fix" that by
     * normalizing the key: it would make one caller answer with the other's
     * value. The cache table still collapses the API work to one call, since
     * both derive the same /24 block key; the cost is one extra indexed read.
     *
     * @var array<string, string>
     */
    private static array $memo = [];

    /**
     * Discard the per-request memo.
     *
     * Only matters where one PHP process serves many notional requests — the
     * test suite, and long-running WP-CLI commands that loop over visitors.
     *
     * @return void
     */
    public static function flushMemo(): void
    {
        self::$memo = [];
    }

    /**
     * Resolve a two-letter country code for the request, consulting the
     * remote endpoint when headers and cache can't answer.
     *
     * Blocking. Only correct on a path that genuinely cannot proceed without
     * the country — geo targeting has to pick a destination before it can
     * redirect. Anything recording the country for later (click rows) wants
     * cachedCountry() instead.
     *
     * @param  string $ip Optional client IP for the API fallback.
     * @return string Uppercase ISO 3166-1 alpha-2 code, or '' if unknown.
     */
    public static function country(string $ip = ''): string
    {
        // Never null with $allowRemote: a failed lookup resolves to '' and
        // writes a negative cache entry.
        return (string) self::resolve($ip, true);
    }

    /**
     * Resolve a country code from free sources only — CDN headers, the local
     * cache, and the `plp_locate_by_ip` filter. Never makes a network call.
     *
     * @param  string $ip Optional client IP for the cache lookup.
     * @return string|null The code, '' where a source positively reports
     *                     "unknown", or null when only a remote lookup could
     *                     answer — the caller should queue one.
     */
    public static function cachedCountry(string $ip = ''): ?string
    {
        return self::resolve($ip, false);
    }

    /**
     * Shared implementation of country() and cachedCountry().
     *
     * @param  string  $ip          Optional client IP.
     * @param  boolean $allowRemote Whether a cache miss may call the endpoint.
     * @return string|null Uppercase ISO 3166-1 alpha-2 code, '' where a source
     *                     positively reports "unknown", or null when only a
     *                     remote lookup could answer and none was permitted.
     */
    private static function resolve(string $ip, bool $allowRemote): ?string
    {
        if (isset(self::$memo[$ip])) {
            return self::$memo[$ip];
        }

        $candidates = [
            'HTTP_CF_IPCOUNTRY',
            'HTTP_X_VERCEL_IP_COUNTRY',
            'HTTP_X_GEOIP_COUNTRY',
            'HTTP_X_APPENGINE_COUNTRY',
            'HTTP_X_COUNTRY_CODE',
            'HTTP_GEOIP_COUNTRY_CODE',
        ];
        $country    = '';
        foreach ($candidates as $key) {
            if (!empty($_SERVER[$key])) {
                // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- CDN geo header read verbatim; the value is truncated to two characters and must pass the /^[A-Z]{2}$/ check below, which is stricter than any sanitizer.
                $code = strtoupper(substr((string) wp_unslash((string) $_SERVER[$key]), 0, 2));
                // Cloudflare sends XX (unknown) and T1 (Tor) pseudo-codes; fall
                // through to the API lookup rather than matching rules on them.
                // T1 already fails the A-Z check below.
                if (preg_match('/^[A-Z]{2}$/', $code) && $code !== 'XX') {
                    $country = $code;
                    break;
                }
            }
        }

        // Tracks a cache miss that only the endpoint could have answered, so
        // the caller can tell "nobody knows yet" from "known to be unknown"
        // without a second read.
        $unresolved = false;

        if ($country === '' && self::isPublicIp($ip)) {
            if ($allowRemote) {
                $country = self::lookupViaApi($ip);
            } else {
                // Without $allowRemote, a cache miss stays unknown — the caller
                // queues a background lookup rather than holding up the redirect.
                $cached     = self::readCache($ip);
                $unresolved = $cached === null;
                $country    = (string) $cached;
            }
        }

        /**
         * Filter: plp_locate_by_ip
         *
         * V3 Pro compatibility hook (defined in v3's PlpUtils::locate_by_ip
         * at `current-version/pretty-link/pro/app/models/PlpUtils.php:141`).
         *
         * @param object $loc    Object with `country` string property.
         * @param string $lockey Transient cache key (md5 of ip).
         * @param mixed  $raw    Null (no raw API object exposed).
         */
        // The v3 per-IP key format, NOT the block cache key — the hook's
        // documented contract ("md5 of ip") predates block caching and
        // integrations may derive their own transients from it.
        $lockey = $ip !== '' ? self::ipCacheKey($ip) : '';
        // Legacy v3 Pretty Links Pro public hook; renaming would break existing integrations.
        $loc = apply_filters('plp_locate_by_ip', (object) ['country' => $country], $lockey, null);

        // Uppercase once here so every consumer (targeting comparisons,
        // prli_clicks.country) shares one normalized contract even when a
        // filter override returns lowercase. XX (unknown pseudo-code) is
        // rejected from this path too, matching the header and API rules.
        if (is_object($loc) && isset($loc->country)) {
            $country = strtoupper((string) $loc->country);
        }
        $country = $country === 'XX' ? '' : $country;

        // Only memoize once a source has actually answered. Caching the empty
        // result of a headers-and-cache-only pass would make the background
        // lookup, once it lands, invisible for the rest of the request.
        if ($country !== '' || $allowRemote) {
            self::$memo[$ip] = $country;
        }

        // Checked after the filter so a filter-supplied country still counts as
        // resolved even when nothing local had an answer.
        return ($country === '' && $unresolved) ? null : $country;
    }

    /**
     * Resolve the client IP from CDN/proxy headers.
     *
     * Mirrors v3's PrliUtils::get_current_client_ip(): header ladder, first
     * entry of a comma-separated list, then the `pl_get_current_client_ip`
     * override filter. No anonymization — that is a click-storage concern
     * (see ClickWriter), and this value is only used transiently for lookups.
     *
     * @return string The resolved client IP, or '' if none.
     */
    public static function resolveIp(): string
    {
        $ip = self::rawIp();

        /**
         * Filter: pl_get_current_client_ip
         *
         * V3 compatibility hook (defined in v3's PrliUtils::get_current_client_ip
         * at `current-version/pretty-link/app/models/PrliUtils.php:384`). Third
         * parties can override the detected client IP for unusual proxy/CDN
         * setups the default header order doesn't fit.
         *
         * @param string $ip Detected client IP.
         */
        // Legacy v3 Pretty Links public filter; preserved for back-compat.
        return (string) apply_filters('pl_get_current_client_ip', $ip);
    }

    /**
     * The client IP straight off the CDN/proxy header ladder, with no
     * `pl_get_current_client_ip` firing. For callers that apply their own
     * processing before the filter (ClickWriter anonymizes first so hook
     * consumers see the value as stored) — everything else wants resolveIp().
     *
     * @return string The detected client IP, or '' if none.
     */
    public static function rawIp(): string
    {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
            if (empty($_SERVER[$key])) {
                continue;
            }
            // First non-empty entry of a comma-separated list; a header of
            // only separators (malformed proxy chain) falls through the ladder.
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Proxy/CDN client-IP header read verbatim; sanitize_text_field() would mangle an address rather than validate one. The geo lookup gates on isPublicIp() (filter_var/FILTER_VALIDATE_IP), but resolveIp() returns this value unvalidated and it is stored on the click row as-is, so treat it as untrusted downstream.
            foreach (explode(',', (string) wp_unslash((string) $_SERVER[$key])) as $part) {
                $part = trim($part);
                if ($part !== '') {
                    return self::unwrapMappedIpv4($part);
                }
            }
        }
        return '';
    }

    /**
     * Normalize an IPv4-mapped IPv6 address (::ffff:8.8.8.8, as reported by
     * some dual-stack servers) to plain IPv4. Without this, IpUtil::anonymize
     * zeroes the mapped address down to "::", collapsing every such visitor
     * into one anonymized IP / geo cache block.
     *
     * @param  string $ip The IP as read from the request.
     * @return string Plain IPv4 for mapped addresses, the input otherwise.
     */
    private static function unwrapMappedIpv4(string $ip): string
    {
        if (strpos($ip, ':') === false) {
            return $ip;
        }
        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- inet_pton warns on malformed input; the false return is handled below.
        $packed = @inet_pton($ip);
        if (
            $packed !== false
            && strlen($packed) === 16
            && substr($packed, 0, 12) === str_repeat("\0", 10) . "\xff\xff"
        ) {
            return (string) inet_ntop(substr($packed, 12));
        }
        return $ip;
    }

    /**
     * Block-level cache key for a country lookup: derived from the IP's
     * /24 (IPv4) or /48 (IPv6) network base via IpUtil::anonymize(), so one
     * entry serves every visitor in the block and nothing full-IP-derived is
     * persisted. Written without an expiry only when the geo API confirms the
     * whole block resolves to one answer (see lookupViaApi()).
     *
     * @param  string $ip The IP being looked up.
     * @return string The cache key.
     */
    private static function cacheKey(string $ip): string
    {
        $base = IpUtil::anonymize($ip);
        return 'pl_locate_by_ip_' . md5(($base !== '' ? $base : $ip) . 'caseproof');
    }

    /**
     * Per-IP cache key (v3 `PlpUtils::locate_by_ip` key format). Used
     * only when a block straddles geo-database networks — a block-level
     * answer would be wrong for part of the block — and only with
     * `anonymize_ips` off, since the key derives from the full IP.
     *
     * @param  string $ip The IP being looked up.
     * @return string The cache key.
     */
    private static function ipCacheKey(string $ip): string
    {
        return 'pl_locate_by_ip_' . md5($ip . 'caseproof');
    }

    /**
     * Day-TTL key for the anonymize-mode straddle case. Namespaced apart
     * from the block key so its entries — block-wide answers that are NOT
     * verified safe for the whole block — are never read once
     * `anonymize_ips` is turned off and per-IP accuracy is expected again.
     *
     * @param  string $ip The IP being looked up.
     * @return string The cache key.
     */
    private static function anonDayKey(string $ip): string
    {
        $base = IpUtil::anonymize($ip);
        return 'pl_locate_by_ip_anon_' . md5(($base !== '' ? $base : $ip) . 'caseproof');
    }

    /**
     * Whether the API's returned network covers the IP's whole cache block
     * (/24 for IPv4, /48 for IPv6) — i.e. the geo database itself says every
     * address in the block shares this answer, so caching it block-wide for
     * a month loses no accuracy.
     *
     * @param  string $network CIDR network from the API, e.g. "8.8.8.0/24".
     * @param  string $ip      The IP that was looked up.
     * @return boolean True when the whole block shares the answer.
     */
    private static function networkCoversBlock(string $network, string $ip): bool
    {
        $parts = explode('/', $network, 2);
        if (count($parts) !== 2 || !is_numeric($parts[1])) {
            return false;
        }
        $prefix = (int) $parts[1];
        $isV6   = strpos($ip, ':') !== false;
        if ($isV6 !== (strpos($parts[0], ':') !== false)) {
            return false;
        }
        // Prefix 0 (0.0.0.0/0) would month-cache every block on one answer;
        // no real country record spans the whole address space.
        if ($prefix < 1 || $prefix > ($isV6 ? 48 : 24)) {
            return false;
        }
        // Verify the network actually contains the IP — the API should only
        // return the record matched for this IP, but a wrong month-long block
        // entry is costly enough to warrant the check. Since prefix <= block
        // size and blocks are prefix-aligned, containing the IP means
        // containing its whole block.
        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- inet_pton warns on malformed input; the false return is handled below.
        $netPacked = @inet_pton($parts[0]);
        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- inet_pton warns on malformed input; the false return is handled below.
        $ipPacked = @inet_pton($ip);
        if ($netPacked === false || $ipPacked === false || strlen($netPacked) !== strlen($ipPacked)) {
            return false;
        }
        $bytes = intdiv($prefix, 8);
        $bits  = $prefix % 8;
        if ($bytes > 0 && substr($netPacked, 0, $bytes) !== substr($ipPacked, 0, $bytes)) {
            return false;
        }
        if ($bits > 0) {
            $mask = (0xFF << (8 - $bits)) & 0xFF;
            if ((ord($netPacked[$bytes]) & $mask) !== (ord($ipPacked[$bytes]) & $mask)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Whether the IP is a routable, non-private/non-reserved address.
     *
     * @param  string $ip The IP address to test.
     * @return boolean True when the IP is public.
     */
    private static function isPublicIp(string $ip): bool
    {
        if ($ip === '') {
            return false;
        }
        return (bool) filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );
    }

    /**
     * Read the two-tier cache for an IP without contacting the endpoint.
     *
     *  - Block tier: set when the API's returned `network` covers the IP's
     *    whole /24 (IPv4) or /48 (IPv6) block, so every address in it shares
     *    the answer and it caches block-wide with no accuracy loss.
     *  - Per-IP tier (v3 key format): used when the block straddles geo
     *    networks. With `anonymize_ips` on, full-IP keys are off-limits, so
     *    the straddle guess is stored under the mode-scoped anonDayKey()
     *    namespace instead — block-wide (the accepted privacy trade-off) and
     *    never read once the option is turned off.
     *
     * @param  string $ip The public IP to look up.
     * @return string|null The cached code ('' for a cached "unknown"), or null
     *                     on a miss.
     */
    private static function readCache(string $ip): ?string
    {
        // Block key holds only network-verified (block-safe) entries; the
        // mode-specific second tier prevents anonymize-era block-wide guesses
        // from leaking into per-IP mode after the option is toggled off.
        $cached = GeoStore::get(self::cacheKey($ip));
        if ($cached !== null) {
            return $cached;
        }

        $anonymize = (bool) (new OptionsStore())->get('anonymize_ips', false);

        return GeoStore::get($anonymize ? self::anonDayKey($ip) : self::ipCacheKey($ip));
    }

    /**
     * Look up a country code for the IP via the cspf-locate API, reading the
     * cache first and writing the outcome back to it. Blocking.
     *
     * @param  string $ip The public IP to geolocate.
     * @return string Uppercase ISO 3166-1 alpha-2 code, or '' on failure.
     */
    private static function lookupViaApi(string $ip): string
    {
        $cached = self::readCache($ip);
        if ($cached !== null) {
            return $cached;
        }

        $anonymize = (bool) (new OptionsStore())->get('anonymize_ips', false);

        $response = wp_remote_get(self::CSPF_ENDPOINT . '?ip=' . rawurlencode($ip), [
            'timeout' => 3,
        ]);

        // Distinguishes "we couldn't reach the endpoint" from "the endpoint
        // answered and doesn't know". Only used to pick the negative-cache
        // tier below; response parsing is unchanged either way.
        $unreachable = is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200;

        $country = '';
        $network = '';
        if (!is_wp_error($response)) {
            $obj = json_decode(wp_remote_retrieve_body($response));
            if (is_object($obj) && isset($obj->country_code)) {
                $code = strtoupper(substr((string) $obj->country_code, 0, 2));
                // Reject XX from the API body too — same pseudo-code rule as
                // the header ladder; it must never cache or match as a country.
                if (preg_match('/^[A-Z]{2}$/', $code) && $code !== 'XX') {
                    $country = $code;
                }
                $network = isset($obj->network) ? (string) $obj->network : '';
            }
        }

        if ($country === '') {
            // Remember the miss briefly. v3 cached the empty result too; v4
            // dropped that, which turned an unreachable endpoint into a full
            // request timeout on every single click, indefinitely.
            //
            // Which tier depends on what "empty" meant. An unreachable
            // endpoint says nothing about this IP — it's our own
            // connectivity — so holding it block-wide costs no accuracy and
            // suppresses the retry storm that matters. An endpoint that
            // answered "unknown" is reporting a fact about this address, so
            // it must not stop us resolving the rest of its block.
            $negativeKey = $unreachable
                ? self::cacheKey($ip)
                : ($anonymize ? self::anonDayKey($ip) : self::ipCacheKey($ip));
            GeoStore::put($negativeKey, '', self::EMPTY_TTL);

            return '';
        }

        // A network's country assignment is stable for years, so a verified
        // block-wide answer is kept indefinitely (null TTL) rather than
        // expiring and forcing a fresh lookup. Straddle guesses are narrowed
        // to the tier that is safe to reuse.
        //
        // The trade-off is that a wrong answer from the endpoint won't age out
        // on its own. To force a re-resolve for support, delete the row(s):
        // DELETE FROM {prefix}prli_geo_cache WHERE expires_at IS NULL;
        // (safe at any time — a miss just re-resolves on the next lookup).
        if ($network !== '' && self::networkCoversBlock($network, $ip)) {
            GeoStore::put(self::cacheKey($ip), $country, null);
        } elseif ($anonymize) {
            GeoStore::put(self::anonDayKey($ip), $country, DAY_IN_SECONDS);
        } else {
            GeoStore::put(self::ipCacheKey($ip), $country, DAY_IN_SECONDS);
        }

        return $country;
    }
}
