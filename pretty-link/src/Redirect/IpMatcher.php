<?php

declare(strict_types=1);

namespace PrettyLinks\Redirect;

/**
 * IP filter matcher. Supports exact, wildcard (192.168.1.*, 192.168.*,
 * 2001:db8:*), and CIDR (192.168.0.0/24, 2001:db8::/32) forms for both
 * IPv4 and IPv6.
 *
 * Used by ClickWriter against the exclude-IP block list and the
 * allow-IP list (v3-canonical option keys: `prli_exclude_ips` and
 * `whitelist_ips`). Also used by BotDetector indirectly via callers.
 */
class IpMatcher
{
    /**
     * Whether the IP matches any entry in a newline/comma separated list.
     *
     * @param  string $ip   The IP address to test.
     * @param  string $list Newline- or comma-separated patterns.
     * @return boolean True when any entry matches.
     */
    public static function matchesAny(string $ip, string $list): bool
    {
        if ($ip === '' || $list === '') {
            return false;
        }
        // Accept newline OR comma separated lists.
        $raw = str_replace(',', "\n", $list);
        foreach (array_map('trim', explode("\n", $raw)) as $entry) {
            if ($entry !== '' && self::matches($ip, $entry)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether the IP matches a single pattern (exact, wildcard, or CIDR).
     *
     * `*` is valid in any position and spans any characters, so it reaches
     * across octets (`192.169.*`) and across IPv6 groups (`2001:db8:*`).
     * Everything outside a `*` is matched literally, except for letter case:
     * matching is case-insensitive, as v3's SQL `LIKE` was, so an uppercase
     * IPv6 prefix still matches.
     *
     * This method does not validate IP syntax. Callers pass header-derived
     * values (`Geo::rawIp()` returns the client-IP header unvalidated), so a
     * wildcard match does not mean the subject is a well-formed address:
     * `192.168.*` matches `192.168.foo`.
     *
     * @param  string $ip      The IP address to test.
     * @param  string $pattern Exact, wildcard (192.168.1.*), or CIDR pattern.
     * @return boolean True on a match.
     */
    public static function matches(string $ip, string $pattern): bool
    {
        $pattern = trim($pattern);
        if ($pattern === '' || $ip === '') {
            return false;
        }

        // Wildcard: `*` spans any characters, as v3's SQL `LIKE '%'` did.
        // Not `[0-9]+` — that anchors to one trailing octet, which silently
        // made every multi-octet entry unmatchable (#900).
        // e.g. `192.169.*` compiles to `~^192\.169\..*\z~i`.
        if (strpos($pattern, '*') !== false) {
            $quoted = array_map(
                static fn(string $part): string => preg_quote($part, '~'),
                explode('*', $pattern)
            );
            return (bool) preg_match('~^' . implode('.*', $quoted) . '\z~i', $ip);
        }

        // CIDR form: 192.168.0.0/24 or 2001:db8::/32.
        if (strpos($pattern, '/') !== false) {
            return self::matchCidr($ip, $pattern);
        }

        // Exact match (works for both IPv4 and IPv6).
        return $ip === $pattern;
    }

    /**
     * Whether the IP falls within a CIDR range (IPv4 or IPv6).
     *
     * @param  string $ip   The IP address to test.
     * @param  string $cidr CIDR notation, e.g. 192.168.0.0/24.
     * @return boolean True when the IP is inside the range.
     */
    private static function matchCidr(string $ip, string $cidr): bool
    {
        $parts = explode('/', $cidr, 2);
        if (count($parts) !== 2 || !ctype_digit($parts[1])) {
            return false;
        }
        $subnet = $parts[0];
        $bits   = (int) $parts[1];

        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- inet_pton warns on malformed input; the false return is handled below.
        $ipBin     = @inet_pton($ip);
        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- inet_pton warns on malformed input; the false return is handled below.
        $subnetBin = @inet_pton($subnet);
        if ($ipBin === false || $subnetBin === false) {
            return false;
        }
        if (strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }

        $totalBits = strlen($ipBin) * 8;
        if ($bits < 0 || $bits > $totalBits) {
            return false;
        }
        if ($bits === 0) {
            return true;
        }

        $wholeBytes = intdiv($bits, 8);
        $leftover   = $bits % 8;

        if (
            $wholeBytes > 0
            && substr($ipBin, 0, $wholeBytes) !== substr($subnetBin, 0, $wholeBytes)
        ) {
            return false;
        }
        if ($leftover > 0) {
            $mask = (0xff << (8 - $leftover)) & 0xff;
            if (
                (ord($ipBin[$wholeBytes]) & $mask)
                !== (ord($subnetBin[$wholeBytes]) & $mask)
            ) {
                return false;
            }
        }
        return true;
    }
}
