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
/**
 * Read access to the prli_clicks table.
 *
 * Custom plugin tables (prli_*): table names interpolated from $wpdb->prefix (trusted),
 * user values bind through $wpdb->prepare(). No caching: these tables are the source
 * of truth for click/redirect data and must read-through. "meta_key"/"meta_value" here
 * refer to our own prli_link_metas table, not wp_postmeta.
 */
class Clicks
{
    /**
     * Sortable columns allow list. Maps the public sort name (used in the
     * REST `sort` arg) to the qualified SQL column. Unknown values fall
     * back to `cl.created_at` so user input can never become a column
     * name in the assembled query. ORDER BY cannot use wpdb::prepare()
     * placeholders for column names, so this allowlist is the injection
     * guard — do not interpolate $sortCol without going through this map.
     *
     * @var array<string, string>
     */
    private const SORT_MAP = [
        'ip'         => 'cl.ip',
        'vuid'       => 'cl.vuid',
        'btype'      => 'cl.btype',
        'bversion'   => 'cl.bversion',
        'host'       => 'cl.host',
        'referer'    => 'cl.referer',
        'uri'        => 'cl.uri',
        'created_at' => 'cl.created_at',
        'country'    => 'cl.country',
        'link'       => 'li.name',
    ];

    /**
     * Searches click rows with filtering, sorting, and pagination.
     *
     * @param array<string, mixed> $args Query arguments (filters, sort, paging).
     *
     * @return array{items: array<int, array<string, mixed>>, total: int, pages: int}
     */
    public function search(array $args): array
    {
        global $wpdb;
        $clicks = $wpdb->prefix . 'prli_clicks';
        $links  = $wpdb->prefix . 'prli_links';

        $where  = [];
        $params = [];

        if (!empty($args['link_ids']) && is_array($args['link_ids'])) {
            $ids = array_values(array_filter(array_map('intval', $args['link_ids']), static fn ($n) => $n > 0));
            if ($ids !== []) {
                $placeholders = implode(',', array_fill(0, count($ids), '%d'));
                $where[]      = 'cl.link_id IN (' . $placeholders . ')';
                foreach ($ids as $id) {
                    $params[] = $id;
                }
            }
        } elseif (!empty($args['link_id'])) {
            $where[]  = 'cl.link_id = %d';
            $params[] = (int) $args['link_id'];
        }
        if (!empty($args['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $args['from'])) {
            $where[]  = 'cl.created_at >= %s';
            $params[] = $args['from'] . ' 00:00:00';
        }
        if (!empty($args['to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $args['to'])) {
            $where[]  = 'cl.created_at <= %s';
            $params[] = $args['to'] . ' 23:59:59';
        }
        if (!empty($args['ip'])) {
            $where[]  = 'cl.ip = %s';
            $params[] = (string) $args['ip'];
        }
        if (!empty($args['vuid'])) {
            $where[]  = 'cl.vuid = %s';
            $params[] = (string) $args['vuid'];
        }
        if (!empty($args['unique'])) {
            $where[] = 'cl.first_click = 1';
        }
        if (isset($args['search']) && $args['search'] !== '') {
            // V3 parity: search OR's across 9 fields. Each space-separated
            // term ANDs with the previous one (each term must match at
            // least one field).
            $terms = preg_split('/\s+/', (string) $args['search'], -1, PREG_SPLIT_NO_EMPTY) ?: [];
            foreach ($terms as $term) {
                $like     = '%' . $wpdb->esc_like($term) . '%';
                $where[]  = '(cl.ip LIKE %s OR cl.vuid LIKE %s OR cl.btype LIKE %s OR cl.bversion LIKE %s OR cl.host LIKE %s OR cl.referer LIKE %s OR cl.uri LIKE %s OR cl.created_at LIKE %s OR li.name LIKE %s)';
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

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $perPage  = (int) ($args['per_page'] ?? 30);
        $page     = (int) ($args['page'] ?? 1);
        $offset   = ($page - 1) * $perPage;

        $sortKey = isset($args['sort']) && is_string($args['sort']) && isset(self::SORT_MAP[$args['sort']])
            ? $args['sort']
            : 'created_at';
        $sortCol = self::SORT_MAP[$sortKey];
        $dir     = isset($args['direction']) && strtolower((string) $args['direction']) === 'asc' ? 'ASC' : 'DESC';

        $totalSql = "SELECT COUNT(*) FROM {$clicks} cl LEFT JOIN {$links} li ON cl.link_id = li.id {$whereSql}";
        $total    = (int) $wpdb->get_var($params ? $wpdb->prepare($totalSql, ...$params) : $totalSql);

        // V3 parity: attach per-row correlated counts so the click list can
        // display "IP (n)" and "vuid (n)" badges indicating how much total
        // activity each identifier has across the whole click table. Cost is
        // two dependent subqueries per row — acceptable at the default
        // per_page = 30.
        $listSql = "SELECT cl.*, li.name AS link_name, li.slug AS link_slug,
                           (SELECT COUNT(*) FROM {$clicks} cl2 WHERE cl2.ip = cl.ip) AS ip_count,
                           (SELECT COUNT(*) FROM {$clicks} cl3 WHERE cl3.vuid = cl.vuid) AS vuid_count
                    FROM {$clicks} cl
                    LEFT JOIN {$links} li ON cl.link_id = li.id
                    {$whereSql}
                    ORDER BY {$sortCol} {$dir}
                    LIMIT %d OFFSET %d";
        $rows    = $wpdb->get_results(
            $wpdb->prepare($listSql, ...array_merge($params, [$perPage, $offset])),
            ARRAY_A
        ) ?: [];

        return [
            'items' => array_map(static function (array $r): array {
                return [
                    'id'          => (int) $r['id'],
                    'link_id'     => (int) $r['link_id'],
                    'link_name'   => (string) ($r['link_name'] ?? ''),
                    'link_slug'   => (string) ($r['link_slug'] ?? ''),
                    'created_at'  => (string) $r['created_at'],
                    'ip'          => (string) $r['ip'],
                    'vuid'        => (string) ($r['vuid'] ?? ''),
                    'host'        => (string) $r['host'],
                    'uri'         => (string) ($r['uri'] ?? ''),
                    'referer'     => (string) ($r['referer'] ?? ''),
                    'country'     => (string) $r['country'],
                    'browser'     => (string) $r['browser'],
                    'btype'       => (string) ($r['btype'] ?? ''),
                    'bversion'    => (string) ($r['bversion'] ?? ''),
                    'os'          => (string) $r['os'],
                    'device_type' => (string) $r['device_type'],
                    'robot'       => (int) ($r['robot'] ?? 0),
                    'first_click' => (int) ($r['first_click'] ?? 0),
                    'ip_count'    => (int) ($r['ip_count'] ?? 0),
                    'vuid_count'  => (int) ($r['vuid_count'] ?? 0),
                ];
            }, $rows),
            'total' => $total,
            'pages' => (int) ceil($total / max(1, $perPage)),
        ];
    }
}
