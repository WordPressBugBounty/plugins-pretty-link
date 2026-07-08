<?php

declare(strict_types=1);

namespace PrettyLinks\Integrations;

use PrettyLinks\Options\Store;

/**
 * One-click registration of Pretty Link's tracking cookies into the
 * CookieYes / Cookie Law Info plugin's cookie database so consent banners
 * accurately list them. See docs/COOKIES.md for the cookie catalog and
 * consent rationale.
 *
 * CookieYes does not expose a public registration filter, so we write
 * directly to its tables (`wp_cky_cookies`, `wp_cky_cookie_categories`)
 * and fire `cky_after_update_cookie` to flush their cache. Direct DB is
 * more robust against their internal class refactors than calling their
 * Cookie / Cookie_Controller pair.
 */
class CookieYes
{
    public const PLUGIN_FILE   = 'cookie-law-info/cookie-law-info.php';
    public const OPTION_KEY    = 'cookieyes_integration';
    public const COOKIE_DOMAIN = 'pretty-link';

    /**
     * Lite option store.
     *
     * @var Store
     */
    private $options;

    /**
     * Stores the option store dependency.
     *
     * @param Store $options Lite option store.
     */
    public function __construct(Store $options)
    {
        $this->options = $options;
    }

    /**
     * Returns whether the CookieYes plugin is active.
     *
     * @return boolean True when CookieYes is active.
     */
    public static function isInstalled(): bool
    {
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        return is_plugin_active(self::PLUGIN_FILE);
    }

    /**
     * Returns whether the integration toggle is enabled.
     *
     * @return boolean True when the integration is enabled.
     */
    public function isEnabled(): bool
    {
        return (bool) $this->options->get(self::OPTION_KEY, false);
    }

    /**
     * Returns the integration status, including registered cookies.
     *
     * @return array<string, mixed>
     */
    public function status(): array
    {
        $installed = self::isInstalled();
        return [
            'installed' => $installed,
            'enabled'   => $installed && $this->isEnabled(),
            'cookies'   => array_map(
                static fn(array $c) => [
                    'name'     => $c['name'],
                    'category' => $c['category'],
                ],
                self::cookies()
            ),
        ];
    }

    /**
     * Persist the toggle and apply or revoke the registration.
     *
     * @param boolean $enabled Whether the integration should be enabled.
     *
     * @return void
     */
    public function setEnabled(bool $enabled): void
    {
        $this->options->set(self::OPTION_KEY, $enabled);
        if (!self::isInstalled()) {
            return;
        }
        if ($enabled) {
            $this->apply();
        } else {
            $this->revoke();
        }
    }

    /**
     * Register all cookies. Idempotent — existing rows (matched by slug
     * inside our domain) are updated rather than duplicated.
     */
    public function apply(): void
    {
        global $wpdb;
        $cookies = self::cookies();
        foreach ($cookies as $cookie) {
            $categoryId = $this->categoryIdForSlug($cookie['category']);
            if ($categoryId === 0) {
                continue;
            }
            $existingId = $this->existingCookieId($cookie['slug']);
            $row        = [
                'name'          => $cookie['name'],
                'slug'          => $cookie['slug'],
                'description'   => wp_json_encode(['default' => $cookie['description']]),
                'duration'      => wp_json_encode($cookie['duration']),
                'domain'        => self::COOKIE_DOMAIN,
                'category'      => $categoryId,
                'type'          => $cookie['type'],
                'discovered'    => 0,
                'url_pattern'   => '',
                'meta'          => wp_json_encode([]),
                'date_modified' => current_time('mysql'),
            ];
            $formats    = ['%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%s'];
            if ($existingId > 0) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Writing to CookieYes's own table; no API exists and a write cannot be cached.
                $wpdb->update($wpdb->prefix . 'cky_cookies', $row, ['cookie_id' => $existingId], $formats, ['%d']);
            } else {
                $row['date_created'] = current_time('mysql');
                $formats[]           = '%s';
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Writing to CookieYes's own table; no API exists and a write cannot be cached.
                $wpdb->insert($wpdb->prefix . 'cky_cookies', $row, $formats);
            }
        }
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- CookieYes's own hook; fired so CookieYes flushes its cache after we touch its table.
        do_action('cky_after_update_cookie');
    }

    /**
     * Remove every cookie this integration owns (matched by domain), so a
     * disabled integration leaves no orphan rows.
     */
    public function revoke(): void
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Deleting from CookieYes's own table; no API exists and a write cannot be cached.
        $wpdb->delete(
            $wpdb->prefix . 'cky_cookies',
            ['domain' => self::COOKIE_DOMAIN],
            ['%s']
        );
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- CookieYes's own hook; fired so CookieYes flushes its cache after we touch its table.
        do_action('cky_after_update_cookie');
    }

    /**
     * Looks up the CookieYes cookie ID for one of our slugs.
     *
     * @param string $slug Cookie slug to look up.
     *
     * @return integer Matching cookie ID, or zero when not found.
     */
    private function existingCookieId(string $slug): int
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reading CookieYes's own table at settings-sync time; CookieYes owns and mutates this data, so a local cache would go stale.
        $id = $wpdb->get_var($wpdb->prepare(
            "SELECT cookie_id FROM {$wpdb->prefix}cky_cookies WHERE slug = %s AND domain = %s LIMIT 1",
            $slug,
            self::COOKIE_DOMAIN
        ));
        return (int) $id;
    }

    /**
     * Resolves a CookieYes category ID from its slug.
     *
     * @param string $slug Category slug to look up.
     *
     * @return integer Matching category ID, or zero when not found.
     */
    private function categoryIdForSlug(string $slug): int
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reading CookieYes's own table at settings-sync time; CookieYes owns and mutates this data, so a local cache would go stale.
        $id = $wpdb->get_var($wpdb->prepare(
            "SELECT category_id FROM {$wpdb->prefix}cky_cookie_categories WHERE slug = %s LIMIT 1",
            $slug
        ));
        return (int) $id;
    }

    /**
     * Catalog of Pretty Link cookies registered in CookieYes. Mirrors
     * docs/COOKIES.md — keep in sync.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function cookies(): array
    {
        return [
            [
                'name'        => 'prli_visitor',
                'slug'        => 'prli_visitor',
                'category'    => 'analytics',
                'type'        => 'http',
                'duration'    => ['days' => 365],
                'description' => __(
                    'Pretty Links sets this cookie to assign each visitor a stable identifier so the plugin can count distinct visitors across clicks.',
                    'pretty-link'
                ),
            ],
            [
                'name'        => 'prli_click_*',
                'slug'        => 'prli_click',
                'category'    => 'analytics',
                'type'        => 'http',
                'duration'    => ['days' => 30],
                'description' => __(
                    'Pretty Links sets one of these cookies (one per link, with the link ID appended to the name) the first time a visitor clicks each link, so the unique-click counter is not inflated by repeat visits.',
                    'pretty-link'
                ),
            ],
            [
                'name'        => 'prli_dedup_*',
                'slug'        => 'prli_dedup',
                'category'    => 'functional',
                'type'        => 'http',
                'duration'    => ['minutes' => 1],
                'description' => __(
                    'Pretty Links sets one of these short-lived cookies on every redirect to suppress duplicate click rows from rapid browser retries, prefetch, or accidental double-clicks.',
                    'pretty-link'
                ),
            ],
        ];
    }
}
