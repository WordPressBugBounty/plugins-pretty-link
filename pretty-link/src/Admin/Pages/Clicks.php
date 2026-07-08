<?php

declare(strict_types=1);

namespace PrettyLinks\Admin\Pages;

use PrettyLinks\Admin\Page;
use PrettyLinks\Options\Store as OptionsStore;

/**
 * Pretty Links → Click History admin page (Lite).
 *
 * V3 drop-in: keeps the `pretty-link-clicks` slug so existing bookmarks
 * and the Pro v3 "Click History" entry-points keep working unchanged.
 *
 * Hidden entirely when the tracking mode is `count` (Simple) — v3 parity:
 * Simple mode stores no per-click rows, so there's nothing to show.
 *
 * Renders two empty mount points:
 *   #prli-clicks-pro-extras-app — populated by the Pro `clicks-pro-extras`
 *     bundle (KPIs, time-series, top-links, breakdowns) when Pro is active.
 *     Stays empty in Lite installs.
 *   #prli-clicks-app — populated by the Lite `clicks` bundle (raw click
 *     list with filters, sort, pagination, CSV export).
 *
 * The Pro extras div is rendered first so when Pro is active the heavy
 * aggregate UI sits above the click list, matching the spec.
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
     * Render the page wrapper. The React Lite app mounts into the inner
     * div and renders both the click history UI and the Pro extras
     * placeholder slot.
     */
    public static function render(): void
    {
        // Two sibling mount points, both OUTSIDE each other's React root:
        // #prli-clicks-pro-extras-app — Pro `clicks-pro-extras` bundle
        // hydrates KPI cards / time-series chart / top-links /
        // breakdowns above the click list (empty on Lite).
        // #prli-clicks-app — Lite click history (page chrome, filter
        // toolbar, raw click table).
        // Keeping the Pro slot here (rather than inside Lite's JSX) prevents
        // Lite's React reconciler from wiping Pro's nested render on every
        // re-render of the Lite tree.
        // Three sibling mount points. `#prli-notices-app` is the shared
        // notices host that every admin page template emits at the top of
        // its `.wrap`; PageShell portals its NoticeStrip into it so the
        // strip always sits above page chrome regardless of which React
        // root owns the shell. `#prli-clicks-pro-extras-app` is the Pro
        // analytics slot (empty on Lite). `#prli-clicks-app` is the Lite
        // Click History React root.
        // `#prli-clicks-app` is ordered BEFORE `#prli-clicks-pro-extras-app`
        // so Lite's page header + "Charts / Table" tabs render at the top
        // of the page while the Pro extras sit under them inside the
        // Charts tab region. Lite toggles the Pro div's `hidden` attribute
        // from the Table tab.
        // Wrap both React mount points inside `#prli-admin-root` so the
        // 137 `#prli-admin-root`-scoped component overrides in base.css
        // (SelectControl / TextControl / ToggleControl / Button styling)
        // apply to every control on this page. Each child div is still a
        // distinct React root — React mounts on element identity, not on
        // any special wrapper behaviour.
        echo '<div class="wrap">'
           . '<div id="prli-notices-app"></div>'
           . '<div id="prli-admin-root" data-page="clicks">'
               . '<div id="prli-clicks-app"></div>'
               . '<div id="prli-clicks-pro-extras-app"></div>'
           . '</div>'
           . '</div>';
    }

    /**
     * Tracking mode `count` (Simple) hides the menu entirely.
     */
    private static function isHidden(): bool
    {
        return (string) (new OptionsStore())->get('extended_tracking', 'normal') === 'count';
    }
}
