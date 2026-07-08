<?php

declare(strict_types=1);

namespace PrettyLinks\Repositories;

use PrettyLinks\GroundLevel\Events\Models\Event;
use PrettyLinks\Options\Store as OptionsStore;
use PrettyLinks\Redirect\ReservedSlugs;
use PrettyLinks\Slug\Generator as SlugGenerator;

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
 */
class Links
{
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
     * Search, filter, sort, and paginate links for the admin list-table.
     *
     * @param  array<string, mixed> $args Search/filter/sort/pagination args.
     * @return array{items: array<int, array<string, mixed>>, total: int, pages: int}
     */
    public function search(array $args): array
    {
        global $wpdb;
        $table  = $wpdb->prefix . 'prli_links';
        $where  = [];
        $params = [];

        // `include` restricts the result to specific link ids. When
        // present we skip the deleted_at filter so hydration of saved
        // chip selections still resolves names for trashed links.
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
        } elseif ($status === 'trashed') {
            $where[] = 'deleted_at IS NOT NULL';
        } else {
            $where[] = 'deleted_at IS NULL';
        }

        if (array_key_exists('prettypay', $args) && $args['prettypay'] !== null && $args['prettypay'] !== '') {
            $where[] = ((int) $args['prettypay']) === 1
                ? 'prettypay_link = 1'
                : 'prettypay_link <> 1';
        }

        // Exact-match filter for the links-list redirect-type dropdown.
        // Value is a raw redirect_type column value (e.g. '302', '301',
        // 'cloak', 'pixel', 'prettybar'). Empty / 'all' means no filter.
        $redirectType = isset($args['redirect_type']) ? (string) $args['redirect_type'] : '';
        if ($redirectType !== '' && $redirectType !== 'all') {
            $where[]  = 'redirect_type = %s';
            $params[] = $redirectType;
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

        $orderby = self::safeOrderBy((string) ($args['orderby'] ?? 'created_at'));
        $order   = strtolower((string) ($args['order'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
        $perPage = (int) ($args['per_page'] ?? 20);
        $page    = (int) ($args['page'] ?? 1);
        $offset  = ($page - 1) * $perPage;

        /**
         * Filter: prli_links_query_clauses
         *
         * Allows extensions (e.g. Pro) to append extra WHERE conditions and
         * bound params to the links search query without modifying this repo.
         *
         * @param array{0: string[], 1: mixed[]} $clauses  [$where, $params]
         * @param array<string, mixed>           $args     Full search args.
         */
        [$where, $params] = (array) apply_filters('prli_links_query_clauses', [$where, $params], $args);

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $totalSql = "SELECT COUNT(*) FROM {$table} {$whereSql}";
        $total    = (int) $wpdb->get_var($params ? $wpdb->prepare($totalSql, ...$params) : $totalSql);

        if (self::isCountMode()) {
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
            $safeWhere = $whereSql !== '' ? str_replace('id IN (', 'li.id IN (', $whereSql) : '';
            $listSql   = "SELECT li.*, {$clicksExpr} AS clicks, {$uniquesExpr} AS uniques
                             FROM {$table} li
                             LEFT JOIN {$metas} lmc ON lmc.link_id = li.id AND lmc.meta_key = 'static-clicks'
                             LEFT JOIN {$metas} lmu ON lmu.link_id = li.id AND lmu.meta_key = 'static-uniques'
                            {$safeWhere}
                            ORDER BY {$orderExpr} {$order} LIMIT %d OFFSET %d";
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
            $listSql    = "SELECT {$table}.*, {$clicksExpr} AS actual_clicks
                             FROM {$table}
                            {$whereSql}
                            ORDER BY {$orderExpr} {$order} LIMIT %d OFFSET %d";
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare($listSql, ...array_merge($params, [$perPage, $offset])),
            ARRAY_A
        ) ?: [];

        return [
            'items' => array_map([self::class, 'hydrate'], $rows),
            'total' => $total,
            'pages' => (int) ceil($total / max(1, $perPage)),
        ];
    }

    /**
     * Find a single link by its id.
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
     * Create a new link, generating/validating the slug and resolving the name.
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
            if (
                $wpdb->get_var(
                    $wpdb->prepare("SELECT id FROM {$table} WHERE slug = %s LIMIT 1", $slug)
                )
            ) {
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

        $allowed = [
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
        ];
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
     * Duplicate a link, generating a unique "-copy" slug and copying its terms.
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
        }
        return $new;
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
     * @param  array<string, mixed> $row Raw prli_links row.
     * @return array<string, mixed>
     */
    private static function hydrate(array $row): array
    {
        $out = [
            'id'               => (int) $row['id'],
            'slug'             => (string) $row['slug'],
            'url'              => (string) $row['url'],
            'name'             => (string) ($row['name'] ?? ''),
            'description'      => (string) ($row['description'] ?? ''),
            'redirect_type'    => (string) ($row['redirect_type'] ?? '302'),
            'param_forwarding' => !in_array($row['param_forwarding'] ?? '', ['', '0', 'off'], true),
            'track_me'         => (bool) ($row['track_me'] ?? 0),
            'nofollow'         => (bool) ($row['nofollow'] ?? 0),
            'sponsored'        => (bool) ($row['sponsored'] ?? 0),
            'new_window'       => (bool) ($row['new_window'] ?? 0),
            'prettypay_link'   => (bool) ($row['prettypay_link'] ?? 0),
            // `actual_clicks` is the live COUNT from prli_clicks when the
            // row came from `search()` in normal/extended mode; falls back
            // to the cached `prli_links.clicks` column for single-row
            // lookups or when count mode is active.
            'clicks'           => (int) ($row['actual_clicks'] ?? $row['clicks'] ?? 0),
            'source'           => (string) ($row['source'] ?? 'admin'),
            'created_at'       => (string) ($row['created_at'] ?? ''),
            'updated_at'       => (string) ($row['updated_at'] ?? ''),
            'deleted_at'       => $row['deleted_at'] ? (string) $row['deleted_at'] : null,
            'pretty_url'       => self::prettyUrl((string) $row['slug'], (string) ($row['source'] ?? 'admin')),
        ];
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
     * True when the site is configured for Simple (count-only) tracking.
     * Reads the option via WP's object cache — effectively free to call
     * multiple times per request.
     */
    private static function isCountMode(): bool
    {
        $opts = get_option('prli_options');
        $mode = is_array($opts) && isset($opts['extended_tracking'])
            ? (string) $opts['extended_tracking']
            : 'normal';
        return $mode === 'count';
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
     * dispatcher (see Redirect\Engine::extractSlug — `[A-Za-z0-9_\-]+`).
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
     * @param  string $slug Raw user-supplied slug.
     * @return string
     */
    private static function sanitizeSlug(string $slug): string
    {
        $slug = trim($slug);
        if ($slug === '') {
            return '';
        }
        // Replace runs of whitespace with a single dash, then strip
        // anything outside the engine alphabet. `/` is allowed so users can
        // type multi-segment slugs like `go/abc`; the prefix feature bakes
        // such slashes into stored slugs at creation time.
        $slug = (string) preg_replace('/\s+/', '-', $slug);
        $slug = (string) preg_replace('#[^A-Za-z0-9_\-/]#', '', $slug);
        $slug = (string) preg_replace('#/+#', '/', $slug);
        $slug = (string) preg_replace('/-+/', '-', $slug);
        return trim($slug, '-/');
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
