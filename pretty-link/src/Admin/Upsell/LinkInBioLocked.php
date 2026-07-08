<?php

declare(strict_types=1);

namespace PrettyLinks\Admin\Upsell;

/**
 * Locked top-level menu stub for the Pretty Links Link in Bio add-on
 * (`addons/pretty-link-link-in-bio/`). Renders only when the add-on
 * plugin isn't currently active. See {@see AddonMenuLocked} for the
 * shared register + render flow.
 */
final class LinkInBioLocked extends AddonMenuLocked
{
    public const SLUG             = 'pretty-link-link-in-bio';
    public const PLUGIN_FILE      = 'pretty-link-link-in-bio/pretty-link-link-in-bio.php';
    protected const FEATURE_ID    = 'addon-link-in-bio';
    protected const MENU_LABEL    = 'Link in Bio';
    protected const MENU_POSITION = 70;

    /**
     * Formal product name for the page title / locked-card heading.
     *
     * @return string The translated "Link in Bio" label.
     */
    protected static function pageTitle(): string
    {
        return __('Link in Bio', 'pretty-link');
    }
}
