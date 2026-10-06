<?php

declare(strict_types=1);

namespace PrettyLinks\Redirect;

/**
 * Reverse-DNS (PTR) lookup with a hard time cap.
 *
 * `gethostbyaddr()` has no timeout: it waits as long as the system resolver is
 * configured to, which is commonly 5-10 seconds per address with no PTR record
 * and longer with retries. PHP can't cap it, because the OS does the lookup.
 * So this sends the PTR query straight to the system's nameservers over UDP,
 * with our own deadline across all of them.
 *
 * Nameservers come from /etc/resolv.conf. Where it can't be read (Windows,
 * `open_basedir`, some containers) there is no bounded way to ask, so this
 * returns null rather than falling back to the unbounded `gethostbyaddr()`.
 * Every outcome other than a valid hostname — timeout, NXDOMAIN, SERVFAIL, a
 * truncated or malformed reply — returns null, and the caller stores the IP
 * instead, which is what `gethostbyaddr()` itself returns for an address with
 * no PTR record.
 */
class ReverseDns
{
    /**
     * Total time allowed for one lookup across every nameserver, in seconds.
     */
    public const TIMEOUT = 3.0;

    /**
     * Where the system resolver's nameservers are listed.
     *
     * @var string
     */
    private static $resolvConf = '/etc/resolv.conf';

    /**
     * DNS record type for PTR.
     */
    private const TYPE_PTR = 12;

