<?php

declare(strict_types=1);

namespace PrettyLinks\Admin;

use PrettyLinks\Support\HasStaticContainer;
use PrettyLinks\Support\StaticContainerAwareness;

/**
 * Top-level Pretty Links admin menu.
 *
 * The parent slug is `pretty-link`. The landing page renders a dashboard
 * React mount point. Submenus attach to this parent via `add_submenu_page`
 * in their own classes (see Admin\Pages\*).
 */
class Page implements StaticContainerAwareness
{
    use HasStaticContainer;

    public const CAPABILITY = 'manage_options';

    public const SLUG = 'pretty-link';

    /**
     * Translated page title shown in the menu and document title.
     *
     * @return string
     */
    public static function pageTitle(): string
    {
        return esc_html__('Pretty Links', 'pretty-link');
    }

    /**
     * Register the top-level menu and its Dashboard submenu.
     *
     * @return string The menu page hook suffix returned by add_menu_page().
     */
    public static function register(): string
    {
        $hook = add_menu_page(
            self::pageTitle(),
            esc_html__('Pretty Links', 'pretty-link'),
            self::capability(),
            self::SLUG,
            [self::class, 'render'],
            self::menuIcon(),
            100
        );

        // Registering the first submenu with the parent's own slug overrides
        // the auto-duplicated "Pretty Links" label WordPress would otherwise
        // generate from the top-level menu title.
        add_submenu_page(
            self::SLUG,
            self::pageTitle(),
            esc_html__('Dashboard', 'pretty-link'),
            self::capability(),
            self::SLUG,
            [self::class, 'render']
        );

        return $hook;
    }

    /**
     * Resolve the required capability. Pro hooks `prli_admin_capability` to
     * return prlipro_options['min_role'] (v3 parity — was a Pro-only setting).
     *
     * Security note: third-party code MUST NOT reduce this below
     * `manage_options`. Lowering the capability grants full Pretty Link
     * admin access (link CRUD, settings, reports) to those users.
     */
    public static function capability(): string
    {
        return (string) apply_filters('prli_admin_capability', self::CAPABILITY);
    }

    /**
     * Icon value passed to `add_menu_page`.
     *
     * Returns `'none'` when our bundled SVG is on disk — WP then renders
     * an empty `.wp-menu-image` div, and `enqueueMenuIconStyle()` paints
     * the icon via a CSS mask whose color follows the admin color scheme's
     * per-state `:before { color: ... }` rules. This avoids the brief
     * flicker that happens when WP's `svg-painter.js` rewrites a data-URI
     * icon's fill on jQuery-ready, and works on every admin color scheme
     * without hardcoding a base color (Fresh uses #a7aaad, Modern uses
     * #f3f1f1, etc.).
     *
     * Falls back to a built-in dashicon if the bundled SVG is missing.
     */
    public static function menuIcon(): string
    {
        $path = self::getContainer()->get('BASE_PATH') . 'assets/images/menu-icon.svg';
        return is_readable($path) ? 'none' : 'dashicons-admin-links';
    }

    /**
     * Emit the CSS that paints the top-level menu icon. Attached to the
     * always-loaded `admin-menu` stylesheet so it ships on every admin
     * page (the sidebar is always visible).
     *
     * The mask sits on `:before` rather than the `.wp-menu-image` div
     * because WP's color-scheme CSS sets the per-state color on `:before`
     * — `currentColor` on the div would inherit the link text color
     * instead of the icon-specific color.
     */
    public static function enqueueMenuIconStyle(): void
    {
        $path = self::getContainer()->get('BASE_PATH') . 'assets/images/menu-icon.svg';
        if (!is_readable($path)) {
            return;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local bundled asset.
        $svg = (string) file_get_contents($path);
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- data-URI encoding.
        $uri = 'data:image/svg+xml;base64,' . base64_encode($svg);

        // WP's base CSS already sets `div.wp-menu-image:before { padding:7px 0 }`
        // which combined with a 20px-tall mask box gives the same 34px total
        // height (7+20+7) that the dashicons font icons use. Don't override
        // padding/margin/position — let WP own the layout.
        $css = sprintf(
            '#adminmenu .toplevel_page_%1$s div.wp-menu-image::before{'
            . 'content:"";display:block;width:20px;height:20px;margin:0 auto;'
            . 'background-color:currentColor;'
            . '-webkit-mask:url("%2$s") center/20px no-repeat;'
            . 'mask:url("%2$s") center/20px no-repeat;'
            . '}',
            self::SLUG,
            $uri
        );

        wp_add_inline_style('admin-menu', $css);
    }

    /**
     * Render the dashboard React mount points.
     *
     * @return void
     */
    public static function render(): void
    {
        echo '<div class="wrap">'
           . '<div id="prli-notices-app"></div>'
           . '<div id="prli-admin-root" data-page="dashboard"></div>'
           . '</div>';
    }

    /**
     * Reorder the Pretty Links submenu deterministically.
     *
     * WordPress's `$position` arg to `add_submenu_page()` is an insertion
     * index, not a sort key — and the resulting `$submenu[$parent]` array
     * gets reindexed (0..N) regardless of the positions passed in. That
     * means registration order wins, which makes cross-plugin ordering
     * fragile (especially once Pro adds pages at the same priority).
     *
     * Hook at admin_menu priority 999 — after every `register()` has run,
     * including third-party integrations like Growth Tools. Slugs not
     * listed in the map fall to the end, preserving their relative order.
     */
    public static function reorderSubmenu(): void
    {
        global $submenu;
        if (!isset($submenu[self::SLUG]) || !is_array($submenu[self::SLUG])) {
            return;
        }

        /**
         * Filter: prli_admin_submenu_order
         *
         * Map of submenu slug => rank (ascending). Lower ranks render first.
         * Pro hooks this to inject its pages (categories, tags, custom
         * reports, public links) between Lite's anchors. Unlisted slugs
         * (third-party additions to our parent menu) sort to the end.
         *
         * @param array<string,int> $order
         */
        $order = (array) apply_filters('prli_admin_submenu_order', [
            // Dashboard (the parent's own slug, registered as first submenu).
            self::SLUG                 => 0,
            'pretty-link-links'        => 10,
            'pretty-link-add-new'      => 20,
            'pretty-link-pay-links'    => 30,
            'pretty-link-clicks'       => 40,
            'pretty-link-options'      => 100,
            'pretty-link-addons'       => 110,
            'pretty-link-growth-tools' => 120,
        ]);

        usort(
            $submenu[self::SLUG],
            static function ($a, $b) use ($order): int {
                $aSlug = isset($a[2]) ? (string) $a[2] : '';
                $bSlug = isset($b[2]) ? (string) $b[2] : '';
                $aRank = $order[$aSlug] ?? PHP_INT_MAX;
                $bRank = $order[$bSlug] ?? PHP_INT_MAX;
                return $aRank <=> $bRank;
            }
        );
    }
}
