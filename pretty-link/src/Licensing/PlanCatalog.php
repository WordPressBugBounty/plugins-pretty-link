<?php

declare(strict_types=1);

namespace PrettyLinks\Licensing;

/**
 * Single source of truth for paid plans and their bundled add-ons.
 *
 * Every constant here matches the canonical mothership product_slug (for
 * plans) or the installed WordPress plugin folder name (for add-ons). Any
 * Lite-side code that needs to ask "what plan is this?" or "does this plan
 * include this add-on?" without a mothership round-trip should read from
 * this file — never hardcode a slug elsewhere.
 *
 * Legacy plans (pro-blogger, pro-developer) are intentionally listed with
 * an empty add-on set: they are still valid Pro licenses, but they predate
 * the add-on entitlement model and grant no add-on installs.
 */
final class PlanCatalog
{
    public const PLAN_BEGINNER             = 'pretty-link-beginner';
    public const PLAN_MARKETER             = 'pretty-link-marketer';
    public const PLAN_EXECUTIVE            = 'pretty-link-executive';
    public const PLAN_LEGACY_PRO_BLOGGER   = 'pretty-link-pro-blogger';
    public const PLAN_LEGACY_PRO_DEVELOPER = 'pretty-link-pro-developer';

    public const ADDON_LINK_IN_BIO      = 'pretty-link-link-in-bio';
    public const ADDON_DEVELOPER_TOOLS  = 'pretty-link-developer-tools';
    public const ADDON_PRODUCT_DISPLAYS = 'pretty-link-product-displays';
    public const ADDON_SPLASH_PAGES     = 'pretty-link-splash-pages';
    public const ADDON_UTMS             = 'pretty-link-utms';
    public const ADDON_USER_LINKS       = 'pretty-link-user-links';

    /**
     * Plan registry. Order matters for tier comparisons via ::tierRank().
     *
     * @return array<string, array{label: string, legacy: bool, addons: string[]}>
     */
    public static function plans(): array
    {
        return [
            self::PLAN_BEGINNER             => [
                'label'  => __('Beginner', 'pretty-link'),
                'legacy' => false,
                'addons' => [],
            ],
            self::PLAN_MARKETER             => [
                'label'  => __('Marketer', 'pretty-link'),
                'legacy' => false,
                'addons' => [
                    self::ADDON_LINK_IN_BIO,
                    self::ADDON_DEVELOPER_TOOLS,
                ],
            ],
            self::PLAN_EXECUTIVE            => [
                'label'  => __('Executive', 'pretty-link'),
                'legacy' => false,
                'addons' => [
                    self::ADDON_LINK_IN_BIO,
                    self::ADDON_DEVELOPER_TOOLS,
                    self::ADDON_PRODUCT_DISPLAYS,
                    self::ADDON_SPLASH_PAGES,
                    self::ADDON_UTMS,
                    self::ADDON_USER_LINKS,
                ],
            ],
            self::PLAN_LEGACY_PRO_BLOGGER   => [
                'label'  => __('Pro Blogger', 'pretty-link'),
                'legacy' => true,
                'addons' => [],
            ],
            self::PLAN_LEGACY_PRO_DEVELOPER => [
                'label'  => __('Pro Developer', 'pretty-link'),
                'legacy' => true,
                'addons' => [],
            ],
        ];
    }

    /**
     * Add-on registry.
     *
     * @return array<string, array{label: string, summary: string, url: string, plans: string[]}>
     */
    public static function addons(): array
    {
        return [
            self::ADDON_LINK_IN_BIO      => [
                'label'   => __('Link in Bio', 'pretty-link'),
                'summary' => __('Publish a hosted mini-site of your best pretty links — one URL for every platform that only allows a single link.', 'pretty-link'),
                'url'     => 'https://prettylinks.com/add-ons/link-in-bio/',
                'plans'   => [self::PLAN_MARKETER, self::PLAN_EXECUTIVE],
            ],
            self::ADDON_DEVELOPER_TOOLS  => [
                'label'   => __('Developer Tools', 'pretty-link'),
                'summary' => __('REST API keys, webhook dispatch, and programmatic link management for developers building on top of Pretty Links.', 'pretty-link'),
                'url'     => 'https://prettylinks.com/add-ons/developer-tools/',
                'plans'   => [self::PLAN_MARKETER, self::PLAN_EXECUTIVE],
            ],
            self::ADDON_PRODUCT_DISPLAYS => [
                'label'   => __('Product Displays', 'pretty-link'),
                'summary' => __('Insert rich product cards (image, price, buy button) that auto-update from your affiliate feeds and track clicks as pretty links.', 'pretty-link'),
                'url'     => 'https://prettylinks.com/add-ons/product-displays/',
                'plans'   => [self::PLAN_EXECUTIVE],
            ],
            self::ADDON_SPLASH_PAGES     => [
                'label'   => __('Splash Pages', 'pretty-link'),
                'summary' => __('Show a branded interstitial (heading, media, CTAs, optional countdown) before forwarding visitors — affiliate disclosures, choose-your-own-destination, video gates.', 'pretty-link'),
                'url'     => 'https://prettylinks.com/add-ons/splash-pages/',
                'plans'   => [self::PLAN_EXECUTIVE],
            ],
            self::ADDON_UTMS             => [
                'label'   => __('UTMs', 'pretty-link'),
                'summary' => __('Build Google Analytics UTM parameters (source, medium, campaign, content, term, ID) right on the link form — no manual URL editing.', 'pretty-link'),
                'url'     => 'https://prettylinks.com/add-ons/utms/',
                'plans'   => [self::PLAN_EXECUTIVE],
            ],
            self::ADDON_USER_LINKS       => [
                'label'   => __('User Links', 'pretty-link'),
                'summary' => __('Drop a self-service pretty-link manager onto a front-end page so logged-in users create and manage their own shortlinks without ever touching wp-admin.', 'pretty-link'),
                'url'     => 'https://prettylinks.com/add-ons/user-links/',
                'plans'   => [self::PLAN_EXECUTIVE],
            ],
        ];
    }

