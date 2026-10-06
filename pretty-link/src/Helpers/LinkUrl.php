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

    /**
     * Whether `$target` would route straight back to the pretty link for
     * `$slug`, i.e. the link redirects to itself and loops forever.
     *
     * v3 made the same check at save time (PrliLink::validate() — "Target URL
     * must be different than the Pretty Link") but compared the raw strings,
     * so a single trailing slash defeated it. The redirect engine trims
     * slashes off the request path before matching (Engine::extractSlug()),
     * which means `/docs` and `/docs/` are the same link to us and the naive
     * compare was never enough. See issue #983.
     *
     * Both the home URL and the `prli_pretty_link_base` filter (Pro's
     * alternate shortlink domain) are treated as our own, because a request
     * arriving on either one resolves through the same engine.
     *
     * @param string $target The resolved target URL.
     * @param string $slug   The link's own slug.
     *
     * @return bool True when following the target re-enters this same link.
     */
    public static function isSelfReferential(string $target, string $slug): bool
    {
        $comparableTarget = self::comparable($target);
        if ($comparableTarget === '' || $slug === '') {
            return false;
        }

        $bases = [
            (string) home_url(),
            (string) apply_filters('prli_pretty_link_base', home_url()),
        ];
        foreach ($bases as $base) {
            $own = rtrim($base, '/') . '/' . ltrim($slug, '/');
            if ($comparableTarget === self::comparable($own)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Reduce a URL to `host/path` for self-reference comparison, applying the
     * same normalisations the redirect engine applies to an incoming request:
     * lowercased host, percent-decoding, surrounding slashes trimmed, and the
     * almost-pretty-permalink `index.php/` prefix stripped. Query string and
     * fragment are dropped — they do not change which slug the engine matches,
     * so a target of `/docs/?utm=x` loops just as readily as `/docs/`.
     *
     * Returns `''` for a URL with no host, which callers treat as "not ours".
     *
     * @param string $url URL to reduce.
     *
     * @return string Normalised `host/path`, or `''` when there is no host.
     */
    private static function comparable(string $url): string
    {
        $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
        if ($host === '') {
            return '';
        }

        $path = trim(rawurldecode((string) wp_parse_url($url, PHP_URL_PATH)), '/');
        if (strpos($path, 'index.php/') === 0) {
            $path = substr($path, strlen('index.php/'));
        }

        return $host . '/' . $path;
    }
}
