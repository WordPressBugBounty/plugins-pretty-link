<?php

declare(strict_types=1);

namespace PrettyLinks\Redirect;

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter
// Custom plugin tables (prli_geo_*): table names interpolate from $wpdb->prefix
// (trusted), all values bind through $wpdb->prepare(). No object caching: this
// IS the cache, and a stale read would mean a redundant remote lookup.

/**
 * Storage for resolved geo lookups and the queue of IPs still awaiting one.
 *
 * Replaces the transient-backed cache Geo used previously. Two `wp_options`
 * rows per cached block with a month TTL added up to hundreds of thousands of
 * rows on a busy site, WP only garbage-collects transients once they expire,
 * and the Turbo Mode dispatcher had to read them with raw SQL anyway (it runs
 * before WordPress loads, so the object cache is unavailable to it). A narrow
 * dedicated table is one indexed row per entry, readable identically from both
 * paths.
 *
 * Cache keys are the same strings Geo has always derived (see Geo::cacheKey,
 * ipCacheKey and anonDayKey), including their `pl_locate_by_ip_` /
 * `pl_locate_by_ip_anon_` prefixes, so the two-tier block/per-IP semantics
 * carry over unchanged. The Turbo Mode dispatcher inlines the same key
 * formulas — keep them in sync.
 */
class GeoStore
{
    /**
     * Resolved-lookup table, without the site prefix.
     */
    public const CACHE_TABLE = 'prli_geo_cache';

    /**
     * Pending-lookup queue table, without the site prefix.
     */
    public const PENDING_TABLE = 'prli_geo_pending';

    /**
     * Fully-qualified name of the resolved-lookup table.
     *
     * @return string The prefixed table name.
     */
    public static function cacheTable(): string
    {
        return self::db()->prefix . self::CACHE_TABLE;
    }

    /**
     * Fully-qualified name of the pending-lookup queue table.
     *
     * @return string The prefixed table name.
     */
    public static function pendingTable(): string
    {
        return self::db()->prefix . self::PENDING_TABLE;
    }

    /**
     * Read a cached country code.
     *
     * @param  string $cacheKey Key as derived by Geo (md5 hash, no prefix).
     * @return string|null The cached code ('' for a cached "unknown"), or null
     *                     on a miss or an entry that has aged out.
     */
    public static function get(string $cacheKey): ?string
    {
        $db    = self::db();
        $table = self::cacheTable();

        $row = $db->get_row(
            $db->prepare(
                "SELECT country FROM {$table}
                  WHERE cache_key = %s
                    AND (expires_at IS NULL OR expires_at > %s)
                  LIMIT 1",
                $cacheKey,
                self::now()
            ),
            ARRAY_A
        );

        return is_array($row) ? (string) $row['country'] : null;
    }

    /**
     * Write (or refresh) a cached country code.
     *
     * @param  string       $cacheKey Key as derived by Geo (md5 hash, no prefix).
     * @param  string       $country  Uppercase ISO 3166-1 alpha-2 code, or '' for
     *                                a cached "unknown".
     * @param  integer|null $ttl      Lifetime in seconds, or null to keep the
     *                                entry indefinitely. A network's country
     *                                assignment is stable for years, so
     *                                successful lookups pass null and are
     *                                re-resolved on demand rather than expired.
     * @return void
     */
    public static function put(string $cacheKey, string $country, ?int $ttl = null): void
    {
        $db    = self::db();
        $table = self::cacheTable();

        // ON DUPLICATE KEY UPDATE rather than a replace: concurrent redirects
        // resolving the same block must not deadlock or lose the row id.
        //
        // "No expiry" has to be a literal NULL in the statement rather than a
        // bound value: wpdb::prepare() renders a null %s as an empty string,
        // which MySQL stores as a zero date — never NULL, and never greater
        // than now, so the entry would be unreadable by get().
        if ($ttl === null) {
            $sql = $db->prepare(
                "INSERT INTO {$table} (cache_key, country, expires_at, created_at)
                 VALUES (%s, %s, NULL, %s)
                 ON DUPLICATE KEY UPDATE
                    country = VALUES(country),
                    expires_at = VALUES(expires_at)",
                $cacheKey,
                $country,
                self::now()
            );
        } else {
            $sql = $db->prepare(
                "INSERT INTO {$table} (cache_key, country, expires_at, created_at)
                 VALUES (%s, %s, %s, %s)
                 ON DUPLICATE KEY UPDATE
                    country = VALUES(country),
                    expires_at = VALUES(expires_at)",
                $cacheKey,
                $country,
                // Same UTC clock that get()'s comparison uses.
                gmdate('Y-m-d H:i:s', time() + $ttl),
                self::now()
            );
        }

        $db->query($sql);
    }