    /**
     * Get the add-on slugs included in the given plan.
     *
     * @param string $planSlug The plan slug to look up.
     *
     * @return string[] Add-on slugs included in the given plan.
     */
    public static function addonsForPlan(string $planSlug): array
    {
        $plan = self::plans()[$planSlug] ?? null;
        return $plan === null ? [] : $plan['addons'];
    }

    /**
     * Get the plan slugs that include the given add-on.
     *
     * @param string $addonSlug The add-on slug to look up.
     *
     * @return string[] Plan slugs that include the given add-on.
     */
    public static function plansForAddon(string $addonSlug): array
    {
        $addon = self::addons()[$addonSlug] ?? null;
        return $addon === null ? [] : $addon['plans'];
    }

    /**
     * Determine whether the given plan includes the given add-on.
     *
     * @param string $planSlug  The plan slug to check.
     * @param string $addonSlug The add-on slug to check.
     *
     * @return boolean True when the plan includes the add-on.
     */
    public static function planAllowsAddon(string $planSlug, string $addonSlug): bool
    {
        return in_array($addonSlug, self::addonsForPlan($planSlug), true);
    }

    /**
     * Determine whether the given plan slug is a recognized plan.
     *
     * @param string $planSlug The plan slug to check.
     *
     * @return boolean True when the plan is known.
     */
    public static function isKnownPlan(string $planSlug): bool
    {
        return isset(self::plans()[$planSlug]);
    }

    /**
     * Determine whether the given plan slug is a legacy (no longer sold) plan.
     *
     * @param string $planSlug The plan slug to check.
     *
     * @return boolean True when the plan is a legacy plan.
     */
    public static function isLegacyPlan(string $planSlug): bool
    {
        $plan = self::plans()[$planSlug] ?? null;
        return $plan !== null && $plan['legacy'];
    }

    /**
     * Currently-sold (non-legacy) paid plans, in cheapest→most-expensive order.
     *
     * @return string[]
     */
    public static function currentPlans(): array
    {
        $current = [];
        foreach (self::plans() as $slug => $plan) {
            if (!$plan['legacy']) {
                $current[] = $slug;
            }
        }
        return $current;
    }

    /**
     * Get the display label for the given plan slug.
     *
     * @param string $planSlug The plan slug to look up.
     *
     * @return string The plan label, defaulting to 'Pro' for unknown plans.
     */
    public static function planLabel(string $planSlug): string
    {
        $plan = self::plans()[$planSlug] ?? null;
        return $plan === null ? __('Pro', 'pretty-link') : $plan['label'];
    }

    /**
     * Get the display label for the given add-on slug.
     *
     * @param string $addonSlug The add-on slug to look up.
     *
     * @return string The add-on label, or an empty string for unknown add-ons.
     */
    public static function addonLabel(string $addonSlug): string
    {
        $addon = self::addons()[$addonSlug] ?? null;
        return $addon === null ? '' : $addon['label'];
    }

    /**
     * Cheapest current plan that grants the given add-on. Returns '' for
     * unknown add-ons.
     *
     * @param string $addonSlug The add-on slug to look up.
     *
     * @return string The cheapest current plan slug, or '' when none grants it.
     */
    public static function lowestPlanForAddon(string $addonSlug): string
    {
        foreach (self::currentPlans() as $planSlug) {
            if (self::planAllowsAddon($planSlug, $addonSlug)) {
                return $planSlug;
            }
        }
        return '';
    }

    /**
     * Comparable tier rank for two current plan slugs. Higher number = higher
     * tier. Unknown / legacy / empty plans return 0 so they never pass a
     * "does the user's plan cover X" check by accident.
     *
     * @param string $planSlug The plan slug to rank.
     *
     * @return integer The tier rank (higher is a higher tier), or 0 when unknown.
     */
    public static function tierRank(string $planSlug): int
    {
        $rank = array_flip(self::currentPlans());
        return isset($rank[$planSlug]) ? $rank[$planSlug] + 1 : 0;
    }
}
