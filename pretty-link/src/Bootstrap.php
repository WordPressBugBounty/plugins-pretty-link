<?php

declare(strict_types=1);

namespace PrettyLinks;

use PrettyLinks\Admin\AdminBar;
use PrettyLinks\Admin\Assets as AdminAssets;
use PrettyLinks\Admin\Dashboard as AdminDashboard;
use PrettyLinks\Admin\Notices;
use PrettyLinks\Admin\MigrationHealth;
use PrettyLinks\Admin\ReviewNotice;
use PrettyLinks\Admin\Page;
use PrettyLinks\Admin\PluginRow;
use PrettyLinks\Admin\Upsell\ProUpsell;
use PrettyLinks\Admin\TopBar;
use PrettyLinks\Admin\Pages\AddNew as AddNewPage;
use PrettyLinks\Admin\Pages\Addons as AddonsPage;
use PrettyLinks\Admin\Upsell\CustomReportsLocked as CustomReportsLockedPage;
use PrettyLinks\Admin\Upsell\DeveloperToolsLocked as DeveloperToolsLockedPage;
use PrettyLinks\Admin\Upsell\LinkInBioLocked as LinkInBioLockedPage;
use PrettyLinks\Admin\Upsell\ProductDisplaysLocked as ProductDisplaysLockedPage;
use PrettyLinks\Admin\Pages\Clicks as ClicksPage;
use PrettyLinks\Admin\Pages\Links as LinksPage;
use PrettyLinks\Admin\Pages\Onboarding as OnboardingPage;
use PrettyLinks\Admin\Pages\WhatsNew as WhatsNewPage;
use PrettyLinks\Admin\Pages\Options as OptionsPage;
use PrettyLinks\Admin\Pages\PayLinks as PayLinksPage;
use PrettyLinks\Clicks\Cleaner;
use PrettyLinks\Compat\AddonCompatibilityGuard;
use PrettyLinks\Database\Migrator;
use PrettyLinks\Redirect\ReservedSlugs;
use PrettyLinks\Editor\ClassicEditor;
use PrettyLinks\Editor\GutenbergEditor;
use PrettyLinks\I18n\ScriptTranslations;
use PrettyLinks\GroundLevel\Container\Container;
use PrettyLinks\GroundLevel\Database\DatabaseServiceProvider;
use PrettyLinks\GroundLevel\Events\EventsServiceProvider;
use PrettyLinks\GroundLevel\InProductNotifications\IPNServiceProvider;
use PrettyLinks\GroundLevel\Mothership\AbstractPluginConnection;
use PrettyLinks\GroundLevel\Mothership\Manager\AddonsManager;
use PrettyLinks\GroundLevel\Mothership\MothershipServiceProvider;
use PrettyLinks\Addons\AddonInstaller;
use PrettyLinks\GroundLevel\Package\Bootstrap as BaseBootstrap;
use PrettyLinks\GroundLevel\Resque\ResqueServiceProvider;
use PrettyLinks\GroundLevel\Support\Models\Hook;
use PrettyLinks\GrowthTools\Loader as GrowthToolsLoader;
use PrettyLinks\Install\Activator;
use PrettyLinks\Install\Deactivator;
use PrettyLinks\Licensing\AuthClient;
use PrettyLinks\Licensing\EditionMismatch;
use PrettyLinks\Licensing\LicenseManager;
use PrettyLinks\Licensing\MothershipConnector;
use PrettyLinks\Onboarding\FirstRunRedirect;
use PrettyLinks\Onboarding\WhatsNew;
use PrettyLinks\Onboarding\Wizard as OnboardingWizard;
use PrettyLinks\Options\Store as OptionsStore;
use PrettyLinks\Redirect\ClickDataWiper;
use PrettyLinks\Redirect\Engine as RedirectEngine;
use PrettyLinks\Redirect\GeoBackfillJob;
use PrettyLinks\Redirect\HostBackfillJob;
use PrettyLinks\Rest\CacheControl as RestCacheControl;
use PrettyLinks\Rest\Router as RestRouter;
use PrettyLinks\Shortcodes\Loader as ShortcodesLoader;
use PrettyLinks\Stripe\CheckoutRedirect as StripeCheckoutRedirect;
use PrettyLinks\Stripe\ConnectAjax as StripeConnectAjax;
use PrettyLinks\Stripe\CustomerPortal as StripeCustomerPortal;
use PrettyLinks\Stripe\InvoiceRenderer as StripeInvoiceRenderer;
use PrettyLinks\Updates\InPluginMessage;
use PrettyLinks\Updates\AddonCatalog;
use PrettyLinks\Updates\AddonInformation;
use PrettyLinks\Updates\AutoUpdatePolicy;
use PrettyLinks\Updates\PluginInformation;

/**
 * Plugin bootstrap.
 */
