<?php

declare(strict_types=1);

namespace PrettyLinks\Admin\Pages;

use PrettyLinks\Admin\Page;
use PrettyLinks\Repositories\Links;

/**
 * Pretty Links → Click History admin page (Lite).
 *
 * V3 drop-in: keeps the `pretty-link-clicks` slug so existing bookmarks
 * and the Pro v3 "Click History" entry-points keep working unchanged.
 *
 * Hidden entirely when the tracking mode is `count` (Simple) — v3 parity:
 * Simple mode stores no per-click rows, so there's nothing to show.
 *
 * Renders one mount point:
 *   #prli-clicks-app — populated by the Lite `clicks` bundle (raw click
 *     list with filters, sort, pagination, CSV export). Extension tabs
 *     add their own mount nodes beside it; Lite shows only the active tab's.
 */
class Clicks
{
    public const SLUG = 'pretty-link-clicks';

    /**
     * Register the Click History submenu under the Pretty Links parent.
     *
     * @return string|null Hook suffix, or null if the page is hidden in
     *                     Simple tracking mode.
     */
    public static function register(): ?string
    {
        if (self::isHidden()) {
            return null;
        }

        return (string) add_submenu_page(
            Page::SLUG,
            esc_html__('Click History', 'pretty-link'),
            esc_html__('Click History', 'pretty-link'),
            Page::capability(),
            self::SLUG,
            [self::class, 'render'],
            40
        );
    }

    /**
     * Render the page wrapper. The Lite click history app mounts into
     * `#prli-clicks-app`.
     */
    public static function render(): void
    {
        // `#prli-notices-app` is the shared notices host that every admin
        // page template emits at the top of its `.wrap`; PageShell portals
        // its NoticeStrip into it so the strip always sits above page chrome
        // regardless of which React root owns the shell. `#prli-clicks-app`
        // is the Lite Click History React root.
        //
        // Extension tabs registered through `window.prli.register.clicksTab()`
        // create their own mount node beside `#prli-clicks-app`, outside
        // Lite's React root (inside it, Lite's reconciler would wipe their
        // tree on every re-render). Lite toggles each by its `mountId`.
        //
        // Both live inside `#prli-admin-root` so the 137
        // `#prli-admin-root`-scoped component overrides in base.css
        // (SelectControl / TextControl / ToggleControl / Button styling)
        // apply to every control on this page.
        echo '<div class="wrap">'
           . '<div id="prli-notices-app"></div>'
           . '<div id="prli-admin-root" data-page="clicks">'
               . '<div id="prli-clicks-app"></div>'
           . '</div>'
           . '</div>';
    }

    /**
     * Tracking mode `count` (Simple) hides the menu entirely.
     */
    private static function isHidden(): bool
    {
        return Links::isCountMode();
    }
}
