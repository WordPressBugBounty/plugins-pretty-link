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

/**
 * Per-click CSV export — runs against the same filter set as the Clicks
 * dashboard (date range, ip, vuid, search, link ids, unique-only). Mode
 * is in $args['mode'] — 'normal' or 'extended'; extended adds the UA-parsed
 * columns the click writer populates only in that mode.
 *
 * Driven by the shared ChunkedCsvExporter base. The legacy
 * `export($args, $mode)` method is retained for back-compat callers and
 * preserves the old `prli_clicks_max_rows_per_file` LIMIT.
 */
class ClicksCsvExporter extends ChunkedCsvExporter
{
    public const DEFAULT_CHUNK_SIZE = 2000;
    public const DEFAULT_MAX_ROWS   = 5000;

    /**
     * Columns present in every tracking mode that records per-visit rows.
     */
    public const COLUMNS_NORMAL = [
        'id',
        'link_id',
        'link_name',
        'link_slug',
        'created_at',
        'ip',
        'vuid',
        'country',
        'browser',
        'referer',
        'uri',
        'robot',
        'first_click',
    ];

    /**
     * Additional columns only populated in extended (UA-parsed) mode.
     */
    public const COLUMNS_EXTENDED = [
        'host',
        'btype',
        'bversion',
        'os',
        'device_type',
        'user_id',
    ];

    /**
     * Logical column → SQL expression. Columns not in this map can't be
     * exported (defensive — keeps the SELECT list a strict whitelist).
     *
     * @var array<string, string>
     */
    private const SELECT_MAP = [
        'id'          => 'c.id',
        'link_id'     => 'c.link_id',
        'link_name'   => 'l.name AS link_name',
        'link_slug'   => 'l.slug AS link_slug',
        'created_at'  => 'c.created_at',
        'ip'          => 'c.ip',
        'vuid'        => 'c.vuid',
        'country'     => 'c.country',
        'browser'     => 'c.browser',
        'referer'     => 'c.referer',
        'uri'         => 'c.uri',
        'robot'       => 'c.robot',
        'first_click' => 'c.first_click',
        'host'        => 'c.host',
        'btype'       => 'c.btype',
        'bversion'    => 'c.bversion',
        'os'          => 'c.os',
        'device_type' => 'c.device_type',
        'user_id'     => 'c.user_id',
    ];

    /**
     * Back-compat single-shot entry point. Honors the v3-era
     * `prli_clicks_max_rows_per_file` filter so existing direct callers
     * (e.g. WP-CLI scripts, third-party integrations) keep their cap.
     * The new chunked path does NOT apply this cap.
     *
     * @param array<string, mixed> $args Export arguments.
     * @param string               $mode Either 'normal' or 'extended'.
     */
    public function export(array $args, string $mode = 'normal'): string
    {
        $maxRows = (int) apply_filters('prli_clicks_max_rows_per_file', self::DEFAULT_MAX_ROWS, $args);
        $columns = $this->columnsForMode($mode);

        // Inline single-shot to honor the row cap exactly as v3 did.
        $columnList              = implode(', ', array_map(
            static fn (string $col): string => self::SELECT_MAP[$col],
            $columns
        ));
        list($whereSql, $params) = $this->buildWhere($args);

        $sql = "SELECT {$columnList}
                FROM {$GLOBALS['wpdb']->prefix}prli_clicks c
                LEFT JOIN {$GLOBALS['wpdb']->prefix}prli_links l ON c.link_id = l.id
                {$whereSql}
                ORDER BY c.created_at DESC
                LIMIT %d";
        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results(
            $wpdb->prepare($sql, ...array_merge($params, [$maxRows])),
            ARRAY_A
        ) ?: [];

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        $fh = fopen('php://temp', 'w+');
        if ($fh === false) {
            return '';
        }
        fputcsv($fh, $columns);
        foreach ($rows as $row) {
            fputcsv($fh, array_map(
                static fn (string $col): string => self::escSpreadsheetCell((string) ($row[$col] ?? '')),
                $columns
            ));
        }
        rewind($fh);
        $out = stream_get_contents($fh);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        fclose($fh);
        return is_string($out) ? $out : '';
    }

    /**
     * Column list for the export, derived from the requested mode.
     *
     * @param  array<string, mixed> $args Export arguments.
     * @return string[]
     */
    protected function columns(array $args): array
    {
        $mode = (string) ($args['mode'] ?? 'normal');
        return $this->columnsForMode($mode);
    }

    /**
     * Resolve the column list for a given tracking mode.
     *
     * @param  string $mode Either 'normal' or 'extended'.
     * @return string[]
     */
    private function columnsForMode(string $mode): array
    {
        return $mode === 'extended'
            ? array_merge(self::COLUMNS_NORMAL, self::COLUMNS_EXTENDED)
            : self::COLUMNS_NORMAL;
    }

