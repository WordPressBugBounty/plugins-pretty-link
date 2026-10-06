<?php

declare(strict_types=1);

namespace PrettyLinks\Repositories;

use PrettyLinks\GroundLevel\Events\Models\Event;
use PrettyLinks\Options\Store as OptionsStore;
use PrettyLinks\Redirect\Engine;
use PrettyLinks\Redirect\ReservedSlugs;
use PrettyLinks\Slug\Generator as SlugGenerator;
use PrettyLinks\Stripe\LinkMeta as StripeLinkMeta;

/**
 * Repository for prli_links. Handles CRUD, soft-delete, bulk operations,
 * and the search/filter API that powers the admin list-table.
 *
 * phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
 * phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
 * phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
 * phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter
 *
 * Every query in this class targets custom plugin tables (prli_links,
 * prli_link_metas, prli_clicks, prli_link_terms). Table names are always
 * interpolated from $wpdb->prefix (trusted) and user values bind through
 * $wpdb->prepare(). No caching layer: these tables are the source of truth
 * for click/redirect data that must read-through.
 *
 * @api
 */
class Links
{
    /**
     * Transient caching whether any stored slug contains a literal space.
     */
    private const SPACE_SLUG_TRANSIENT = 'prli_has_space_slug';

    /**
     * Columns a caller may ask for through `search()`'s `fields` arg.
     *
     * @var string[]
     */
    private const PROJECTABLE_FIELDS = [
        'id',
        'name',
        'description',
        'url',
        'slug',
        'nofollow',
        'sponsored',
        'track_me',
        'param_forwarding',
        'redirect_type',
        'created_at',
        'updated_at',
        'group_id',
        'prettypay_link',
        'new_window',
        'clicks',
        'uniques',
        'deleted_at',
        'source',
    ];

    /**
     * The payload keys the column write (`create()` / `update()`) reads.
     * Every other key in a save() payload belongs to an extension.
     *
     * @var string[]
     */
    public const COLUMN_FIELDS = [
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
        'prettypay_link',
        'source',
    ];

    /**
     * Reason the most recent soft/hard delete was blocked by the
     * `prli_pre_link_trash` filter, or '' if nothing was blocked.
     * Static so REST controllers can read it after a failed delete
     * without needing repo singletons.
     *
     * @var string
     */
    private static string $lastTrashBlockReason = '';

    /**
     * Returns the reason the most recent delete was blocked, or '' if none.
     *
     * @return string
     */
    public static function lastTrashBlockReason(): string
    {
        return self::$lastTrashBlockReason;
    }

    /**
     * Whether any stored link slug contains a literal space — the signal that a
     * site relied on v3's space-in-slug behaviour (spaces can only enter the
     * table via a v3 upgrade or an import; v4 saves normalise them unless the
     * compat toggle is on). Drives the Options UI visibility of the
     * `allow_slug_spaces` toggle and the migrator's one-time default. Cached
     * for an hour — an anchored `LIKE '% %'` can't use the slug index, so it's
     * a scan; staleness only delays the toggle appearing and never affects
     * slug resolution or the stored setting.
     *
     * @return boolean
     */
    public static function hasSpaceSlug(): bool
    {
        $cached = get_transient(self::SPACE_SLUG_TRANSIENT);
        if ($cached === '1' || $cached === '0') {
            return $cached === '1';
        }
        global $wpdb;
        $table = $wpdb->prefix . 'prli_links';
        // Active links only — a site whose space-slug links are all trashed is
        // not "still using" spaces, so it should stay on the default (toggle
        // hidden/off). Resolution is unconditional regardless.
        $found = (bool) $wpdb->get_var("SELECT 1 FROM {$table} WHERE slug LIKE '% %' AND deleted_at IS NULL LIMIT 1");
        set_transient(self::SPACE_SLUG_TRANSIENT, $found ? '1' : '0', HOUR_IN_SECONDS);
        return $found;
    }

    /**
     * Build WHERE clauses + bound params for a links list/export query.
     *
     * Shared by `search()` and `CsvExporter` so filtered CSV export matches
     * the links list (including Pro `prli_links_query_clauses` filters such
     * as Broken / Expired / Split).
     *
     * Recognised args include `include` / `exclude` (id allow/deny lists),
     * `status` ('any', 'trashed', or 'active' — 'active' also drops trashed
     * rows from an `include` list, which otherwise returns them; 'trashed' is
     * not applied alongside `include`), `prettypay`, `redirect_type` (string or array => IN),
     * `redirect_type__not_in` (string or array => NOT IN), `source`,
     * `search`, `category` and `tag`.
     *
     * @param  array<string, mixed> $args Search / export args.
     * @return array{0: string[], 1: mixed[]} [$where, $params]
     */
    public function buildSearchClauses(array $args): array
    {
        global $wpdb;
        $where  = [];
        $params = [];

        // `include` restricts the result to specific link ids. When
        // present we skip the deleted_at filter so hydration of saved
        // chip selections still resolves names for trashed links —
        // unless the caller passes `status => 'active'`, which keeps
        // trashed rows out (batched "resolve these live links" lookups).
        $include = isset($args['include']) && is_array($args['include'])
            ? array_values(array_filter(array_map('intval', $args['include'])))
            : [];

        $status = (string) ($args['status'] ?? 'any');
        if ($include) {
            $placeholders = implode(',', array_fill(0, count($include), '%d'));
            $where[]      = "id IN ({$placeholders})";
            foreach ($include as $id) {
                $params[] = $id;
            }
            if ($status === 'active') {
                $where[] = 'deleted_at IS NULL';
            }
        } elseif ($status === 'trashed') {
            $where[] = 'deleted_at IS NOT NULL';
        } else {
            $where[] = 'deleted_at IS NULL';
        }

        // `exclude` drops specific ids from the result — link pickers use it
        // to hide the row being edited (or ids already chosen) so a link
        // can't be pointed at itself. Applies on top of `include`.
        $exclude = isset($args['exclude']) && is_array($args['exclude'])
            ? array_values(array_filter(array_map('intval', $args['exclude'])))
            : [];
        if ($exclude) {
            $placeholders = implode(',', array_fill(0, count($exclude), '%d'));
            $where[]      = "id NOT IN ({$placeholders})";
            foreach ($exclude as $id) {
                $params[] = $id;
            }
        }

        if (array_key_exists('prettypay', $args) && $args['prettypay'] !== null && $args['prettypay'] !== '') {
            $where[] = ((int) $args['prettypay']) === 1
                ? 'prettypay_link = 1'
                : 'prettypay_link <> 1';
        }

        // Redirect-type filter. Values are raw redirect_type column values
        // (e.g. '302', '301', 'cloak', 'pixel', 'prettybar').
        //
        // `redirect_type` is either a string (the links-list dropdown, where
        // '' and 'all' mean no filter) or an array, which becomes IN (...).
        // `redirect_type__not_in` takes the same string-or-array shape and
        // becomes NOT IN (...), for pickers that must exclude types — e.g.
        // Splash Pages refusing to target another splash link.
        $redirectTypes = self::normalizeRedirectTypes($args['redirect_type'] ?? null);
        if ($redirectTypes) {
            if (count($redirectTypes) === 1) {
                $where[] = 'redirect_type = %s';
            } else {
                $placeholders = implode(',', array_fill(0, count($redirectTypes), '%s'));
                $where[]      = "redirect_type IN ({$placeholders})";
            }
            foreach ($redirectTypes as $type) {
                $params[] = $type;
            }
        }

        $notRedirectTypes = self::normalizeRedirectTypes($args['redirect_type__not_in'] ?? null);
        if ($notRedirectTypes) {
            $placeholders = implode(',', array_fill(0, count($notRedirectTypes), '%s'));
            $where[]      = "redirect_type NOT IN ({$placeholders})";
            foreach ($notRedirectTypes as $type) {
                $params[] = $type;
            }
        }

        if (!empty($args['source'])) {
            $sources = is_array($args['source'])
                ? $args['source']
                : explode(',', (string) $args['source']);
            $valid   = array_values(array_filter(array_map(
                static function ($s): string {
                    return trim((string) $s);
                },
                $sources
            ), static function ($s): bool {
                return in_array($s, ['admin', 'user', 'public'], true);
            }));
            if ($valid) {
                $placeholders = implode(',', array_fill(0, count($valid), '%s'));
                $where[]      = "source IN ({$placeholders})";
                foreach ($valid as $v) {
                    $params[] = $v;
                }
            }
        }

        $search = trim((string) ($args['search'] ?? ''));
        if ($search !== '') {
            $like       = '%' . $wpdb->esc_like($search) . '%';
            $parts      = ['slug LIKE %s', 'name LIKE %s', 'url LIKE %s'];
            $partParams = [$like, $like, $like];
            /**
             * Filter: prli_links_search_clauses
             *
             * Extend the free-text search query with additional OR'd
             * fragments. Each fragment uses %s placeholders; the matching
             * values go in the second element. Pro uses this to match
             * category and tag names since those tables live in Pro.
             *
             * @param array{0: string[], 1: mixed[]} $clauses [$parts, $params]
             * @param string                         $search  Raw search term.
             * @param string                         $like    Pre-escaped LIKE pattern.
             */
            [$parts, $partParams] = (array) apply_filters(
                'prli_links_search_clauses',
                [$parts, $partParams],
                $search,
                $like
            );
            $where[]              = '(' . implode(' OR ', $parts) . ')';
            foreach ($partParams as $p) {
                $params[] = $p;
            }
        }

        $category = isset($args['category']) ? (int) $args['category'] : 0;
        if ($category > 0) {
            $where[]  = "id IN (
                SELECT link_id FROM {$wpdb->prefix}prli_link_terms
                WHERE taxonomy = 'category' AND term_id = %d
            )";
            $params[] = $category;
        }

