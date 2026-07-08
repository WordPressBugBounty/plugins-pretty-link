<?php

declare(strict_types=1);

namespace PrettyLinks\Redirect;

/**
 * IP filter matcher. Supports exact, wildcard (192.168.1.*), and
 * CIDR (192.168.0.0/24, 2001:db8::/32) forms for both IPv4 and IPv6.
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

        // Wildcard form: 192.168.1.* matches any final octet.
        if (strpos($pattern, '*') !== false) {
            $regex = '~^' . str_replace(
                ['.', '*'],
                ['\\.', '[0-9]+'],
                $pattern
            ) . '$~';
            return (bool) preg_match($regex, $ip);
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
