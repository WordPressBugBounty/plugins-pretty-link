<?php

declare(strict_types=1);

namespace PrettyLinks\Admin\Upsell;

use PrettyLinks\Admin\Page;
use PrettyLinks\Licensing\PlanCatalog;
use PrettyLinks\Licensing\ProState;
use PrettyLinks\Support\HasStaticContainer;
use PrettyLinks\Support\StaticContainerAwareness;

/**
 * Centralized Pro upsell module.
 *
 * One source of truth for:
 *  - whether an upgrade CTA should render right now (license/edition check)
 *  - the canonical pricing URL (with UTM tags per placement)
 *  - the catalog of Pro-only features (redirect types, link-form tabs,
 *    option sections, tools) that Lite surfaces as locked teasers
 *  - the catalog of paid add-ons (Developer Tools, Link in Bio, Product
 *    Displays) that Lite can promote in onboarding
 *  - bootstrap payload shape consumed by the React shell's <ProLock /> /
 *    <ProLockedCard /> components
 *
 * Lite knows about these Pro concepts by NAME only — no Pro code is imported.
 * Pro itself continues to own its implementations; this module just holds the
 * labels, descriptions, and CTA wiring so that the "every Pro mention in the
 * Lite admin" blast radius stays in one file.
 *
 * This is the second intentional exception to the "Lite has no knowledge of
 * Pro" rule (the first being the WhatsNew landing page).
 */
class ProUpsell implements StaticContainerAwareness
{
    use HasStaticContainer;

    public const PRICING_URL = 'https://prettylinks.com/pricing/';
    public const ADDONS_URL  = 'https://prettylinks.com/add-ons/';

    /**
     * Sentinel CSS class attached to the "Upgrade to Pro" menu item so
     * admin CSS can color it without brittle :nth-child selectors.
     */
    public const MENU_CSS_HOOK = 'prli-upgrade-menu-item';

    /**
     * Default upgrade CTA copy — used on every attention-heavy surface
     * (menu row, WhatsNew card, locked option sections, locked link-form
     * tabs, locked Custom Reports / Developer Tools pages, onboarding
     * Finish tier CTA). Evergreen, no discount claim. Keep it here (one
     * string, one file) so wording changes are a single-line edit.
     * Ambient surfaces — the small `Pro` pill, the "Learn more" link in
     * `<ProTeaserStrip>` — stay neutral and do NOT read this constant;
     * pricing language doesn't belong in tiny decorations.
     */
    public static function ctaLabel(): string
    {
        return __('Upgrade to Pro', 'pretty-link');
    }

    /**
     * Onboarding resume queues — stored in user meta so the wizard can pick
     * up where the user left off after they return from checkout. Keys are
     * kept compatible with v3 `PrliOnboardingHelper` so upgraded installs
     * that already had a queue don't lose it.
     */
    public const META_FEATURES_NOT_ENABLED  = 'prli_onboarding_features_not_enabled';
    public const META_ADDONS_NOT_INSTALLED  = 'prli_onboarding_addons_not_installed';
    public const META_ADDONS_UPGRADE_FAILED = 'prli_onboarding_addons_upgrade_failed';

    /**
     * Sentinel used by {@see ::requiredPlanFor()} when the user picked
     * Pro features only (no add-ons). Beginner is the cheapest plan that
     * unlocks every Pro feature; add-on selections escalate the tier via
     * {@see PlanCatalog}.
     */
    public const PLAN_FEATURES_ONLY = PlanCatalog::PLAN_BEGINNER;

    /**
     * Whether the current install should see upgrade CTAs at all.
     *
     * Returns true when the edition is free OR the Pro key is missing /
     * inactive. When Pro is activated and valid, every upsell surface goes
     * quiet — the user has already paid.
     */
    // Pro-state queries live on {@see ProState}, not here. Call
    // `ProState::isProInstalled()` (presence — every general upsell
    // surface) or `ProState::isProInstalledAndActivated()` (license —
    // only the PrettyPay fee notice) directly.

