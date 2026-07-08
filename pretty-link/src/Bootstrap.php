<?php

declare(strict_types=1);

namespace PrettyLinks;

use PrettyLinks\Admin\AdminBar;
use PrettyLinks\Admin\Assets as AdminAssets;
use PrettyLinks\Admin\Dashboard as AdminDashboard;
use PrettyLinks\Admin\Notices;
use PrettyLinks\Admin\ReviewNotice;
use PrettyLinks\Admin\Page;
use PrettyLinks\Admin\PluginRow;
use PrettyLinks\Admin\Upsell\ProUpsell;
use PrettyLinks\Admin\TopBar;
use PrettyLinks\Addons\AddonsService;
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
use PrettyLinks\GroundLevel\Container\Container;
use PrettyLinks\GroundLevel\Database\DatabaseServiceProvider;
use PrettyLinks\GroundLevel\Events\EventsServiceProvider;
use PrettyLinks\GroundLevel\InProductNotifications\IPNServiceProvider;
use PrettyLinks\GroundLevel\Mothership\AbstractPluginConnection;
use PrettyLinks\GroundLevel\Package\Bootstrap as BaseBootstrap;
use PrettyLinks\GroundLevel\Resque\ResqueServiceProvider;
use PrettyLinks\GroundLevel\Support\Models\Hook;
use PrettyLinks\GrowthTools\Loader as GrowthToolsLoader;
use PrettyLinks\Install\Activator;
use PrettyLinks\Install\Deactivator;
use PrettyLinks\Licensing\AuthClient;
use PrettyLinks\Licensing\EditionMismatch;
use PrettyLinks\Licensing\LicenseClient;
use PrettyLinks\Licensing\LicenseManager;
use PrettyLinks\Licensing\MothershipConnector;
use PrettyLinks\Onboarding\FirstRunRedirect;
use PrettyLinks\Onboarding\WhatsNew;
use PrettyLinks\Onboarding\Wizard as OnboardingWizard;
use PrettyLinks\Options\Store as OptionsStore;
use PrettyLinks\Redirect\ClickDataWiper;
use PrettyLinks\Redirect\Engine as RedirectEngine;
use PrettyLinks\Rest\Router as RestRouter;
use PrettyLinks\Shortcodes\Loader as ShortcodesLoader;
use PrettyLinks\Stripe\CheckoutRedirect as StripeCheckoutRedirect;
use PrettyLinks\Stripe\ConnectAjax as StripeConnectAjax;
use PrettyLinks\Stripe\CustomerPortal as StripeCustomerPortal;
use PrettyLinks\Stripe\InvoiceRenderer as StripeInvoiceRenderer;
use PrettyLinks\Updates\InPluginMessage;

/**
 * Plugin bootstrap.
 */
