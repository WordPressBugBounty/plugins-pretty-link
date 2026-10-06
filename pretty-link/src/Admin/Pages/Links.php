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
        // When the links list has taken the parent slug (#820), Page already
        // registered this screen as the parent's own first row. Register it
        // again with an empty parent so it stays reachable without adding a
        // second, redundant row: `page=pretty-link-links` is hardcoded in
        // eight places (What's New, the dashboard widget, Add New, taxonomy
        // filters, the MonsterInsights shim, Pro's split-test report), and
        // skipping registration turns every one of them into a dead link.
        // Same pattern Pro's SplitTestReport uses for its hidden screen.
        if (Page::linksListIsFirst()) {
            return (string) add_submenu_page(
                '',
                esc_html__('Pretty Links', 'pretty-link'),
                esc_html__('Pretty Links', 'pretty-link'),
                Page::capability(),
                self::SLUG,
                [self::class, 'render']
            );
        }

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
