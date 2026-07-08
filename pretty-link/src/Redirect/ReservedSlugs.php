<?php

declare(strict_types=1);

namespace PrettyLinks\Redirect;

use PrettyLinks\Options\Store as OptionsStore;

/**
 * Wildcard-aware slug reservation check for non-admin-created links.
 *
 * The reservation gates link CREATION from untrusted sources (`user` and
 * `public`) by default — keeping those users from carving out URLs that
 * collide with WordPress core, REST, admin-ajax, config files, and
 * similar paths a site owner would never want hijacked. Admin-source
 * creation can also be gated by flipping the
 * `reserved_slugs_admins_bypass` option off (default: on, so admins
 * remain trusted).
 *
 * The engine itself doesn't consult this list — it just looks up
 * whatever slug was stored.
 *
 * Patterns use shell-style wildcards (`*` matches any run of characters),
 * match the full stored slug (including any path separators), and are
 * matched case-insensitively so `wp-admin` and `WP-Admin` both block.
 *
 * Filter `prli_reserved_slugs` receives the merged list — Lite hooks it
 * to append site-specific patterns from `prli_options.reserved_slug_patterns`
 * before plugins (and add-ons) get a turn.
 */
class ReservedSlugs
{
    /**
     * Register the option-extras listener on the `prli_reserved_slugs` filter.
     *
     * @return void
     */
    public static function bootstrap(): void
    {
        // Lite registers its own listener so the option-stored extras are
        // always honored, regardless of which add-ons are installed.
        add_filter('prli_reserved_slugs', [self::class, 'mergeOptionExtras']);
    }

    /**
     * Merge admin-configured extras from `prli_options.reserved_slug_patterns`
     * into the filter pipeline. Newline- or comma-separated input;
     * trimmed and empty-line-stripped before merging.
     *
     * @param  string[] $list Patterns accumulated by earlier filter callbacks.
     * @return string[] The list with option-configured extras appended.
     */
    public static function mergeOptionExtras(array $list): array
    {
        $raw = (string) (new OptionsStore())->get('reserved_slug_patterns', '');
        if ($raw === '') {
            return $list;
        }
        $parts  = preg_split('/[\r\n,]+/', $raw) ?: [];
        $extras = array_values(array_filter(array_map('trim', $parts), 'strlen'));
        if ($extras === []) {
            return $list;
        }
        return array_merge($list, $extras);
    }

    /**
     * Built-in wildcard patterns always applied regardless of plugin
     * additions. Covers WP core filenames, REST, admin-ajax, feeds,
     * rewrite bases, and common plugin conventions.
     *
     * @var string[]
     */
    private const DEFAULTS = [
        // WP core endpoints + admin surface.
        '*wp-admin*',
        '*wp-login*',
        '*wp-content*',
        '*wp-includes*',
        '*wp-config*',
        '*wp-json*',
        '*wp-cron*',
        '*wp-signup*',
        '*wp-activate*',
        '*wp-mail*',
        '*wp-blog-header*',
        '*wp-trackback*',
        '*wp-comments-post*',
        '*wp-links-opml*',
        '*admin-ajax*',
        '*admin-post*',
        '*xmlrpc*',
        // Static / discovery files.
        '*.php',
        '*.htaccess*',
        '*readme*',
        '*license*',
        '*sitemap*',
        '*robots*',
        '*favicon*',
        '*.well-known*',
        // WP default rewrite bases.
        'page',
        'page/*',
        'category',
        'category/*',
        'tag',
        'tag/*',
        'author',
        'author/*',
        'search',
        'search/*',
        // Feed / discovery rewrite bases.
        'feed',
        'feed/*',
        'rss',
        'rss/*',
        'rss2',
        'rss2/*',
        'atom',
        'atom/*',
        'comments',
        'comments/*',
        'trackback',
        'trackback/*',
        'embed',
        'embed/*',
    ];

    /**
     * Is the slug reserved for the given source?
     *
     * `'user'` and `'public'` sources are always checked. `'admin'` source
     * bypasses the check by default (matches v3 + the pre-extraction v4
     * default), but the site owner can disable bypass via
     * `prli_options.reserved_slugs_admins_bypass = false` to enforce the
     * patterns site-wide.
     *
     * @param  string $slug   The stored slug to test.
     * @param  string $source Creation source: 'admin', 'user', or 'public'.
     * @return boolean True when the slug is reserved for that source.
     */
    public static function isReserved(string $slug, string $source = 'admin'): bool
    {
        if ($slug === '') {
            return false;
        }
        if ($source === 'admin') {
            $bypass = (bool) (new OptionsStore())->get('reserved_slugs_admins_bypass', true);
            if ($bypass) {
                return false;
            }
        } elseif ($source !== 'user' && $source !== 'public') {
            // Unknown sources don't trigger the check — keeps the gate
            // narrow. Plugins that introduce their own source string can
            // call `isReserved($slug, 'user')` explicitly when they want
            // the same treatment as user-created links.
            return false;
        }
        foreach (self::patterns() as $pattern) {
            if (fnmatch($pattern, $slug, FNM_CASEFOLD)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Built-in list, exposed via `prli_reserved_slugs` for plugins that
     * need to append or remove patterns.
     *
     * @return string[]
     */
    public static function patterns(): array
    {
        /**
         * Merged reserved-slug patterns.
         *
         * @var string[] $filtered
         */
        $filtered = apply_filters('prli_reserved_slugs', self::DEFAULTS);
        return array_values(array_unique(array_filter(array_map('strval', $filtered))));
    }
}
