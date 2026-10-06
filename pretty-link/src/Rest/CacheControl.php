<?php

declare(strict_types=1);

namespace PrettyLinks\Rest;

use WP_REST_Request;

/**
 * Keeps the admin REST surface out of every page/edge cache we know how to
 * talk to.
 *
 * The v4 admin is a React app that reads and writes exclusively through
 * `/wp-json/pretty-links/v1/*`. Several hosts and caching plugins will happily
 * cache `/wp-json/` responses — LiteSpeed does it when "Cache REST API" and
 * "Cache Logged-in Users" are both on, and WP Engine's REST cache has done the
 * same — which makes the admin show empty link lists or missing click history,
 * and makes saves look like they didn't stick, while the database is perfectly
 * fine.
 *
 * WordPress core sends its own no-cache headers for authenticated REST
 * requests, but the caches above key off their own control headers and
 * constants rather than core's, so we have to opt out explicitly.
 *
 * Note that a full-page cache running from `advanced-cache.php` can serve a
 * response before WordPress ever loads, in which case nothing here runs. That
 * case is covered on the client instead: the admin appends a unique
 * cache-busting parameter to every read, so a URL-keyed cache has nothing to
 * match against. See `src-js/shared/api.js`.
 */
class CacheControl
{
    /**
     * Marks the current request uncacheable when it targets our admin REST
     * namespace, or another namespace added through the
     * `prli_rest_nocache_namespaces` filter.
     *
     * Hooked on `rest_pre_dispatch` — the earliest point at which the route is
     * known, which matters because some caches decide cacheability well before
     * the response is serialised.
     *
     * @param mixed           $result  Response to short-circuit with, if any.
     * @param \WP_REST_Server $server  The REST server instance.
     * @param WP_REST_Request $request The incoming REST request.
     *
     * @return mixed The unmodified `$result`.
     */
    public static function preventCaching($result, $server, WP_REST_Request $request)
    {
        unset($server);

        if (! self::isCovered(ltrim($request->get_route(), '/'))) {
            return $result;
        }

        self::defineConstants();
        self::sendHeaders();
        self::notifyCachingPlugins();

        return $result;
    }

    /**
     * Whether a route sits in a namespace we keep out of caches.
     *
     * `pretty-links/v1` is always covered. Add-ons that serve their own
     * namespace (a public API, say) add it through the filter, which can
     * extend the list but not remove our own namespace from it.
     *
     * @param string $route The request route, without its leading slash.
     *
     * @return boolean
     */
    private static function isCovered(string $route): bool
    {
        /**
         * Filters the extra REST namespaces that get Pretty Links' cache
         * opt-outs, on top of `pretty-links/v1`.
         *
         * @api
         *
         * @param string[] $namespaces Extra namespaces, e.g. `pl/v1`.
         */
        $extra      = apply_filters('prli_rest_nocache_namespaces', []);
        $namespaces = array_merge([Router::NAMESPACE], is_array($extra) ? $extra : []);

        foreach ($namespaces as $namespace) {
            if (! is_string($namespace)) {
                continue;
            }
            $namespace = trim($namespace, '/');
            if ($namespace !== '' && ($route === $namespace || strpos($route, $namespace . '/') === 0)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Defines the de facto standard opt-out constants.
     *
     * `DONOTCACHEPAGE` is honoured by WP Rocket, W3 Total Cache, WP Super
     * Cache, Comet Cache, Batcache, WP Engine and most managed hosts. The
     * object and database variants stop the same plugins from persisting the
     * query results behind the response.
     *
     * @return void
     */
    private static function defineConstants(): void
    {
        foreach (['DONOTCACHEPAGE', 'DONOTCACHEOBJECT', 'DONOTCACHEDB'] as $constant) {
            if (! defined($constant)) {
                define($constant, true);
            }
        }
    }

    /**
     * Sends the cache-control headers understood by servers, CDNs and proxies.
     *
     * Each of these is a different vendor's dialect of "do not store this":
     * `Cache-Control` for browsers and well-behaved proxies, `X-Accel-Expires`
     * for nginx fastcgi/proxy cache (Kinsta, most managed nginx stacks),
     * `X-LiteSpeed-Cache-Control` for the LiteSpeed server itself,
     * `Surrogate-Control` for Varnish/Fastly/Pantheon, and the
     * `CDN-Cache-Control` pair for Cloudflare and other tiered CDNs that
     * deliberately ignore the origin's `Cache-Control`.
     *
     * @return void
     */
    private static function sendHeaders(): void
    {
        if (headers_sent()) {
            return;
        }

        $headers = [
            'Cache-Control'              => 'no-cache, no-store, must-revalidate, max-age=0, private',
            'Pragma'                     => 'no-cache',
            'Expires'                    => 'Wed, 11 Jan 1984 05:00:00 GMT',
            'X-Accel-Expires'            => '0',
            'X-LiteSpeed-Cache-Control'  => 'no-cache',
            'Surrogate-Control'          => 'no-store',
            'CDN-Cache-Control'          => 'no-store',
            'Cloudflare-CDN-Cache-Control' => 'no-store',
        ];

        foreach ($headers as $name => $value) {
            header($name . ': ' . $value);
        }
    }

    /**
     * Fires the plugin-specific opt-outs for caches that don't read the
     * constants or the headers.
     *
     * @return void
     */
    private static function notifyCachingPlugins(): void
    {
        $reason = 'Pretty Links admin REST response';

        // LiteSpeed Cache: has its own control layer and will cache REST for
        // logged-in users when configured to. This is the documented opt-out.
        do_action('litespeed_control_set_nocache', $reason);

        // W3 Total Cache: belt and braces alongside DONOTCACHEPAGE.
        add_filter('w3tc_can_cache', '__return_false', PHP_INT_MAX);

        // Cache Enabler.
        add_filter('cache_enabler_bypass_cache', '__return_true', PHP_INT_MAX);

        // WP Rocket: honours DONOTCACHEPAGE, but this also skips its optimizer.
        add_filter('do_rocket_generate_caching_files', '__return_false', PHP_INT_MAX);

        // Batcache: cancels the current request's cache entry outright.
        global $batcache;
        if (is_object($batcache) && property_exists($batcache, 'cancel')) {
            $batcache->cancel = true;
        }

        // WP Engine: their mu-plugin exposes this to drop the page from the
        // server cache.
        if (function_exists('exclude_page_from_wpe_server_cache')) {
            exclude_page_from_wpe_server_cache();
        }
    }
}