    /**
     * Canonical pricing URL with UTM tagging for attribution.
     *
     * @param string $placement Short identifier for where the CTA lives
     *                          (e.g. 'whats-new', 'menu', 'paylinks-fee',
     *                          'link-form-redirect-type').
     * @param string $feature   Optional feature id from FEATURES; surfaced
     *                          as utm_content so marketing can see which
     *                          specific teaser drove the click.
     */
    public static function upgradeUrl(string $placement, string $feature = ''): string
    {
        $params = [
            'utm_source'   => 'prli',
            'utm_medium'   => 'admin',
            'utm_campaign' => $placement !== '' ? $placement : 'upgrade',
        ];
        if ($feature !== '') {
            $params['utm_content'] = $feature;
        }
        return add_query_arg($params, self::PRICING_URL);
    }

    /**
     * Catalog of Pro features that Lite surfaces as locked teasers.
     *
     * Keyed by a stable id so JS and PHP references stay in sync. `label`
     * and `summary` are translated at lookup time, not at class load.
     *
     * Keep this list tight. Every entry here must be:
     *  - user-visible (i.e. a thing a user is looking for when they hit a
     *    gap in the Lite UI, NOT every Pro internal)
     *  - genuinely a paid feature (not a free thing gated behind Pro for
     *    marketing — those erode trust)
     *
     * @return array<string, array{label: string, summary: string}>
     */
    public static function features(): array
    {
        return [
            'redirect-cloak'          => [
                'label'   => __('Cloaked redirect', 'pretty-link'),
                'summary' => __('Keep the pretty URL in the address bar and show the destination inside it — hides the final affiliate URL from visitors.', 'pretty-link'),
            ],
            'redirect-metarefresh'    => [
                'label'   => __('Meta Refresh redirect', 'pretty-link'),
                'summary' => __('Wipes the Referer header so merchants can\'t see which page on your site the click came from — protects your traffic sources.', 'pretty-link'),
            ],
            'redirect-javascript'     => [
                'label'   => __('JavaScript redirect', 'pretty-link'),
                'summary' => __('Browser-side redirect that evades server-log tracking and some referral-attribution schemes.', 'pretty-link'),
            ],
            'redirect-pixel'          => [
                'label'   => __('Pixel redirect', 'pretty-link'),
                'summary' => __('Fires one or more tracking pixels before redirecting — use for remarketing or conversion tags.', 'pretty-link'),
            ],
            'redirect-prettybar'      => [
                'label'   => __('Pretty Bar redirect', 'pretty-link'),
                'summary' => __('Wraps the destination in a branded top bar, bottom bar, or floating pill with your logo and share buttons.', 'pretty-link'),
            ],
            'link-form-keywords'      => [
                'label'   => __('Keywords & URL replacements', 'pretty-link'),
                'summary' => __('Auto-linkify words in your content and silently rewrite existing URLs through Pretty Links — hands-off affiliate linking.', 'pretty-link'),
            ],
            'link-form-rotation'      => [
                'label'   => __('Rotation & split testing', 'pretty-link'),
                'summary' => __('Rotate visitors across multiple destinations and measure which one converts best.', 'pretty-link'),
            ],
            'link-form-targeting'     => [
                'label'   => __('Smart targeting', 'pretty-link'),
                'summary' => __('Send visitors to different destinations based on country, device, browser, or time of day.', 'pretty-link'),
            ],
            'link-form-expiration'    => [
                'label'   => __('Expiring links', 'pretty-link'),
                'summary' => __('Automatically disable a link on a set date or after N clicks — ideal for limited-time promos.', 'pretty-link'),
            ],
            'link-form-qr'            => [
                'label'   => __('Per-link QR codes', 'pretty-link'),
                'summary' => __('Generate PNG or SVG QR codes with optional logo overlay and custom colors.', 'pretty-link'),
            ],
            'options-pretty-bar'      => [
                'label'   => __('Pretty Bar templates', 'pretty-link'),
                'summary' => __('Branded interstitial overlays with your logo, colors, and optional share buttons.', 'pretty-link'),
            ],
            'options-replacements'    => [
                'label'   => __('Keyword & URL replacement engine', 'pretty-link'),
                'summary' => __('Site-wide auto-linkification with throttling, case sensitivity, and disclosure controls.', 'pretty-link'),
            ],
            'options-link-health'     => [
                'label'   => __('Link health monitoring', 'pretty-link'),
                'summary' => __('Scheduled health checks catch broken destinations before your visitors do.', 'pretty-link'),
            ],
            'options-autocreate'      => [
                'label'   => __('Auto-create links', 'pretty-link'),
                'summary' => __('Turn URL patterns in your content into pretty links automatically as you publish.', 'pretty-link'),
            ],
            // Add-on features (each is its own paid plugin, separate from
            // Pretty Links Pro itself but bundled with Pro plans). The
            // `'addon-*'` ID prefix marks them so locked-card surfaces and
            // upsell URLs route to the per-add-on landing page rather than
            // the generic `/pro/` upgrade path.
            'addon-utms'              => [
                'label'   => __('UTM Builder', 'pretty-link'),
                'summary' => __('Build Google Analytics UTM parameters (source, medium, campaign, content, term, ID) right on the link form — no manual URL editing, no broken tags.', 'pretty-link'),
            ],
            'addon-user-links'        => [
                'label'   => __('User Links — front-end dashboard', 'pretty-link'),
                'summary' => __('Drop a self-service pretty-link manager onto a front-end page so logged-in users create and manage their own shortlinks without ever touching wp-admin.', 'pretty-link'),
            ],
            'addon-splash-pages'      => [
                'label'   => __('Splash Pages', 'pretty-link'),
                'summary' => __('Show a branded interstitial page (heading, media, CTAs, optional countdown) before forwarding visitors — perfect for affiliate disclosures, choose-your-own-destination, or video gates.', 'pretty-link'),
            ],
            'addon-link-in-bio'       => [
                'label'   => __('Link in Bio', 'pretty-link'),
                'summary' => __('Build branded /bio/{slug}/ landing pages — profile, stacked buttons, social-icon row — every click tracked through Pretty Links.', 'pretty-link'),
            ],
            'addon-product-displays'  => [
                'label'   => __('Product Displays', 'pretty-link'),
                'summary' => __('Drop styled product cards/grids into any post, page, or Bio page via shortcode or Gutenberg block — every CTA tracked as a pretty link.', 'pretty-link'),
            ],
            'addon-developer-tools'   => [
                'label'   => __('Developer Tools', 'pretty-link'),
                'summary' => __('Public REST API with bearer-token auth and outbound HMAC-signed webhooks — connect Pretty Links to Make, Zapier, n8n, custom dashboards, and CI pipelines.', 'pretty-link'),
            ],
            'options-alt-domain'      => [
                'label'   => __('Alternate short domain', 'pretty-link'),
                'summary' => __('Serve pretty URLs on a second domain you own (e.g. short.yoursite.com) without moving WordPress.', 'pretty-link'),
            ],
            'links-categories'        => [
                'label'   => __('Link categories', 'pretty-link'),
                'summary' => __('Organize hundreds or thousands of links with first-class categories. Filter dashboard and reports at a glance.', 'pretty-link'),
            ],
            'reports-custom'          => [
                'label'   => __('Custom reports & conversions', 'pretty-link'),
                'summary' => __('Aggregate clicks across any set of links and track real conversion goals via a one-line pixel on your thank-you page.', 'pretty-link'),
            ],
            'paylinks-fee-bypass'     => [
                'label'   => __('Zero platform fee on PrettyPay', 'pretty-link'),
                'summary' => __('Pretty Links Pro removes the 3% platform fee added to every PrettyPay checkout — you keep every dollar after Stripe\'s own fees.', 'pretty-link'),
            ],
            'tool-bookmarklet'        => [
                'label'   => __('Bookmarklet', 'pretty-link'),
                'summary' => __('Drag a button to your bookmarks bar and create a pretty link for any page you\'re browsing with one click.', 'pretty-link'),
            ],
            'tool-duplicate-keywords' => [
                'label'   => __('Duplicate keywords', 'pretty-link'),
                'summary' => __('Find keywords assigned to more than one link and resolve the conflicts so auto-replacements stay unambiguous.', 'pretty-link'),
            ],
            'tool-mu-dispatch'        => [
                'label'   => __('Redirect turbo mode', 'pretty-link'),
                'summary' => __('Short-circuits redirects before the rest of WordPress loads via a must-use plugin — dramatically faster on high-traffic sites.', 'pretty-link'),
            ],
        ];
    }

