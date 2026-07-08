<?php

declare(strict_types=1);

namespace PrettyLinks\Stripe;

defined('ABSPATH') || exit;

use PrettyLinks\Admin\Page;
use PrettyLinks\Admin\Pages\Options as OptionsPage;

/**
 * On-site `/pl-customer-portal` route. 1:1 port of v3
 * `PrliStripeController::customer_portal_redirect()`:
 *
 *  - Serves a stable, copyable URL on the customer's own domain that 302s to
 *    the configured Stripe Customer Portal login page. Admins hand this link
 *    out (emails, account pages) instead of the raw Stripe URL.
 *  - Slug is filterable (`pl_customer_portal_page_name`, default
 *    `pl-customer-portal`) to match v3 exactly so links distributed under v3
 *    keep resolving after upgrade.
 *  - When the portal isn't configured yet: admins get a "Configure" prompt,
 *    everyone else a plain notice (v3 parity).
 */
final class CustomerPortal
{
    /**
     * Registers the customer-portal request hooks.
     *
     * @return void
     */
    public static function loadHooks(): void
    {
        add_action('parse_request', [self::class, 'maybeRedirect']);
    }

    /**
     * The on-site page slug that proxies to the Stripe portal login URL.
     */
    public static function pageName(): string
    {
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Public 3.x filter; renaming would break drop-in compatibility for existing integrations.
        return (string) apply_filters('pl_customer_portal_page_name', 'pl-customer-portal');
    }

    /**
     * Redirects portal-page requests to the Stripe portal login URL.
     *
     * @param \WP $wp The WordPress environment instance.
     *
     * @return void
     */
    public static function maybeRedirect(\WP $wp): void
    {
        $pageName = self::pageName();
        $matched  = (isset($wp->query_vars['pagename']) && $wp->query_vars['pagename'] === $pageName)
            || (isset($wp->query_vars['name']) && $wp->query_vars['name'] === $pageName);
        if (!$matched) {
            return;
        }

        $portal = get_option('prli_stripe_customer_portal');
        if (is_array($portal) && !empty($portal['login_page']['url'])) {
            // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Redirecting to the Stripe-hosted portal login URL (external by definition).
            wp_redirect(esc_url_raw((string) $portal['login_page']['url']));
            exit;
        }

        $configureLink = '';
        if (current_user_can(Page::capability())) {
            $configureLink = sprintf(
                '<br><br><a href="%1$s" class="button button-large">%2$s</a>',
                esc_url(admin_url('admin.php?page=' . OptionsPage::SLUG . '#payments')),
                esc_html__('Configure Customer Portal', 'pretty-link')
            );
        }

        wp_die(
            esc_html__('The Customer Portal is not yet configured.', 'pretty-link')
            . wp_kses_post($configureLink)
        );
    }
}
