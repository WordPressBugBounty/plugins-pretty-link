<?php

declare(strict_types=1);

namespace PrettyLinks\Admin\Pages;

use PrettyLinks\Admin\Page;

class PayLinks
{
    public const SLUG = 'pretty-link-pay-links';

    /**
     * CSS class tagged onto the submenu <li> so admin-chrome.css can paint it
     * the PrettyPay brand green. Targeting a stable class beats the previous
     * href-suffix selector, which lost to WP core's current-item color
     * whenever the page was active (the "not always green" bug).
     */
    public const MENU_CSS_HOOK = 'prli-paylinks-menu-item';

    /**
     * Register the PrettyPay™ Links submenu page and tag its menu item.
     *
     * @return string The resulting page hook suffix, or an empty string when
     *                the PrettyPay feature is disabled.
     */
    public static function register(): string
    {
        // Master switch — hidden entirely when the site opts out of
        // PrettyPay. Existing pay links still resolve at the redirect
        // engine layer; this is purely a UI gate.
        if (!self::enabled()) {
            return '';
        }
        $hook = (string) add_submenu_page(
            Page::SLUG,
            esc_html__('PrettyPay™ Links', 'pretty-link'),
            esc_html__('PrettyPay™ Links', 'pretty-link'),
            Page::capability(),
            self::SLUG,
            [self::class, 'render'],
            30
        );

        // Add_submenu_page() has no class parameter, so set the 5th $submenu
        // element directly — the slot WP renders onto the <li> (same one
        // ProUpsell uses). Done inline at registration, not via a post-hoc
        // hook that rewrites the menu afterward.
        global $submenu;
        if (isset($submenu[Page::SLUG]) && is_array($submenu[Page::SLUG])) {
            foreach ($submenu[Page::SLUG] as &$item) {
                if (isset($item[2]) && $item[2] === self::SLUG) {
                    $item[4] = isset($item[4]) && $item[4] !== ''
                        ? $item[4] . ' ' . self::MENU_CSS_HOOK
                        : self::MENU_CSS_HOOK;
                    break;
                }
            }
            unset($item);
        }

        return $hook;
    }

    /**
     * Paint the PrettyPay submenu item brand green. Attached to WP core's
     * `admin-menu` stylesheet handle (the same mechanism the top-level menu
     * icon uses in {@see Page::enqueueMenuIconStyle}) so it prints
     * render-blocking with the core menu styles — no grey→green flash that a
     * separate enqueued file produces. Targets the stable `<li>` class set in
     * register(); the `.current` variant out-specifies WP core's current-item
     * color so the item stays green while it's the active page.
     */
    public static function enqueueMenuStyle(): void
    {
        if (!self::enabled()) {
            return;
        }
        $css = sprintf(
            '#adminmenu .wp-submenu li.%1$s a,'
            . '#adminmenu .wp-submenu li.%1$s a:hover,'
            . '#adminmenu .wp-submenu li.%1$s a:focus,'
            . '#adminmenu .wp-submenu li.%1$s.current a,'
            . '#adminmenu .wp-has-current-submenu .wp-submenu li.%1$s.current a'
            . '{color:#89F336}',
            self::MENU_CSS_HOOK
        );
        wp_add_inline_style('admin-menu', $css);
    }

    /**
     * Whether the PrettyPay feature is enabled for this site.
     * Default is on — must be explicitly turned off via Options.
     */
    public static function enabled(): bool
    {
        $options = (array) (get_option('prli_options', []) ?: []);
        return !isset($options['prettypay_enabled']) || (bool) $options['prettypay_enabled'];
    }

    /**
     * Render the React mount point for the PrettyPay™ Links screen.
     *
     * @return void
     */
    public static function render(): void
    {
        echo '<div class="wrap">'
           . '<div id="prli-notices-app"></div>'
           . '<div id="prli-admin-root" data-page="pay-links"></div>'
           . '</div>';
    }
}
