<?php

declare(strict_types=1);

namespace PrettyLinks\Admin\Pages;

use PrettyLinks\Admin\Page;

class Links
{
    public const SLUG = 'pretty-link-links';

    /**
     * Register the Pretty Links list submenu page.
     *
     * @return string The resulting page hook suffix.
     */
    public static function register(): string
    {
        return (string) add_submenu_page(
            Page::SLUG,
            esc_html__('Pretty Links', 'pretty-link'),
            esc_html__('Pretty Links', 'pretty-link'),
            Page::capability(),
            self::SLUG,
            [self::class, 'render'],
            10
        );
    }

    /**
     * Render the React mount point for the Pretty Links list screen.
     *
     * @return void
     */
    public static function render(): void
    {
        echo '<div class="wrap">'
           . '<div id="prli-notices-app"></div>'
           . '<div id="prli-admin-root" data-page="links"></div>'
           . '</div>';
    }
}