class Bootstrap extends BaseBootstrap
{
    /**
     * Register container parameters, providers, services, and the
     * Mothership-to-`prli_*` event bridges that wire up licensing and
     * add-on management. Runs once during plugin bootstrap.
     *
     * @return void
     */
    public function init(): void
    {
        global $wpdb;

        $container = $this->container();

        // IPN + GroundLevel Database/Events/Resque parameters. Lowercase
        // dot-notation keys must be set directly on the container —
        // PluginConfig::toArray() values get uppercased by configToParams()
        // and would never match these provider parameter names.
        //
        // Events/Resque table prefixes intentionally match the v3 Pretty
        // Links Developer Tools add-on (`prlidt`, `prlidt_resque`) so an
        // existing customer site upgrading from v3 keeps its
        // `wp_prlidt_events` and `wp_prlidt_resque_*` tables in place — the
        // schema versions are tracked per-Database, so dbDelta only applies
        // additive ALTERs forward to v5 column shape.
        $container->parameters([
            IPNServiceProvider::PARAM_PRODUCT_SLUG    => 'pretty-links',
            IPNServiceProvider::PARAM_PREFIX          => 'prli_',
            IPNServiceProvider::PARAM_RENDER_HOOK     => TopBar::IPN_RENDER_HOOK,
            IPNServiceProvider::PARAM_MENU_SLUG       => Page::SLUG,
            IPNServiceProvider::PARAM_THEME           => [
                'primaryColor'       => '#01aae9',
                'primaryColorDarker' => '#0d459c',
            ],
            DatabaseServiceProvider::PARAM_CONNECTION => $wpdb,
            DatabaseServiceProvider::PARAM_PREFIX     => 'prli',
            EventsServiceProvider::PARAM_PREFIX       => 'prlidt',
            ResqueServiceProvider::PARAM_DB_PREFIX    => 'prlidt_resque',
            ResqueServiceProvider::PARAM_PREFIX       => 'prlidt_resque',
        ]);

        // Mothership plugin connection. The connector is the single source of
        // truth for how Pretty Links stores credentials, which product to check
        // for updates, and update behavior. ground-level-mothership owns license
        // activation, plugin/add-on updates, and add-on management off the back
        // of it (see Licensing\LicenseManager + the REST controllers, which
        // delegate to the GL services).
        $container->singleton(
            AbstractPluginConnection::class,
            static function (): AbstractPluginConnection {
                return new MothershipConnector();
            }
        );

        // Register Mothership explicitly (IPN also depends on it; the container
        // dedupes provider registration). Defaults the API base URL to the new
        // licenses server; a PRLI_MOTHERSHIP_API_BASE_URL constant overrides it
        // for staging (read by Mothership\Util::getApiBaseUrl()).
        $container->provider(MothershipServiceProvider::class);

        // Lite is the WordPress.org build, and GL's license manager hangs a
        // paid-license nag off the Plugins screen: "Please activate your
        // license to download this update. Visit your account dashboard",
        // printed by appendLicenseUpdateMessage() for any update row with an
        // empty package. v5 had neither the hook nor the method, so this is
        // new chrome, and upselling a licence from a free wp.org plugin's
        // update row is exactly the kind of thing Plugin Check and reviewers
        // object to. Pro keeps it — there the message is accurate and useful.
        // Deferred to admin_init because MothershipServiceProvider::boot()
        // registers GL's hooks after this line runs — and admin_init is still
        // well before the Plugins screen renders its update rows.
        add_action('admin_init', [$container->get(LicenseManager::class), 'removeLiteUpdateNag'], 1);
        add_action('admin_init', [$container->get(LicenseManager::class), 'maybeSyncReleaseChannel'], 1);
        // `init`, not `admin_init`: the latter never fires under cron or WP-CLI,
        // which is exactly where a staging box silently talks to production
        // licensing with nobody watching an admin screen.
        add_action('init', [$container->get(LicenseManager::class), 'warnOnRetiredHostConstant'], 1);

        // GL answers plugins_api for our slug whether or not its version check
        // succeeded, and core stops at the first non-false answer — so a failed
        // lookup renders an empty "View details" modal. Registered from Lite
        // because that plugins_api hook is not gated by the Update URI header.
        (new PluginInformation())->register();

        // GL's LegacyUpdateService gives every add-on update row a slug, so
        // WordPress renders a "View details" link — and sends it to wp.org,
        // where our add-ons don't exist. develop had AddonUpdateChecker
        // answering plugins_api for those slugs; nothing replaced it.
        $addonInformation = new AddonInformation();
        $addonInformation->register();

        // GL's auto-update policy enum has no "defer to the site" value, so
        // whichever value the connector returns, activating a licence takes the
        // Plugins-screen "Enable auto-updates" toggle out of the user's hands.
        // This hands it back for every slug GL answers for.
        $autoUpdates = new AutoUpdatePolicy($container->get(AbstractPluginConnection::class));
        $autoUpdates->claim('pretty-link');
        $autoUpdates->register();

        // An installed edition that doesn't match the licence must not be
        // updated from the licensed product's package — v3 blanked the URL and
        // EditionMismatch's docblock still promised it, but the two classes
        // that used to do it went with the migration. The licensed edition has
        // its own route: the mismatch notice's `install_plugins`-gated CTA.
        add_filter(
            'site_transient_update_plugins',
            [EditionMismatch::class, 'blankMismatchedPackages'],
            20
        );

        // Surface the parent plugin's edition-mismatch warning on each add-on's
        // own plugin-update row, as develop did from AddonUpdateChecker. Auto
        // updates can't work while the installed edition doesn't match the
        // licence, and this row is the user's breadcrumb to fixing it — the
        // main plugin's row alone is easy to miss in a long plugin list.
        // Deferred to admin_init so the catalog transient is warm and the work
        // stays off the front end; the Plugins screen renders well after.
        add_action('admin_init', [EditionMismatch::class, 'registerAddonRows'], 5);

        // GL fires this so add-ons can register themselves for update checks,
        // and nothing in the repo listens. Meanwhile LegacyUpdateService skips
        // exactly the add-ons that declare their own `Update URI`
        // (hasUpdateUri()), on the assumption they are handled here. So an
        // add-on shipping that header got updates from neither path.
        //
        // Registration is deliberately limited to add-ons that are installed
        // AND whose `Update URI` header resolves to their own plugin directory
        // — the only case where core actually calls
        // `update_plugins_{hostname}` for them (see wp_update_plugins(): the
        // dynamic segment is the *host* of the header, and for a bare token
        // like `pretty-link-splash-pages` that host is the token itself).
        // Registering anything else would be worse than useless: GL's
        // UpdateService::plugin() also adds `plugins_api` and
        // `auto_update_plugin` filters, and its plugins_api handler ignores the
        // incoming result and calls the licensing server before checking
        // success — so arming it for a product that needs no update filter puts
        // a blocking network request on the "View details" modal and lets a
        // failed lookup overwrite the cache-answered payload from
        // AddonInformation. (For the add-ons that DO need it, that second
        // problem is handled below; the blocking request is inherent.)
        //
        // The bare-token form is the only header shape this path supports, and
        // that is now written down (docs/ADDONS.md). GL keys its registry on
        // the header's host, so two add-ons sharing a URL-form host —
        // `https://prettylinks.com/…` — would collapse to one registration and
        // one product id. Add-ons on that shape stay on the legacy transient
        // path instead, which a strauss fixup stops hasUpdateUri() from
        // skipping; see bin/strauss-fixups.php.
        add_action('pretty-link_register_addons', static function ($registrar) use (
            $addonInformation,
            $autoUpdates
        ): void {
            if (!is_object($registrar) || !method_exists($registrar, 'plugin')) {
                return;
            }

            // GL dispatches this from `init` on every request. Nothing reads
            // `update_plugins_*` or `plugins_api` on the front end, and the
            // header check below stats and reads a file per catalog entry, so
            // keep that work off the redirect path.
            if (!is_admin() && !wp_doing_cron() && !(defined('WP_CLI') && WP_CLI)) {
                return;
            }

            foreach (AddonCatalog::cached() as $product) {
                $mainFile = (string) ($product->main_file ?? '');
                $slug     = (string) ($product->slug ?? '');

                if ($mainFile === '' || $slug === '') {
                    continue;
                }

                $directory = dirname($mainFile);
                if ($directory === '.' || $directory === '' || $directory === DIRECTORY_SEPARATOR) {
                    continue;
                }

                $file = WP_PLUGIN_DIR . '/' . $mainFile;
                if (!is_readable($file)) {
                    continue;
                }

                $headers   = get_file_data($file, ['UpdateURI' => 'Update URI'], 'plugin');
                $updateUri = isset($headers['UpdateURI']) ? (string) $headers['UpdateURI'] : '';
                if ($updateUri === '') {
                    continue;
                }

                if (wp_parse_url(sanitize_url($updateUri), PHP_URL_HOST) !== $directory) {
                    continue;
                }

                $registrar->plugin($directory, $slug);

                // Registering arms GL's own plugins_api handler for this slug,
                // and it runs second: it ignores the incoming result and, when
                // its version check fails, returns a bare `['slug' => …]`
                // stub — replacing the payload AddonInformation just answered
                // from cache with the blank modal PluginInformation exists to
                // prevent for the host plugin. GL runs that stub through a
                // per-slug filter on the way out, which is the seam.
                $addonInformation->guard($directory);
                $autoUpdates->claim($directory);
            }
        });

        // Bridge ground-level-mothership's `pretty-link_*` license lifecycle
        // events to the historical `prli_*` actions that Pretty Links add-ons
        // and internal listeners hook. Edition transitions on activate/
        // deactivate are fired separately by Licensing\LicenseManager.
        //
        // GL passes ($activation, $licenseKey, $domain) — and ($response,
        // $licenseKey, $domain, $freed) on deactivate. Only the first argument
        // crosses this boundary: 3.x and develop both passed the payload and
        // add-ons read it, but forwarding the key would hand the full license
        // key to every plugin on the site for the price of a one-line
        // add_action.
        // The payload is normalized to an array: 3.x and develop both passed
        // arrays, and GL's Api\Response implements Arrayable only — no
        // ArrayAccess — so handing it over raw makes `$activation['status']` a
        // fatal in any listener written against the documented shape.
        add_action('pretty-link_license_activated', static function ($activation = null): void {
            $payload = LicenseManager::actionPayload($activation);
            // Fired first so listeners can drop cached update data before
            // anything rebuilds it, which is the ordering they had on develop.
            do_action('prli_license_activated_before_queue_update', $payload);
            do_action('prli_license_activated', $payload);
        }, 10, 1);
        add_action('pretty-link_license_deactivated', static function ($response = null): void {
            $payload = LicenseManager::actionPayload($response);
            do_action('prli_license_deactivated_before_queue_update', $payload);
            do_action('prli_license_deactivated', $payload);
        }, 10, 1);

        // Expiry/invalidation come from GL's status cron. Bridge them to the
        // historical `prli_license_*` actions, forwarding GL's $activation
        // payload so listeners can read the revoked product. Pro's UpdateChecker
        // hooks these directly to clear WP's update cache — GL's onLicenseRevoked
        // doesn't, and edition() can't detect the change on a Pro build (it falls
        // back to PRLI_EDITION once the activation transient is gone).
        foreach (
            [
                'pretty-link_active_license_expired'     => 'prli_license_expired',
                'pretty-link_active_license_invalidated' => 'prli_license_invalidated',
            ] as $glAction => $prliAction
        ) {
            add_action($glAction, static function ($activation = null) use ($prliAction): void {
                do_action($prliAction, LicenseManager::actionPayload($activation));
            }, 10, 1);
        }

        // Onboarding queue drain: install a queued add-on by slug. The wizard
        // queues Pro add-ons checked during setup and drains them at the Finish
        // step (Wizard::drainQueue) once a license is active — this listener
        // performs the actual install via the shared AddonInstaller. Without it
        // queued add-ons would stay queued forever.
        add_filter('prli_onboarding_drain_addon', static function ($installed, $slug) use ($container) {
            if (null !== $installed) {
                return $installed;
            }
            // The wizard itself only gates on `manage_options` (Page::capability()),
            // but this listener installs and activates plugins — exactly what the
            // REST twin requires `install_plugins` + `activate_plugins` for.
            // Those two are not decoration: DISALLOW_FILE_MODS and multisite's
            // "super admins only" rule are both enforced solely inside
            // map_meta_cap(), so gating on `manage_options` alone silently
            // bypasses both. Report a hard failure so the slug stays queued and
            // the Finish step tells the user to install it manually.
            if (!current_user_can('install_plugins') || !current_user_can('activate_plugins')) {
                return false;
            }
            $manager = $container->get(AddonsManager::class);
            $addon   = $manager->getAddon((string) $slug);

            // The cache can predate this license's entitlements — the drain runs
            // right after activation. A stale cache does not return null for a
            // newly entitled add-on, though: it returns the row it already had,
            // typed `upgrade-addon`. Checking only for null meant the refetch
            // never fired in the one case it exists for, and the closure went
            // straight to reporting a hard failure — for the cache's remaining
            // hour, immediately after the customer upgraded. So refetch on
            // either shape, and only then decide.
            if (null === $addon || ($addon->type ?? '') === 'upgrade-addon') {
                $addon = $manager->getAddon((string) $slug, false);
            }

            if (null !== $addon && ($addon->type ?? '') === 'upgrade-addon') {
                // The current license doesn't entitle this add-on (it came back
                // as an upsell, not a downloadable product) — mirror the REST
                // install path's guard and stop retrying a download we can't do.
                return false;
            }
            $url = isset($addon->version->url) ? (string) $addon->version->url : '';
            if ('' === $url) {
                // Unresolved (cache/transient miss, or no downloadable version
                // yet) — return null to leave it queued for a later retry rather
                // than marking it a hard failure.
                return null;
            }
            $result = (new AddonInstaller())->installFromUrl($url, (string) ($addon->main_file ?? ''));
            // Onboarding wants the add-on installed AND active; a successful
            // download that fails to activate is surfaced as a failure so the
            // user is told to finish it manually, not silently dropped.
            return !empty($result['success']) && !empty($result['activated']);
        }, 10, 2);

        $container->provider(IPNServiceProvider::class);

        // GroundLevel Events + Resque. Both depend on DatabaseServiceProvider
        // which gets pulled in transitively. Lite has no first-party consumer
        // today — these are exposed for add-ons (Pretty Links Developer Tools
        // is the current consumer; future add-ons may join).
        $container->provider(EventsServiceProvider::class);
        $container->provider(ResqueServiceProvider::class);

        // Plugin services.
        $container->singletons([
            OptionsStore::class    => static function (): OptionsStore {
                return new OptionsStore();
            },
            Migrator::class        => static function (Container $c): Migrator {
                global $wpdb;
                return new Migrator($wpdb, $c->get('BASE_PATH'), self::version());
            },
            RedirectEngine::class  => static function (Container $c): RedirectEngine {
                global $wpdb;
                return new RedirectEngine($wpdb, $c->get(OptionsStore::class));
            },
            Cleaner::class         => static function (): Cleaner {
                global $wpdb;
                return new Cleaner($wpdb);
            },
            RestRouter::class      => static function (Container $c): RestRouter {
                return new RestRouter($c);
            },
            LicenseManager::class  => static function (): LicenseManager {
                return new LicenseManager();
            },
            AuthClient::class      => static function (): AuthClient {
                return new AuthClient();
            },
            InPluginMessage::class => static function (): InPluginMessage {
                return new InPluginMessage(self::version());
            },
            ClassicEditor::class   => static function (Container $c): ClassicEditor {
                return new ClassicEditor($c->get('BASE_URL'));
            },
            GutenbergEditor::class => static function (Container $c): GutenbergEditor {
                return new GutenbergEditor($c->get('BASE_URL'), $c->get('BASE_PATH'));
            },
        ]);
    }

