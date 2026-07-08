<?php

declare(strict_types=1);

namespace PrettyLinks\Admin\Upsell;

/**
 * Locked top-level menu stub for the Pretty Links Product Displays
 * add-on (`addons/pretty-link-product-displays/`). Renders only when
 * the add-on plugin isn't currently active. See {@see AddonMenuLocked}
 * for the shared register + render flow.
 */
final class ProductDisplaysLocked extends AddonMenuLocked
{
    public const SLUG          = 'pretty-link-product-displays';
    public const PLUGIN_FILE   = 'pretty-link-product-displays/pretty-link-product-displays.php';
    protected const FEATURE_ID = 'addon-product-displays';
    // Sidebar entry — shortened to "Products" so the wp-admin sidebar
    // doesn't wrap with the (PRO) badge appended; the page title (below)
    // keeps the formal "Product Displays" name.
    protected const MENU_LABEL    = 'Products';
    protected const MENU_POSITION = 75;

    /**
     * Formal product name for the page title / locked-card heading.
     *
     * @return string The translated "Product Displays" label.
     */
    protected static function pageTitle(): string
    {
        return __('Product Displays', 'pretty-link');
    }
}
