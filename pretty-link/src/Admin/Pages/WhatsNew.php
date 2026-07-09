<?php

declare(strict_types=1);

namespace PrettyLinks\Admin\Pages;

defined('ABSPATH') || exit;

use PrettyLinks\Admin\Page;
use PrettyLinks\Admin\Upsell\ProUpsell;
use PrettyLinks\Licensing\ProState;

/**
 * Hidden "What's New in 4.0" landing page.
 *
 * Linked from the persistent notice in Onboarding\WhatsNew. Not listed in the
 * menu; reachable via admin.php?page=pretty-link-whats-new.
 *
 * Content is authored inline here — Lite cards describe the rewrite, Pro cards
 * tease paid features with a badge for FOMO. This is the one intentional
 * exception to the "Lite has no knowledge of Pro" rule: a single marketing
 * page where Pro mentions are the whole point.
 */
class WhatsNew
{
    public const SLUG = 'pretty-link-whats-new';

    /**
     * Register the hidden "What's New in 4.0" submenu page.
     *
     * @return string The resulting page hook suffix.
     */
    public static function register(): string
    {
        $hook = (string) add_submenu_page(
            '',
            esc_html__('What\'s New in Pretty Links 4.0', 'pretty-link'),
            esc_html__('What\'s New', 'pretty-link'),
            Page::capability(),
            self::SLUG,
            [self::class, 'render']
        );
        // '' parent keeps the page out of the visible menu while keeping it URL-reachable.
        // WP's get_admin_page_title() only searches $menu when parent is empty, so it never
        // finds this page's title — leaving $title null and triggering a PHP deprecation in
        // admin-header.php. Pre-set $title on load-* before admin-header.php runs instead.
        add_action('load-' . $hook, static function (): void {
            $GLOBALS['title'] = "What's New in Pretty Links 4.0"; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Intentionally setting the admin page <title> for this screen.
        });
        return $hook;
    }