    /**
     * Paid add-ons that deserve a mention during onboarding.
     *
     * Defers to {@see PlanCatalog::addons()} — the catalog is the single
     * source of truth for slugs, labels, and plan entitlement.
     *
     * @return array<string, array{label: string, summary: string, url: string}>
     */
    public static function addons(): array
    {
        $out = [];
        foreach (PlanCatalog::addons() as $slug => $addon) {
            $out[$slug] = [
                'label'   => $addon['label'],
                'summary' => $addon['summary'],
                'url'     => $addon['url'],
            ];
        }
        return $out;
    }

    /**
     * Look up one feature by id. Returns null when the id is unknown — keep
     * callers honest (typos blow up visibly rather than silently rendering
     * an empty teaser).
     *
     * @param  string $id Feature id (key of self::features()).
     * @return array{label: string, summary: string}|null
     */
    public static function feature(string $id): ?array
    {
        $catalog = self::features();
        return $catalog[$id] ?? null;
    }

    /**
     * JS bootstrap payload. Keep small — only what the React shell needs to
     * render <ProLock> / <ProLockedCard> without a round-trip.
     *
     * @return array{catalog: array<string, array{label: string, summary: string}>, pricingUrl: string, addonsUrl: string, addonsAdminUrl: string, ctaLabel: string}
     */
    public static function bootstrapPayload(): array
    {
        // No Pro-state booleans here — JS reads `proState.installed` /
        // `proState.installedAndActivated` from the top-level bootstrap
        // (see `Assets::bootstrapData()`) via `@shared/pro-state.js`.
        return [
            'catalog'        => self::features(),
            'pricingUrl'     => self::upgradeUrl('admin'),
            'addonsUrl'      => self::ADDONS_URL,
            // In-product Add-ons page. Add-on locked cards route Pro users
            // here (instead of external pricing) — the Add-ons page sources
            // each add-on's status from the mothership, so it shows an
            // Install button for add-ons in the user's plan and an upgrade
            // CTA for ones that aren't. See AddonsController::index().
            'addonsAdminUrl' => admin_url('admin.php?page=' . \PrettyLinks\Admin\Pages\Addons::SLUG),
            // Single source of truth for the upgrade-CTA label. JS
            // components read it via `bootstrap.upsell.ctaLabel` so a
            // copy change is one-line both sides.
            'ctaLabel'       => self::ctaLabel(),
        ];
    }

