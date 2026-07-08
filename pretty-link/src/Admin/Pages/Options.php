<?php

declare(strict_types=1);

namespace PrettyLinks\Admin\Pages;

use PrettyLinks\Admin\Page;

class Options
{
    public const SLUG = 'pretty-link-options';

    /**
     * Register the Options submenu page.
     *
     * @return string The resulting page hook suffix.
     */
    public static function register(): string
    {
        return (string) add_submenu_page(
            Page::SLUG,
            esc_html__('Options', 'pretty-link'),
            esc_html__('Options', 'pretty-link'),
            Page::capability(),
            self::SLUG,
            [self::class, 'render'],
            70
        );
    }

    /**
     * Render the React mount point for the Options screen.
     *
     * @return void
     */
    public static function render(): void
    {
        echo '<div class="wrap">'
           . '<div id="prli-notices-app"></div>'
           . '<div id="prli-admin-root" data-page="options"></div>'
           . '</div>';
    }
}