    /**
     * Render the "What's New in 4.0" marketing landing page.
     *
     * @return void
     */
    public static function render(): void
    {
        if (!current_user_can(Page::capability())) {
            wp_die(esc_html__('You do not have permission to access this page.', 'pretty-link'));
        }

        self::enqueueAssets();

        $logo         = esc_url(Page::getContainer()->get('BASE_URL') . 'assets/images/logo-horizontal.svg');
        $linksUrl     = esc_url(admin_url('admin.php?page=pretty-link-links'));
        $clicksUrl    = esc_url(admin_url('admin.php?page=pretty-link-clicks'));
        $optionsUrl   = esc_url(admin_url('admin.php?page=pretty-link-options'));
        $addonsUrl    = esc_url(admin_url('admin.php?page=pretty-link-addons'));
        $dashboardUrl = esc_url(admin_url('admin.php?page=pretty-link'));

        $liteCards = [
            [
                'icon'  => 'admin',
                'title' => __('A rebuilt admin', 'pretty-link'),
                'body'  => __('Every screen was rebuilt with WordPress\' own interface components. Pages load faster, read more clearly, and you can get around the whole thing from the keyboard.', 'pretty-link'),
            ],
            [
                'icon'  => 'speed',
                'title' => __('Blazing-fast redirects', 'pretty-link'),
                'body'  => __('Each redirect does one quick database lookup and saves the click afterward, so visitors are on their way first and never wait on analytics.', 'pretty-link'),
            ],
            [
                'icon'  => 'trash',
                'title' => __('Trash and restore', 'pretty-link'),
                'body'  => __('Links move to a Trash view before they\'re gone for good, so you can bring back anything you removed by mistake. No database work required.', 'pretty-link'),
            ],
            [
                'icon'  => 'shield',
                'title' => __('Reserved-slug protection', 'pretty-link'),
                'body'  => __('Pretty Links now catches slugs that would clash with WordPress core paths, REST routes, and other plugins, so a stray link can\'t break /wp-admin.', 'pretty-link'),
            ],
            [
                'icon'  => 'retention',
                'title' => __('Configurable click retention', 'pretty-link'),
                'body'  => __('Choose how long to keep click history, anywhere from 30 days to 2 years, and a scheduled task tidies up old clicks for you.', 'pretty-link'),
            ],
            [
                'icon'  => 'privacy',
                'title' => __('Privacy built in', 'pretty-link'),
                'body'  => __('Optional IP anonymization, geolocation from CDN headers (Cloudflare, Akamai, Google), and full support for WordPress\' privacy export and erasure tools.', 'pretty-link'),
            ],
            [
                'icon'  => 'editor',
                'title' => __('Block editor & Classic integrations', 'pretty-link'),
                'body'  => __('Insert and create pretty links without leaving the post editor: a dedicated block with type-ahead search, plus a refreshed button for the Classic editor.', 'pretty-link'),
            ],
            [
                'icon'  => 'slug',
                'title' => __('Configurable link prefix', 'pretty-link'),
                'body'  => __('New installs use a /go/ prefix by default so caching layers can skip link traffic cleanly. Existing sites keep the prefix they already have.', 'pretty-link'),
            ],
            [
                'icon'  => 'pay',
                'title' => __('PrettyPay™ Stripe links', 'pretty-link'),
                'body'  => __('Collect a Stripe payment before the redirect with a dedicated Pay Link. Connect Stripe once and every checkout is ready to go.', 'pretty-link'),
            ],
            [
                'icon'  => 'bot',
                'title' => __('Bot & device detection', 'pretty-link'),
                'body'  => __('Every click records device type, browser, and whether it looks like a bot, so your reports reflect real visitors instead of crawlers.', 'pretty-link'),
            ],
            [
                'icon'  => 'bulk',
                'title' => __('Bulk actions & search', 'pretty-link'),
                'body'  => __('Search, sort, and filter thousands of links in an instant, then trash, restore, toggle nofollow, or switch tracking on many at once from the new Links table.', 'pretty-link'),
            ],
            [
                'icon'  => 'exchange',
                'title' => __('CSV import & click-history export', 'pretty-link'),
                'body'  => __('Import hundreds of links from a spreadsheet, and export full click history to CSV with filters, date ranges, and Unicode-safe encoding.', 'pretty-link'),
            ],
        ];

        $proCards = [
            [
                'icon'  => 'split',
                'title' => __('Split testing with reports', 'pretty-link'),
                'body'  => __('Send visitors across several destinations and see which one performs best, with per-variant charts, conversion tracking, and automatic winner detection.', 'pretty-link'),
            ],
            [
                'icon'  => 'target',
                'title' => __('Smart targeting', 'pretty-link'),
                'body'  => __('Send visitors to different destinations based on their country, device, browser, or the time of day, all without touching code.', 'pretty-link'),
            ],
            [
                'icon'  => 'qr',
                'title' => __('QR codes for every link', 'pretty-link'),
                'body'  => __('Generate a PNG or SVG QR code for any link on demand, with an optional logo overlay and your choice of size and colors.', 'pretty-link'),
            ],
            [
                'icon'  => 'bar',
                'title' => __('Pretty Bar interstitials', 'pretty-link'),
                'body'  => __('Wrap any destination in a branded top bar, bottom bar, or floating pill, complete with your logo, your colors, and optional share buttons.', 'pretty-link'),
            ],
            [
                'icon'  => 'autolink',
                'title' => __('Automatic affiliate linking', 'pretty-link'),
                'body'  => __('Turn keywords into links automatically across posts, pages, custom post types, WooCommerce, and more. Pretty Links can also rewrite existing URLs and create new links from patterns in your content, so your published writing earns without manual edits.', 'pretty-link'),
            ],
            [
                'icon'  => 'health',
                'title' => __('Link health monitoring', 'pretty-link'),
                'body'  => __('Scheduled checks catch broken destinations before your visitors do, with optional email digests of what needs attention.', 'pretty-link'),
            ],
            [
                'icon'  => 'taxonomy',
                'title' => __('Link categories', 'pretty-link'),
                'body'  => __('Organize hundreds or thousands of links into categories, then filter the dashboard and reports by category at a glance.', 'pretty-link'),
            ],
            [
                'icon'  => 'expire',
                'title' => __('Expiring links', 'pretty-link'),
                'body'  => __('Set a date, time, or click count, and Pretty Links disables the link on its own once it\'s reached. Handy for limited-time promos.', 'pretty-link'),
            ],
            [
                'icon'  => 'cloak',
                'title' => __('Cloaked & referrer-wiping redirects', 'pretty-link'),
                'body'  => __('Go beyond 301/302/307 with cloak, JavaScript, and Pretty Bar redirects. Meta Refresh even clears the Referer header, so merchants can\'t see where your affiliate clicks came from and your traffic sources stay private.', 'pretty-link'),
            ],
            [
                'icon'  => 'kernel',
                'title' => __('Redirect turbo mode', 'pretty-link'),
                'body'  => __('Pro installs a small must-use plugin that answers pretty-link requests before the rest of WordPress loads, skipping your theme, plugins, and page builder. Built for high-traffic affiliate sites.', 'pretty-link'),
            ],
            [
                'icon'  => 'reports',
                'title' => __('Custom reports & A/B conversion tracking', 'pretty-link'),
                'body'  => __('Combine clicks from any group of links into one report, and track real conversions with a one-line pixel you add to your thank-you or confirmation pages. See which split-test variant actually makes sales, not just which gets the most clicks.', 'pretty-link'),
            ],
            [
                'icon'  => 'analytics',
                'title' => __('Visual click history', 'pretty-link'),
                'body'  => __('Pro adds summary cards, an over-time line chart, a top-links list, and breakdowns by country, referrer, device, and browser, right on the Click History page. Spot trends without exporting a spreadsheet.', 'pretty-link'),
            ],
        ];

        // Order: Marketer-tier add-ons first (Link in Bio, Developer Tools),
        // then Executive-tier (Splash Pages, Product Displays, UTMs, User
        // Links). Mirrors the plan progression so a Marketer-plan user sees
        // their entitlements at the top of the section.
        $addonCards = [
            [
                'icon'  => 'bio',
                'title' => __('Link in Bio', 'pretty-link'),
                'body'  => __('Publish one page on your own domain with all the links you want to share. Perfect for the single-link field on Instagram, TikTok, and other bios, branded as you instead of a third-party shortener.', 'pretty-link'),
            ],
            [
                'icon'  => 'api',
                'title' => __('Developer Tools', 'pretty-link'),
                'body'  => __('A documented REST API and webhook events for when links are added, updated, trashed, restored, or removed. Connect Pretty Links to your stack and automate workflows without touching the database.', 'pretty-link'),
            ],
            [
                'icon'  => 'droplets',
                'title' => __('Splash Pages', 'pretty-link'),
                'body'  => __('A new splash redirect that shows a branded page (heading, image or video, and up to two link buttons) before sending visitors on. Includes four templates and a per-link funnel report that ties CTA clicks back to each view.', 'pretty-link'),
            ],
            [
                'icon'  => 'box',
                'title' => __('Product Displays', 'pretty-link'),
                'body'  => __('Drop product and product-group display boxes into any post or page. Each one shows an image, price, and call-to-action button that links through your pretty links, so every click is tracked.', 'pretty-link'),
            ],
            [
                'icon'  => 'target',
                'title' => __('UTMs', 'pretty-link'),
                'body'  => __('Build Google Analytics UTM tags (source, medium, campaign, content, term, and ID) right on the link form. No hand-editing URLs, no broken tags, no copy-pasting from a spreadsheet.', 'pretty-link'),
            ],
            [
                'icon'  => 'dashboard',
                'title' => __('User Links', 'pretty-link'),
                'body'  => sprintf(
                    // Translators: %1$s: opening MemberPress link anchor, %2$s: closing anchor.
                    __('Add a self-service link manager to a front-end page so logged-in users can create and manage their own short links without ever seeing wp-admin. Pair it with %1$sMemberPress%2$s to charge for access and run your own branded link-shortening service.', 'pretty-link'),
                    '<a href="https://memberpress.com/?utm_source=prli&utm_medium=whats-new&utm_campaign=user-links" target="_blank" rel="noopener noreferrer">',
                    '</a>'
                ),
            ],
        ];

        // Show the CTA when the user hasn't paid yet — Lite installs
        // (no Pro on disk) AND Pro installs whose license is missing
        // or expired. The "installed but not activated" case still
        // needs the upgrade pitch because the user can't actually use
        // any Pro features until they activate. Goes quiet only after
        // a paid, activated license is in place.
        $showUpgradeCta = !ProState::isProInstalledAndActivated();
        $upgradeUrl     = ProUpsell::upgradeUrl('whats-new');
        $addonsUrl      = esc_url(add_query_arg(
            [
                'utm_source'   => 'prli',
                'utm_medium'   => 'admin',
                'utm_campaign' => 'whats-new',
            ],
            ProUpsell::ADDONS_URL
        ));

        ?>
        <div id="prli-admin-root" class="wrap prli-whats-new-wrap">
            <header class="prli-whats-new-header">
                <img src="<?php echo esc_attr($logo); ?>" alt="<?php esc_attr_e('Pretty Links', 'pretty-link'); ?>" class="prli-whats-new-logo" />
                <h1><?php esc_html_e('What\'s new in Pretty Links 4.0', 'pretty-link'); ?></h1>
                <p class="prli-whats-new-lede">
                    <?php esc_html_e('We rebuilt Pretty Links from scratch to make it faster and easier to use. Here\'s what\'s new.', 'pretty-link'); ?>
                </p>
            </header>

            <section class="prli-whats-new-section">
                <h2 class="prli-whats-new-section-title"><?php esc_html_e('In every install', 'pretty-link'); ?></h2>
                <p class="prli-whats-new-section-lede">
                    <?php esc_html_e('This is the new foundation under both the free and Pro versions. It\'s faster, safer, and easier to work in day to day.', 'pretty-link'); ?>
                </p>
                <div class="prli-whats-new-grid">
                    <?php foreach ($liteCards as $card) : ?>
                        <article class="prli-whats-new-card prli-whats-new-card--<?php echo esc_attr($card['icon']); ?>">
                            <h3><?php echo esc_html($card['title']); ?></h3>
                            <p><?php echo wp_kses_post($card['body']); ?></p>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="prli-whats-new-section">
                <h2 class="prli-whats-new-section-title">
                    <?php esc_html_e('Unlock even more with Pro', 'pretty-link'); ?>
                </h2>
                <p class="prli-whats-new-section-lede">
                    <?php esc_html_e('Pro builds on the same foundation with extra tools for affiliate marketers and content creators.', 'pretty-link'); ?>
                </p>
                <div class="prli-whats-new-grid">
                    <?php foreach ($proCards as $card) : ?>
                        <article class="prli-whats-new-card prli-whats-new-card--pro prli-whats-new-card--<?php echo esc_attr($card['icon']); ?>">
                            <span class="prli-whats-new-badge"><?php esc_html_e('Pro', 'pretty-link'); ?></span>
                            <h3><?php echo esc_html($card['title']); ?></h3>
                            <p><?php echo wp_kses_post($card['body']); ?></p>
                        </article>
                    <?php endforeach; ?>
                </div>
                <?php if ($showUpgradeCta) : ?>
                    <div class="prli-whats-new-pro-cta">
                        <a
                            class="components-button is-primary"
                            href="<?php echo esc_url($upgradeUrl); ?>"
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            <?php echo esc_html(ProUpsell::ctaLabel()); ?>
                        </a>
                    </div>
                <?php endif; ?>
            </section>

            <section class="prli-whats-new-section">
                <h2 class="prli-whats-new-section-title"><?php esc_html_e('Pro add-ons', 'pretty-link'); ?></h2>
                <p class="prli-whats-new-section-lede">
                    <?php
                    printf(
                        // Translators: %1$s: opening anchor to the add-ons page, %2$s: closing anchor.
                        esc_html__('Six Pro %1$sadd-ons%2$s take Pretty Links further: hosted bio pages, REST and webhook access, branded splash pages, product cards, a UTM builder on the link form, and a front-end dashboard for your users. Link in Bio and Developer Tools come with the Marketer plan; the rest unlock on Executive (Super Affiliate).', 'pretty-link'),
                        '<a href="' . esc_url($addonsUrl) . '" target="_blank" rel="noopener noreferrer">',
                        '</a>'
                    );
                    ?>
                </p>
                <div class="prli-whats-new-grid">
                    <?php foreach ($addonCards as $card) : ?>
                        <article class="prli-whats-new-card prli-whats-new-card--pro prli-whats-new-card--<?php echo esc_attr($card['icon']); ?>">
                            <span class="prli-whats-new-badge"><?php esc_html_e('Add-on', 'pretty-link'); ?></span>
                            <h3><?php echo esc_html($card['title']); ?></h3>
                            <p><?php echo wp_kses_post($card['body']); ?></p>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="prli-whats-new-actions">
                <a class="components-button is-primary" href="<?php echo esc_attr($linksUrl); ?>">
                    <?php esc_html_e('Manage your links', 'pretty-link'); ?>
                </a>
                <a class="components-button is-secondary" href="<?php echo esc_attr($clicksUrl); ?>">
                    <?php esc_html_e('View click history', 'pretty-link'); ?>
                </a>
                <a class="components-button is-secondary" href="<?php echo esc_attr($optionsUrl); ?>">
                    <?php esc_html_e('Review options', 'pretty-link'); ?>
                </a>
                <a class="components-button is-tertiary" href="<?php echo esc_attr($dashboardUrl); ?>">
                    <?php esc_html_e('Back to dashboard', 'pretty-link'); ?>
                </a>
            </section>
        </div>
        <?php
    }

    /**
     * Enqueue the page-specific stylesheet for the "What's New" screen.
     *
     * @return void
     */
    private static function enqueueAssets(): void
    {
        $base = Page::getContainer()->get('BASE_URL');
        $path = Page::getContainer()->get('BASE_PATH');
        $rel  = 'assets/css/whats-new.css';
        if (is_file($path . $rel)) {
            wp_enqueue_style(
                'prli-whats-new',
                $base . $rel,
                ['wp-components'],
                \PrettyLinks\Bootstrap::version()
            );
        }
    }
}
