<?php

declare(strict_types=1);

namespace PrettyLinks\Admin\Pages;

defined('ABSPATH') || exit;

use PrettyLinks\Admin\Page;
use PrettyLinks\Onboarding\Wizard;

/**
 * Hidden submenu page that hosts the onboarding wizard.
 *
 * Registered under the top-level Pretty Links menu, then immediately hidden
 * via the `submenu_file` pattern (we add the page so `admin.php?page=...`
 * resolves, but we don't want it cluttering the menu itself).
 */
class Onboarding
{
    public const SLUG = 'pretty-link-onboarding';

    /**
     * Register the hidden onboarding wizard submenu page.
     *
     * @return string The resulting page hook suffix.
     */
    public static function register(): string
    {
        $hook = (string) add_submenu_page(
            '',
            esc_html__('Welcome to Pretty Links', 'pretty-link'),
            esc_html__('Onboarding', 'pretty-link'),
            Page::capability(),
            self::SLUG,
            [self::class, 'render']
        );
        // '' parent keeps the page out of the visible menu while keeping it URL-reachable.
        // WP's get_admin_page_title() only searches $menu when parent is empty, so it never
        // finds this page's title — leaving $title null and triggering a PHP deprecation in
        // admin-header.php. Pre-set $title on load-* before admin-header.php runs instead.
        add_action('load-' . $hook, static function (): void {
            $GLOBALS['title'] = 'Welcome to Pretty Links'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Intentionally setting the admin page <title> for this screen.
        });
        return $hook;
    }

    /**
     * Render the onboarding wizard.
     *
     * @return void
     */
    public static function render(): void
    {
        (new Wizard())->render();
    }
}
