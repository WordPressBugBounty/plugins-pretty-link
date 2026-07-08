<?php

declare(strict_types=1);

namespace PrettyLinks\Admin;

// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
// phpcs:disable WordPress.Security.ValidatedSanitizedInput.MissingUnslash
// phpcs:disable WordPress.Security.NonceVerification.Recommended
// $_SERVER values (REMOTE_ADDR, HTTP_USER_AGENT, REQUEST_URI, etc.) are read for
// click tracking / targeting / UI rendering, not form-submission input. State-changing
// operations in this class protect with wp_verify_nonce / check_admin_referer.
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
use PrettyLinks\Admin\Upsell\ProUpsell;
use PrettyLinks\Integrations\CookieYes;
use PrettyLinks\Licensing\PlanCatalog;
use PrettyLinks\Licensing\ProState;
use PrettyLinks\Options\Store as OptionsStore;
use PrettyLinks\Support\HasStaticContainer;
use PrettyLinks\Support\StaticContainerAwareness;

/**
 * Enqueues the React bundle for the current Pretty Links admin page.
 *
 * Per REWRITE-PLAN.md §8: one React app per submenu. Bundles are built by
 *
 * @wordpress/scripts into `assets/js/build/<page>.{js,asset.php}` and
 * `assets/css/build/<page>.css`.
 */
class Assets implements StaticContainerAwareness
{
    use HasStaticContainer;

    /**
     * Admin hook-suffix → bundle handle map.
     *
     * Keys correspond to the `$hook_suffix` WordPress passes to
     * admin_enqueue_scripts. For top-level menu: `toplevel_page_pretty-link`.
     * For submenus: `{top_title_slug}_page_{submenu_slug}`.
     *
     * @var array<string, string>
     */
    private const BUNDLES = [
        'toplevel_page_pretty-link'               => 'dashboard',
        'pretty-links_page_pretty-link-links'     => 'links',
        'pretty-links_page_pretty-link-add-new'   => 'add-new',
        'pretty-links_page_pretty-link-pay-links' => 'pay-links',
        'pretty-links_page_pretty-link-clicks'    => 'clicks',
        'pretty-links_page_pretty-link-options'   => 'options',
        'pretty-links_page_pretty-link-addons'    => 'addons',
    ];

    public const SHARED_HANDLE = 'prli-shared';

    /**
     * Register (and enqueue) the `prli-shared` script + style handle.
     *
     * `prli-shared` is the externalized shared library that page bundles
     * resolve `@shared` against (see `webpack.config.js`). It MUST be on
     * the page before any page bundle, and it MUST be the script handle
     * `wp_localize_script` attaches `prliAdmin` to — `prli-shared`'s own
     * `bootstrap.js` snapshots `window.prliAdmin` at module-eval time, so
     * the localized data has to land in the DOM before the shared bundle
     * evaluates.
     *
     * Idempotent: safe to call from both Lite and Pro enqueue paths;
     * each call after the first short-circuits via wp_script_is(...).
     *
     * @param string|null $basePath Plugin base filesystem path; resolved from the container when null.
     * @param string|null $baseUrl  Plugin base URL; resolved from the container when null.
     */
    public static function enqueueSharedLibrary(?string $basePath = null, ?string $baseUrl = null): void
    {
        if (wp_script_is(self::SHARED_HANDLE, 'enqueued')) {
            return;
        }

        $basePath = $basePath ?? (string) self::getContainer()->get('BASE_PATH');
        $baseUrl  = $baseUrl  ?? (string) self::getContainer()->get('BASE_URL');

        $jsRel    = 'assets/js/build/shared.js';
        $cssRel   = 'assets/css/build/shared.css';
        $assetRel = 'assets/js/build/shared.asset.php';

        $asset   = is_file($basePath . $assetRel) ? require $basePath . $assetRel : null;
        $deps    = is_array($asset) && isset($asset['dependencies']) && is_array($asset['dependencies'])
            ? $asset['dependencies']
            : ['wp-element', 'wp-components', 'wp-i18n', 'wp-api-fetch', 'wp-data'];
        $version = is_array($asset) && isset($asset['version']) ? (string) $asset['version'] : \PrettyLinks\Bootstrap::version();

        if (is_file($basePath . $jsRel)) {
            wp_enqueue_script(
                self::SHARED_HANDLE,
                $baseUrl . $jsRel,
                $deps,
                $version,
                true
            );
            wp_set_script_translations(self::SHARED_HANDLE, 'pretty-link');
        }

        if (is_file($basePath . $cssRel)) {
            wp_enqueue_style(
                self::SHARED_HANDLE,
                $baseUrl . $cssRel,
                ['wp-components'],
                $version
            );
        }
    }

