<?php

declare(strict_types=1);

namespace PrettyLinks\Admin;

use PrettyLinks\Admin\Pages\Links as LinksPage;
use PrettyLinks\Options\Store as OptionsStore;

/**
 * Top-level Pretty Links admin menu.
 *
 * The parent slug is `pretty-link`. The landing page renders a dashboard
 * React mount point. Submenus attach to this parent via `add_submenu_page`
 * in their own classes (see Admin\Pages\*).
 */
class Page
{
    public const CAPABILITY = 'manage_options';

    public const SLUG = 'pretty-link';

    /**
     * Where the top-level menu sits in the admin sidebar.
     *
     * 55 is the gap between Comments (25) and Appearance (60), which is where
     * Pretty Links lived through 3.x and where long-time users look for it.
     * 4.0 shipped 100 by mistake, dropping it below Settings (#821).
     *
     * The fraction matters: `$menu` is keyed by position, so two plugins
     * claiming a bare 55 means one silently overwrites the other. v3 dodged
     * that with `menu_position => 55.5532265` buried in its CPT registration —
     * same idea, but arbitrary enough that nobody could tell whether the digits
     * meant anything. A single decimal place is just as collision-resistant
     * and reads as a deliberate choice.
     */
    public const MENU_POSITION = 55.5;

    /**
     * Where the Dashboard lives when the links list has taken the parent
     * slug. Unused in the default layout, where the Dashboard is the parent.
     */
    public const DASHBOARD_SLUG = 'pretty-link-dashboard';

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
     * Returns '' without registering anything when the current user can't
     * reach any of our pages.
     *
     * The guard is needed because `add_menu_page()` adds its row to `$menu`
     * with no capability check of its own — unlike `add_submenu_page()`, which
     * returns early and leaves `$submenu` untouched. WordPress only removes
     * such a parent later if its submenu came out empty, and the renderer
     * prints the parent link without checking the capability whenever the
     * submenu array has anything in it. So a top-level row plus a single
     * unguarded submenu row is enough to produce a visible menu whose flyout
     * renders nothing. See `ProUpsell::registerMenu()`, which supplies that
     * row and now carries the same guard.
     *
     * @return string The menu page hook suffix, or '' when nothing was registered.
     */
    public static function register(): string
    {
        // Resolve once: `prli_admin_capability` is a third-party filter, and
        // re-running it per call risks the guard disagreeing with what gets
        // registered. The filtered value (not the constant) is what lets a
        // site grant Pretty Links to another role and still get the full menu.
        $capability = self::capability();

        if (!current_user_can($capability)) {
            return '';
        }

        // What the parent slug renders is a setting, not a WordPress
        // inference (#820). Deciding it here keeps the whole menu shape
        // readable in one place: the parent, its first row, and the label
        // on that row all come from the same flag.
        $linksFirst   = self::linksListIsFirst();
        $parentRender = $linksFirst ? [LinksPage::class, 'render'] : [self::class, 'render'];

        $hook = add_menu_page(
            self::pageTitle(),
            esc_html__('Pretty Links', 'pretty-link'),
            $capability,
            self::SLUG,
            $parentRender,
            self::menuIcon(),
            self::MENU_POSITION
        );

        // The first submenu must use the parent's own slug. It overrides the
        // duplicate label WordPress would generate from the menu title, and
        // registering any other slug first makes core insert that duplicate
        // itself (wp-admin/includes/plugin.php) — so this row is the only
        // lever over what the parent menu item points at.
        add_submenu_page(
            self::SLUG,
            self::pageTitle(),
            // "Pretty Links" when it is the links list, matching what that
            // row is called in the default layout — the setting moves the
            // screen, it does not rename it.
            $linksFirst
                ? esc_html__('Pretty Links', 'pretty-link')
                : esc_html__('Dashboard', 'pretty-link'),
            $capability,
            self::SLUG,
            $parentRender
        );

        // With the parent showing links, the Dashboard keeps a home but no
        // menu row: an empty parent slug registers the page without listing
        // it. Its URL changes to `page=pretty-link-dashboard` in this mode,
        // which is the one visible trade of turning the setting on.
        if ($linksFirst) {
            add_submenu_page(
                '',
                self::pageTitle(),
                esc_html__('Dashboard', 'pretty-link'),
                $capability,
                self::DASHBOARD_SLUG,
                [self::class, 'render']
            );
        }

        return $hook;
    }

    /**
     * Whether the Links list should take the parent menu's place.
     *
     * Read straight from the store rather than cached: menu registration runs
     * once per request, and a stale value here would leave the sidebar
     * disagreeing with the setting the user just saved.
     */
    public static function linksListIsFirst(): bool
    {
        return (bool) (new OptionsStore())->get('links_first_menu_item', false);
    }

    /**
     * Resolve the required capability. Pro hooks `prli_admin_capability` to
     * return prlipro_options['min_role'] (v3 parity — was a Pro-only setting).
     *
     * Security note: third-party code MUST NOT reduce this below
     * `manage_options`. Lowering the capability grants full Pretty Link
     * admin access (link CRUD, settings, reports) to those users.
     *
     * @api
     */
    public static function capability(): string
    {
        return (string) apply_filters('prli_admin_capability', self::CAPABILITY);
    }

    /**
     * The page slug an admin hook suffix belongs to.
     *
     * WordPress builds a submenu's hook suffix from the sanitized, translated
     * parent menu title (`pretty-links_page_<slug>` in English), so matching
     * whole suffixes breaks on any locale that translates "Pretty Links".
     * Every admin page hook ends in `_page_<slug>` — top-level, submenu, or
     * empty-parent (`admin_page_`) — and our slugs never contain `_page_`,
     * so the slug is what follows the last one.
     *
     * @api
     *
     * @param string $hookSuffix The `$hook_suffix` WordPress passes to admin_enqueue_scripts.
     *
     * @return string The page slug, or '' when the hook is not an admin page hook.
     */
    public static function slugFromHook(string $hookSuffix): string
    {
        $pos = strrpos($hookSuffix, '_page_');
        return $pos === false ? '' : substr($hookSuffix, $pos + strlen('_page_'));
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
        $path = PRLI_PATH . 'assets/images/menu-icon.svg';
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
     *
     * The rule resets font and line-height and sets `box-sizing:content-box`
     * so an inherited `border-box` cannot fold core's `:before` padding into
     * the 20px mask box (#897). Padding stays with core so the icon moves
     * with the other menu icons.
     */
    public static function enqueueMenuIconStyle(): void
    {
        $path = PRLI_PATH . 'assets/images/menu-icon.svg';
        if (!is_readable($path)) {
            return;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local bundled asset.
        $svg = (string) file_get_contents($path);
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- data-URI encoding.
        $uri = 'data:image/svg+xml;base64,' . base64_encode($svg);

        // Core pads the icon with `div.wp-menu-image:before` (admin-menu.css)
        // and sets the font with `.dashicons-before:before` (dashicons.css),
        // a class WP adds even for `'none'` icons. The #897 trigger is an
        // inherited `box-sizing: border-box`, which counts that 14px padding
        // inside our 20px mask box. `content-box` keeps it outside.
        $css = sprintf(
            '#adminmenu .toplevel_page_%1$s div.wp-menu-image::before{'
            . 'content:"";'
            . 'display:block;'
            . 'width:20px;'
            . 'height:20px;'
            . 'margin:0 auto;'
            . 'font:0/0 a;'
            . 'line-height:0;'
            . 'box-sizing:content-box;'
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
