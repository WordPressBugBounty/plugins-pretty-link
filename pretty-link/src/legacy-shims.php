<?php

/**
 * Legacy v3 API function shims.
 *
 * These global functions are 3.x compatibility wrappers around the v4
 * Repositories\Links repo. They exist ONLY so external plugins, themes,
 * and user snippets written against v3's documented public API keep
 * working after upgrading to v4.
 *
 * DEPRECATED as of 4.0.0. Every shim calls _deprecated_function() so
 * WP_DEBUG logs each use. These shims will be removed in a future major
 * version — new code should use \PrettyLinks\Repositories\Links directly
 * or the REST API at /wp-json/pretty-links/v1/.
 *
 * Return-shape note: shims return v4's hydrated link array (cast to
 * stdClass for OBJECT return type). We add the legacy `link_status`
 * string back in (always 'enabled' — v4 dropped the disabled concept)
 * so code checking that field still works. Other fields preserve their
 * v3 names where the schema didn't rename them.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

use PrettyLinks\Repositories\Links as PrliLinksRepo;

/**
 * Restore the v3 `link_status` string field on a hydrated v4 link array.
 * v4 dropped link_status entirely; all links are active. Always 'enabled'.
 *
 * Soft-deleted v4 links (`deleted_at IS NOT NULL`) surface as `null` to
 * match v3's contract: v3 hard-deleted, so `prli_get_link()` on a deleted
 * id returned `null`. Legacy callers check `if ($link) { ... }`, which
 * would otherwise silently operate on a trashed row.
 *
 * @param  array<string, mixed>|null $link Hydrated v4 link array, or null.
 * @return array<string, mixed>|null
 */
function prli_legacy_reshape_link(?array $link): ?array
{
    if (!is_array($link)) {
        return $link;
    }
    if (!empty($link['deleted_at'])) {
        return null;
    }
    $link['link_status']      = 'enabled';
    $link['param_forwarding'] = !empty($link['param_forwarding']) ? 1 : 0;
    return $link;
}

/**
 * V3 shim: create a pretty link.
 *
 * @param  string                 $target_url       Required target URL.
 * @param  string                 $slug             Optional custom slug; auto-generated if empty.
 * @param  string                 $name             Optional label.
 * @param  string                 $description      Optional notes.
 * @param  integer                $group_id         DEPRECATED in v3; ignored.
 * @param  boolean|integer|string $track_me         Empty falls back to site default; truthy → on.
 * @param  boolean|integer|string $nofollow         Empty falls back to site default; truthy → on.
 * @param  boolean|integer|string $sponsored        Empty falls back to site default; truthy → on.
 * @param  string                 $redirect_type    Empty falls back to site default.
 * @param  boolean|integer|string $param_forwarding Empty or falsy → off.
 * @return integer|false The new link ID on success, false on failure.
 */
function prli_create_pretty_link( // phpcs:ignore Squiz.Commenting.FunctionComment.ScalarTypeHintMissing -- v3 contract: params are intentionally untyped to accept the loose values legacy callers pass.
    $target_url,
    $slug = '',
    $name = '',
    $description = '',
    $group_id = 0,
    $track_me = '',
    $nofollow = '',
    $sponsored = '',
    $redirect_type = '',
    $param_forwarding = ''
) {
    _deprecated_function(
        __FUNCTION__,
        '4.0.0',
        '\\PrettyLinks\\Repositories\\Links::save()'
    );

    $data = ['url' => (string) $target_url];
    if ($slug !== '') {
        $data['slug'] = (string) $slug;
    }
    if ($name !== '') {
        $data['name'] = (string) $name;
    }
    if ($description !== '') {
        $data['description'] = (string) $description;
    }
    if ($track_me !== '') {
        $data['track_me'] = $track_me ? 1 : 0;
    }
    if ($nofollow !== '') {
        $data['nofollow'] = $nofollow ? 1 : 0;
    }
    if ($sponsored !== '') {
        $data['sponsored'] = $sponsored ? 1 : 0;
    }
    if ($redirect_type !== '') {
        $data['redirect_type'] = (string) $redirect_type;
    }
    if ($param_forwarding !== '') {
        $data['param_forwarding'] = $param_forwarding ? 'on' : 'off';
    }

    $result = (new PrliLinksRepo())->save($data);
    if (isset($result['error'])) {
        return false;
    }
    return (int) $result['id'];
}