        $tag = isset($args['tag']) ? (int) $args['tag'] : 0;
        if ($tag > 0) {
            $where[]  = "id IN (
                SELECT link_id FROM {$wpdb->prefix}prli_link_terms
                WHERE taxonomy = 'tag' AND term_id = %d
            )";
            $params[] = $tag;
        }

        /**
         * Filter: prli_links_query_clauses
         *
         * Allows extensions (e.g. Pro) to append extra WHERE conditions and
         * bound params to the links search query without modifying this repo.
         *
         * @param array{0: string[], 1: mixed[]} $clauses  [$where, $params]
         * @param array<string, mixed>           $args     Full search args.
         */
        return (array) apply_filters('prli_links_query_clauses', [$where, $params], $args);
    }

    /**
     * Search, filter, sort, and paginate links for the admin list-table.
     *
     * The ORDER BY always ends with an `id` tiebreak in the requested
     * direction (unless the caller already sorts by `id`, which is unique on
     * its own). Without it, rows sharing a sort value — very
     * common under the default `created_at DESC` after a bulk/CSV import that
     * lands many links inside the same second — have an unspecified relative
     * order, and MySQL is free to return them differently for each LIMIT /
     * OFFSET page. That makes a row show up twice while another is skipped
     * entirely. `id` is the primary key, so it's unique, indexed and stable.
     *
     * ## Projection mode
     *
     * By default every row comes back fully hydrated, and in normal /
     * extended tracking each one also carries a correlated click-count
     * subquery. That's right for the links list, which shows click counts,
     * but wasteful for a link picker that only needs a few columns on every
     * debounced keystroke. Two args opt out, while still running
     * `buildSearchClauses()` so the `prli_links_search_clauses` /
     * `prli_links_query_clauses` filters (and therefore Pro's category/tag
     * search) keep working:
     *
     *   'fields'      => ['id', 'name', 'slug', 'url']  Restrict the SELECT to
     *                    these columns. Rows come back with exactly these
     *                    keys, cast the same way `hydrate()` casts them, and
     *                    skip hydration's derived fields (`pretty_url`, the
     *                    live click count). Unknown names are dropped; an
     *                    empty result falls back to the full row.
     *   'with_clicks' => false  Drop the click-count subquery (and, in count
     *                    mode, the two `prli_link_metas` JOINs). Implied by
     *                    `fields` unless it asks for `clicks` / `uniques`.
     *                    Rows are still hydrated, but `clicks` / `uniques`
     *                    fall back to the denormalized columns.
     *
     * Asking for `clicks` (or, in count mode, `uniques`) in `fields` keeps the
     * counter query, since those values come from a subquery / JOIN rather
     * than a column — only the PHP-side hydration is skipped in that case.
     *
     * `fields` names real `prli_links` columns only. Hydration's derived
     * `pretty_url` can't be requested, so a projected caller that needs it
     * builds it from `slug` (see {@see \PrettyLinks\Helpers\LinkUrl::build()}).
     *
     * Hydrated rows are a published contract (see hydrate()).
     *
     * @param  array<string, mixed> $args Search/filter/sort/pagination args.
     * @return array{items: array<int, array<string, mixed>>, total: int, pages: int}
     */
    public function search(array $args): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'prli_links';

        $countMode = self::isCountMode();
        $fields    = self::normalizeFields($args['fields'] ?? null);
        // `fields` implies "no click subquery" unless it actually asks for a
        // counter; an explicit with_clicks always wins. `uniques` only needs
        // the counter query in count mode, where it comes from the metas
        // JOIN — in normal/extended it's a plain prli_links column.
        $wantsCounters = in_array('clicks', $fields, true)
            || ($countMode && in_array('uniques', $fields, true));
        $withClicks    = array_key_exists('with_clicks', $args)
            ? (bool) $args['with_clicks']
            : ($fields === [] || $wantsCounters);

        $orderby = self::safeOrderBy((string) ($args['orderby'] ?? 'created_at'));
        $order   = strtolower((string) ($args['order'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
        $perPage = (int) ($args['per_page'] ?? 20);
        $page    = (int) ($args['page'] ?? 1);
        $offset  = ($page - 1) * $perPage;

        [$where, $params] = $this->buildSearchClauses($args);

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $totalSql = "SELECT COUNT(*) FROM {$table} {$whereSql}";
        $total    = (int) $wpdb->get_var($params ? $wpdb->prepare($totalSql, ...$params) : $totalSql);

        if (!$withClicks) {
            // Projection / no-counter mode: one plain table read, no
            // correlated subquery and no meta JOINs. `orderby=clicks` and
            // `orderby=uniques` fall back to the denormalized columns on
            // prli_links, which is what a picker wants anyway.
            $select   = $fields === []
                ? "{$table}.*"
                : implode(', ', array_map(
                    static function (string $field) use ($table): string {
                        return "{$table}.{$field}";
                    },
                    $fields
                ));
            $tiebreak = $orderby === 'id' ? '' : ", {$table}.id {$order}";
            $listSql  = "SELECT {$select}
                             FROM {$table}
                            {$whereSql}
                            ORDER BY {$orderby} {$order}{$tiebreak} LIMIT %d OFFSET %d";
        } elseif ($countMode) {
            // In count mode clicks and uniques live in prli_link_metas. Two
            // LEFT JOINs (lmc = clicks, lmu = uniques) pull them in so the
            // list table shows correct values and ORDER BY clicks works in SQL.
            // Category/tag WHERE conditions use bare `id`; qualify them to
            // avoid ambiguity with lmc.id / lmu.id in the JOINs.
            $metas       = $wpdb->prefix . 'prli_link_metas';
            $clicksExpr  = 'COALESCE(CAST(lmc.meta_value AS UNSIGNED), 0)';
            $uniquesExpr = 'COALESCE(CAST(lmu.meta_value AS UNSIGNED), 0)';
            // Route orderby=clicks/uniques through the JOIN aliases so the
            // sort uses the meta-derived counters; everything else qualifies
            // to li.<column> (the prli_links column).
            if ($orderby === 'clicks') {
                $orderExpr = $clicksExpr;
            } elseif ($orderby === 'uniques') {
                $orderExpr = $uniquesExpr;
            } else {
                $orderExpr = "li.{$orderby}";
            }
            // Qualify bare `id IN (` / `id NOT IN (` conditions — ours and any
            // an extension appended through `prli_links_query_clauses` — as
            // `li.id`, or they'd be ambiguous against lmc.id / lmu.id. The
            // lookbehind keeps the rewrite off identifiers that merely end in
            // "id" (`link_id IN (…)`) and off ones already qualified
            // (`li.id IN (…)`). Search terms are never at risk here: they bind
            // as %s params and never reach this string.
            $safeWhere = $whereSql !== ''
                ? (string) preg_replace(
                    '/(?<![A-Za-z0-9_.])id (NOT )?IN \(/',
                    'li.id $1IN (',
                    $whereSql
                )
                : '';
            $tiebreak  = $orderby === 'id' ? '' : ", li.id {$order}";
            // Counters always come from the JOIN aliases here, so project the
            // plain columns and let clicks/uniques be added as expressions.
            $plain     = $fields === []
                ? ['li.*']
                : array_map(
                    static function (string $field): string {
                        return "li.{$field}";
                    },
                    array_values(array_diff($fields, ['clicks', 'uniques']))
                );
            $select    = implode(', ', array_merge(
                $plain,
                ["{$clicksExpr} AS clicks", "{$uniquesExpr} AS uniques"]
            ));
            $listSql   = "SELECT {$select}
                             FROM {$table} li
                             LEFT JOIN {$metas} lmc ON lmc.link_id = li.id AND lmc.meta_key = 'static-clicks'
                             LEFT JOIN {$metas} lmu ON lmu.link_id = li.id AND lmu.meta_key = 'static-uniques'
                            {$safeWhere}
                            ORDER BY {$orderExpr} {$order}{$tiebreak} LIMIT %d OFFSET %d";
        } else {
            // Normal / extended tracking: derive the clicks count from
            // `prli_clicks` instead of the denormalized `prli_links.clicks`
            // column so rankings aren't skewed by stale cached counters
            // (e.g. after a trim-clicks operation). `link_id` is indexed
            // so the correlated subquery stays cheap at page sizes (20).
            // The subquery is aliased as `actual_clicks`; `hydrate()`
            // prefers it over the cached `clicks` column when present.
            $clicks     = $wpdb->prefix . 'prli_clicks';
            $clicksExpr = "(SELECT COUNT(*) FROM {$clicks} cl WHERE cl.link_id = {$table}.id)";
            $orderExpr  = $orderby === 'clicks' ? $clicksExpr : $orderby;
            $tiebreak   = $orderby === 'id' ? '' : ", {$table}.id {$order}";
            // `clicks` is the one field the subquery supplies; everything
            // else (uniques included) is a real column and can be projected.
            $plain      = $fields === []
                ? ["{$table}.*"]
                : array_map(
                    static function (string $field) use ($table): string {
                        return "{$table}.{$field}";
                    },
                    array_values(array_diff($fields, ['clicks']))
                );
            $select     = implode(', ', array_merge($plain, ["{$clicksExpr} AS actual_clicks"]));
            $listSql    = "SELECT {$select}
                             FROM {$table}
                            {$whereSql}
                            ORDER BY {$orderExpr} {$order}{$tiebreak} LIMIT %d OFFSET %d";
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare($listSql, ...array_merge($params, [$perPage, $offset])),
            ARRAY_A
        ) ?: [];

        $items = $fields === []
            ? array_map([self::class, 'hydrate'], $rows)
            : array_map(
                static function (array $row) use ($fields): array {
                    return self::project($row, $fields);
                },
                $rows
            );

        return [
            'items' => $items,
            'total' => $total,
            'pages' => (int) ceil($total / max(1, $perPage)),
        ];
    }

    /**
     * Find a single link by its id.
     *
     * Returns the hydrated link shape, a published contract (see hydrate()).
     *
     * @param  integer $id Link id.
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'prli_links';
        $row   = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE id = %d LIMIT 1", $id),
            ARRAY_A
        );
        if (!is_array($row)) {
            return null;
        }
        self::applyMetaClicks($row);
        return self::hydrate($row);
    }

    /**
     * Find a single link by its slug.
     *
     * Returns the hydrated link shape, a published contract (see hydrate()).
     *
     * @param  string $slug Link slug.
     * @return array<string, mixed>|null
     */
    public function findBySlug(string $slug): ?array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'prli_links';
        $row   = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE slug = %s LIMIT 1", $slug),
            ARRAY_A
        );
        if (!is_array($row)) {
            return null;
        }
        self::applyMetaClicks($row);
        return self::hydrate($row);
    }

    /**
     * Find the oldest non-deleted link pointing at the given target URL.
     *
     * Returns the hydrated link shape, a published contract (see hydrate()).
     *
     * @param  string $url Target URL.
     * @return array<string, mixed>|null
     */
    public function findByUrl(string $url): ?array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'prli_links';
        $row   = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE url = %s AND deleted_at IS NULL ORDER BY id ASC LIMIT 1",
                $url
            ),
            ARRAY_A
        );
        if (!is_array($row)) {
            return null;
        }
        self::applyMetaClicks($row);
        return self::hydrate($row);
    }

    /**
     * Whether any link with the given source value exists. Includes
     * soft-deleted rows on purpose: callers use this to answer "has the
     * site ever used this source?", so a trashed public link still counts.
     *
     * @param string $source The link source value to check for (e.g. 'public').
     */
    public function existsBySource(string $source): bool
    {
        global $wpdb;
        $table  = $wpdb->prefix . 'prli_links';
        $exists = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT EXISTS (SELECT 1 FROM {$table} WHERE source = %s)", $source)
        );
        return $exists === 1;
    }

    /**
     * The link save seam. Every writer that saves a link from a payload
     * goes through here, so fields owned by extensions persist the same
     * way whatever surface the save came from.
     * `create()` and `update()` are only the `prli_links` column write
     * this wraps.
     *
     * Ordering contract. Each step runs at most once per call, in order:
     *
     *  1. `$original` is a copy of `$payload` as the writer passed it.
     *  2. The PrettyPay™ `stripe_*` keys are pulled off the working payload
     *     (`Stripe\LinkMeta::extract()`).
     *  3. Filter `prli_link_payload_pre_save` ($data, $context, $linkId):
     *     extensions remove the keys they own, or rewrite core ones (e.g.
     *     fold their own field into `url`). Nothing has been written yet and
     *     the save may still fail, so listeners must not persist anything here.
     *  4. Filter `prli_validate_link` ($errors, $data, $context, $linkId):
     *     a non-empty error list aborts the save. Nothing is written and
     *     `prli_link_after_save` does not fire.
     *  5. The column write: `create()` or `update()`, which fire
     *     `prli-create-link` / `prli_update_link` with the column-only row.
     *     A repo error (`url_invalid`, `slug_in_use`, …) or a missing link
     *     on update ends the save here, again without `prli_link_after_save`.
     *  6. The extracted PrettyPay™ meta is stored.
     *  7. Action `prli_link_after_save` ($linkId, $original, $context):
     *     extensions persist their fields, read from `$original`, the
     *     untouched payload. It fires once per successful save.
     *
     * `$context` is 'update' when `$id` is set, 'create' otherwise; `$linkId`
     * in steps 3–4 is 0 on create. A listener must not save the same link
     * through `save()` again from inside steps 3–7.
     *
     * Not routed here: `duplicate()` (it clones stored rows and metas, then
     * fires `prli_link_duplicated`), `bulk()` flag and term toggles,
     * `restore()`, and the deletes.
     *
     * @param  array<string, mixed> $payload Link payload: `prli_links` columns plus any extension-owned fields.
     * @param  integer              $id      Link id to update, or 0 to create.
     * @return array<string, mixed>|null The saved row; `['error' => code]` on failure
     *                                   (`validation_failed` adds `messages`); null when
     *                                   `$id` names no link.
     */
    public function save(array $payload, int $id = 0): ?array
    {
        $context    = $id > 0 ? 'update' : 'create';
        $original   = $payload;
        $stripe     = new StripeLinkMeta(new LinkMetas());
        $stripeMeta = $stripe->extract($payload);

        /**
         * Filter: prli_link_payload_pre_save
         *
         * Plugins remove fields they own from `$data` so the column write
         * never sees them (it only knows `prli_links` columns). Return the
         * trimmed array. See save() for the ordering contract.
         *
         * @param array<string, mixed> $data    Payload after Lite strips Stripe meta.
         * @param string               $context 'create' | 'update'
         * @param int                  $linkId  0 on create, link id on update.
         */
        $data = (array) apply_filters('prli_link_payload_pre_save', $payload, $context, $id);

        /**
         * Filter: prli_validate_link
         *
         * Third-party validation. Push error-message strings into the
         * array; a non-empty return aborts the save. Back-compatible with
         * v3's one-arg `$errors` listeners — PHP ignores the extra args.
         *
         * @param array<int, string>   $errors  Start empty, add messages.
         * @param array<string, mixed> $data    Payload about to be saved.
         * @param string               $context 'create' | 'update'.
         * @param int                  $linkId  0 on create, link id on update.
         */
        $errors   = (array) apply_filters('prli_validate_link', [], $data, $context, $id);
        $messages = array_values(array_filter(array_map(
            static fn ($e) => is_string($e) ? trim($e) : '',
            $errors
        )));
        if ($messages !== []) {
            return [
                'error'    => 'validation_failed',
                'messages' => $messages,
            ];
        }

        $link = $id > 0 ? $this->update($id, $data) : $this->create($data);
        if ($link === null || isset($link['error']) || !isset($link['id'])) {
            return $link;
        }

        $linkId = (int) $link['id'];
        $stripe->persist($linkId, $stripeMeta);

        /**
         * Action: prli_link_after_save
         *
         * Fires after the link row is written. Plugins read their own
         * fields from `$original` (the unmodified payload) and persist
         * them (categories, tags, keywords, splitTest, etc.). See save()
         * for the ordering contract.
         *
         * @param int                  $linkId
         * @param array<string, mixed> $original Full unmodified payload.
         * @param string               $context  'create' | 'update'
         */
        do_action('prli_link_after_save', $linkId, $original, $context);

        return $link;
    }

    /**
     * Create a new link, generating/validating the slug and resolving the name.
     *
     * This is the column write only: extension-owned fields in `$data` are
     * ignored and the save seam's hooks (`prli_link_payload_pre_save`,
     * `prli_validate_link`, `prli_link_after_save`) don't run; the legacy
     * `prli-create-link` and `prli_event_recorded` actions still fire. Use
     * save() to save a payload.
     *
     * @param  array<string, mixed> $data Link field values.
     * @return array<string, mixed>
     */
    public function create(array $data): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'prli_links';

        $defaults = (new OptionsStore())->all();

        $url             = trim(str_replace(["\r", "\n"], '', (string) ($data['url'] ?? '')));
        $defaultRedirect = (string) ($defaults['link_redirect_type'] ?? '302');
        $redirectType    = (string) ($data['redirect_type'] ?? $defaultRedirect);
        $isPayLink       = !empty($data['prettypay_link']) || $redirectType === 'prettypay_link_stripe';
        // V3 parity: pay links and `pixel` links route via their own mechanism
        // and don't need a target URL. Everything else does.
        if ($url === '' && !$isPayLink && $redirectType !== 'pixel') {
            return ['error' => 'url_required'];
        }
        if ($url !== '' && !$isPayLink && $redirectType !== 'pixel' && !self::isValidUrl($url)) {
            return ['error' => 'url_invalid'];
        }

        $source        = (string) ($data['source'] ?? 'admin');
        $allowedSource = ['admin', 'user', 'public'];
        if (!in_array($source, $allowedSource, true)) {
            $source = 'admin';
        }

        $slugInput = (string) ($data['slug'] ?? '');
        // Reject leading slash explicitly (not silently normalized) so a
        // caller that tries to force-mount a link at "/" or bypass the
        // prefix segmentation gets an actionable error. JS strips on
        // input; this is defense in depth for REST callers.
        if ($slugInput !== '' && $slugInput[0] === '/') {
            return ['error' => 'invalid_slug'];
        }
        $slug = self::sanitizeSlug($slugInput);
        if ($slug === null) {
            return ['error' => 'invalid_slug'];
        }
        if ($slug === '') {
            // Generator bakes the per-source prefix into the returned slug
            // so the stored value IS the final URL path — no runtime prefix
            // concept anywhere else.
            $slug = SlugGenerator::generate($wpdb, $source);
        } else {
            // User/public sources can't escape their prefix namespace —
            // a visitor who types "my-link" into the user dashboard gets
            // "u/my-link" stored (idempotent if they typed "u/my-link"
            // already). Admin-sourced slugs are left untouched.
            $slug = self::forceSourcePrefix($slug, $source);
            if (ReservedSlugs::isReserved($slug, $source)) {
                return ['error' => 'slug_reserved'];
            }
            if (self::slugTaken($slug)) {
                return ['error' => 'slug_in_use'];
            }
        }

        // Site-wide flag defaults: explicit input wins; otherwise fall back to
        // the options-page default so "Default nofollow / sponsored / tracking"
        // toggles actually take effect on new links.
        $trackMe   = array_key_exists('track_me', $data)
            ? (!empty($data['track_me']) ? 1 : 0)
            : (!empty($defaults['link_track_me']) ? 1 : 0);
        $nofollow  = array_key_exists('nofollow', $data)
            ? (!empty($data['nofollow']) ? 1 : 0)
            : (!empty($defaults['link_nofollow']) ? 1 : 0);
        $sponsored = array_key_exists('sponsored', $data)
            ? (!empty($data['sponsored']) ? 1 : 0)
            : (!empty($defaults['link_sponsored']) ? 1 : 0);

        // V3 parity: if no name was supplied, try fetching the target
        // page's <title>. Pixel and PrettyPay™ links have no meaningful
        // target HTML, so they fall back to the slug instead. Any fetch
        // failure also falls back to the slug — the name column is
        // never left blank.
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            if ($redirectType === 'pixel' || $isPayLink) {
                $name = $slug;
            } else {
                $fetched = $url !== '' ? \PrettyLinks\Helpers\PageTitle::fetch($url) : '';
                $name    = $fetched !== '' ? $fetched : $slug;
            }
        }

        $now = current_time('mysql', true);
        $row = [
            'slug'             => $slug,
            'url'              => $url,
            'name'             => $name,
            'description'      => (string) ($data['description'] ?? ''),
            'redirect_type'    => $redirectType,
            'param_forwarding' => in_array($data['param_forwarding'] ?? '', ['', '0', 'off', false], true) ? 0 : 1,
            'track_me'         => $trackMe,
            'nofollow'         => $nofollow,
            'sponsored'        => $sponsored,
            'new_window'       => !empty($data['new_window']) ? 1 : 0,
            'prettypay_link'   => !empty($data['prettypay_link']) ? 1 : 0,
            'source'           => $source,
            'clicks'           => 0,
            'uniques'          => 0,
            'created_at'       => $now,
            'updated_at'       => $now,
        ];

        $inserted = $wpdb->insert($table, $row);
        if ($inserted === false) {
            return ['error' => 'insert_failed'];
        }
        $id = (int) $wpdb->insert_id;
        do_action('prli-create-link', $id, $row);
        do_action(
            'prli_event_recorded',
            Event::init([
                'event'    => 'link-added',
                'obj_id'   => $id,
                'obj_type' => 'PrliLink',
                'args'     => wp_json_encode($row),
            ])->save()
        );
        return $this->find($id) ?? [];
    }

    /**
     * Update an existing link with the allowed subset of supplied fields.
     *
     * This is the column write only: extension-owned fields in `$data` are
     * ignored and the save seam's hooks (`prli_link_payload_pre_save`,
     * `prli_validate_link`, `prli_link_after_save`) don't run; the legacy
     * `prli_update_link` and `prli_event_recorded` actions still fire. Use
     * save() to save a payload.
     *
     * @param  integer              $id   Link id to update.
     * @param  array<string, mixed> $data Link field values to patch.
     * @return array<string, mixed>|null
     */
    public function update(int $id, array $data): ?array
    {
        global $wpdb;
        $existing = $this->find($id);
        if ($existing === null) {
            return null;
        }

        // `source` records how a link was created, so it is create-only.
        $allowed = array_diff(self::COLUMN_FIELDS, ['source']);
        $patch   = [];
        foreach ($allowed as $key) {
            if (!array_key_exists($key, $data)) {
                continue;
            }
            if (in_array($key, ['track_me', 'nofollow', 'sponsored', 'new_window', 'prettypay_link'], true)) {
                $patch[$key] = !empty($data[$key]) ? 1 : 0;
            } elseif ($key === 'param_forwarding') {
                $patch[$key] = in_array($data[$key], ['', '0', 'off', false, null], true) ? 0 : 1;
            } elseif ($key === 'slug') {
                $raw = (string) $data[$key];
                if ($raw !== '' && $raw[0] === '/') {
                    return ['error' => 'invalid_slug'];
                }
                $sanitized = self::sanitizeSlug($raw);
                if ($sanitized === null) {
                    return ['error' => 'invalid_slug'];
                }
                if ($sanitized !== '') {
                    // Re-saving a user/public link forces its prefix back
                    // in — a visitor trying to rename `u/abc` to `evil`
                    // gets `u/evil` stored. Reserved-slug check inherits
                    // the link's existing source; admin-owned links
                    // bypass both steps.
                    $existingSource = (string) ($existing['source'] ?? 'admin');
                    $sanitized      = self::forceSourcePrefix($sanitized, $existingSource);
                    if (ReservedSlugs::isReserved($sanitized, $existingSource)) {
                        return ['error' => 'slug_reserved'];
                    }
                    // Same guard create() applies, but only when the slug is
                    // actually CHANGING. Without it, renaming one link onto
                    // another's slug was accepted, leaving two live rows
                    // answering to it and the loser silently unreachable (#852).
                    //
                    // Gating on the change rather than the presence matters:
                    // every caller sends the whole row back (LinkForm posts
                    // `{ ...values }`, the CSV importer re-sends the export), so
                    // a presence check would refuse a plain "edit the target URL
                    // and save" on any site that ALREADY has two rows sharing a
                    // slug — the population `Engine::resolve()`'s ordering exists
                    // for. Both rows would become permanently unsavable through
                    // every surface, with no way to repair the duplicate.
                    $currentSlug = (string) ($existing['slug'] ?? '');
                    if ($sanitized !== $currentSlug && self::slugTaken($sanitized, $id)) {
                        return ['error' => 'slug_in_use'];
                    }
                    $patch[$key] = $sanitized;
                }
            } elseif ($key === 'url') {
                $urlVal = trim(str_replace(["\r", "\n"], '', (string) $data[$key]));
                if ($urlVal !== '' && !self::isValidUrl($urlVal)) {
                    return ['error' => 'url_invalid'];
                }
                $patch[$key] = $urlVal;
            } else {
                $patch[$key] = (string) $data[$key];
            }
        }
        if (!$patch) {
            return $existing;
        }

        // V3 parity: clearing the name triggers a title re-fetch. Pixel
        // and PrettyPay™ links skip the fetch and fall back to the slug
        // (their own or the existing one); other types fetch from the
        // patched URL if present, otherwise the existing URL. Any fetch
        // failure falls back to the slug so name is never blank.
        if (array_key_exists('name', $patch) && trim((string) $patch['name']) === '') {
            $effectiveType = (string) ($patch['redirect_type'] ?? ($existing['redirect_type'] ?? ''));
            $effectiveSlug = (string) ($patch['slug'] ?? ($existing['slug'] ?? ''));
            $effectiveUrl  = (string) ($patch['url']  ?? ($existing['url']  ?? ''));
            $isPay         = !empty($existing['prettypay_link'])
                || $effectiveType === 'prettypay_link_stripe';
            if ($effectiveType === 'pixel' || $isPay) {
                $patch['name'] = $effectiveSlug;
            } else {
                $fetched       = $effectiveUrl !== ''
                    ? \PrettyLinks\Helpers\PageTitle::fetch($effectiveUrl)
                    : '';
                $patch['name'] = $fetched !== '' ? $fetched : $effectiveSlug;
            }
        }

        $patch['updated_at'] = current_time('mysql', true);

        $wpdb->update($wpdb->prefix . 'prli_links', $patch, ['id' => $id]);
        do_action('prli_update_link', $id, $patch);
        do_action(
            'prli_event_recorded',
            Event::init([
                'event'    => 'link-updated',
                'obj_id'   => $id,
                'obj_type' => 'PrliLink',
                'args'     => wp_json_encode($this->find($id) ?? []),
            ])->save()
        );
        return $this->find($id);
    }

    /**
     * Soft-delete a link by stamping deleted_at, unless the trash filter blocks it.
     *
     * @param  integer $id Link id to soft-delete.
     * @return boolean
     */
    public function softDelete(int $id): bool
    {
        /**
         * Filter: prli_pre_link_trash
         *
         * Lets extensions block trashing a link by returning a non-empty
         * reason string. Used by Splash Pages to prevent removal of a
         * link that's referenced as a CTA on another splash link.
         *
         * @param string $reason Empty by default; non-empty blocks the trash.
         * @param int    $id     Link id about to be soft-deleted.
         */
        $blockReason = (string) apply_filters('prli_pre_link_trash', '', $id);
        if ($blockReason !== '') {
            self::$lastTrashBlockReason = $blockReason;
            return false;
        }
        self::$lastTrashBlockReason = '';

        global $wpdb;
        $ok = (bool) $wpdb->update(
            $wpdb->prefix . 'prli_links',
            ['deleted_at' => current_time('mysql', true)],
            ['id' => $id]
        );
        if ($ok) {
            /**
             * Action: prli_delete_link
             *
             * Soft delete: fires while the link, terms and metas rows all
             * still exist. See {@see self::hardDelete()} for the hard path.
             *
             * @param int               $id      Trashed link id.
             * @param array{soft: bool} $context `soft` is true here.
             */
            do_action('prli_delete_link', $id, ['soft' => true]);
            do_action(
                'prli_event_recorded',
                Event::init([
                    'event'    => 'link-trashed',
                    'obj_id'   => $id,
                    'obj_type' => 'PrliLink',
                    'args'     => wp_json_encode($this->find($id) ?? []),
                ])->save()
            );
        }
        return $ok;
    }

    /**
     * Permanently delete a link and its related rows, unless the trash filter blocks it.
     *
     * @param  integer $id Link id to delete.
     * @return boolean
     */
    public function hardDelete(int $id): bool
    {
        // Same gate as softDelete — a link referenced as a splash CTA
        // can't be permanently deleted either.
        $blockReason = (string) apply_filters('prli_pre_link_trash', '', $id);
        if ($blockReason !== '') {
            self::$lastTrashBlockReason = $blockReason;
            return false;
        }
        self::$lastTrashBlockReason = '';

        global $wpdb;
        // Snapshot the link BEFORE deletion so the link-removed event payload
        // still carries the full object — receivers expect to see what was
        // removed, and we can't read it back after the rows are gone.
        $snapshot = $this->find($id);
        $wpdb->delete($wpdb->prefix . 'prli_link_terms', ['link_id' => $id]);
        $wpdb->delete($wpdb->prefix . 'prli_link_metas', ['link_id' => $id]);
        $deleted = (bool) $wpdb->delete($wpdb->prefix . 'prli_links', ['id' => $id]);
        if ($deleted) {
            /**
             * Action: prli_delete_link
             *
             * Fires AFTER the link row and its `prli_link_terms` and
             * `prli_link_metas` rows are gone, so listeners cannot read the
             * link's terms or metas here — key cleanup off `$id` alone.
             * Soft-delete fires the same action from {@see self::softDelete()}
             * with every row still in place.
             *
             * @param int               $id      Deleted link id.
             * @param array{soft: bool} $context `soft` is false here.
             */
            do_action('prli_delete_link', $id, ['soft' => false]);
            do_action(
                'prli_event_recorded',
                Event::init([
                    'event'    => 'link-removed',
                    'obj_id'   => $id,
                    'obj_type' => 'PrliLink',
                    'args'     => wp_json_encode($snapshot ?? []),
                ])->save()
            );
        }
        return $deleted;
    }

    /**
     * Replace all term assignments for a link in a single taxonomy. Pass an
     * empty array to clear. Idempotent — safe to call on unchanged input.
     *
     * @param integer $linkId   Link id.
     * @param string  $taxonomy Taxonomy ('category' or 'tag').
     * @param int[]   $termIds  Term ids to assign.
     */
    public function setTerms(int $linkId, string $taxonomy, array $termIds): void
    {
        if ($taxonomy !== 'category' && $taxonomy !== 'tag') {
            return;
        }
        global $wpdb;
        $table = $wpdb->prefix . 'prli_link_terms';
        $wpdb->delete($table, [
            'link_id'  => $linkId,
            'taxonomy' => $taxonomy,
        ]);
        $ids = array_values(array_unique(array_filter(array_map('intval', $termIds))));
        foreach ($ids as $termId) {
            $wpdb->insert($table, [
                'link_id'  => $linkId,
                'term_id'  => $termId,
                'taxonomy' => $taxonomy,
            ]);
        }
    }

    /**
     * Get the category and tag term ids assigned to a single link.
     *
     * @param  integer $linkId Link id.
     * @return array{categories: int[], tags: int[]}
     */
    public function getTerms(int $linkId): array
    {
        $all = $this->getTermsForLinks([$linkId]);
        return $all[$linkId] ?? [
            'categories' => [],
            'tags'       => [],
        ];
    }

    /**
     * Bulk variant: one query for N links. Returns a map keyed by link id
     * with the same shape as getTerms() per entry.
     *
     * @param  int[] $linkIds Link ids to fetch terms for.
     * @return array<int, array{categories: int[], tags: int[]}>
     */
    public function getTermsForLinks(array $linkIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $linkIds))));
        if (!$ids) {
            return [];
        }
        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $rows         = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT link_id, term_id, taxonomy FROM {$wpdb->prefix}prli_link_terms
                 WHERE link_id IN ({$placeholders})",
                ...$ids
            ),
            ARRAY_A
        ) ?: [];
        $out          = [];
        foreach ($ids as $id) {
            $out[$id] = [
                'categories' => [],
                'tags'       => [],
            ];
        }
        foreach ($rows as $row) {
            $lid = (int) $row['link_id'];
            if ($row['taxonomy'] === 'category') {
                $out[$lid]['categories'][] = (int) $row['term_id'];
            } elseif ($row['taxonomy'] === 'tag') {
                $out[$lid]['tags'][] = (int) $row['term_id'];
            }
        }
        return $out;
    }


    /**
     * Restore a soft-deleted link by clearing its deleted_at stamp.
     *
     * @param  integer $id Link id to restore.
     * @return boolean
     */
    public function restore(int $id): bool
    {
        global $wpdb;
        $ok = (bool) $wpdb->update(
            $wpdb->prefix . 'prli_links',
            ['deleted_at' => null],
            ['id' => $id]
        );
        if ($ok) {
            /**
             * Fires after a trashed link is restored (its deleted_at stamp
             * cleared). Counterpart to `prli_delete_link` with soft => true;
             * lets caches that drop trashed links pick the link back up.
             *
             * @param int $id Restored link id.
             */
            do_action('prli_restore_link', $id);
            do_action(
                'prli_event_recorded',
                Event::init([
                    'event'    => 'link-restored',
                    'obj_id'   => $id,
                    'obj_type' => 'PrliLink',
                    'args'     => wp_json_encode($this->find($id) ?? []),
                ])->save()
            );
        }
        return $ok;
    }

    /**
     * Duplicate a link, generating a unique "-copy" slug and copying its terms
     * and metas.
     *
     * @param  integer $id Link id to duplicate.
     * @return array<string, mixed>|null
     */
    public function duplicate(int $id): ?array
    {
        $src = $this->find($id);
        if ($src === null) {
            return null;
        }
        global $wpdb;
        $slugBase = (string) $src['slug'] . '-copy';
        $slug     = $slugBase;
        $suffix   = 2;
        while (
            $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}prli_links WHERE slug = %s LIMIT 1",
                $slug
            ))
        ) {
            $slug = $slugBase . '-' . $suffix++;
        }

        $new = $this->create(array_merge($src, [
            'slug'       => $slug,
            'name'       => $src['name'] . ' (copy)',
            'clicks'     => 0,
            'uniques'    => 0,
            'deleted_at' => null,
        ]));
        if (isset($new['id'])) {
            $terms = $this->getTerms($id);
            $this->setTerms((int) $new['id'], 'category', $terms['categories']);
            $this->setTerms((int) $new['id'], 'tag', $terms['tags']);
            $this->copyMetas($id, (int) $new['id']);

            /**
             * Action: prli_link_duplicated
             *
             * Fires after a link is duplicated and its terms and metas are
             * copied. Use it to copy link state kept in other tables.
             *
             * @param int $newId  New link id.
             * @param int $fromId Source link id.
             */
            do_action('prli_link_duplicated', (int) $new['id'], $id);
        }
        return $new;
    }

    /**
     * Copy a link's `prli_link_metas` rows onto another link, minus per-link
     * runtime state. Every row is copied in id order, so multi-value keys
     * (several rows under one meta_key, e.g. URL replacements) keep all of
     * their rows and their order.
     *
     * @param  integer $fromId Source link id.
     * @param  integer $toId   Destination link id.
     * @return void
     */
    private function copyMetas(int $fromId, int $toId): void
    {
        /**
         * Filter: prli_link_duplicate_skip_meta_keys
         *
         * Meta keys left behind when a link is duplicated. Use it for
         * per-link runtime state (counters, check results) that must not
         * carry over to the copy.
         *
         * @param string[] $keys   Meta keys to skip.
         * @param int      $fromId Source link id.
         */
        $skip = array_values(array_map('strval', (array) apply_filters(
            'prli_link_duplicate_skip_meta_keys',
            ['static-clicks', 'static-uniques'],
            $fromId
        )));

        global $wpdb;
        $table = $wpdb->prefix . 'prli_link_metas';
        $sql   = "INSERT INTO {$table} (meta_key, meta_value, meta_order, link_id, created_at)
                  SELECT meta_key, meta_value, meta_order, %d, %s
                    FROM {$table}
                   WHERE link_id = %d";
        $args  = [$toId, current_time('mysql', true), $fromId];
        if ($skip) {
            $sql .= ' AND meta_key NOT IN (' . implode(', ', array_fill(0, count($skip), '%s')) . ')';
            $args = array_merge($args, $skip);
        }
        $sql .= ' ORDER BY id ASC';
        $wpdb->query($wpdb->prepare($sql, $args));
    }

    /**
     * Apply a bulk action (trash/restore/delete, flag toggles, or term ops) to links.
     *
     * @param  string $action  Bulk action key.
     * @param  int[]  $ids     Link ids to act on.
     * @param  int[]  $termIds Term ids; only meaningful for taxonomy actions.
     * @return array<string, mixed>
     */
    public function bulk(string $action, array $ids, array $termIds = []): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) {
            return ['affected' => 0];
        }

        $taxonomyAction = [
            'bulk_add_categories'    => [
                'op'  => 'add',
                'tax' => 'category',
            ],
            'bulk_remove_categories' => [
                'op'  => 'remove',
                'tax' => 'category',
            ],
            'bulk_add_tags'          => [
                'op'  => 'add',
                'tax' => 'tag',
            ],
            'bulk_remove_tags'       => [
                'op'  => 'remove',
                'tax' => 'tag',
            ],
        ];
        if (isset($taxonomyAction[$action])) {
            $spec = $taxonomyAction[$action];
            return ['affected' => $this->bulkTerms($ids, $spec['tax'], $termIds, $spec['op'] === 'add')];
        }

        // V3-parity flag toggles (nofollow/sponsored/track_me). Each
        // action flips one column to 0 or 1 for the selected link ids
        // in a single UPDATE.
        $flagAction = [
            'bulk_nofollow_on'   => [
                'col' => 'nofollow',
                'val' => 1,
            ],
            'bulk_nofollow_off'  => [
                'col' => 'nofollow',
                'val' => 0,
            ],
            'bulk_sponsored_on'  => [
                'col' => 'sponsored',
                'val' => 1,
            ],
            'bulk_sponsored_off' => [
                'col' => 'sponsored',
                'val' => 0,
            ],
            'bulk_track_on'      => [
                'col' => 'track_me',
                'val' => 1,
            ],
            'bulk_track_off'     => [
                'col' => 'track_me',
                'val' => 0,
            ],
        ];
        if (isset($flagAction[$action])) {
            $spec = $flagAction[$action];
            return ['affected' => $this->bulkSetFlag($ids, $spec['col'], $spec['val'])];
        }

        $affected = 0;
        foreach ($ids as $id) {
            switch ($action) {
                case 'trash':
                    if ($this->softDelete($id)) {
                        ++$affected;
                    }
                    break;
                case 'restore':
                    if ($this->restore($id)) {
                        ++$affected;
                    }
                    break;
                case 'delete':
                    if ($this->hardDelete($id)) {
                        ++$affected;
                    }
                    break;
            }
        }
        return ['affected' => $affected];
    }

    /**
     * Flip one boolean-ish column across many links in a single UPDATE.
     *
     * @param  int[]   $linkIds Link ids to update.
     * @param  string  $column  Column to set (must be in the allowed list).
     * @param  integer $value   Value to store (coerced to 0 or 1).
     * @return integer
     */
    private function bulkSetFlag(array $linkIds, string $column, int $value): int
    {
        static $allowed = ['nofollow', 'sponsored', 'track_me', 'new_window'];
        if (!in_array($column, $allowed, true)) {
            return 0;
        }
        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($linkIds), '%d'));
        $value        = $value ? 1 : 0;
        $sql          = "UPDATE {$wpdb->prefix}prli_links
                         SET {$column} = %d, updated_at = %s
                         WHERE id IN ({$placeholders})";
        $wpdb->query($wpdb->prepare(
            $sql,
            ...array_merge([$value, current_time('mysql', true)], $linkIds)
        ));
        return count($linkIds);
    }

    /**
     * Add or remove a set of terms across many links in a single query.
     * Add is idempotent via INSERT IGNORE against the composite PK; remove
     * targets exact (link_id, term_id, taxonomy) matches.
     *
     * @param  int[]   $linkIds  Link ids to update.
     * @param  string  $taxonomy Taxonomy ('category' or 'tag').
     * @param  int[]   $termIds  Term ids to add or remove.
     * @param  boolean $add      True to add terms, false to remove them.
     * @return integer
     */
    private function bulkTerms(array $linkIds, string $taxonomy, array $termIds, bool $add): int
    {
        $termIds = array_values(array_unique(array_filter(array_map('intval', $termIds))));
        if (!$termIds || ($taxonomy !== 'category' && $taxonomy !== 'tag')) {
            return 0;
        }
        global $wpdb;
        $table = $wpdb->prefix . 'prli_link_terms';

        if ($add) {
            $values       = [];
            $placeholders = [];
            foreach ($linkIds as $linkId) {
                foreach ($termIds as $termId) {
                    $placeholders[] = '(%d, %d, %s)';
                    $values[]       = $linkId;
                    $values[]       = $termId;
                    $values[]       = $taxonomy;
                }
            }
            $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO {$table} (link_id, term_id, taxonomy) VALUES " . implode(', ', $placeholders),
                ...$values
            ));
        } else {
            $linkPh = implode(',', array_fill(0, count($linkIds), '%d'));
            $termPh = implode(',', array_fill(0, count($termIds), '%d'));
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$table}
                 WHERE taxonomy = %s
                   AND link_id IN ({$linkPh})
                   AND term_id IN ({$termPh})",
                ...array_merge([$taxonomy], $linkIds, $termIds)
            ));
        }
        return count($linkIds);
    }

    /**
     * Generate a fresh unique slug using the default source.
     *
     * @return string
     */
    public function generateSlug(): string
    {
        return SlugGenerator::generate();
    }

    /**
     * Normalize a raw DB row into the public link array shape.
     *
     * This shape is a published contract, returned by find(), findBySlug(),
     * findByUrl() and search() (hydrated rows, i.e. without `fields`
     * projection). External consumers read these keys directly and send
     * them out in fixed wire formats, so renaming, moving or retyping one
     * doesn't error: it silently sends empty values. Keep these keys and
     * types stable:
     *
     * - `pretty_url` (string) full short URL, built from the stored slug
     * - `clicks` (int) count mode: the `prli_link_metas` counter; otherwise
     *   the live `prli_clicks` count from search(), else `prli_links.clicks`
     * - `uniques` (int)
     * - `param_forwarding` (bool) normalized from '' / '0' / 'off'
     * - `deleted_at` (string|null) null while the link is live
     *
     * The LinksTest::testHydratedRowKeepsItsPublishedShape* tests pin these.
     *
     * @param  array<string, mixed> $row Raw prli_links row.
     * @return array<string, mixed>
     */
    private static function hydrate(array $row): array
    {
        $out = self::project($row, [
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
            'prettypay_link',
            'clicks',
            'source',
            'created_at',
            'updated_at',
            'deleted_at',
        ]);
        $out['pretty_url'] = self::prettyUrl((string) $row['slug'], (string) ($row['source'] ?? 'admin'));
        // In count mode uniques come from the static-uniques meta (populated
        // by the search/find queries); in normal/extended they come from the
        // fast-read counter on prli_links.
        $out['uniques'] = (int) ($row['uniques'] ?? 0);
        return $out;
    }

    /**
     * In count mode, overwrite the `clicks` and `uniques` keys in a raw DB
     * row with values from prli_link_metas so single-row lookups show the
     * correct counts. No-op in normal/extended mode.
     *
     * @param array<string, mixed> $row Raw prli_links row, modified in place.
     */
    private static function applyMetaClicks(array &$row): void
    {
        if (!self::isCountMode()) {
            return;
        }
        global $wpdb;
        $metas          = $wpdb->prefix . 'prli_link_metas';
        $id             = (int) $row['id'];
        $metaRows       = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT meta_key, meta_value FROM {$metas}
                  WHERE link_id = %d AND meta_key IN ('static-clicks', 'static-uniques')",
                $id
            ),
            ARRAY_A
        ) ?: [];
        $meta           = array_column($metaRows, 'meta_value', 'meta_key');
        $row['clicks']  = (int) ($meta['static-clicks'] ?? 0);
        $row['uniques'] = (int) ($meta['static-uniques'] ?? 0);
    }

    /**
     * Whether another row already holds this slug.
     *
     * Trashed rows count as taken, matching what `create()` has always done.
     * Freeing a trashed link's slug would mean restoring it could land a second
     * live row on the same slug — the exact collision this guards against.
     *
     * Deliberately not backed by a `UNIQUE` index, and therefore not atomic:
     * two concurrent writers can both pass this check and both write. The
     * constraint is not added because `prli_links` has never had one, so any
     * site already carrying duplicates (from an import or a direct write) would
     * fail the migration outright — see #852, and #833 for the index work.
     * `Engine::resolve()` orders by id so those sites stay deterministic
     * meanwhile. A later attempt at the index is a deliberate change, not a
     * missing piece of this one.
     *
     * @param  string  $slug     Sanitized slug to check.
     * @param  integer $exceptId Row to ignore — the one being updated.
     * @return boolean
     */
    private static function slugTaken(string $slug, int $exceptId = 0): bool
    {
        global $wpdb;
        $table = $wpdb->prefix . 'prli_links';

        // `id <> 0` is always true, so one query serves both callers.
        return $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$table} WHERE slug = %s AND id <> %d LIMIT 1",
                $slug,
                $exceptId
            )
        ) !== null;
    }

    /**
     * True when the site is configured for Simple (count-only) tracking.
     * Reads the option via WP's object cache — effectively free to call
     * multiple times per request.
     *
     * Public because Simple mode moves the click counters from the
     * `prli_links` columns into `prli_link_metas`, so anything reading a
     * count has to know which store is live.
     */
    public static function isCountMode(): bool
    {
        return self::trackingMode() === 'count';
    }

    /**
     * The configured tracking mode: 'normal', 'extended', or 'count'.
     * Reads the option via WP's object cache.
     *
     * String-casts a present non-null value; a missing or null value reads as
     * 'normal'. `Options\Store::get()` (used by `ClickWriter` and
     * `Redirect\Engine`) keeps a stored null as null instead — both agree
     * for the `=== 'count'` / `=== 'extended'` checks callers make.
     *
     * @return string
     */
    public static function trackingMode(): string
    {
        $opts = get_option('prli_options');
        return is_array($opts) && isset($opts['extended_tracking'])
            ? (string) $opts['extended_tracking']
            : 'normal';
    }

    /**
     * Build the public pretty URL for a stored slug.
     *
     * @param  string $slug   Stored slug (already prefix-baked).
     * @param  string $source Link source; currently unused for URL building.
     * @return string
     */
    private static function prettyUrl(string $slug, string $source = 'admin'): string
    {
        // The stored slug IS the path — prefix (if any) was baked in at
        // creation time. No runtime prefix prepending; rename/remove the
        // `base_slug_prefix` option without breaking existing URLs.
        unset($source);
        return \PrettyLinks\Helpers\LinkUrl::build($slug);
    }

    /**
     * Normalize a redirect-type filter arg into a list of column values.
     *
     * Accepts a single string or an array, and drops the links-list
     * dropdown's "no filter" sentinels ('' and 'all') along with duplicates.
     *
     * @param  mixed $value Raw `redirect_type` / `redirect_type__not_in` arg.
     * @return string[]
     */
    private static function normalizeRedirectTypes($value): array
    {
        if ($value === null) {
            return [];
        }
        $values = is_array($value) ? $value : [$value];
        $values = array_map(
            static function ($type): string {
                return trim((string) $type);
            },
            $values
        );
        return array_values(array_unique(array_filter(
            $values,
            static function (string $type): bool {
                // '' and 'all' are the links-list dropdown's "no filter"
                // sentinels, so they never become a WHERE condition.
                return $type !== '' && $type !== 'all';
            }
        )));
    }

    /**
     * Normalize `search()`'s `fields` arg into a safe column list.
     *
     * Anything not in {@see self::PROJECTABLE_FIELDS} is dropped, so the
     * result is always safe to interpolate into the SELECT. An empty result
     * means "no projection" and the caller falls back to the full row.
     *
     * @param  mixed $value Raw `fields` arg.
     * @return string[]
     */
    private static function normalizeFields($value): array
    {
        if (!is_array($value)) {
            return [];
        }
        // Anything not stringable is dropped rather than cast: a nested array
        // would raise "Array to string conversion" and an object without
        // __toString() would throw, so one malformed entry from a caller (or
        // from `prli_links_index_args`) would abort the search.
        $fields = array_map(
            static function ($field): string {
                return is_scalar($field) ? trim((string) $field) : '';
            },
            $value
        );
        return array_values(array_unique(array_filter(
            $fields,
            static function (string $field): bool {
                return in_array($field, self::PROJECTABLE_FIELDS, true);
            }
        )));
    }

    /**
     * Cast a single raw DB column the way the API exposes it.
     *
     * Shared by `hydrate()` and projection mode so a projected row's `id` is
     * an int and its `nofollow` a bool, exactly as on a hydrated one.
     *
     * @param  string               $column Column name.
     * @param  array<string, mixed> $row    Raw prli_links row.
     * @return mixed
     */
    private static function castColumn(string $column, array $row)
    {
        switch ($column) {
            case 'id':
                return (int) ($row['id'] ?? 0);
            case 'group_id':
                // Nullable in the schema: keep NULL distinguishable from
                // group 0 so a picker can tell "ungrouped" from a real id.
                return isset($row['group_id']) ? (int) $row['group_id'] : null;
            case 'clicks':
                // `actual_clicks` is the live COUNT from prli_clicks when the
                // row came from `search()` in normal/extended mode; falls back
                // to the cached `prli_links.clicks` column for single-row
                // lookups, count mode, or projection mode.
                return (int) ($row['actual_clicks'] ?? $row['clicks'] ?? 0);
            case 'uniques':
                return (int) ($row['uniques'] ?? 0);
            case 'param_forwarding':
                return !in_array($row['param_forwarding'] ?? '', ['', '0', 'off'], true);
            case 'nofollow':
            case 'sponsored':
            case 'track_me':
            case 'new_window':
            case 'prettypay_link':
                return (bool) ($row[$column] ?? 0);
            case 'redirect_type':
                return (string) ($row['redirect_type'] ?? '302');
            case 'source':
                return (string) ($row['source'] ?? 'admin');
            case 'deleted_at':
                return $row['deleted_at'] ? (string) $row['deleted_at'] : null;
            default:
                return (string) ($row[$column] ?? '');
        }
    }

    /**
     * Cast the requested columns of a raw row, preserving field order.
     *
     * @param  array<string, mixed> $row    Raw prli_links row.
     * @param  string[]             $fields Column names to keep.
     * @return array<string, mixed>
     */
    private static function project(array $row, array $fields): array
    {
        $out = [];
        foreach ($fields as $field) {
            $out[$field] = self::castColumn($field, $row);
        }
        return $out;
    }

    /**
     * Whitelist the orderby column, falling back to created_at when invalid.
     *
     * @param  string $orderby Requested orderby column.
     * @return string
     */
    private static function safeOrderBy(string $orderby): string
    {
        $allowed = ['id', 'slug', 'name', 'url', 'clicks', 'uniques', 'created_at', 'updated_at'];
        return in_array($orderby, $allowed, true) ? $orderby : 'created_at';
    }

    /**
     * Coerce a user-supplied slug into the engine-safe alphabet used by the
     * dispatcher (see Redirect\Engine::SLUG_CHAR_CLASS).
     * Whitespace and separators become dashes; invalid chars are stripped.
     *
     * @param  string $url URL to validate.
     * @return boolean
     */
    private static function isValidUrl(string $url): bool
    {
        $valid = (bool) preg_match('%^https?://%i', $url);
        return (bool) apply_filters('prli_is_valid_url', $valid, $url);
    }

    /**
     * Sanitize a raw slug into the engine-safe alphabet.
     *
     * Delegates to {@see Engine::canonicalizeSlug()} so Links save and QR
     * preview share one pipeline (NFC on write, whitespace, charset strip).
     *
     * @param  string $slug Raw user-supplied slug.
     * @return string|null
     */
    private static function sanitizeSlug(string $slug): ?string
    {
        $allowSpaces = (bool) (new OptionsStore())->get('allow_slug_spaces');
        return Engine::canonicalizeSlug($slug, $allowSpaces);
    }

    /**
     * Force the per-source prefix onto a user-provided slug so user/public
     * links can't escape their namespace. Admin-sourced slugs are returned
     * unchanged — admins are trusted to pick any path, including one that
     * overlaps the user/public prefix (unlikely but legal).
     *
     * Idempotent: a slug already beginning with `{prefix}/` is returned
     * as-is so re-saving an edit doesn't double-prepend. When the
     * configured prefix is empty (admin explicitly cleared it), no
     * forcing happens for that source either.
     *
     * @param  string $slug   Sanitized slug to prefix.
     * @param  string $source Link source ('user', 'public', or 'admin').
     * @return string
     */
    private static function forceSourcePrefix(string $slug, string $source): string
    {
        if ($slug === '' || ($source !== 'user' && $source !== 'public')) {
            return $slug;
        }
        /**
         * Resolve the per-source prefix via the shared link-prefix filter.
         *
         * @see \PrettyLinks\Slug\Generator::generate — same filter the
         * generator uses, so a forced slug and a generated slug always
         * land in the same namespace. Lite has no built-in prefix; any
         * plugin hooking the filter supplies one.
         */
        $prefix = (string) apply_filters('prli_link_prefix_for_source', '', $source);
        $prefix = trim($prefix, '/');
        if ($prefix === '') {
            return $slug;
        }
        $needle = $prefix . '/';
        if (strncmp($slug, $needle, strlen($needle)) === 0) {
            return $slug;
        }
        return $needle . $slug;
    }
}