    /**
     * Enqueue the chrome styles plus the page bundle for the current admin screen.
     *
     * @param string $hookSuffix WordPress `$hook_suffix` for the current admin page.
     */
    public static function enqueue(string $hookSuffix): void
    {
        $basePath = self::getContainer()->get('BASE_PATH');
        $baseUrl  = self::getContainer()->get('BASE_URL');

        $chromeRel = 'assets/css/admin-chrome.css';
        if (is_file($basePath . $chromeRel)) {
            wp_enqueue_style(
                'prli-admin-chrome',
                $baseUrl . $chromeRel,
                [],
                \PrettyLinks\Bootstrap::version()
            );
        }

        $bundle = self::resolveBundle($hookSuffix);
        if ($bundle === null) {
            // Non-React Pretty Links admin screens (e.g. Add-ons) still need
            // the shared design tokens + brand bar + page chrome. Same
            // `prli-shared` style + script handle React pages depend on —
            // no separate `shell` bundle to keep in sync.
            if (strpos($hookSuffix, 'pretty-link') !== false) {
                self::enqueueSharedLibrary($basePath, $baseUrl);
            }
            return;
        }

        // Shared library MUST register before any page bundle. Page bundles
        // declare `@shared` as a webpack external resolved against
        // `window.prli.shared`, which only exists once `prli-shared` runs.
        self::enqueueSharedLibrary($basePath, $baseUrl);

        $jsRel     = "assets/js/build/{$bundle}.js";
        $cssRel    = "assets/css/build/{$bundle}.css";
        $assetRel  = "assets/js/build/{$bundle}.asset.php";
        $assetFile = $basePath . $assetRel;

        $asset = is_file($assetFile) ? require $assetFile : null;
        $deps  = is_array($asset) && isset($asset['dependencies']) && is_array($asset['dependencies'])
            ? $asset['dependencies']
            : ['wp-element', 'wp-components', 'wp-i18n', 'wp-api-fetch', 'wp-data'];
        // Ensure the shared library is always a dep of the page bundle.
        // The .asset.php emitted by @wordpress/scripts only sees imports
        // resolvable to npm packages or relative paths, so the `@shared`
        // external never makes it in there — we add it here.
        if (!in_array(self::SHARED_HANDLE, $deps, true)) {
            $deps[] = self::SHARED_HANDLE;
        }
        $version = is_array($asset) && isset($asset['version']) ? (string) $asset['version'] : \PrettyLinks\Bootstrap::version();

        $handle = 'prli-admin-' . $bundle;

        // The options page has a QR logo picker that uses wp.media(). Ensure
        // the media modal libs are enqueued on any admin page that may open
        // the picker — cheap and only fires on Pretty Links screens.
        if (function_exists('wp_enqueue_media')) {
            wp_enqueue_media();
        }

        if (is_file($basePath . $jsRel)) {
            wp_enqueue_script(
                $handle,
                $baseUrl . $jsRel,
                $deps,
                $version,
                true
            );
            wp_set_script_translations($handle, 'pretty-link');
        }

        if (is_file($basePath . $cssRel)) {
            wp_enqueue_style(
                $handle,
                $baseUrl . $cssRel,
                ['wp-components', self::SHARED_HANDLE],
                $version
            );
        }

        // Flags stylesheet for pages that render country flags in their
        // reports. Served statically from assets/vendor/flag-icons/.
        if (in_array($bundle, ['clicks', 'dashboard'], true)) {
            self::enqueueFlagIcons($basePath, $baseUrl);
        }

        // Attach prliAdmin to the shared handle: shared/bootstrap.js reads
        // window.prliAdmin at module-eval time, so the inline localize
        // tag has to land before the shared <script> — which only happens
        // when wp_localize_script targets the shared handle directly.
        wp_localize_script(
            self::SHARED_HANDLE,
            'prliAdmin',
            self::bootstrapData($bundle)
        );
    }

