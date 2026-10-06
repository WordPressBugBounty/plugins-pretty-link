<?php

declare(strict_types=1);

namespace PrettyLinks\Repositories;

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_key
// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_value
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter
// Custom plugin tables (prli_*): table names interpolated from $wpdb->prefix (trusted),
// user values bind through $wpdb->prepare(). No caching: these tables are the source
// of truth for click/redirect data and must read-through. "meta_key"/"meta_value" here
// refer to our own prli_link_metas table, not wp_postmeta.

/**
 * Thin CRUD over `prli_link_metas`. Matches the v3 meta surface used by
 * PrettyPay: one row per (link_id, meta_key), values are plain strings
 * with JSON reserved for the handful of keys (`stripe_line_items`) that
 * actually carry structured data.
 *
 * @api
 */
class LinkMetas
{
    /**
     * Returns all meta rows for a link as a key/value map.
     *
     * @param integer $linkId Link identifier.
     *
     * @return array<string, string>
     */
    public function all(int $linkId): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'prli_link_metas';
        $rows  = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT meta_key, meta_value FROM {$table} WHERE link_id = %d",
                $linkId
            ),
            ARRAY_A
        );

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['meta_key']] = (string) ($row['meta_value'] ?? '');
        }
        return $out;
    }

    /**
     * Returns a single meta value, or the default when not present.
     *
     * @param integer     $linkId  Link identifier.
     * @param string      $key     Meta key to read.
     * @param string|null $default Value returned when the key is absent.
     *
     * @return string|null Stored meta value or the default.
     */
    public function get(int $linkId, string $key, ?string $default = null): ?string
    {
        global $wpdb;
        $table = $wpdb->prefix . 'prli_link_metas';
        // ORDER BY id so a pre-existing duplicate (written before the UNIQUE
        // index existed, on a site where the migration step hasn't run or
        // failed) resolves the same way on every read instead of leaving the
        // value up to storage-engine order.
        $value = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT meta_value FROM {$table}
                  WHERE link_id = %d AND meta_key = %s
               ORDER BY id ASC
                  LIMIT 1",
                $linkId,
                $key
            )
        );
        return $value === null ? $default : (string) $value;
    }

    /**
     * Inserts or updates a single meta value for a link.
     *
     * @param integer $linkId Link identifier.
     * @param string  $key    Meta key to write.
     * @param string  $value  Meta value to store.
     *
     * @return void
     */
    public function set(int $linkId, string $key, string $value): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'prli_link_metas';

        // Lowest id, matching get(). This method is for single-valued keys, but
        // the TABLE is not single-valued — multi-value metas legitimately keep
        // several rows per key (that is what v3's `meta_order` column is for), so
        // this deliberately touches one row rather than every match. Ordering it
        // is the fix: get() and set() now always mean the same row, where before
        // each took whichever the storage engine offered first.
        $existingId = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$table}
                  WHERE link_id = %d AND meta_key = %s
               ORDER BY id ASC
                  LIMIT 1",
                $linkId,
                $key
            )
        );
        if ($existingId > 0) {
            $wpdb->update($table, ['meta_value' => $value], ['id' => $existingId]);
            return;
        }

        // No row yet. Insert only if nobody else has, so two concurrent writers
        // can't both land a row that get() would then resolve arbitrarily.
        // InnoDB locks the scanned range on the `link_id_meta_key` index, so the
        // loser blocks and finds the row on the retry below. A UNIQUE index would
        // be the neater guard but isn't available on this table — see above.
        $inserted = (int) $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO {$table} (link_id, meta_key, meta_value, created_at)
                 SELECT %d, %s, %s, %s FROM (SELECT 1) AS seed
                  WHERE NOT EXISTS (
                        SELECT 1 FROM {$table} WHERE link_id = %d AND meta_key = %s
                  )",
                $linkId,
                $key,
                $value,
                current_time('mysql', true),
                $linkId,
                $key
            )
        );
        if ($inserted > 0) {
            return;
        }

        // Lost the race — write into the row the winner created. Inline rather
        // than recursing, so a failed INSERT can't loop.
        $winnerId = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$table}
                  WHERE link_id = %d AND meta_key = %s
               ORDER BY id ASC
                  LIMIT 1",
                $linkId,
                $key
            )
        );
        if ($winnerId > 0) {
            $wpdb->update($table, ['meta_value' => $value], ['id' => $winnerId]);
        }
    }

    /**
     * Reverse-lookup: return the first `link_id` matching a (key, value) pair, or null.
     * Used by features that need to find the link associated with an external ID
     * (e.g. auto-create's `source_post_id`).
     *
     * @param string $key   Meta key to match.
     * @param string $value Meta value to match.
     *
     * @return integer|null Matching link identifier or null when none found.
     */
    public function findLinkIdByMeta(string $key, string $value): ?int
    {
        global $wpdb;
        $table  = $wpdb->prefix . 'prli_link_metas';
        $linkId = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT link_id FROM {$table} WHERE meta_key = %s AND meta_value = %s LIMIT 1",
                $key,
                $value
            )
        );
        return $linkId === null ? null : (int) $linkId;
    }

    /**
     * Batched {@see get()}: one key's value for several links in one query.
     * Links without the key are absent from the result. Where a link holds
     * several rows for the key, the lowest id wins, as in get().
     *
     * @param integer[] $linkIds Link identifiers.
     * @param string    $key     Meta key to read.
     *
     * @return array<int, string> Meta value keyed by link id.
     */
    public function getForLinks(array $linkIds, string $key): array
    {
        $linkIds = array_values(array_unique(array_filter(array_map('intval', $linkIds))));
        if ($linkIds === []) {
            return [];
        }
        global $wpdb;
        $table        = $wpdb->prefix . 'prli_link_metas';
        $placeholders = implode(',', array_fill(0, count($linkIds), '%d'));
        // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $placeholders is a run of literal %d, one per bound id.
        $rows = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT link_id, meta_value FROM {$table}
                  WHERE meta_key = %s AND link_id IN ({$placeholders})
               ORDER BY id ASC",
                $key,
                ...$linkIds
            ),
            ARRAY_A
        );
        return self::firstValuePerLink($rows);
    }

    /**
     * Every link's value for one key, for scans such as "which links point
     * at link X" that have to read the key across the whole table. Lowest id
     * wins per link, as in get() — so this is the wrong reader for multi-row
     * keys such as `prli-url-replacements`; it returns only one of their values.
     *
     * @param string $key Meta key to read.
     *
     * @return array<int, string> Meta value keyed by link id.
     */
    public function allForKey(string $key): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'prli_link_metas';
        $rows  = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT link_id, meta_value FROM {$table} WHERE meta_key = %s ORDER BY id ASC",
                $key
            ),
            ARRAY_A
        );
        return self::firstValuePerLink($rows);
    }

    /**
     * Deletes a single meta row for a link.
     *
     * @param integer $linkId Link identifier.
     * @param string  $key    Meta key to delete.
     *
     * @return void
     */
    public function delete(int $linkId, string $key): void
    {
        global $wpdb;
        $wpdb->delete(
            $wpdb->prefix . 'prli_link_metas',
            [
                'link_id'  => $linkId,
                'meta_key' => $key,
            ]
        );
    }

    /**
     * Deletes every meta row for a link.
     *
     * @param integer $linkId Link identifier.
     *
     * @return void
     */
    public function deleteAll(int $linkId): void
    {
        global $wpdb;
        $wpdb->delete($wpdb->prefix . 'prli_link_metas', ['link_id' => $linkId]);
    }

    /**
     * Collapse id-ordered `link_id, meta_value` rows to the first value per link.
     *
     * @param array<int, array<string, mixed>> $rows Rows ordered by meta id.
     *
     * @return array<int, string> Meta value keyed by link id.
     */
    private static function firstValuePerLink(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $linkId = (int) $row['link_id'];
            if (!isset($out[$linkId])) {
                $out[$linkId] = (string) ($row['meta_value'] ?? '');
            }
        }
        return $out;
    }
}