/**
 * V3 shim: update a pretty link. v3 used `-1` as a sentinel meaning
 * "don't touch this field" for name/description, and `''` for the others.
 * Both are preserved here. v3 compared these loosely; the two loose forms
 * legacy callers actually pass are also accepted: `null` counts as `''` for
 * url/slug/redirect_type, and the string `"-1"` counts as `-1`. The boolean
 * flags stay strict on `''`, as v3 had them.
 *
 * @param  integer                $id               Required.
 * @param  string|null            $target_url       Empty or null keeps existing.
 * @param  string|null            $slug             Empty or null keeps existing.
 * @param  string|integer|null    $name             -1 or "-1" keeps existing.
 * @param  string|integer|null    $description      -1 or "-1" keeps existing.
 * @param  integer                $group_id         DEPRECATED in v3; ignored.
 * @param  boolean|integer|string $track_me         Empty keeps existing.
 * @param  boolean|integer|string $nofollow         Empty keeps existing.
 * @param  boolean|integer|string $sponsored        Empty keeps existing.
 * @param  string|null            $redirect_type    Empty or null keeps existing.
 * @param  boolean|integer|string $param_forwarding Empty keeps existing.
 * @return boolean true on success, false on failure.
 */
function prli_update_pretty_link( // phpcs:ignore Squiz.Commenting.FunctionComment.ScalarTypeHintMissing -- v3 contract: params are intentionally untyped to accept the loose values legacy callers pass.
    $id,
    $target_url = '',
    $slug = '',
    $name = -1,
    $description = -1,
    $group_id = '',
    $track_me = '',
    $nofollow = '',
    $sponsored = '',
    $redirect_type = '',
    $param_forwarding = ''
): bool {
    _deprecated_function(
        __FUNCTION__,
        '4.0.0',
        '\\PrettyLinks\\Repositories\\Links::save()'
    );

    $id = (int) $id;
    if ($id <= 0) {
        return false;
    }

    $data = [];
    if ((string) $target_url !== '') {
        $data['url'] = (string) $target_url;
    }
    if ((string) $slug !== '') {
        $data['slug'] = (string) $slug;
    }
    if ((string) $name !== '-1') {
        $data['name'] = (string) $name;
    }
    if ((string) $description !== '-1') {
        $data['description'] = (string) $description;
    }
    if ($track_me !== '') {
        $data['track_me'] = $track_me ? 1 : 0;
    }
    if ($nofollow !== '') {
        $data['nofollow'] = $nofollow ? 1 : 0;
    }
    if ($sponsored !== '') {
        $data['sponsored'] = $sponsored ? 1 : 0;
    }
    if ((string) $redirect_type !== '') {
        $data['redirect_type'] = (string) $redirect_type;
    }
    if ($param_forwarding !== '') {
        $data['param_forwarding'] = $param_forwarding ? 'on' : 'off';
    }

    // Mirrors the create shim: the repo signals refusal with an `error` key,
    // and v3 callers expect a boolean. Returning true for a refused update
    // (a taken slug, a reserved slug, an invalid URL) reports success for a
    // link that did not change.
    $result = (new PrliLinksRepo())->save($data, $id);

    return is_array($result) && !isset($result['error']);
}

/**
 * V3 shim: return every pretty link as an array of associative arrays.
 * Matches v3's `prli_link->getAll('',' ORDER BY li.name', ARRAY_A)` shape
 * as closely as the v4 hydrate allows.
 *
 * @return array<int, array<string, mixed>>
 */
function prli_get_all_links(): array
{
    _deprecated_function(
        __FUNCTION__,
        '4.0.0',
        '\\PrettyLinks\\Repositories\\Links::search()'
    );

    // v3 returned every link with no stats, so page through the whole table
    // and skip the per-row click-count subquery v3's call never ran. Count
    // mode keeps the counters on: there they come from the static-clicks /
    // static-uniques meta JOINs (no prli_clicks read), because the
    // prli_links columns are never bumped.
    $repo       = new PrliLinksRepo();
    $withClicks = PrliLinksRepo::isCountMode();
    $out        = [];
    $page       = 1;
    do {
        $result = $repo->search([
            'per_page'    => 1000,
            'page'        => $page,
            'orderby'     => 'name',
            'order'       => 'asc',
            'with_clicks' => $withClicks,
        ]);
        foreach ($result['items'] as $item) {
            $out[] = prli_legacy_reshape_link($item);
        }
        $pages = $result['pages'];
        $page++;
    } while ($page <= $pages);
    return $out;
}