    /**
     * Register the "Get More with Pro" submenu item under the Pretty Links
     * parent menu. Hidden entirely when Pro is active.
     *
     * WordPress lets you pass an external URL as the submenu's slug; the
     * parent-slug lookup then treats it as a link-out rather than an
     * internal page. We add a sentinel CSS class via `add_filter('submenu_*')`
     * so `src/Admin/Assets.php` can style just this item yellow.
     */
    public static function registerMenu(): void
    {
        // Hide the "Get More with Pro" submenu only when Pro is installed
        // AND licensed. An unlicensed Pro install still needs the CTA
        // because the user hasn't yet paid — same rationale as the
        // PayLinks fee check in `Stripe\Fee`: license-gated money paths
        // keep the upsell visible until the license activates.
        if (ProState::isProInstalledAndActivated()) {
            return;
        }

        global $submenu;
        $parent = Page::SLUG;
        $url    = self::upgradeUrl('menu');

        // Add_submenu_page() doesn't accept a target attribute, but we can
        // manipulate $submenu directly (same pattern WP uses internally).
        // Each row is [ title, capability, slug(url), page_title, class ].
        $submenu[$parent][] = [ // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Intentionally rewriting the admin $submenu entry for this menu item.
            // Translators: Menu item promoting the paid Pro edition.
            esc_html(self::ctaLabel()),
            Page::capability(),
            $url,
            esc_attr__('Upgrade to Pretty Links Pro', 'pretty-link'),
            self::MENU_CSS_HOOK,
        ];
    }

    /**
     * Compute the minimum paid plan slug that covers the supplied lists of
     * Pro features and add-ons. Returns null when the lists are empty.
     *
     * Plan→add-on entitlements live in {@see PlanCatalog}; this method just
     * walks the selections and returns the highest-rank plan needed.
     *
     * @param string[] $features Pro feature ids (keys of self::features()).
     * @param string[] $addons   Add-on slugs (keys of PlanCatalog::addons()).
     */
    public static function requiredPlanFor(array $features, array $addons): ?string
    {
        $hasFeature = false;
        foreach ($features as $id) {
            if (is_string($id) && $id !== '' && isset(self::features()[$id])) {
                $hasFeature = true;
                break;
            }
        }

        $tier = $hasFeature ? self::PLAN_FEATURES_ONLY : null;

        foreach ($addons as $slug) {
            if (!is_string($slug) || $slug === '') {
                continue;
            }
            $required = PlanCatalog::lowestPlanForAddon($slug);
            if ($required === '') {
                continue;
            }
            if ($tier === null || PlanCatalog::tierRank($required) > PlanCatalog::tierRank($tier)) {
                $tier = $required;
            }
        }

        return $tier;
    }