    /**
     * Enqueues the flag-icons stylesheet from assets/flag-icons/.
     * Public so Pro pages that render country flags (e.g. clicks-pro-extras)
     * can request the same stylesheet from their own enqueue path.
     *
     * @param string|null $basePath Plugin base filesystem path; resolved from the container when null.
     * @param string|null $baseUrl  Plugin base URL; resolved from the container when null.
     */
    public static function enqueueFlagIcons(?string $basePath = null, ?string $baseUrl = null): void
    {
        $basePath = $basePath ?? (string) self::getContainer()->get('BASE_PATH');
        $baseUrl  = $baseUrl ?? (string) self::getContainer()->get('BASE_URL');
        $rel      = 'assets/flag-icons/css/flag-icons.min.css';
        if (is_file($basePath . $rel)) {
            wp_enqueue_style(
                'prli-flag-icons',
                $baseUrl . $rel,
                [],
                '7.5.0'
            );
        }
    }

    /**
     * Build the `window.prliAdmin` bootstrap payload. Public so Pro
     * pages that don't have a Lite bundle (e.g. Split Test Reports) can
     * reuse the same shape from their own enqueue path — keeps the
     * frontend `bootstrap.js` reader uniform.
     *
     * @param  string $bundle Page bundle name the payload is being built for.
     * @return array<string, mixed>
     */
    public static function bootstrapData(string $bundle): array
    {
        $user    = wp_get_current_user();
        $options = get_option('prli_options');
        $mode    = is_array($options) && isset($options['extended_tracking'])
            ? (string) $options['extended_tracking']
            : 'normal';
        if (!in_array($mode, ['normal', 'extended', 'count'], true)) {
            $mode = 'normal';
        }
        $baseUrl = self::getContainer()->get('BASE_URL');
        $payload = [
            'page'                     => $bundle,
            'restRoot'                 => esc_url_raw(rest_url('pretty-links/v1/')),
            'restNonce'                => wp_create_nonce('wp_rest'),
            'assetsUrl'                => esc_url_raw($baseUrl . 'assets/'),
            'adminUrl'                 => esc_url_raw(admin_url()),
            'homeUrl'                  => esc_url_raw(home_url('/')),
            // Plain (empty) permalink structure can't route /slug URLs to WP.
            // The admin UI uses this to swap the Add/Edit Link forms for a
            // blocking notice until the site switches to a pretty structure.
            'hasPrettyPermalinks'      => (string) get_option('permalink_structure') !== '',
            'permalinksUrl'            => esc_url_raw(admin_url('options-permalink.php')),
            /**
             * Base URL used by the admin UI when previewing a pretty link
             * (e.g. the slug field's live preview). Routed through
             * `LinkUrl::build('')` so it reflects any permalink-structure
             * prefix (e.g. `/index.php/` on almost-pretty hosts) and
             * honors the `prli_pretty_link_base` filter that plugins
             * (like Pro's alternate shortlink domain) hook into.
             */
            'prettyLinkBase'           => esc_url_raw(
                trailingslashit(\PrettyLinks\Helpers\LinkUrl::build(''))
            ),
            'trackingMode'             => $mode,
            'capability'               => Page::capability(),
            'currentUser'              => [
                'id'    => (int) ($user->ID ?? 0),
                'name'  => (string) ($user->display_name ?? ''),
                'email' => (string) ($user->user_email ?? ''),
            ],
            'version'                  => \PrettyLinks\Bootstrap::version(),
            'redirectTypes'            => self::redirectTypes(),
            'deprecatedRedirectTypes'  => self::deprecatedRedirectTypes(),
            'legacyRedirectTypeLabels' => self::legacyRedirectTypeLabels(),
            // Pro-only redirect types surfaced on Lite installs so the
            // redirect-type dropdown visually hints at the paid
            // feature set. Empty when Pro is installed (the real
            // types override these via prli_redirect_types).
            'lockedRedirectTypes'      => self::lockedRedirectTypes(),
            // Distinct redirect_type values present across non-trashed
            // links. The Links list merges this with `redirectTypes` /
            // `deprecatedRedirectTypes` so the redirect-type filter
            // dropdown surfaces legacy values (e.g. `307`) only when
            // at least one link still uses them.
            'usedRedirectTypes'        => self::usedRedirectTypes(),
            'dateFormat'               => get_option('date_format', 'Y-m-d'),
            'timeFormat'               => get_option('time_format', 'H:i'),
            'gmtOffset'                => (float) get_option('gmt_offset', 0),
            'timezone'                 => wp_timezone_string(),
            'notices'                  => Notices::activeForScreen(),
            // Eligibility gate for the JS-rendered review-ask notice.
            // Cookie-based dismiss on the client is the caching safety net;
            // usermeta is the durable store (see Admin\ReviewNotice).
            'reviewAsk'                => ReviewNotice::isEligible(),
            'linkDefaults'             => self::linkDefaults(),
            'preferences'              => self::preferences((int) ($user->ID ?? 0)),
            'brand'                    => [
                'name'       => 'Pretty Links',
                'logo'       => esc_url_raw($baseUrl . 'assets/images/logo-horizontal.svg'),
                'logoWhite'  => esc_url_raw($baseUrl . 'assets/images/logo-horizontal-white.svg'),
                'supportUrl' => 'https://prettylinks.com/support/',
                'docsUrl'    => 'https://prettylinks.com/docs/',
            ],
            'prefill'                  => self::pagePrefill($bundle),
            // Options page only — Tools section's CookieYes integration card
            // needs install detection and the current toggle state on first
            // paint.
            'cookieYes'                => $bundle === 'options'
                ? (new CookieYes(new OptionsStore()))->status()
                : null,
            // Stripe connection flag drives the PrettyPay™ UI: hides the
            // "Add new" header button and swaps the empty-state CTA to
            // "Connect Stripe" when the site isn't linked yet.
            'stripeConnected'          => \PrettyLinks\Stripe\Client::isConnectionActive(),
            // ProUpsell module — license state, pricing URL, feature
            // catalog. Empty `active: false` on paid/active installs.
            'upsell'                   => ProUpsell::bootstrapPayload(),
            // Canonical Pro-state snapshot. JS reads via `@shared/pro-state.js`.
            'proState'                 => [
                'installed'             => ProState::isProInstalled(),
                'installedAndActivated' => ProState::isProInstalledAndActivated(),
            ],
            'capabilities'             => [
                'phpVersion' => PHP_VERSION,
            ],
            // Add-ons that are present *and* active append their slugs
            // here via the `prli_admin_bootstrap` filter (each add-on's
            // Bootstrap hooks it once it loads). Inactive plugins don't
            // run any code, so they can't self-announce — that's why we
            // separately compute `installedInactiveAddons` below from
            // the filesystem against PlanCatalog's known slugs.
            'installedAddons'          => [],
            // Add-ons whose plugin file is on disk but currently
            // deactivated (so their PHP isn't loaded). Used by the
            // upsell stubs to suppress the "buy this" pitch — users
            // already have the bits, they just need to activate.
            'installedInactiveAddons'  => self::detectInstalledInactiveAddons(),
        ];

        /**
         * Filter: prli_admin_bootstrap
         *
         * Admin React bundle's `window.prliAdmin` payload. Plugins append
         * their own keys. Receives the bundle name so filters can be
         * page-scoped.
         *
         * @param array<string, mixed> $payload
         * @param string               $bundle
         */
        /**
         * Filtered bootstrap payload.
         *
         * @var array<string, mixed> $payload
         */
        $payload = apply_filters('prli_admin_bootstrap', $payload, $bundle);
        return $payload;
    }

