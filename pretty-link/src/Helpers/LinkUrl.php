<?php

declare(strict_types=1);

namespace PrettyLinks\Helpers;

/**
 * Build the base URL used for rendering pretty links.
 *
 * Lite always returns `home_url()`-rooted URLs. The base is exposed via
 * the `prli_pretty_link_base` filter so plugins can swap in an alternate
 * shortlink domain (Pretty Links Pro's `use_prettylink_url` feature is
 * implemented that way).
 */
final class LinkUrl
{
    /**
     * Build a pretty URL for a given slug path. Pass `''` for just the base.
     *
     * @param string $path Slug path to append, or empty for the bare base URL.
     *
     * @return string Fully built pretty URL.
     */
    public static function build(string $path = ''): string
    {
        $slug = ltrim($path, '/');
        /**
         * Filter: prli_pretty_link_base
         *
         * The scheme+host (no trailing slash) used as the base for every
         * pretty URL. Defaults to `home_url()`. Plugins hooking can return
         * any URL — the slug path is appended after.
         *
         * @param string $base Default home URL.
         */
        $base = rtrim((string) apply_filters('prli_pretty_link_base', home_url()), '/');

        // Almost-pretty permalinks (`/index.php/%postname%/`) don't rewrite
        // bare paths to index.php at the web-server layer, so `/slug` 404s
        // before WP boots. Prepend `/index.php/` so the request reaches WP
        // and the redirect engine can resolve the slug. Slugs stay bare in
        // the DB — the prefix is applied only at render time.
        $structure = (string) get_option('permalink_structure');
        if (strpos($structure, '/index.php') !== false) {
            return $base . '/index.php/' . $slug;
        }

        return $base . '/' . $slug;
    }
}
