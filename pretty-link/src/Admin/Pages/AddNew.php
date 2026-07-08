<?php

declare(strict_types=1);

namespace PrettyLinks\Admin\Pages;

use PrettyLinks\Admin\Page;

class AddNew
{
    public const SLUG = 'pretty-link-add-new';

    /**
     * Register the Add New / Edit Link submenu page and the per-request
     * chrome reconciliation for its edit mode.
     */
    public static function register(): string
    {
        $hook = (string) add_submenu_page(
            Page::SLUG,
            esc_html__('Add New Link', 'pretty-link'),
            esc_html__('Add New', 'pretty-link'),
            Page::capability(),
            self::SLUG,
            [self::class, 'render'],
            20
        );

        // The edit flow reuses this page's slug
        // (admin.php?page=pretty-link-add-new&id=N), so the static submenu
        // registration above can't reflect it — the browser <title> stays
        // "Add New Link" and the "Add New" submenu item stays highlighted
        // while the React body correctly reads "Edit Link". Reconcile the
        // WordPress chrome per-request when an id is present.
        add_action('load-' . $hook, [self::class, 'setEditTitle']);
        add_filter('submenu_file', [self::class, 'highlightLinksListInEditMode']);

        return $hook;
    }

    /**
     * Browser <title> for the edit screen. Runs on this page's load-{hook}
     * (so it only fires here) and is guarded on the id param. Pre-setting
     * $GLOBALS['title'] before admin-header.php runs makes
     * get_admin_page_title() return it instead of the registered "Add New
     * Link" label — the same approach Onboarding uses for its hidden page.
     */
    public static function setEditTitle(): void
    {
        if (self::isEditMode()) {
            // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- admin-header.php reads $title to build the browser <title>; setting it per-request is the documented mechanism (same as Admin\Pages\Onboarding).
            $GLOBALS['title'] = esc_html__('Edit Link', 'pretty-link');
        }
    }

    /**
     * `submenu_file` filter for this shared Add New / Edit Link page:
     *  - PrettyPay context (?prettypay=1): keep the PrettyPay™ Links submenu
     *    highlighted — the link belongs there, mirroring v3 which kept
     *    "PrettyPay Links" current for any prettypay=1 screen.
     *  - Editing a regular link (?id=N): highlight the links list rather than
     *    "Add New".
     * This is a global filter, so it self-gates on the current page + params.
     *
     * @param  mixed $submenuFile Submenu slug WordPress would highlight.
     * @return mixed
     */
    public static function highlightLinksListInEditMode($submenuFile)
    {
        if (self::isPrettyPayContext()) {
            return PayLinks::SLUG;
        }
        if (self::isEditMode()) {
            return Links::SLUG;
        }
        return $submenuFile;
    }

    /**
     * True when the current request is this page's edit view —
     * page=pretty-link-add-new with a positive link id. Read-only admin
     * navigation context (not a form submission), so no nonce check.
     */
    private static function isEditMode(): bool
    {
        // Is_scalar guards the (string) casts: this runs on every admin page
        // via the global submenu_file filter, so a malformed ?page[]= / ?id[]=
        // would otherwise trip an "Array to string conversion" warning.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin navigation context, not a form submission.
        if (!isset($_GET['page']) || !is_scalar($_GET['page']) || sanitize_key(wp_unslash((string) $_GET['page'])) !== self::SLUG) {
            return false;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin navigation context, not a form submission.
        return isset($_GET['id']) && is_scalar($_GET['id']) && absint(wp_unslash((string) $_GET['id'])) > 0;
    }

    /**
     * True when the current request is the PrettyPay flavour of this page —
     * page=pretty-link-add-new with a truthy ?prettypay=1 — and PrettyPay is
     * enabled so the PrettyPay™ Links submenu it points at actually exists.
     * Covers both editing an existing pay link and creating a new one, which
     * is how v3 kept "PrettyPay Links" highlighted for any prettypay screen.
     */
    private static function isPrettyPayContext(): bool
    {
        // Is_scalar guards the (string) casts; this runs on every admin page
        // via the global submenu_file filter (see isEditMode() for the same
        // malformed ?page[]= / ?prettypay[]= rationale).
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin navigation context, not a form submission.
        if (!isset($_GET['page']) || !is_scalar($_GET['page']) || sanitize_key(wp_unslash((string) $_GET['page'])) !== self::SLUG) {
            return false;
        }
        // No PrettyPay™ Links submenu to highlight when the feature is off —
        // let the regular edit-mode highlight take over instead.
        if (!PayLinks::enabled()) {
            return false;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin navigation context, not a form submission.
        if (!isset($_GET['prettypay']) || !is_scalar($_GET['prettypay'])) {
            return false;
        }
        // Wp_validate_boolean: prettypay=1/true reads truthy; 0/''/false falsy
        // (the menu href uses prettypay=1). is_scalar already guarded above.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin navigation context, not a form submission.
        return wp_validate_boolean(sanitize_text_field(wp_unslash((string) $_GET['prettypay'])));
    }

    /**
     * Render the React mount point for the Add New / Edit Link screen.
     */
    public static function render(): void
    {
        echo '<div class="wrap">'
           . '<div id="prli-notices-app"></div>'
           . '<div id="prli-admin-root" data-page="add-new"></div>'
           . '</div>';
    }
}