    /**
     * Human-readable label for a plan slug.
     *
     * @param string $plan Plan slug.
     */
    public static function planLabel(string $plan): string
    {
        return PlanCatalog::planLabel($plan);
    }

    /**
     * Build the upgrade-CTA payload for a plan: heading, primary-button
     * label, and pricing URL that round-trips the user back into the
     * wizard's Resume step on success.
     *
     * @param  string $plan      Plan slug.
     * @param  string $returnUrl Optional URL to round-trip the user back to after checkout.
     * @return array{plan: string, planLabel: string, heading: string, label: string, url: string}
     */
    public static function planCta(string $plan, string $returnUrl = ''): array
    {
        $label = self::planLabel($plan);
        $url   = self::planUpgradeUrl($plan, $returnUrl);

        return [
            'plan'      => $plan,
            'planLabel' => $label,
            'heading'   => sprintf(
                // Translators: %s: plan label, e.g. "Marketer".
                __('Upgrade to %s to finish setup', 'pretty-link'),
                $label
            ),
            'label'     => sprintf(
                // Translators: %s: plan label.
                __('Upgrade to %s', 'pretty-link'),
                $label
            ),
            'url'       => $url,
        ];
    }

    /**
     * Build the upgrade URL for a plan. Appends `return_url` when
     * supplied so the pricing page can bring the user back to the
     * wizard after checkout.
     *
     * @param string $plan      Plan slug.
     * @param string $returnUrl Optional URL appended as `return_url` for post-checkout return.
     */
    public static function planUpgradeUrl(string $plan, string $returnUrl = ''): string
    {
        $params = [
            'utm_source'   => 'prli',
            'utm_medium'   => 'admin',
            'utm_campaign' => 'onboarding',
            'utm_content'  => $plan !== '' ? $plan : 'pro',
        ];
        if ($returnUrl !== '') {
            $params['return_url'] = rawurlencode($returnUrl);
        }
        return add_query_arg($params, self::PRICING_URL);
    }

    /**
     * Render the add-on rows for the onboarding wizard's Features step.
     * Hooked to `prli_onboarding_render_addon_rows`.
     *
     * Always renders every catalog add-on regardless of edition — entitlement
     * is enforced at install time by {@see Wizard::drainQueue()}, not by
     * hiding the checkbox. Three render states:
     *
     *  - already active on this site → checked, disabled, "Already active" badge
     *  - selectable → standard checkbox; selection queues into user-meta
     *
     * Free users (and Pro users without the right plan) still see every box;
     * the upgrade CTA at the Finish step nudges them to the cheapest plan
     * that covers their selections.
     */
    public static function renderOnboardingAddons(): void
    {
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        // Mirror v3: let the user check these even without a license.
        // Selections are stashed in user-meta so the wizard can drain them
        // automatically after the user returns from checkout.
        $userId       = get_current_user_id();
        $queuedAddons = $userId > 0
            ? (array) get_user_meta($userId, self::META_ADDONS_NOT_INSTALLED, true)
            : [];

        foreach (self::addons() as $slug => $addon) {
            $name        = 'prli_queue_addon_' . sanitize_key($slug);
            $pluginFile  = $slug . '/' . $slug . '.php';
            $alreadyOn   = is_plugin_active($pluginFile);
            $description = $addon['summary'];

            \PrettyLinks\Onboarding\Wizard::renderFeatureItem(
                $name,
                $addon['label'],
                $description,
                '',
                in_array($slug, $queuedAddons, true),
                !$alreadyOn,
                $alreadyOn,
                $alreadyOn ? __('Already active', 'pretty-link') : __('Pro add-on', 'pretty-link')
            );
        }
    }