    /**
     * Queue an IP for background resolution.
     *
     * Called on the redirect path in place of a blocking remote lookup, so it
     * must stay a single cheap write. INSERT IGNORE against the unique `ip`
     * column collapses repeat hits from the same visitor into one row.
     *
     * @param  string $ip The IP awaiting a country lookup.
     * @return void
     */
    public static function queue(string $ip): void
    {
        if ($ip === '') {
            return;
        }

        $db    = self::db();
        $table = self::pendingTable();

        $db->query(
            $db->prepare(
                "INSERT IGNORE INTO {$table} (ip, created_at) VALUES (%s, %s)",
                $ip,
                self::now()
            )
        );
    }

    /**
     * Read a batch of queued IPs, oldest first.
     *
     * @param  integer $limit Maximum rows to return.
     * @return array<int, string> The queued IPs.
     */
    public static function pending(int $limit): array
    {
        if ($limit < 1) {
            return [];
        }

        $db    = self::db();
        $table = self::pendingTable();

        $ips = $db->get_col(
            $db->prepare(
                "SELECT ip FROM {$table} ORDER BY id ASC LIMIT %d",
                $limit
            )
        );

        return is_array($ips) ? array_map('strval', $ips) : [];
    }

    /**
     * Drop an IP from the queue once it has been resolved.
     *
     * @param  string $ip The IP to dequeue.
     * @return void
     */
    public static function dequeue(string $ip): void
    {
        $db    = self::db();
        $table = self::pendingTable();

        $db->query(
            $db->prepare("DELETE FROM {$table} WHERE ip = %s", $ip)
        );
    }

    /**
     * Delete cache rows whose expiry has passed.
     *
     * Expired entries were only filtered at read time, so the per-IP (day) and
     * negative (hour) tiers left dead rows behind forever — the same unbounded
     * growth this table was meant to end, just in a different place. Rows with
     * no expiry are the verified block answers we deliberately keep.
     *
     * @param  integer $limit Maximum rows to delete in one pass, so a large
     *                        backlog never holds a lock for long.
     * @return integer Rows deleted.
     */
    public static function purgeExpired(int $limit = 500): int
    {
        if ($limit < 1) {
            return 0;
        }

        $db    = self::db();
        $table = self::cacheTable();

        return (int) $db->query(
            $db->prepare(
                "DELETE FROM {$table}
                  WHERE expires_at IS NOT NULL
                    AND expires_at <= %s
                  LIMIT %d",
                self::now(),
                $limit
            )
        );
    }

    /**
     * Whether any cache rows have expired and are awaiting purge.
     *
     * @return boolean True when there is something to purge.
     */
    public static function hasExpired(): bool
    {
        $db    = self::db();
        $table = self::cacheTable();

        return (int) $db->get_var(
            $db->prepare(
                "SELECT EXISTS(
                    SELECT 1 FROM {$table}
                     WHERE expires_at IS NOT NULL AND expires_at <= %s
                )",
                self::now()
            )
        ) === 1;
    }

    /**
     * Whether any IPs are still queued.
     *
     * @return boolean True when the queue is non-empty.
     */
    public static function hasPending(): bool
    {
        $db    = self::db();
        $table = self::pendingTable();

        return (int) $db->get_var("SELECT EXISTS(SELECT 1 FROM {$table})") === 1;
    }

    /**
     * Current UTC time in MySQL DATETIME format.
     *
     * @return string The formatted timestamp.
     */
    private static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    /**
     * The WordPress database handle.
     *
     * @return \wpdb The global handle.
     */
    private static function db(): \wpdb
    {
        global $wpdb;
        return $wpdb;
    }
}
