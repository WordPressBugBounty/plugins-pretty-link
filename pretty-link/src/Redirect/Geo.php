<?php

declare(strict_types=1);

namespace PrettyLinks\Redirect;

// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
// phpcs:disable WordPress.Security.ValidatedSanitizedInput.MissingUnslash
// phpcs:disable WordPress.Security.NonceVerification.Recommended
// $_SERVER values (REMOTE_ADDR, HTTP_USER_AGENT, REQUEST_URI, etc.) are read for
// click tracking / targeting / UI rendering, not form-submission input. State-changing
// operations in this class protect with wp_verify_nonce / check_admin_referer.

/**
 * Geolocation lookup: CDN headers first, cspf-locate API fallback.
 */
class Geo
{
    private const CSPF_ENDPOINT = 'https://cspf-locate.herokuapp.com';

    /**
     * Resolve a two-letter country code for the request.
     *
     * @param  string $ip Optional client IP for the API fallback.
     * @return string Uppercase ISO 3166-1 alpha-2 code, or '' if unknown.
     */
    public static function country(string $ip = ''): string
    {
        $candidates = [
            'HTTP_CF_IPCOUNTRY',
            'HTTP_X_GEOIP_COUNTRY',
            'HTTP_X_APPENGINE_COUNTRY',
            'HTTP_X_COUNTRY_CODE',
            'HTTP_GEOIP_COUNTRY_CODE',
        ];
        $country    = '';
        foreach ($candidates as $key) {
            if (!empty($_SERVER[$key])) {
                $code = strtoupper(substr((string) $_SERVER[$key], 0, 2));
                if (preg_match('/^[A-Z]{2}$/', $code)) {
                    $country = $code;
                    break;
                }
            }
        }

        if ($country === '' && self::isPublicIp($ip)) {
            $country = self::lookupViaApi($ip);
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
        $lockey = $ip !== '' ? 'pl_locate_by_ip_' . md5($ip . 'caseproof') : '';
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- v3 Pretty Links Pro public hook; renaming would break existing integrations.
        $loc = apply_filters('plp_locate_by_ip', (object) ['country' => $country], $lockey, null);

        return is_object($loc) && isset($loc->country) ? (string) $loc->country : $country;
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
     * Look up a country code for the IP via the cspf-locate API, with a
     * day-long transient cache.
     *
     * @param  string $ip The public IP to geolocate.
     * @return string Uppercase ISO 3166-1 alpha-2 code, or '' on failure.
     */
    private static function lookupViaApi(string $ip): string
    {
        $lockey = 'pl_locate_by_ip_' . md5($ip . 'caseproof');
        $cached = get_transient($lockey);

        if ($cached !== false) {
            return (string) $cached;
        }

        $response = wp_remote_get(self::CSPF_ENDPOINT . '?ip=' . rawurlencode($ip), [
            'timeout' => 3,
        ]);

        $country = '';
        if (!is_wp_error($response)) {
            $obj = json_decode(wp_remote_retrieve_body($response));
            if (is_object($obj) && isset($obj->country_code)) {
                $code = strtoupper(substr((string) $obj->country_code, 0, 2));
                if (preg_match('/^[A-Z]{2}$/', $code)) {
                    $country = $code;
                }
            }
        }

        if ($country !== '') {
            set_transient($lockey, $country, DAY_IN_SECONDS);
        }

        return $country;
    }
}
