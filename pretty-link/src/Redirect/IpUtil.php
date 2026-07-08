<?php

declare(strict_types=1);

namespace PrettyLinks\Redirect;

/**
 * IP anonymization for the GDPR/privacy toggle.
 *
 * IPv4: zero the last octet (192.168.1.42 -> 192.168.1.0).
 * IPv6: zero the last 80 bits (keep the /48 network).
 */
class IpUtil
{
    /**
     * Anonymize an IP address by zeroing its host portion.
     *
     * @param  string $ip The IP address to anonymize.
     * @return string The anonymized IP, or '' if the input is invalid.
     */
    public static function anonymize(string $ip): string
    {
        if ($ip === '') {
            return '';
        }
        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- inet_pton emits a warning on malformed input; the false return is handled below.
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return '';
        }
        $len = strlen($packed);
        if ($len === 4) {
            $packed[3] = "\0";
        } elseif ($len === 16) {
            for ($i = 6; $i < 16; $i++) {
                $packed[$i] = "\0";
            }
        } else {
            return '';
        }
        return (string) inet_ntop($packed);
    }
}
