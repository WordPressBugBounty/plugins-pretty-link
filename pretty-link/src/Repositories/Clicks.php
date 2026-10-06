<?php

declare(strict_types=1);

namespace PrettyLinks\Repositories;

use PrettyLinks\Support\SiteDate;

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
 *
 * @api
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
     * Public so the REST route builds its `sort` enum from these keys and
     * the two lists can't drift apart.
     *
     * @var array<string, string>
     */
    public const SORT_MAP = [
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
        // Site-local calendar days in, inclusive UTC bounds out. An absent or
        // unusable bound is omitted rather than defaulted.
        list($fromUtc, $toUtc) = SiteDate::optionalBoundsUtc(
            (string) ($args['from'] ?? ''),
            (string) ($args['to'] ?? '')
        );
        if ($fromUtc !== '') {
            $where[]  = 'cl.created_at >= %s';
            $params[] = $fromUtc;
        }
        if ($toUtc !== '') {
            $where[]  = 'cl.created_at <= %s';
            $params[] = $toUtc;
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
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- $totalSql is assembled above from $wpdb->prefix table names plus a WHERE clause of literal %d/%s placeholders; every user value travels in $params and binds through prepare(). Plugin-owned prli_clicks/prli_links, so no WP API applies, and the count must read through for an admin list view.
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
        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- $listSql interpolates only $wpdb->prefix table names and $sortCol/$dir; $sortCol comes exclusively from the SORT_MAP allowlist above (that map is the injection guard, since ORDER BY cannot take a prepare() placeholder) and $dir is the literal 'ASC'/'DESC' from a ternary. Every user value travels in $params and binds through prepare(). Plugin-owned prli_clicks/prli_links, so no WP API applies, and the list must read through.
        $rows    = $wpdb->get_results(
            $wpdb->prepare($listSql, ...array_merge($params, [$perPage, $offset])),
            ARRAY_A
        ) ?: [];
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

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

    /**
     * The id of a visitor's newest click on a link at or after a UTC time,
     * or 0 when there is none (an empty vuid never matches). "Newest" is the
     * highest row id, i.e. the last click written.
     *
     * @param integer $linkId   Link identifier.
     * @param string  $vuid     Visitor id (the `prli_visitor` cookie).
     * @param string  $sinceUtc UTC MySQL datetime lower bound, inclusive.
     *
     * @return integer
     */
    public function latestIdForVisitor(int $linkId, string $vuid, string $sinceUtc): int
    {
        if ($vuid === '') {
            return 0;
        }
        global $wpdb;
        $clicks = $wpdb->prefix . 'prli_clicks';
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin-owned table name from $wpdb->prefix; values bind through prepare(); must read through.
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$clicks}
              WHERE link_id = %d AND vuid = %s AND created_at >= %s
           ORDER BY id DESC LIMIT 1",
            $linkId,
            $vuid,
            $sinceUtc
        ));
    }

    /**
     * Distinct non-bot visitors (by vuid) who clicked a link, optionally
     * bounded by inclusive UTC datetimes. Clicks with no vuid don't count.
     *
     * Pass full `Y-m-d H:i:s` UTC datetimes: the bounds compare as strings,
     * so a date-only upper bound stops at midnight. For site-local calendar
     * days, convert with `SiteDate::dayStartUtc()` / `SiteDate::dayEndUtc()`.
     *
     * @param integer $linkId  Link identifier.
     * @param string  $fromUtc UTC MySQL datetime lower bound, or '' for none.
     * @param string  $toUtc   UTC MySQL datetime upper bound, or '' for none.
     *
     * @return integer
     */
    public function countUniqueVisitors(int $linkId, string $fromUtc = '', string $toUtc = ''): int
    {
        global $wpdb;
        $clicks = $wpdb->prefix . 'prli_clicks';
        $where  = ['link_id = %d', "vuid <> ''", 'robot = 0'];
        $params = [$linkId];
        if ($fromUtc !== '') {
            $where[]  = 'created_at >= %s';
            $params[] = $fromUtc;
        }
        if ($toUtc !== '') {
            $where[]  = 'created_at <= %s';
            $params[] = $toUtc;
        }
        $sql = "SELECT COUNT(DISTINCT vuid) FROM {$clicks} WHERE " . implode(' AND ', $where);
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- $sql holds only the $wpdb->prefix table name and literal placeholder clauses; every value binds through prepare(); must read through.
        return (int) $wpdb->get_var($wpdb->prepare($sql, ...$params));
    }
}