    /**
     * Resolve an IP to its hostname.
     *
     * @param  string $ip      An IPv4 or IPv6 address.
     * @param  float  $timeout Total seconds allowed.
     * @return string|null The hostname, or null when there isn't one in time.
     */
    public static function lookup(string $ip, float $timeout = self::TIMEOUT): ?string
    {
        $name = self::ptrName($ip);
        if ($name === null) {
            return null;
        }

        $nameservers = self::nameservers();
        if ($nameservers === []) {
            return null;
        }

        $deadline = microtime(true) + $timeout;
        foreach ($nameservers as $nameserver) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                break;
            }
            $answer = self::query($nameserver, $name, $remaining);
            if ($answer !== false) {
                // An authoritative "no record" is final; don't ask the next server.
                return $answer;
            }
        }
        return null;
    }

    /**
     * The in-addr.arpa / ip6.arpa name for an address.
     *
     * @param  string $ip The address.
     * @return string|null The PTR query name, or null for an invalid address.
     */
    public static function ptrName(string $ip): ?string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return implode('.', array_reverse(explode('.', $ip))) . '.in-addr.arpa';
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $packed = inet_pton($ip);
            if ($packed === false) {
                return null;
            }
            $nibbles = str_split(bin2hex($packed));
            return implode('.', array_reverse($nibbles)) . '.ip6.arpa';
        }
        return null;
    }

    /**
     * Nameserver addresses from resolv.conf, in order.
     *
     * @return string[]
     */
    private static function nameservers(): array
    {
        if (!@is_readable(self::$resolvConf)) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- open_basedir makes an unreadable path warn; that is an expected outcome.
            return [];
        }
        $lines = file(self::$resolvConf, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return [];
        }
        $servers = [];
        foreach ($lines as $line) {
            if (preg_match('/^\s*nameserver\s+(\S+)/', $line, $m) && filter_var($m[1], FILTER_VALIDATE_IP) !== false) {
                $servers[] = $m[1];
            }
        }
        return array_values(array_unique($servers));
    }

    /**
     * Ask one nameserver for a PTR record.
     *
     * @param  string $nameserver Nameserver IP.
     * @param  string $name       PTR query name.
     * @param  float  $timeout    Seconds allowed for this server.
     * @return string|null|false Hostname; null for a definitive "none"; false
     *                           when this server didn't answer usefully.
     */
    private static function query(string $nameserver, string $name, float $timeout)
    {
        $deadline = microtime(true) + $timeout;
        $id       = random_int(0, 0xFFFF);
        $packet   = self::buildQuery($id, $name);
        if ($packet === null) {
            return false;
        }

        $address = strpos($nameserver, ':') !== false ? '[' . $nameserver . ']' : $nameserver;
        $socket  = @stream_socket_client('udp://' . $address . ':53', $errno, $errstr, $timeout); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A failed socket is an expected outcome; the return value is checked.
        if ($socket === false) {
            return false;
        }

        // Only the time left after connecting, so the cap holds end to end.
        $timeout = $deadline - microtime(true);
        if ($timeout <= 0) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- UDP socket, not a file.
            fclose($socket);
            return false;
        }
        $seconds      = (int) floor($timeout);
        // 0s + 0us would mean "no timeout" to stream_set_timeout().
        $microseconds = max($seconds > 0 ? 0 : 1, (int) (($timeout - $seconds) * 1000000));
        stream_set_timeout($socket, $seconds, $microseconds);

        $response = false;
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- UDP socket, not a file.
        if (fwrite($socket, $packet) === strlen($packet)) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- UDP socket, not a file.
            $response = fread($socket, 4096);
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- UDP socket, not a file.
        fclose($socket);

        if (!is_string($response) || $response === '') {
            return false;
        }
        return self::parseResponse($response, $id, $name);
    }

    /**
     * Build a recursive PTR query packet.
     *
     * @param  integer $id   Query id.
     * @param  string  $name Query name.
     * @return string|null The packet, or null for a name DNS can't carry.
     */
    public static function buildQuery(int $id, string $name): ?string
    {
        // Header: id, flags (recursion desired), 1 question, 0 answer/authority/additional.
        $packet = pack('nnnnnn', $id, 0x0100, 1, 0, 0, 0);
        foreach (explode('.', $name) as $label) {
            $length = strlen($label);
            if ($length === 0 || $length > 63) {
                return null;
            }
            $packet .= chr($length) . $label;
        }
        return $packet . "\0" . pack('nn', self::TYPE_PTR, 1);
    }

    /**
     * Pull the first PTR hostname out of a DNS response.
     *
     * @param  string      $response Raw response packet.
     * @param  integer     $id       The query id it must answer.
     * @param  string|null $name     The PTR name asked about. When given, the
     *                               question and the answer's owner must match
     *                               it, so an unrelated record isn't stored.
     * @return string|null|false Hostname; null when the server says there's
     *                           none; false for a reply we can't use.
     */
    public static function parseResponse(string $response, int $id, ?string $name = null)
    {
        if (strlen($response) < 12) {
            return false;
        }
        $header = unpack('nid/nflags/nqd/nan', substr($response, 0, 8));
        if (!is_array($header) || $header['id'] !== $id || ($header['flags'] & 0x8000) === 0) {
            return false;
        }

        $rcode     = $header['flags'] & 0x000F;
        $truncated = ($header['flags'] & 0x0200) !== 0;

        // Check the question before trusting any answer, NXDOMAIN included:
        // when we know what we asked, the reply must echo exactly that one
        // PTR/IN question, or it's someone else's reply (try the next server).
        if ($name !== null && $header['qd'] !== 1) {
            return false;
        }
        $offset = 12;
        for ($i = 0; $i < $header['qd']; $i++) {
            $question = self::readName($response, $offset);
            if ($question === null || strlen($response) < $offset + 4) {
                return false;
            }
            $qfields = unpack('ntype/nclass', substr($response, $offset, 4));
            $offset += 4;
            if (
                $name !== null
                && (strcasecmp($question, $name) !== 0 || $qfields['type'] !== self::TYPE_PTR || $qfields['class'] !== 1)
            ) {
                return false;
            }
        }

        if ($rcode === 3) {
            // NXDOMAIN: no PTR record exists — unless the reply was cut short.
            return $truncated ? false : null;
        }
        if ($rcode !== 0) {
            return false;
        }

        $unusable = false;

        for ($i = 0; $i < $header['an']; $i++) {
            $owner = self::readName($response, $offset);
            if ($owner === null || strlen($response) < $offset + 10) {
                return false;
            }
            $record = unpack('ntype/nclass/Nttl/nlength', substr($response, $offset, 10));
            $offset += 10;
            if (!is_array($record)) {
                return false;
            }
            $end = $offset + $record['length'];
            if (strlen($response) < $end) {
                return false;
            }
            $forUs = $record['class'] === 1 && ($name === null || strcasecmp($owner, $name) === 0);
            if ($record['type'] === self::TYPE_PTR && !$forUs) {
                // A PTR for another name or class isn't our answer.
                $unusable = true;
            } elseif ($record['type'] === self::TYPE_PTR) {
                // The name must fill its RDATA exactly; anything else means
                // the record was malformed or read into its neighbours.
                $dataOffset = $offset;
                $host       = self::readName($response, $dataOffset);
                if ($host !== null && $dataOffset === $end && self::isHostname($host)) {
                    return $host;
                }
                $unusable = true;
            }
            $offset = $end;
        }

        // A truncated reply may have dropped the answer, an unusable PTR isn't
        // an answer either, and an empty reply without RA (recursion
        // available) is a referral rather than a verdict: let the next server
        // try. Otherwise NOERROR with no PTR means there's none.
        $recursive = ($header['flags'] & 0x0080) !== 0;
        return $truncated || $unusable || !$recursive ? false : null;
    }

    /**
     * Whether a PTR answer is a plausible hostname worth storing.
     *
     * DNS label rules: at most 253 characters, labels of 1–63 characters
     * made of letters, digits, hyphens and underscores, no leading or trailing
     * hyphen. Keeps a spoofed or odd reply from planting arbitrary text in
     * click history.
     *
     * @param  string $host The decoded PTR name.
     * @return boolean
     */
    public static function isHostname(string $host): bool
    {
        if ($host === '' || strlen($host) > 253) {
            return false;
        }
        foreach (explode('.', $host) as $label) {
            if (preg_match('/^[A-Za-z0-9_](?:[A-Za-z0-9_-]{0,61}[A-Za-z0-9_])?$/', $label) !== 1) {
                return false;
            }
        }
        return true;
    }

    /**
     * Read a (possibly compressed) domain name, advancing $offset past it.
     *
     * @param  string  $packet The packet.
     * @param  integer $offset Read position, updated to just after the name.
     * @return string|null The name, or null for a malformed one.
     */
    private static function readName(string $packet, int &$offset): ?string
    {
        $labels = [];
        $pos    = $offset;
        $jumped = false;
        $length = strlen($packet);

        // A name has at most 127 labels; anything longer is a pointer loop.
        for ($guard = 0; $guard < 128; $guard++) {
            if ($pos >= $length) {
                return null;
            }
            $byte = ord($packet[$pos]);
            if ($byte === 0) {
                if (!$jumped) {
                    $offset = $pos + 1;
                }
                return implode('.', $labels);
            }
            if (($byte & 0xC0) === 0xC0) {
                if ($pos + 1 >= $length) {
                    return null;
                }
                if (!$jumped) {
                    $offset = $pos + 2;
                }
                $pos    = (($byte & 0x3F) << 8) | ord($packet[$pos + 1]);
                $jumped = true;
                continue;
            }
            // Plain labels are 1-63 bytes; 0x40-0xBF are reserved label types.
            if ($byte > 63 || $pos + 1 + $byte > $length) {
                return null;
            }
            $labels[] = substr($packet, $pos + 1, $byte);
            $pos     += 1 + $byte;
        }
        return null;
    }
}
