<?php

declare(strict_types=1);

namespace PrettyLinks\Admin\Upsell;

/**
 * Locked top-level menu stub for the Pretty Links Developer Tools
 * add-on (`addons/pretty-link-developer-tools/`). Renders only when
 * the add-on plugin isn't currently active. See {@see AddonMenuLocked}
 * for the shared register + render flow.
 */
final class DeveloperToolsLocked extends AddonMenuLocked
{
    public const SLUG          = 'pretty-link-developer-tools';
    public const PLUGIN_FILE   = 'pretty-link-developer-tools/pretty-link-developer-tools.php';
    protected const FEATURE_ID = 'addon-developer-tools';
    // Sidebar entry — shortened so the wp-admin sidebar doesn't get
    // crowded; the page title (below) keeps the formal name.
    protected const MENU_LABEL    = 'Developer';
    protected const MENU_POSITION = 85;

    /**
     * Formal product name for the page title / locked-card heading.
     *
     * @return string The translated "Developer Tools" label.
     */
    protected static function pageTitle(): string
    {
        return __('Developer Tools', 'pretty-link');
    }
}