    /**
     * Build the list of add-on slugs whose plugin file is present on
     * disk but currently *deactivated*. Active add-ons announce
     * themselves through the `prli_admin_bootstrap` filter from their
     * own Bootstrap class; deactivated ones don't run any code and
     * can't self-announce, so we detect them server-side by looking
     * for the canonical `<slug>/<slug>.php` plugin file under
     * `WP_PLUGIN_DIR`.
     *
     * Used by upsell stubs to swap the "Get this add-on" pitch for an
     * "Already installed — activate it" prompt.
     *
     * @return string[] Add-on slugs (matching `PlanCatalog::ADDON_*`).
     */
    private static function detectInstalledInactiveAddons(): array
    {
        if (!function_exists('is_plugin_active')) {
            include_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $inactive = [];
        foreach (array_keys(PlanCatalog::addons()) as $slug) {
            $pluginFile = $slug . '/' . $slug . '.php';
            $absPath    = WP_PLUGIN_DIR . '/' . $pluginFile;
            if (file_exists($absPath) && !is_plugin_active($pluginFile)) {
                $inactive[] = $slug;
            }
        }
        return $inactive;
    }

    /**
     * Currently selectable redirect types. Plugins extend via
     * `prli_redirect_types`; each entry is `['value' => string, 'label' => string]`.
     *
     * @return list<array{value: string, label: string}>
     */
    private static function redirectTypes(): array
    {
        /**
         * Filterable selectable redirect types.
         *
         * @var list<array{value: string, label: string}> $types
         */
        $types = apply_filters('prli_redirect_types', [
            [
                'value' => '302',
                'label' => __('302 Found', 'pretty-link'),
            ],
            [
                'value' => '301',
                'label' => __('301 Moved Permanently', 'pretty-link'),
            ],
        ]);
        return array_values($types);
    }

    /**
     * Pro-only redirect types surfaced as locked options on Lite so the
     * dropdown hints at the paid feature set. Hidden on Pro installs
     * because `prli_redirect_types` seeds them as real selectable entries
     * — we'd double up otherwise.
     *
     * @return list<array{value: string, label: string, featureId: string}>
     */
    private static function lockedRedirectTypes(): array
    {
        if (ProState::isProInstalled()) {
            return [];
        }
        return [
            [
                'value'     => 'cloak',
                'label'     => __('Cloaked redirect', 'pretty-link'),
                'featureId' => 'redirect-cloak',
            ],
            [
                'value'     => 'metarefresh',
                'label'     => __('Meta Refresh redirect', 'pretty-link'),
                'featureId' => 'redirect-metarefresh',
            ],
            [
                'value'     => 'javascript',
                'label'     => __('JavaScript redirect', 'pretty-link'),
                'featureId' => 'redirect-javascript',
            ],
            [
                'value'     => 'pixel',
                'label'     => __('Pixel redirect', 'pretty-link'),
                'featureId' => 'redirect-pixel',
            ],
            [
                'value'     => 'prettybar',
                'label'     => __('Pretty Bar redirect', 'pretty-link'),
                'featureId' => 'redirect-prettybar',
            ],
        ];
    }

    /**
     * Redirect types no longer offered for new selections but still rendered
     * in the dropdown for existing links so admins don't lose their setting.
     *
     * @return list<array{value: string, label: string}>
     */
    private static function deprecatedRedirectTypes(): array
    {
        /**
         * Filterable deprecated redirect types.
         *
         * @var list<array{value: string, label: string}> $types
         */
        $types = apply_filters('prli_deprecated_redirect_types', [
            [
                'value' => '307',
                'label' => __('307 Temporary Redirect (legacy)', 'pretty-link'),
            ],
        ]);
        return array_values($types);
    }

    /**
     * Labels for redirect-type values that can appear on an existing link
     * but aren't in the selectable list — e.g. `prettypay_link_stripe` is
     * set by pay-link forms, not picked manually. Keyed by value.
     *
     * @return array<string, string>
     */
    private static function legacyRedirectTypeLabels(): array
    {
        /**
         * Filterable legacy redirect-type labels.
         *
         * @var array<string, string> $labels
         */
        $labels = apply_filters('prli_legacy_redirect_type_labels', [
            'prettypay_link_stripe' => __('PrettyPay™ Link (Stripe)', 'pretty-link'),
        ]);
        return $labels;
    }

    /**
     * Distinct redirect_type values present in non-trashed links.
     *
     * Cheap single query — scans the redirect_type column without
     * joining anything else. Returns an empty list when no links exist
     * yet.
     *
     * @return string[]
     */
    private static function usedRedirectTypes(): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'prli_links';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_col(
            "SELECT DISTINCT redirect_type FROM {$table} WHERE deleted_at IS NULL AND redirect_type <> ''"
        );
        return is_array($rows)
            ? array_values(array_filter(array_map('strval', $rows)))
            : [];
    }