class Bootstrap extends BaseBootstrap
{
    /**
     * Initializes container bindings and plugin services.
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

        // Mothership plugin connection. IPN depends on Mothership; the
        // provider resolves AbstractPluginConnection out of the container.
        // Our connector reads/writes the v3.x license option keys so there's
        // one source of truth shared with Licensing\LicenseManager.
        $container->singleton(
            AbstractPluginConnection::class,
            static function (): AbstractPluginConnection {
                return new MothershipConnector();
            }
        );

        // IPN registers MothershipServiceProvider via its dependencies() chain.
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
            LicenseClient::class   => static function (): LicenseClient {
                return new LicenseClient(
                    defined('PRLI_EDITION') ? (string) constant('PRLI_EDITION') : 'pretty-link-lite'
                );
            },
            LicenseManager::class  => static function (Container $c): LicenseManager {
                return new LicenseManager($c->get(LicenseClient::class));
            },
            AuthClient::class      => static function (): AuthClient {
                return new AuthClient();
            },
            AddonsService::class   => static function (Container $c): AddonsService {
                return new AddonsService($c->get(LicenseClient::class));
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

        Page::setContainer($container);
        AdminAssets::setContainer($container);
        Notices::setContainer($container);
        TopBar::setContainer($container);
        ProUpsell::setContainer($container);
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
     * Builds the list of WordPress hooks the plugin registers.
     *
     * @return array<int, mixed> List of hook definitions.
     */
    protected function configureHooks(): array
    {
        $container = $this->container();

        $pluginBasename = plugin_basename(PRLI_FILE);

        return [
            // Disable any pre-4.0 add-on build before its deferred hooks fire.
            // PHP_INT_MIN runs ahead of every other plugins_loaded callback —
            // not just the add-ons' default-priority-10 work, but any that
            // register at 0 or a negative priority too. The notice renderer
            // hooks in_admin_header priority 2 so it survives
            // Notices::suppressThirdParty() (priority 1) on Pretty Links
            // screens and shows site-wide.
            new Hook(Hook::TYPE_ACTION, 'plugins_loaded', [AddonCompatibilityGuard::class, 'enforce'], PHP_INT_MIN),
            new Hook(Hook::TYPE_ACTION, 'admin_init', [AddonCompatibilityGuard::class, 'maybeDismiss']),
            new Hook(Hook::TYPE_ACTION, 'in_admin_header', [AddonCompatibilityGuard::class, 'registerNotice'], 2),
            new Hook(Hook::TYPE_ACTION, 'init', [$container->get(RedirectEngine::class), 'dispatch'], 1),
            new Hook(Hook::TYPE_ACTION, 'rest_api_init', [$container->get(RestRouter::class), 'register']),
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
            new Hook(Hook::TYPE_ACTION, 'admin_init', [$container->get(LicenseManager::class), 'maybeCheck']),
            new Hook(Hook::TYPE_ACTION, 'admin_init', [$container->get(LicenseManager::class), 'activateFromDefine']),
            // Edition-mismatch warning flows through the React notice strip
            // via `prli_notices_active`. The in_plugin_update_message- hook is
            // a display-only WordPress hook (not an updater), so it stays Lite
            // even though the updater itself lives in Pro. UpdateChecker
            // refresh hooks live in Pro Bootstrap alongside the updater.
            new Hook(Hook::TYPE_FILTER, 'prli_notices_active', [EditionMismatch::class, 'injectAdminNotice'], 10, 2),
            new Hook(Hook::TYPE_ACTION, 'in_plugin_update_message-pretty-link/pretty-link.php', [EditionMismatch::class, 'pluginUpdateRowMessage'], 10, 2),
            new Hook(Hook::TYPE_ACTION, 'prli_auto_trim_clicks', [$container->get(Cleaner::class), 'runAutoTrim']),
            new Hook(Hook::TYPE_ACTION, 'wp_dashboard_setup', [AdminDashboard::class, 'register']),
            new Hook(Hook::TYPE_FILTER, 'plugin_action_links_' . $pluginBasename, [PluginRow::class, 'actionLinks']),
            new Hook(Hook::TYPE_FILTER, 'admin_footer_text', [PluginRow::class, 'footerText']),
        ];
    }

    /**
     * Runs deferred bootstrap work once the plugin is fully loaded.
     *
     * @return void
     */
    public function loaded(): void
    {
        $container = $this->container();

        // Create/upgrade tables BEFORE firing prli_loaded. Extensions (Pro)
        // hook prli_loaded to seed their own defaults and inspect link data;
        // they can't be made to tolerate missing tables on a fresh install
        // because the whole point of those checks is to read the state of
        // the DB. Running the Migrator synchronously here means every
        // subsequent code path in this request (and every future request)
        // sees a fully installed schema. maybeRun() is guarded by an option
        // version check so it no-ops on steady-state loads.
        $container->get(Migrator::class)->maybeRun();

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

        /**
         * Action: prli_loaded
         *
         * Fires after Lite has wired itself up. Other plugins that extend
         * Pretty Links register their hooks here — receives the DI
         * container so services can be resolved.
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