/**
 * V3 shim: look up a link by slug.
 *
 * @param  string  $slug          Link slug to look up.
 * @param  mixed   $return_type   OBJECT (stdClass), ARRAY_A (assoc), or ARRAY_N (numeric).
 * @param  boolean $include_stats Kept for signature compat — v4 always hydrates click/unique counts.
 * @return object|array<string, mixed>|array<int, mixed>|null
 */
function prli_get_link_from_slug($slug, $return_type = OBJECT, $include_stats = false) // phpcs:ignore Squiz.Commenting.FunctionComment.ScalarTypeHintMissing -- v3 contract: params are intentionally untyped to accept the loose values legacy callers pass.
{
    _deprecated_function(
        __FUNCTION__,
        '4.0.0',
        '\\PrettyLinks\\Repositories\\Links::findBySlug()'
    );

    $link = (new PrliLinksRepo())->findBySlug((string) $slug);
    return prli_legacy_format_return(prli_legacy_reshape_link($link), $return_type);
}

/**
 * V3 shim: look up a link by ID.
 *
 * @param  integer $id            Link ID to look up.
 * @param  mixed   $return_type   OBJECT (stdClass), ARRAY_A (assoc), or ARRAY_N (numeric).
 * @param  boolean $include_stats Signature compat only — v4 always includes stats.
 * @return object|array<string, mixed>|array<int, mixed>|null
 */
function prli_get_link($id, $return_type = OBJECT, $include_stats = false) // phpcs:ignore Squiz.Commenting.FunctionComment.ScalarTypeHintMissing -- v3 contract: params are intentionally untyped to accept the loose values legacy callers pass.
{
    _deprecated_function(
        __FUNCTION__,
        '4.0.0',
        '\\PrettyLinks\\Repositories\\Links::find()'
    );

    $link = (new PrliLinksRepo())->find((int) $id);
    return prli_legacy_format_return(prli_legacy_reshape_link($link), $return_type);
}

/**
 * V3 shim: return the fully-qualified pretty URL for a link.
 *
 * @param  integer $id Link ID to look up.
 * @return string|false URL on success, false if the link doesn't exist.
 */
function prli_get_pretty_link_url($id) // phpcs:ignore Squiz.Commenting.FunctionComment.ScalarTypeHintMissing -- v3 contract: param is intentionally untyped to accept the loose values legacy callers pass.
{
    _deprecated_function(
        __FUNCTION__,
        '4.0.0',
        '\\PrettyLinks\\Repositories\\Links::find()->pretty_url'
    );

    $link = prli_legacy_reshape_link((new PrliLinksRepo())->find((int) $id));
    if ($link === null) {
        return false;
    }
    return (string) ($link['pretty_url'] ?? '');
}

/**
 * Apply v3's return_type conventions to a hydrated v4 link array.
 *
 * @param  array<string, mixed>|null $link       Hydrated v4 link array, or null.
 * @param  mixed                     $returnType OBJECT | ARRAY_A | ARRAY_N.
 * @return object|array<string, mixed>|array<int, mixed>|null
 */
function prli_legacy_format_return($link, $returnType)
{
    if ($link === null) {
        return null;
    }
    if ($returnType === ARRAY_N) {
        return array_values($link);
    }
    if ($returnType === ARRAY_A) {
        return $link;
    }
    return (object) $link;
}

// Hook shim: v3 fired prli_record_click with an associative array after each
// click row was written. v4 fires prli_click_written with positional args.
// Re-emit the v3 hook so third-party code hooked to it keeps working.
add_action('prli_click_written', static function (int $linkId, int $clickId, string $url): void {
    do_action('prli_record_click', [
        'link_id'  => $linkId,
        'click_id' => $clickId,
        'url'      => $url,
    ]);
}, 10, 3);
