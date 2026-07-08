<?php

declare(strict_types=1);

namespace PrettyLinks\Rest\Controllers;

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
use PrettyLinks\Tools\ClicksCsvExporter;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

class ReportsController extends BaseController
{
    /**
     * Registers the reports REST routes.
     *
     * @return void
     */
    public function register(): void
    {
        $common = [
            'from' => ['type' => 'string'],
            'to'   => ['type' => 'string'],
        ];

        register_rest_route($this->namespace(), '/reports/counters', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'counters'],
                'permission_callback' => $this->permission(),
                'args'                => $common,
            ],
        ]);

        // Top N links by click activity in the given range. Powers the Lite
        // Dashboard "Top performing" card and (via the same contract) Pro's
        // Click History extras — both go through this one endpoint so they
        // can never disagree about the ranking.
        register_rest_route($this->namespace(), '/reports/top-links', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'topLinks'],
                'permission_callback' => $this->permission(),
                'args'                => array_merge($common, [
                    'limit' => [
                        'type'    => 'integer',
                        'default' => 10,
                    ],
                    'sort'  => [
                        'type'    => 'string',
                        'enum'    => ['top', 'bottom'],
                        'default' => 'top',
                    ],
                ]),
            ],
        ]);

        register_rest_route($this->namespace(), '/reports/clicks/export', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'exportClicks'],
                'permission_callback' => $this->permission(),
                'args'                => [
                    'from'     => ['type' => 'string'],
                    'to'       => ['type' => 'string'],
                    // Comma-separated list of link ids (supports one or many
                    // — the Click History toolbar sends this uniformly).
                    'link_ids' => ['type' => 'string'],
                    'ip'       => ['type' => 'string'],
                    'vuid'     => ['type' => 'string'],
                    'search'   => ['type' => 'string'],
                    'unique'   => ['type' => 'boolean'],
                ],
            ],
        ]);
    }

    /**
     * Range-scoped click summary. Dashboard KPIs and Pro's Click History
     * KPIs both read this endpoint so they stay in lock-step.
     *
     * `clicks` and `uniques` come from prli_clicks within the range, NOT
     * from the fast-read counters on prli_links — the counters accumulate
     * forever and have no notion of "last 30 days", so they can't answer
     * range queries. `total_links` is the current live-link count, which
     * is range-independent.
     *
     * Simple (count-only) tracking mode never inserts prli_clicks rows, so
     * `clicks` and `uniques` come from the static-clicks / static-uniques
     * meta counters instead.
     *
     * @param WP_REST_Request $request The incoming REST request.
     *
     * @return WP_REST_Response
     */
    public function counters(WP_REST_Request $request): WP_REST_Response
    {
        global $wpdb;
        $links  = $wpdb->prefix . 'prli_links';
        $metas  = $wpdb->prefix . 'prli_link_metas';
        $clicks = $wpdb->prefix . 'prli_clicks';

        list($fromDt, $toDt) = $this->dateBounds($request);
        $days                = $this->dayCount($fromDt, $toDt);

        $isCount = $this->isCountMode();

        // Count (Simple) mode: no per-visit rows exist, so clicks and uniques
        // come from the static-clicks / static-uniques meta counters. Skip
        // the prli_clicks query entirely.
        if ($isCount) {
            $row         = $wpdb->get_results(
                "SELECT meta_key, COALESCE(SUM(CAST(meta_value AS UNSIGNED)),0) AS total
                   FROM {$metas}
                  WHERE meta_key IN ('static-clicks','static-uniques')
                  GROUP BY meta_key",
                ARRAY_A
            ) ?: [];
            $meta        = array_column($row, 'total', 'meta_key');
            $clickCount  = (int) ($meta['static-clicks'] ?? 0);
            $uniqueCount = (int) ($meta['static-uniques'] ?? 0);
            return new WP_REST_Response([
                'clicks'      => $clickCount,
                'uniques'     => $uniqueCount,
                'avg_per_day' => $days > 0 ? $clickCount / $days : 0,
                'total_links' => (int) $wpdb->get_var(
                    "SELECT COUNT(*) FROM {$links} WHERE deleted_at IS NULL"
                ),
            ]);
        }

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT COUNT(*) AS clicks,
                        COUNT(DISTINCT vuid) AS uniques
                   FROM {$clicks}
                  WHERE created_at BETWEEN %s AND %s",
                $fromDt,
                $toDt
            ),
            ARRAY_A
        ) ?: [
            'clicks'  => 0,
            'uniques' => 0,
        ];

        return new WP_REST_Response([
            'clicks'      => (int) ($row['clicks'] ?? 0),
            'uniques'     => (int) ($row['uniques'] ?? 0),
            'avg_per_day' => $days > 0 ? ((int) ($row['clicks'] ?? 0)) / $days : 0,
            'total_links' => (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$links} WHERE deleted_at IS NULL"
            ),
        ]);
    }

    /**
     * Returns the top links by clicks within the range.
     *
     * @param WP_REST_Request $request The incoming REST request.
     *
     * @return WP_REST_Response
     */
    public function topLinks(WP_REST_Request $request): WP_REST_Response
    {
        global $wpdb;
        $clicks = $wpdb->prefix . 'prli_clicks';
        $links  = $wpdb->prefix . 'prli_links';

        list($fromDt, $toDt) = $this->dateBounds($request);
        $limit               = max(1, min(100, (int) $request->get_param('limit')));
        // ORDER BY direction is an enum, not a placeholder — wpdb::prepare()
        // cannot parameterize column names or direction keywords.
        $dir = $request->get_param('sort') === 'bottom' ? 'ASC' : 'DESC';

        // Count (Simple) mode: no per-visit rows exist, so the prli_clicks
        // INNER JOIN would return empty. Read clicks and uniques from the
        // static-clicks / static-uniques meta instead.
        if ($this->isCountMode()) {
            $metas = $wpdb->prefix . 'prli_link_metas';
            $rows  = (array) $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT li.id, li.name, li.slug, li.url,
                            COALESCE(CAST(lmc.meta_value AS UNSIGNED), 0) AS clicks,
                            COALESCE(CAST(lmu.meta_value AS UNSIGNED), 0) AS uniques
                       FROM {$links} li
                       LEFT JOIN {$metas} lmc ON lmc.link_id = li.id AND lmc.meta_key = 'static-clicks'
                       LEFT JOIN {$metas} lmu ON lmu.link_id = li.id AND lmu.meta_key = 'static-uniques'
                      WHERE li.deleted_at IS NULL
                        AND COALESCE(CAST(lmc.meta_value AS UNSIGNED), 0) > 0
                      ORDER BY clicks {$dir}, li.id ASC
                      LIMIT %d",
                    $limit
                ),
                ARRAY_A
            );
            $items = array_map(static function (array $r): array {
                return [
                    'id'      => (int) ($r['id'] ?? 0),
                    'name'    => (string) ($r['name'] ?? ''),
                    'slug'    => (string) ($r['slug'] ?? ''),
                    'url'     => (string) ($r['url'] ?? ''),
                    'clicks'  => (int) ($r['clicks'] ?? 0),
                    'uniques' => (int) ($r['uniques'] ?? 0),
                ];
            }, $rows);
            return new WP_REST_Response($items);
        }

        $rows = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT li.id, li.name, li.slug, li.url,
                        COUNT(cl.id) AS clicks,
                        COUNT(DISTINCT cl.vuid) AS uniques
                   FROM {$links} li
                   INNER JOIN {$clicks} cl ON cl.link_id = li.id
                  WHERE li.deleted_at IS NULL
                    AND cl.created_at BETWEEN %s AND %s
                  GROUP BY li.id
                  ORDER BY clicks {$dir}, li.id ASC
                  LIMIT %d",
                $fromDt,
                $toDt,
                $limit
            ),
            ARRAY_A
        );

        $items = array_map(static function (array $r): array {
            return [
                'id'      => (int) ($r['id'] ?? 0),
                'name'    => (string) ($r['name'] ?? ''),
                'slug'    => (string) ($r['slug'] ?? ''),
                'url'     => (string) ($r['url'] ?? ''),
                'clicks'  => (int) ($r['clicks'] ?? 0),
                'uniques' => (int) ($r['uniques'] ?? 0),
            ];
        }, $rows);

        return new WP_REST_Response($items);
    }

    /**
     * Resolve YYYY-MM-DD from/to into inclusive UTC MySQL datetimes. Missing
     * or malformed values fall back to last-30-days so every caller has a
     * safe default. ClickWriter stamps rows with `current_time('mysql', true)`
     * which is UTC, so the whole analytics layer stays UTC end-to-end.
     *
     * @param WP_REST_Request $request The incoming REST request.
     *
     * @return array{0:string,1:string}
     */
    private function dateBounds(WP_REST_Request $request): array
    {
        $from = $this->normalizeDate((string) $request->get_param('from'));
        $to   = $this->normalizeDate((string) $request->get_param('to'));
        if ($from === '') {
            $from = gmdate('Y-m-d', strtotime('-30 days'));
        }
        if ($to === '') {
            $to = gmdate('Y-m-d');
        }
        return [$from . ' 00:00:00', $to . ' 23:59:59'];
    }

    /**
     * Validates a YYYY-MM-DD date string, returning '' when malformed.
     *
     * @param string $raw The raw date string.
     *
     * @return string
     */
    private function normalizeDate(string $raw): string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) === 1 ? $raw : '';
    }

    /**
     * Returns the inclusive number of days between two datetimes.
     *
     * @param string $fromDt The range start datetime.
     * @param string $toDt   The range end datetime.
     *
     * @return integer
     */
    private function dayCount(string $fromDt, string $toDt): int
    {
        $fromTs = strtotime($fromDt);
        $toTs   = strtotime($toDt);
        if ($fromTs === false || $toTs === false || $toTs < $fromTs) {
            return 1;
        }
        return max(1, (int) floor(($toTs - $fromTs) / 86400) + 1);
    }

    /**
     * Exports clicks for the given links as CSV.
     *
     * @param WP_REST_Request $request The incoming REST request.
     *
     * @return WP_REST_Response
     */
    public function exportClicks(WP_REST_Request $request): WP_REST_Response
    {
        $rawIds  = (string) $request->get_param('link_ids');
        $linkIds = [];
        if ($rawIds !== '') {
            foreach (explode(',', $rawIds) as $id) {
                $int = (int) trim($id);
                if ($int > 0) {
                    $linkIds[] = $int;
                }
            }
        }
        $args         = [
            'from'     => (string) $request->get_param('from'),
            'to'       => (string) $request->get_param('to'),
            'link_ids' => $linkIds,
            'ip'       => (string) $request->get_param('ip'),
            'vuid'     => (string) $request->get_param('vuid'),
            'search'   => (string) $request->get_param('search'),
            'unique'   => (bool) $request->get_param('unique'),
        ];
        $args['mode'] = $this->trackingMode();

        $exporter = new ClicksCsvExporter();

        // Preflight: cheap row-count honoring the same filters, used by
        // the JS layer to surface a "large export" warning before kicking
        // off the chunked download.
        if ($request->get_param('count_only')) {
            return new WP_REST_Response(['total' => $exporter->total($args)]);
        }

        $offsetParam = $request->get_param('offset');
        if ($offsetParam !== null && $offsetParam !== '') {
            $offset = (int) $offsetParam;
            $limit  = (int) ($request->get_param('limit') ?: ClicksCsvExporter::DEFAULT_CHUNK_SIZE);
            return new WP_REST_Response($exporter->chunk($args, $offset, $limit));
        }

        return new WP_REST_Response(['csv' => $exporter->export($args, $this->trackingMode())]);
    }
}