    /**
     * Plugin version from the main plugin file header — the single source of
     * truth. Cached per-request so repeat lookups don't re-parse the file.
     * Public so every subsystem that needs the version (migrators, asset
     * cache-busters, remote-service payloads) can derive it here instead of
     * hardcoding a copy that silently drifts on bump.
     */
    public static function version(): string
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }
        $data    = get_file_data(PRLI_FILE, ['Version' => 'Version']);
        $version = isset($data['Version']) ? (string) $data['Version'] : '';
        $cached  = $version !== '' ? $version : '0.0.0';
        return $cached;
    }

    /**
     * Build the list of WordPress action/filter hooks the plugin registers.
     *
     * @return Hook[] The hooks to attach during bootstrap.
     */
    protected function configureHooks(): array
    {
        $container = $this->container();

        $pluginBasename = plugin_basename(PRLI_FILE);

        return [
            // Deactivate pre-4.0 add-on builds before any of their callbacks
            // run. `enforce` must stay at PHP_INT_MIN: the legacy add-ons bind
            // to removed v3 internals from their own `plugins_loaded`
            // callbacks, so anything later than the first callback of the
            // request is too late to stop the fatal. The notice renderer hooks
            // in_admin_header priority 2 so it survives
            // Notices::suppressThirdParty() (priority 1) on Pretty Links
            // screens and shows site-wide.
            new Hook(Hook::TYPE_ACTION, 'plugins_loaded', [AddonCompatibilityGuard::class, 'enforce'], PHP_INT_MIN),
            new Hook(Hook::TYPE_ACTION, 'admin_init', [AddonCompatibilityGuard::class, 'maybeDismiss']),
            new Hook(Hook::TYPE_ACTION, 'in_admin_header', [AddonCompatibilityGuard::class, 'registerNotice'], 2),
            new Hook(Hook::TYPE_ACTION, 'init', [$container->get(RedirectEngine::class), 'dispatch'], 1),
            // Geo backfill: queue a drain from the Resque jobs cron rather than
            // from a request, so pending lookups are picked up no matter which
            // path queued them. The Turbo Mode dispatcher exits before the
            // plugin loads and can't enqueue anything itself, and a
            // ClickWriter enqueue can fail. Priority 5 so it lands before the
            // worker's own run() on the same tick and the job it queues is
            // processed immediately. Hook name is ResqueServiceProvider's
            // PARAM_JOBS_ACTION default, which we don't override.
            new Hook(Hook::TYPE_ACTION, 'resque_run_jobs', [GeoBackfillJob::class, 'maybeQueueDrain'], 5),
            new Hook(Hook::TYPE_ACTION, 'resque_run_jobs', [HostBackfillJob::class, 'maybeQueueDrain'], 5),
            new Hook(Hook::TYPE_ACTION, 'rest_api_init', [$container->get(RestRouter::class), 'register']),
            // Hosts and caching plugins that cache `/wp-json/` will serve the
            // React admin stale or empty payloads. Opt our namespace out of
            // every cache we can reach from PHP.
            new Hook(Hook::TYPE_FILTER, 'rest_pre_dispatch', [RestCacheControl::class, 'preventCaching'], 10, 3),
            // Merge per-source JS translation JSON (Loco / WordPress.org) onto
            // our built bundles — see ScriptTranslations for the why.
            new Hook(Hook::TYPE_FILTER, 'pre_load_script_translations', [ScriptTranslations::class, 'merge'], 10, 4),
            // Migration failure surfacing: a first-party notice (via Pretty
            // Links' own notice queue), a Site Health panel, and the one-click
            // retry handler.
            new Hook(Hook::TYPE_FILTER, 'prli_notices_active', [MigrationHealth::class, 'injectNotice'], 10, 2),
            new Hook(Hook::TYPE_FILTER, 'debug_information', [MigrationHealth::class, 'siteHealthInfo']),
            new Hook(Hook::TYPE_ACTION, 'admin_post_' . MigrationHealth::RETRY_ACTION, [MigrationHealth::class, 'handleRetry']),
            new Hook(Hook::TYPE_ACTION, 'admin_menu', [Page::class, 'register']),
            // Top-level menu icon is painted via CSS mask attached to the
            // always-loaded `admin-menu` style handle so the sidebar shows
            // it on every admin page (not just Pretty Links screens).
            new Hook(Hook::TYPE_ACTION, 'admin_enqueue_scripts', [Page::class, 'enqueueMenuIconStyle']),
            new Hook(Hook::TYPE_ACTION, 'admin_enqueue_scripts', [PayLinksPage::class, 'enqueueMenuStyle']),
            new Hook(Hook::TYPE_ACTION, 'admin_menu', [LinksPage::class, 'register'], 11),
            new Hook(Hook::TYPE_ACTION, 'admin_menu', [AddNewPage::class, 'register'], 11),
            new Hook(Hook::TYPE_ACTION, 'admin_menu', [PayLinksPage::class, 'register'], 11),
            new Hook(Hook::TYPE_ACTION, 'admin_menu', [ClicksPage::class, 'register'], 11),
            new Hook(Hook::TYPE_ACTION, 'admin_menu', [OptionsPage::class, 'register'], 11),
            new Hook(Hook::TYPE_ACTION, 'admin_menu', [AddonsPage::class, 'register'], 11),
            // Locked "Custom Reports" placeholder — self-suppresses when Pro
            // is on disk (Pro's real CustomReports page wins the slug).
            new Hook(Hook::TYPE_ACTION, 'admin_menu', [CustomReportsLockedPage::class, 'register'], 11),
            new Hook(Hook::TYPE_ACTION, 'admin_menu', [DeveloperToolsLockedPage::class, 'register'], 11),
            new Hook(Hook::TYPE_ACTION, 'admin_menu', [LinkInBioLockedPage::class, 'register'], 11),
            new Hook(Hook::TYPE_ACTION, 'admin_menu', [ProductDisplaysLockedPage::class, 'register'], 11),
            new Hook(Hook::TYPE_ACTION, 'admin_menu', [OnboardingPage::class, 'register'], 11),
            new Hook(Hook::TYPE_ACTION, 'admin_menu', [WhatsNewPage::class, 'register'], 11),
            new Hook(Hook::TYPE_ACTION, 'admin_menu', [GrowthToolsLoader::class, 'register'], 11),
            // Final pass — sort our submenu by the shared rank map (see
            // Page::reorderSubmenu). Runs after every register() at
            // priority 11 and after third-party integrations (Growth
            // Tools) have added their entries.
            new Hook(Hook::TYPE_ACTION, 'admin_menu', [Page::class, 'reorderSubmenu'], 999),
            // "Get More with Pro" link-out submenu — hidden when Pro is
            // active. Priority 99999 so it lands AFTER Growth Tools (which
            // registers its own menu at admin_menu priority 9999). This
            // guarantees our upgrade CTA is always the final submenu entry,
            // even with unknown third-party additions in the middle.
            new Hook(Hook::TYPE_ACTION, 'admin_menu', [ProUpsell::class, 'registerMenu'], 99999),
            // Onboarding add-on rows — teases Developer Tools, Link in
            // Bio, and Product Displays when Pro is not yet active. Fires
            // inside the Features step's add-ons list.
            new Hook(Hook::TYPE_ACTION, 'prli_onboarding_render_addon_rows', [ProUpsell::class, 'renderOnboardingAddons']),
            // Onboarding Pro-feature rows — surfaces checkable Pro toggles on
            // Lite so the Features step reflects the full paid set. Save
            // step picks these up and queues them in user meta for the
            // post-upgrade resume loop.
            new Hook(Hook::TYPE_ACTION, 'prli_onboarding_render_feature_rows', [ProUpsell::class, 'renderOnboardingProFeatures']),
            // "+ New > Pretty Link" entry in the WP admin bar. Priority 80 runs
            // after WP core builds the `new-content` parent node (which our
            // child attaches to).
            new Hook(Hook::TYPE_ACTION, 'admin_bar_menu', [AdminBar::class, 'register'], 80),
            // Inline-style hooks must run during enqueue (before <head> closes),
            // not during admin_bar_menu — too late to attach to admin-bar.css.
            new Hook(Hook::TYPE_ACTION, 'admin_enqueue_scripts', [AdminBar::class, 'enqueueStyles']),
            new Hook(Hook::TYPE_ACTION, 'wp_enqueue_scripts', [AdminBar::class, 'enqueueStyles']),
            new Hook(Hook::TYPE_ACTION, 'admin_enqueue_scripts', [AdminAssets::class, 'enqueue']),
            new Hook(Hook::TYPE_ACTION, 'admin_enqueue_scripts', [AdminDashboard::class, 'enqueueAssets']),
            // Onboarding styles enqueue on this hook (not inside the render
            // callback) so they land in <head> before the body renders —
            // otherwise the wizard flashes unstyled on first paint.
            new Hook(Hook::TYPE_ACTION, 'admin_enqueue_scripts', [OnboardingWizard::class, 'enqueueAssets']),
            new Hook(Hook::TYPE_ACTION, 'in_admin_header', [Notices::class, 'suppressThirdParty'], 1),
            new Hook(Hook::TYPE_ACTION, 'in_admin_header', [TopBar::class, 'maybeRender'], 20),
            new Hook(Hook::TYPE_ACTION, 'admin_init', [FirstRunRedirect::class, 'maybeRedirect']),
            // Caseproof auth service return handler — exchanges tokens on
            // the `?prli-connect=true` return and optionally chains to
            // Stripe Connect when `stripe_connect=true&method_id=…` is set.
            new Hook(Hook::TYPE_ACTION, 'admin_init', [AuthClient::class, 'maybeHandleReturn']),
            // Wizard request handler (form POSTs, step clamping, post-complete
            // rebound, dismiss-notice) — must run on admin_init so
            // wp_safe_redirect() fires before admin-header.php writes output.
            new Hook(Hook::TYPE_ACTION, 'admin_init', [OnboardingWizard::class, 'handleRequest']),
            // Resume-onboarding notice flows through the React notice strip
            // via the `prli_notices_active` filter, and clears its day-long
            // dismiss transient on the `prli_notice_dismissed` action fired
            // by Notices::dismiss().
            new Hook(Hook::TYPE_FILTER, 'prli_notices_active', [OnboardingWizard::class, 'injectResumeNotice'], 10, 2),
            new Hook(Hook::TYPE_ACTION, 'prli_notice_dismissed', [OnboardingWizard::class, 'onNoticeDismissed']),
            new Hook(Hook::TYPE_ACTION, 'init', [OnboardingWizard::class, 'registerShareASaleFilter']),
            new Hook(Hook::TYPE_ACTION, 'admin_init', [WhatsNew::class, 'maybeRegister']),
            new Hook(Hook::TYPE_ACTION, 'admin_init', [$container->get(LicenseManager::class), 'migrateLegacyLicenseInfo'], 5),
            new Hook(Hook::TYPE_ACTION, 'admin_init', [$container->get(LicenseManager::class), 'maybeCheck']),
            // Refill GL's `pretty-link_activation` transient when its 24h cache
            // has lapsed. (Not `prli_license_info` — nothing writes that key any
            // more; it is read once by migrateLegacyLicenseInfo() and otherwise
            // left alone.) The twice-daily GL cron normally keeps it fresh, so
            // this covers the sites where cron doesn't run: without it a Lite
            // install holding a Pro key silently reverts to reporting itself
            // free — taking the "install Pro" notice and the installer with it.
            // Throttled internally.
            new Hook(Hook::TYPE_ACTION, 'admin_init', [$container->get(LicenseManager::class), 'maybeRefreshLicenseInfo']),
            new Hook(Hook::TYPE_ACTION, 'admin_init', [$container->get(LicenseManager::class), 'activateFromDefine']),
            // Edition-mismatch warning flows through the React notice strip
            // via `prli_notices_active`. The in_plugin_update_message- hook is
            // a display-only WordPress hook (not an updater), so it stays Lite
            // even though the updater itself lives in Pro. UpdateChecker
            // refresh hooks live in Pro Bootstrap alongside the updater.
            new Hook(Hook::TYPE_FILTER, 'prli_notices_active', [EditionMismatch::class, 'injectAdminNotice'], 10, 2),
            new Hook(Hook::TYPE_ACTION, 'in_plugin_update_message-' . plugin_basename(PRLI_FILE), [EditionMismatch::class, 'pluginUpdateRowMessage'], 10, 2),
            new Hook(Hook::TYPE_ACTION, 'prli_auto_trim_clicks', [$container->get(Cleaner::class), 'runAutoTrim']),
            new Hook(Hook::TYPE_ACTION, 'wp_dashboard_setup', [AdminDashboard::class, 'register']),
            new Hook(Hook::TYPE_FILTER, 'plugin_action_links_' . $pluginBasename, [PluginRow::class, 'actionLinks']),
            new Hook(Hook::TYPE_FILTER, 'admin_footer_text', [PluginRow::class, 'footerText']),
        ];
    }

    /**
     * Run once the plugin has finished loading: install/upgrade the database
     * schema, schedule cron, register editors and Stripe hooks, and wire the
     * activation/deactivation handlers.
     *
     * @return void
     */
    public function loaded(): void
    {
        $container = $this->container();

        Cleaner::ensureCronScheduled();
        ReservedSlugs::bootstrap();
        ShortcodesLoader::register();
        StripeConnectAjax::loadHooks();
        StripeCheckoutRedirect::loadHooks();
        StripeInvoiceRenderer::loadHooks();
        StripeCustomerPortal::loadHooks();
        $container->get(InPluginMessage::class)->register();
        $container->get(ClassicEditor::class)->register();
        $container->get(GutenbergEditor::class)->register();
        (static function (): void {
            global $wpdb;
            (new ClickDataWiper($wpdb))->register();
        })();

        register_activation_hook(
            $this->config->getBasename(),
            static function (): void {
                Activator::onActivate();
            }
        );

        register_deactivation_hook(
            $this->config->getBasename(),
            static function (): void {
                Deactivator::onDeactivate();
                /**
                 * Fires during plugin deactivation so extensions can clean
                 * up artifacts they dropped outside the plugin directory
                 * (e.g. Pro's must-use redirect dispatcher).
                 */
                do_action('prli_plugin_deactivating');
            }
        );

        $this->loadBundledExtensions();

        // The schema migration and the prli_loaded fan-out are deferred to
        // after_setup_theme rather than run inline here. loaded() executes
        // while the plugin file is still being included by wp-settings.php,
        // which is before wp-includes/pluggable.php loads. Migrator::run()
        // requires wp-admin/includes/upgrade.php for dbDelta, and core's
        // upgrade.php includes the wp-content/install.php drop-in some hosts
        // (e.g. SiteGround) ship, which calls pluggable functions such as
        // get_user_by() and takes the whole site down. Any hook after
        // pluggable.php and before init is safe from that fatal; we
        // deliberately use after_setup_theme at priority 1 so the schema is
        // installed before the redirect engine dispatches at init:1. See
        // #766/#770. Extensions must hook prli_loaded (fired right after
        // the migrator), never plugins_loaded.
        add_action('after_setup_theme', [$this, 'bootDeferred'], 1);
    }

    /**
     * Runs the schema migration, then fires prli_loaded for extensions.
     *
     * Deferred from loaded() to after_setup_theme:1 so it runs after
     * pluggable.php is loaded; requiring wp-admin/includes/upgrade.php during
     * plugin include crashes sites with a wp-content/install.php drop-in
     * (#766). Any post-pluggable, pre-init hook would be safe; this one is
     * the deliberate choice (#770). The Migrator runs before prli_loaded so
     * extensions still see a fully installed schema — hook prli_loaded, never
     * plugins_loaded.
     *
     * @return void
     */
    public function bootDeferred(): void
    {
        $container = $this->container();

        $container->get(Migrator::class)->maybeRun();

        /**
         * Action: prli_loaded
         *
         * Fires after Lite has wired itself up and the schema is installed.
         * Other plugins that extend Pretty Links register their hooks here,
         * receiving the DI container so services can be resolved.
         *
         * @param \PrettyLinks\GroundLevel\Container\Container $container
         */
        do_action('prli_loaded', $container);
    }

    /**
     * Loads any extension entry-point files shipped alongside Lite.
     * Keeps the directory-scan generic — we don't hardcode which extension
     * is here. Each loader is responsible for registering itself on the
     * `prli_loaded` action or earlier.
     */
    private function loadBundledExtensions(): void
    {
        $basePath = rtrim($this->config->getBasePath(), '/\\') . '/';
        foreach (['pro'] as $dir) {
            $entry = $basePath . $dir . '/' . $dir . '.php';
            if (!is_file($entry)) {
                // Legacy naming (v3 shipped `pretty-link-pro.php` rather than `pro.php`).
                $entry = $basePath . $dir . '/pretty-link-' . $dir . '.php';
            }
            if (is_file($entry)) {
                require_once $entry;
            }
        }
    }
}
