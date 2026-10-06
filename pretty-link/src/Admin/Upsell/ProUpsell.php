<?php

declare(strict_types=1);

namespace PrettyLinks\Admin\Upsell;

use PrettyLinks\Admin\Page;
use PrettyLinks\Licensing\PlanCatalog;
use PrettyLinks\Licensing\ProState;

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
class ProUpsell
{
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
        return self::tagSalesUrl(self::PRICING_URL, $placement, $feature);
    }

    /**
     * Attach the standard attribution tags to any prettylinks.com sales URL.
     *
     * Every money link the plugin emits — pricing, add-on detail pages, the
     * Add-ons directory — carries the same `utm_source`/`utm_medium` pair so
     * marketing can isolate in-plugin traffic with one filter, then split it
     * by placement (`utm_campaign`) and locked feature (`utm_content`).
     *
     * @param string $base      Destination URL on prettylinks.com.
     * @param string $placement Short identifier for where the CTA lives; rides as utm_campaign.
     * @param string $content   Optional feature id or other detail; rides as utm_content.
     */
    public static function tagSalesUrl(string $base, string $placement, string $content = ''): string
    {
        $params = [
            'utm_source'   => 'prli',
            'utm_medium'   => 'admin',
            'utm_campaign' => $placement !== '' ? $placement : 'upgrade',
        ];
        if ($content !== '') {
            $params['utm_content'] = $content;
        }
        return add_query_arg($params, $base);
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
                'summary' => __('Your domain stays in the address bar instead of the raw affiliate URL, so the link reads as yours. Destinations that refuse framing fall back to a normal redirect.', 'pretty-link'),
            ],
            'redirect-metarefresh'    => [
                'label'   => __('Meta Refresh redirect', 'pretty-link'),
                'summary' => __('Merchants stop seeing which of your pages sent the click. Keep your best-converting placements to yourself instead of handing rivals a map of what works.', 'pretty-link'),
            ],
            'redirect-javascript'     => [
                'label'   => __('JavaScript redirect', 'pretty-link'),
                'summary' => __('Hands the visitor off from the browser rather than the server, with an optional per-link delay so your page can do its work before they move on.', 'pretty-link'),
            ],
            'redirect-pixel'          => [
                'label'   => __('Pixel redirect', 'pretty-link'),
                'summary' => __('A link with no destination. Embed it in an email or a page and every load is recorded as a click, so opens and views land in the same reports as everything else.', 'pretty-link'),
            ],
            'redirect-prettybar'      => [
                'label'   => __('Pretty Bar redirect', 'pretty-link'),
                'summary' => __('Stay on screen after the click. Your logo, message and share buttons ride above the destination, turning a click you used to lose into another touch — with a normal redirect as the fallback where framing is refused.', 'pretty-link'),
            ],
            'link-form-keywords'      => [
                'label'   => __('Keywords & URL replacements', 'pretty-link'),
                'summary' => __('Write naturally now, monetize later. Choose a phrase once and matching mentions become tracked links under your replacement rules — including posts you published years ago.', 'pretty-link'),
            ],
            'link-form-rotation'      => [
                'label'   => __('Rotation & split testing', 'pretty-link'),
                'summary' => __('Stop guessing which offer performs. Split traffic across destinations, watch the clicks land, and keep the one that actually earns.', 'pretty-link'),
            ],
            'link-form-targeting'     => [
                'label'   => __('Smart targeting', 'pretty-link'),
                'summary' => __('Send a visitor in Berlin somewhere different from one in Boston. Route by country, device, browser or time of day so nobody lands on an offer they cannot buy.', 'pretty-link'),
            ],
            'link-form-expiration'    => [
                'label'   => __('Expiring links', 'pretty-link'),
                'summary' => __('Promotions that end themselves. Retire a link on a date or after a set number of clicks, so an expired deal never keeps taking traffic.', 'pretty-link'),
            ],
            'link-form-qr'            => [
                'label'   => __('Per-link QR codes', 'pretty-link'),
                'summary' => __('Take a link off the screen and into the world. Generate a PNG or SVG code with your logo and colors for packaging, slides or print, and track the scans like any other click.', 'pretty-link'),
            ],
            'options-pretty-bar'      => [
                'label'   => __('Pretty Bar templates', 'pretty-link'),
                'summary' => __('Brand the handoff once. Build the bar with your logo, colors and buttons, and every Pretty Bar link uses it, falling back to a normal redirect where the destination refuses framing.', 'pretty-link'),
            ],
            'options-social-buttons'      => [
                'label'   => __('Social share buttons', 'pretty-link'),
                'summary' => __('Turn readers into distributors. Where a post has a pretty link, the share bar points at it, so every share people spread lands back in your click reports; posts without one share the plain permalink.', 'pretty-link'),
            ],
            'options-replacements'    => [
                'label'   => __('Keyword & URL replacement engine', 'pretty-link'),
                'summary' => __('Monetize an entire archive without opening a single old post. Set the rules once and links appear site-wide, with throttling and disclosure controls to keep it tasteful.', 'pretty-link'),
            ],
            'options-link-health'     => [
                'label'   => __('Link health monitoring', 'pretty-link'),
                'summary' => __('A dead destination is a commission you never hear about. Scheduled checks find the broken ones and email you the list before your readers find them for you.', 'pretty-link'),
            ],
            'options-autocreate'      => [
                'label'   => __('Auto-create links', 'pretty-link'),
                'summary' => __('New posts arrive already earning. Publish, and the pretty links are created for you instead of waiting for someone to remember.', 'pretty-link'),
            ],
            // Add-on features (each is its own paid plugin, separate from
            // Pretty Links Pro itself but bundled with Pro plans). The
            // `'addon-*'` ID prefix marks them so locked-card surfaces and
            // upsell URLs route to the per-add-on landing page rather than
            // the generic `/pro/` upgrade path.
            'addon-utms'              => [
                'label'   => __('UTM Builder', 'pretty-link'),
                'summary' => __('Campaign data you can actually trust. Build source, medium, campaign, content, term and ID tags on the link form itself, so your reports never hinge on someone hand-editing a URL correctly.', 'pretty-link'),
            ],
            'addon-user-links'        => [
                'label'   => __('User Links — front-end dashboard', 'pretty-link'),
                'summary' => __('Let your team or members make their own short links on a front-end page. They get self-service, you keep one tracked, branded link namespace and never hand out wp-admin logins.', 'pretty-link'),
            ],
            'addon-splash-pages'      => [
                'label'   => __('Splash Pages', 'pretty-link'),
                'summary' => __('Own the moment before the handoff. A branded page with your message, media and calls to action, plus an optional countdown, does your disclosure and your pitch on the way out.', 'pretty-link'),
            ],
            'addon-link-in-bio'       => [
                'label'   => __('Link in Bio', 'pretty-link'),
                'summary' => __('One link for every social profile, and it is yours rather than a rented page. Profile, stacked buttons and social icons on your own domain, with every tap tracked.', 'pretty-link'),
            ],
            'addon-product-displays'  => [
                'label'   => __('Product Displays', 'pretty-link'),
                'summary' => __('Turn a mention into a storefront. Drop styled product cards or grids into any post, page or Bio page by shortcode or block, with every button tracked as a pretty link.', 'pretty-link'),
            ],
            'addon-developer-tools'   => [
                'label'   => __('Developer Tools', 'pretty-link'),
                'summary' => __('Wire Pretty Links into the rest of your stack. A token-authenticated REST API and signed webhooks connect it to Make, Zapier, n8n, your dashboards or your CI.', 'pretty-link'),
            ],
            'options-alt-domain'      => [
                'label'   => __('Alternate short domain', 'pretty-link'),
                'summary' => __('Ship links on a short domain you already own, like go.yourbrand.com, without moving WordPress or running a second install.', 'pretty-link'),
            ],
            'links-categories'        => [
                'label'   => __('Link categories', 'pretty-link'),
                'summary' => __('Keep a thousand links findable. Group them by campaign, client or partner, then filter the dashboard and reports down to just that set.', 'pretty-link'),
            ],
            'reports-custom'          => [
                'label'   => __('Custom reports & conversions', 'pretty-link'),
                'summary' => __('Show conversions, not just traffic. Roll clicks up across any group of links and record real conversions with a one-line pixel on your thank-you page.', 'pretty-link'),
            ],
            'paylinks-fee-bypass'     => [
                'label'   => __('Zero platform fee on PrettyPay', 'pretty-link'),
                'summary' => __('Keep the whole sale. Pro drops the 3% platform fee from every PrettyPay checkout, so past a few hundred dollars a month the upgrade has already paid for itself.', 'pretty-link'),
            ],
            'tool-bookmarklet'        => [
                'label'   => __('Bookmarklet', 'pretty-link'),
                'summary' => __('Make a link while you are reading the page. One click from your bookmarks bar, no tab switching, no copy and paste back into wp-admin.', 'pretty-link'),
            ],
            'tool-duplicate-keywords' => [
                'label'   => __('Duplicate keywords', 'pretty-link'),
                'summary' => __('Find the phrases claimed by more than one link and settle them, so automatic replacements always point where you meant.', 'pretty-link'),
            ],
            'tool-mu-dispatch'        => [
                'label'   => __('Redirect turbo mode', 'pretty-link'),
                'summary' => __('Serve redirects before WordPress even boots. On a busy site that is the difference between a redirect that feels instant and one that waits on a full page load.', 'pretty-link'),
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
                // PlanCatalog holds the bare marketing URL; attribution is
                // added here so every upsell-surface link is tagged while
                // the catalog stays a clean source of truth.
                'url'     => self::tagSalesUrl($addon['url'], 'addon-detail', $slug),
            ];
        }
        return $out;
    }

    /**
     * Look up one feature by id. Returns null when the id is unknown — keep
     * callers honest (typos blow up visibly rather than silently rendering
     * an empty teaser).
     *
     * @param  string $id Pro feature id (a key of self::features()).
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
            'addonsUrl'      => self::tagSalesUrl(self::ADDONS_URL, 'admin'),
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

        // Writing to $submenu by hand skips the capability check that
        // add_submenu_page() does for every other row under this parent, so we
        // have to do it ourselves. Without this, a user who can't reach any
        // Pretty Links page still gets a submenu array containing only this
        // row — which is enough to stop WordPress removing the parent, leaving
        // a menu whose flyout renders nothing and whose parent link points at
        // the pricing page. See Page::register() for the full mechanism.
        if (!current_user_can(Page::capability())) {
            return;
        }

        global $submenu;
        $parent = Page::SLUG;
        $url    = self::upgradeUrl('menu');

        // The add_submenu_page() function doesn't accept a target attribute,
        // but we can manipulate $submenu directly (same pattern WP uses
        // internally). Each row is [ title, capability, slug(url), page_title, class ].
        $submenu[$parent][] = [ // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
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
     * @param string $plan Paid plan slug (e.g. a key from PlanCatalog).
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
     * @param  string $plan      Paid plan slug to build the CTA for.
     * @param  string $returnUrl URL to return to after checkout; appended as `return_url` when non-empty.
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
     * @param string $plan      Paid plan slug; used as the `utm_content` value (falls back to "pro" when empty).
     * @param string $returnUrl URL to return to after checkout; rawurlencoded into `return_url` when non-empty.
     */
    public static function planUpgradeUrl(string $plan, string $returnUrl = ''): string
    {
        $url = self::tagSalesUrl(self::PRICING_URL, 'onboarding', $plan !== '' ? $plan : 'pro');
        if ($returnUrl !== '') {
            $url = add_query_arg('return_url', rawurlencode($returnUrl), $url);
        }
        return $url;
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
}