    /**
     * Site-wide link defaults exposed to the admin form. The React form seeds
     * its initial state from these on create so the admin sees — and saves —
     * the values they configured on the Options page. v3 baked these into the
     * new-link form the same way; without this seed the form would always
     * submit its hardcoded defaults and the options-page toggles would never
     * take effect.
     *
     * @return array{redirect_type: string, nofollow: bool, sponsored: bool, track_me: bool}
     */
    private static function linkDefaults(): array
    {
        $opts = (new \PrettyLinks\Options\Store())->all();
        return [
            'redirect_type' => (string) ($opts['link_redirect_type'] ?? '302'),
            'nofollow'      => (bool) ($opts['link_nofollow'] ?? false),
            'sponsored'     => (bool) ($opts['link_sponsored'] ?? false),
            'track_me'      => (bool) ($opts['link_track_me'] ?? true),
        ];
    }

    /**
     * User UI preferences read inline with the page load so the React
     * bundle starts with the saved values instead of fetching them via
     * REST on mount (which caused a visible refetch of the list on each
     * page load — see PreferencesController for the write path).
     *
     * @param  integer $userId Current user ID; returns an empty array when not positive.
     * @return array<string, mixed>
     */
    private static function preferences(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }
        $keys = ['links_visible_columns', 'links_per_page', 'clicks_per_page'];
        $out  = [];
        foreach ($keys as $key) {
            $metaKey = 'prli_pref_' . $key;
            if (!metadata_exists('user', $userId, $metaKey)) {
                continue;
            }
            $raw     = (string) get_user_meta($userId, $metaKey, true);
            $decoded = json_decode($raw, true);
            if ($decoded !== null) {
                $out[$key] = $decoded;
            }
        }
        return $out;
    }

    /**
     * Read form prefill fields from the querystring for pages that accept
     * them (currently only `add-new`). Each field is sanitized per its
     * semantic type. Empty/missing values are omitted so form state init
     * only sees keys the caller actually set.
     *
     * Partner plugins that drop the user on `admin.php?page=pretty-link-add-new`
     * use this to pre-populate fields instead of scraping React internals.
     * Documented querystring params: `prefill_url`, `prefill_name`,
     * `prefill_slug`. Additional fields can be added via the
     * `prli_admin_prefill` filter.
     *
     * @param  string $bundle Page bundle name; only `add-new` produces prefill values.
     * @return array<string, string>
     */
    private static function pagePrefill(string $bundle): array
    {
        if ($bundle !== 'add-new') {
            return [];
        }

        $prefill = [];

        if (isset($_GET['prefill_url'])) {
            $url = esc_url_raw(wp_unslash((string) $_GET['prefill_url']));
            if ($url !== '') {
                $prefill['url'] = $url;
            }
        }

        if (isset($_GET['prefill_name'])) {
            $name = sanitize_text_field(wp_unslash((string) $_GET['prefill_name']));
            if ($name !== '') {
                $prefill['name'] = $name;
            }
        }

        if (isset($_GET['prefill_slug'])) {
            $slug = sanitize_title(wp_unslash((string) $_GET['prefill_slug']));
            if ($slug !== '') {
                $prefill['slug'] = $slug;
            }
        }

        /**
         * Filter: prli_admin_prefill
         *
         * Per-page prefill values delivered to the React form via
         * `window.prliAdmin.prefill`. Keys must match LinkForm state keys
         * (url, name, slug, description, redirect_type, nofollow,
         * sponsored, track_me, new_window, param_forwarding). Values must
         * already be sanitized.
         *
         * @param array<string, string> $prefill
         * @param string                $bundle
         */
        $filtered = apply_filters('prli_admin_prefill', $prefill, $bundle);
        return is_array($filtered) ? $filtered : $prefill;
    }

    /**
     * Map an admin hook-suffix to its page bundle name.
     *
     * @param  string $hookSuffix WordPress `$hook_suffix` for the current admin page.
     * @return string|null Bundle name, or null when the screen has no bundle.
     */
    private static function resolveBundle(string $hookSuffix): ?string
    {
        if (isset(self::BUNDLES[$hookSuffix])) {
            return self::BUNDLES[$hookSuffix];
        }

        // Fallback: match any hook whose slug begins with `pretty-link`.
        foreach (self::BUNDLES as $knownHook => $bundle) {
            if ($hookSuffix === $knownHook) {
                return $bundle;
            }
        }
        return null;
    }
}
