<?php

declare(strict_types=1);

namespace PrettyLinks\Tools;

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

/**
 * Lite link CSV export. Column order is back-compat with v3 (see
 * REWRITE-PLAN.md Appendix A). Pro extends via `prli_csv_export_columns`
 * and `prli_csv_export_rows`.
 *
 * Driven by the shared ChunkedCsvExporter base — see that class for the
 * chunking contract. The legacy `allLinks()` entry-point is retained for
 * back-compat and dispatches to `fullCsv()`.
 */
class CsvExporter extends ChunkedCsvExporter
{
    /**
     * Exported column names, in v3-compatible order.
     *
     * @var string[]
     */
    public const COLUMNS = [
        'id',
        'slug',
        'url',
        'name',
        'description',
        'redirect_type',
        'param_forwarding',
        'track_me',
        'nofollow',
        'sponsored',
        'new_window',
        'source',
        // Read-only on import — Links::create() always writes the current
        // time and Links::update()'s allowlist excludes both keys, so a
        // round-tripped CSV can't override them. Exported for archive value.
        'created_at',
        'updated_at',
        // Read-only on import — aggregates derived from prli_clicks rows
        // (normal/extended) or static-clicks/static-uniques meta (count
        // mode). The CSV importer doesn't honor either, so re-importing
        // won't reset analytics.
        'clicks',
        'uniques',
    ];

    /**
     * Back-compat single-shot entry point.
     */
    public function allLinks(): string
    {
        return $this->fullCsv();
    }

    /**
     * Column list for the export, filterable by Pro.
     *
     * @param  array<string, mixed> $args Export arguments.
     * @return string[]
     */
    protected function columns(array $args): array
    {
        return (array) apply_filters('prli_csv_export_columns', self::COLUMNS);
    }

    /**
     * Total number of link rows eligible for export.
     *
     * @param array<string, mixed> $args Export arguments.
     */
    protected function totalRows(array $args): int
    {
        global $wpdb;
        $count = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}prli_links
              WHERE deleted_at IS NULL AND prettypay_link = 0"
        );
        return $count;
    }

    /**
     * Fetch one page of link rows for the export.
     *
     * @param  array<string, mixed> $args    Export arguments.
     * @param  integer              $offset  Row offset.
     * @param  integer              $limit   Maximum rows to fetch.
     * @param  string[]             $columns Full column list including extras.
     * @return array<int, array<string, mixed>>
     */
    protected function fetchPage(array $args, int $offset, int $limit, array $columns): array
    {
        global $wpdb;

        // Simple (count) mode never bumps prli_links.clicks / .uniques —
        // those values live in prli_link_metas under static-clicks /
        // static-uniques. Substitute the expressions per-mode so the CSV
        // always reflects what the admin UI shows.
        $isCount = (string) (new OptionsStore())->get('extended_tracking', 'normal') === 'count';
        $links   = $wpdb->prefix . 'prli_links';
        $metas   = $wpdb->prefix . 'prli_link_metas';

        $select = [];
        foreach (self::COLUMNS as $col) {
            if ($col === 'clicks' && $isCount) {
                $select[] = '(SELECT COALESCE(CAST(meta_value AS UNSIGNED), 0) '
                          . "FROM {$metas} WHERE link_id = li.id AND meta_key = 'static-clicks' LIMIT 1) AS clicks";
            } elseif ($col === 'uniques' && $isCount) {
                $select[] = '(SELECT COALESCE(CAST(meta_value AS UNSIGNED), 0) '
                          . "FROM {$metas} WHERE link_id = li.id AND meta_key = 'static-uniques' LIMIT 1) AS uniques";
            } else {
                $select[] = "li.{$col}";
            }
        }
        $baseCols = implode(',', $select);

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- $baseCols is built from the COLUMNS whitelist; LIMIT/OFFSET are bound below.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT {$baseCols} FROM {$links} li
                  WHERE li.deleted_at IS NULL AND li.prettypay_link = 0
                  ORDER BY li.id ASC
                  LIMIT %d OFFSET %d",
                $limit,
                $offset
            ),
            ARRAY_A
        ) ?: [];

        // Pre-fill any extra columns added via the columns filter so each
        // row has the key, then let the rows filter populate them.
        $extraColumns = array_diff($columns, self::COLUMNS);
        if ($extraColumns) {
            foreach ($rows as &$row) {
                foreach ($extraColumns as $col) {
                    $row[$col] = '';
                }
            }
            unset($row);
        }

        /**
         * Filter: prli_csv_export_rows
         * Pro hooks this to bulk-populate extra columns. Receives one
         * chunk of rows at a time when called from the chunked path,
         * which means Pro's bulk lookups run once per chunk — same total
         * work as the single-shot path, just spread.
         *
         * @param array<int, array<string, mixed>> $rows
         * @param string[]                         $columns Full column list including extras.
         */
        $rows = (array) apply_filters('prli_csv_export_rows', $rows, $columns);

        return $rows;
    }
}