    /**
     * Render checkable Pro feature rows inside the onboarding Features step.
     * Hooked to `prli_onboarding_render_feature_rows` when Pro is absent or
     * inactive — users can queue the Pro features they want and we drive
     * them into place after the license activates.
     *
     * @param array<string, mixed> $state Current wizard state.
     */
    public static function renderOnboardingProFeatures(array $state): void
    {
        unset($state);
        if (ProState::isProInstalled()) {
            return;
        }

        $userId         = get_current_user_id();
        $queuedFeatures = $userId > 0
            ? (array) get_user_meta($userId, self::META_FEATURES_NOT_ENABLED, true)
            : [];

        // A curated subset of the catalog — the options-level features
        // where a "yes, turn this on for me" choice is meaningful during
        // setup. Redirect/link-form features configure per-link and are
        // not sensibly toggled here.
        $onboardableFeatures = [
            'options-pretty-bar',
            'options-replacements',
            'options-link-health',
            'options-autocreate',
            'link-form-qr',
        ];

        foreach ($onboardableFeatures as $id) {
            $entry = self::feature($id);
            if ($entry === null) {
                continue;
            }
            $label = sprintf(
                // Translators: %s: feature name, e.g. "Link health monitoring".
                __('%s (Pro feature)', 'pretty-link'),
                $entry['label']
            );
            $name = 'prli_queue_feature_' . sanitize_key($id);
            echo '<li class="prli-onboarding-feature prli-onboarding-feature--upsell">';
            echo '<label>';
            echo '<input type="checkbox" name="' . esc_attr($name) . '" value="1" ';
            checked(in_array($id, $queuedFeatures, true));
            echo ' />';
            echo '<span class="prli-onboarding-feature-label">' . esc_html($label);
            echo ' <span class="prli-onboarding-feature-badge prli-onboarding-feature-badge--pro">'
                . esc_html__('Pro', 'pretty-link')
                . '</span>';
            echo '</span>';
            echo '<span class="prli-onboarding-feature-desc">' . esc_html($entry['summary']) . '</span>';
            echo '</label>';
            echo '</li>';
        }
    }

    /**
     * Collect the Pro feature ids the user queued during onboarding. Reads
     * from `$_POST` so callers receive a fresh snapshot per submission.
     *
     * @param  array<string, mixed> $post Reference copy of `$_POST`.
     * @return string[]
     */
    public static function collectQueuedFeatures(array $post): array
    {
        $catalog = self::features();
        $queued  = [];
        foreach ($catalog as $id => $_entry) {
            $field = 'prli_queue_feature_' . sanitize_key($id);
            if (!empty($post[$field])) {
                $queued[] = $id;
            }
        }
        return $queued;
    }

    /**
     * Collect the add-on slugs the user queued during onboarding.
     *
     * @param  array<string, mixed> $post Reference copy of `$_POST`.
     * @return string[]
     */
    public static function collectQueuedAddons(array $post): array
    {
        $catalog = self::addons();
        $queued  = [];
        foreach (array_keys($catalog) as $slug) {
            $field = 'prli_queue_addon_' . sanitize_key((string) $slug);
            if (!empty($post[$field])) {
                $queued[] = (string) $slug;
            }
        }
        return $queued;
    }

    /**
     * Enable a Pro feature by writing the corresponding option key(s).
     * Returns true when any known toggle flipped. Reads from `prlipro_options`
     * (the shared Pro-options blob) — Pro's Options\Store owns writes.
     *
     * @param string $featureId Feature id (key of self::features()).
     */
    public static function enableQueuedFeature(string $featureId): bool
    {
        // Lite → option-key mapping. Matches v3's enable_disable_feature.
        static $map = [
            'options-pretty-bar'   => ['enable_pretty_bar' => true],
            'options-replacements' => [
                'keyword_replacement_is_on' => true,
                'url_replacement_is_on'     => true,
            ],
            'options-link-health'  => ['enable_link_health' => true],
            'options-autocreate'   => ['autocreate_enable' => true],
            'link-form-qr'         => ['generate_qr_codes' => true],
        ];

        if (!isset($map[$featureId])) {
            return false;
        }

        $opts = get_option('prlipro_options', []);
        if (!is_array($opts)) {
            $opts = [];
        }
        $changed = false;
        foreach ($map[$featureId] as $key => $value) {
            if (($opts[$key] ?? null) !== $value) {
                $opts[$key] = $value;
                $changed    = true;
            }
        }
        if ($changed) {
            update_option('prlipro_options', $opts);
        }
        return $changed;
    }
}
