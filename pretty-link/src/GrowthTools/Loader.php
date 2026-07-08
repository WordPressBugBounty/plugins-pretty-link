<?php

declare(strict_types=1);

namespace PrettyLinks\GrowthTools;

use PrettyLinks\Admin\Page;

/**
 * Wires the shared Caseproof Growth Tools menu into the Pretty Links admin.
 *
 * Growth Tools ships as `caseproof/growth-tools` and is namespace-prefixed by
 * strauss into this plugin's vendor-prefixed tree. The strauss-generated class
 * name is checked at runtime — if composer/strauss hasn't regenerated yet (e.g.
 * first checkout before `composer install`), this loader silently does nothing
 * and the site stays up.
 *
 * Config mirrors v3's `PrliGrowthToolsController` (see
 * `current-version/pretty-link/app/controllers/PrliGrowthToolsController.php`)
 * so the menu item and button styling stay identical.
 */
class Loader
{
    public const MENU_SLUG = 'pretty-link-growth-tools';

    /**
     * Registered on `admin_menu` (priority 11, after Page::register).
     */
    public static function register(): void
    {
        $appClass    = '\\PrettyLinks\\Caseproof\\GrowthTools\\App';
        $configClass = '\\PrettyLinks\\Caseproof\\GrowthTools\\Config';

        if (!class_exists($appClass) || !class_exists($configClass)) {
            return;
        }

        $config = new $configClass([
            'parentMenuSlug'   => Page::SLUG,
            'instanceId'       => 'pretty-link',
            'menuSlug'         => self::MENU_SLUG,
            'buttonCSSClasses' => ['pretty-link-cta-button', 'pretty-link-cta-gt-button'],
        ]);

        new $appClass($config);
    }
}
