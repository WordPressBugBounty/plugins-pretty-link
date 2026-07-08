<?php

declare(strict_types=1);

namespace PrettyLinks\Repositories;

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

/**
 * Thin CRUD over `prli_link_metas`. Matches the v3 meta surface used by
 * PrettyPay: one row per (link_id, meta_key), values are plain strings
 * with JSON reserved for the handful of keys (`stripe_line_items`) that
 * actually carry structured data.
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
        $value = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT meta_value FROM {$table} WHERE link_id = %d AND meta_key = %s LIMIT 1",
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
        $table      = $wpdb->prefix . 'prli_link_metas';
        $existingId = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$table} WHERE link_id = %d AND meta_key = %s LIMIT 1",
                $linkId,
                $key
            )
        );
        if ($existingId) {
            $wpdb->update($table, ['meta_value' => $value], ['id' => (int) $existingId]);
            return;
        }
        $wpdb->insert($table, [
            'link_id'    => $linkId,
            'meta_key'   => $key,
            'meta_value' => $value,
            'created_at' => current_time('mysql', true),
        ]);
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
}