    /**
     * Total number of click rows matching the export filters.
     *
     * @param array<string, mixed> $args Export arguments.
     */
    protected function totalRows(array $args): int
    {
        global $wpdb;
        list($whereSql, $params) = $this->buildWhere($args);
        $sql                     = "SELECT COUNT(*)
                  FROM {$wpdb->prefix}prli_clicks c
                  LEFT JOIN {$wpdb->prefix}prli_links l ON c.link_id = l.id
                  {$whereSql}";
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return (int) $wpdb->get_var(
            $params ? $wpdb->prepare($sql, ...$params) : $sql
        );
    }

    /**
     * Fetch one page of click rows for the export.
     *
     * @param  array<string, mixed> $args    Export arguments.
     * @param  integer              $offset  Row offset.
     * @param  integer              $limit   Maximum rows to fetch.
     * @param  string[]             $columns Resolved column list.
     * @return array<int, array<string, mixed>>
     */
    protected function fetchPage(array $args, int $offset, int $limit, array $columns): array
    {
        global $wpdb;
        $columnList              = implode(', ', array_map(
            static fn (string $col): string => self::SELECT_MAP[$col],
            $columns
        ));
        list($whereSql, $params) = $this->buildWhere($args);

        $sql = "SELECT {$columnList}
                FROM {$wpdb->prefix}prli_clicks c
                LEFT JOIN {$wpdb->prefix}prli_links l ON c.link_id = l.id
                {$whereSql}
                ORDER BY c.created_at DESC, c.id DESC
                LIMIT %d OFFSET %d";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return $wpdb->get_results(
            $wpdb->prepare($sql, ...array_merge($params, [$limit, $offset])),
            ARRAY_A
        ) ?: [];
    }

    /**
     * Format a single cell, escaping spreadsheet-injection characters.
     *
     * @param string               $col Column name.
     * @param array<string, mixed> $row Row data keyed by column name.
     */
    protected function formatCell(string $col, array $row): string
    {
        return self::escSpreadsheetCell((string) ($row[$col] ?? ''));
    }

    /**
     * Build the shared WHERE clause + params. Same filter logic as the
     * Clicks dashboard so export rows match what the user sees.
     *
     * @param  array<string, mixed> $args Export arguments.
     * @return array{0: string, 1: list<mixed>}
     */
    private function buildWhere(array $args): array
    {
        global $wpdb;
        $where  = [];
        $params = [];

        $linkIds = [];
        if (!empty($args['link_ids']) && is_array($args['link_ids'])) {
            foreach ($args['link_ids'] as $id) {
                $int = (int) $id;
                if ($int > 0) {
                    $linkIds[] = $int;
                }
            }
        } elseif (!empty($args['link_id'])) {
            $linkIds[] = (int) $args['link_id'];
        }
        if ($linkIds) {
            $placeholders = implode(',', array_fill(0, count($linkIds), '%d'));
            $where[]      = "c.link_id IN ({$placeholders})";
            foreach ($linkIds as $id) {
                $params[] = $id;
            }
        }
        if (!empty($args['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $args['from'])) {
            $where[]  = 'c.created_at >= %s';
            $params[] = $args['from'] . ' 00:00:00';
        }
        if (!empty($args['to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $args['to'])) {
            $where[]  = 'c.created_at <= %s';
            $params[] = $args['to'] . ' 23:59:59';
        }
        if (!empty($args['ip'])) {
            $where[]  = 'c.ip = %s';
            $params[] = (string) $args['ip'];
        }
        if (!empty($args['vuid'])) {
            $where[]  = 'c.vuid = %s';
            $params[] = (string) $args['vuid'];
        }
        if (!empty($args['unique'])) {
            $where[] = 'c.first_click = 1';
        }
        if (isset($args['search']) && $args['search'] !== '') {
            $terms = preg_split('/\s+/', (string) $args['search'], -1, PREG_SPLIT_NO_EMPTY) ?: [];
            foreach ($terms as $term) {
                $like     = '%' . $wpdb->esc_like($term) . '%';
                $where[]  = '(c.ip LIKE %s OR c.vuid LIKE %s OR c.btype LIKE %s OR c.bversion LIKE %s OR c.host LIKE %s OR c.referer LIKE %s OR c.uri LIKE %s OR c.created_at LIKE %s OR l.name LIKE %s)';
                $params[] = $like;
                $params[] = $like;
                $params[] = $like;
                $params[] = $like;
                $params[] = $like;
                $params[] = $like;
                $params[] = $like;
                $params[] = $like;
                $params[] = $like;
            }
        }

        return [
            $where ? 'WHERE ' . implode(' AND ', $where) : '',
            $params,
        ];
    }

    /**
     * Prefix cells that begin with a formula trigger to prevent CSV injection.
     *
     * @param  string $value Raw cell value.
     * @return string The escaped cell value.
     */
    private static function escSpreadsheetCell(string $value): string
    {
        if ($value === '') {
            return $value;
        }
        $first = $value[0];
        if (in_array($first, ['=', '+', '-', '@', "\t", "\r", "\n"], true)) {
            return "'" . $value;
        }
        return $value;
    }
}
