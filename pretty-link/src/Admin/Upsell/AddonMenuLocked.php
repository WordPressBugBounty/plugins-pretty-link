<?php

declare(strict_types=1);

namespace PrettyLinks\Admin\Upsell;

use PrettyLinks\Admin\Page;
use PrettyLinks\Admin\Pages\Addons;
use PrettyLinks\Licensing\ProState;

/**
 * Abstract base class for locked top-level admin-menu stubs that
 * advertise an add-on which lives in its own plugin (separate from the
 * Pro folder). Each concrete subclass adds a single Pretty Links submenu
 * entry that:
 *
 *   - hides itself when the add-on plugin is currently active (the
 *     add-on registers its real submenu and we don't want a duplicate);
 *   - renders an "Already installed — Activate" card if the plugin file
 *     is on disk but currently deactivated; or
 *   - renders an upgrade-pitch card if the plugin isn't installed at all.
 *
 * Subclasses declare the four required constants (slug, plugin file,
 * feature ID, menu label/position) and the base class handles
 * registration + render. Mirrors {@see CustomReportsLocked} for the
 * Pro-bundled "Custom Reports" page, but gates on add-on presence
 * rather than `ProState::isProInstalled()`.
 */
abstract class AddonMenuLocked
{
    /**
     * WP plugin folder slug (== `addonSlug` in the JS bootstrap).
     */
    public const SLUG = '';

    /**
     * WP plugin file (folder/main.php) — passed to `is_plugin_active()`.
     */
    public const PLUGIN_FILE = '';

    /**
     * Feature ID in `ProUpsell::features()` — drives card label/summary.
     */
    protected const FEATURE_ID = '';

    /**
     * Sidebar entry text (the formal product name lives in `pageTitle()`).
     */
    protected const MENU_LABEL = '';

    /**
     * Sub-menu position passed to `add_submenu_page()`.
     */
    protected const MENU_POSITION = 90;

    /**
     * Register the locked add-on submenu unless the add-on is active.
     *
     * @return string|null The page hook suffix, or null when the add-on plugin is active.
     */
    public static function register(): ?string
    {
        if (!function_exists('is_plugin_active')) {
            include_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        // Suppress only when the add-on is currently active — its own
        // submenu wins the slot. If the plugin file is on disk but
        // deactivated we still register the stub so the user can find
        // and activate it; `render()` swaps the upsell pitch for an
        // Activate button below.
        if (is_plugin_active(static::PLUGIN_FILE)) {
            return null;
        }

        $menuTitle = sprintf(
            '%s <span class="prli-menu-pro-badge">%s</span>',
            esc_html(static::MENU_LABEL),
            esc_html__('PRO', 'pretty-link')
        );

        return (string) add_submenu_page(
            Page::SLUG,
            static::pageTitle(),
            $menuTitle,
            Page::capability(),
            static::SLUG,
            [static::class, 'render'],
            static::MENU_POSITION
        );
    }

    /**
     * Render the add-on card: activate, view-add-ons, or upsell variant.
     *
     * @return void
     */
    public static function render(): void
    {
        if (!function_exists('is_plugin_active')) {
            include_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $isInstalledInactive = file_exists(WP_PLUGIN_DIR . '/' . static::PLUGIN_FILE);

        $entry   = ProUpsell::feature(static::FEATURE_ID);
        $heading = is_array($entry) && !empty($entry['label'])
            ? (string) $entry['label']
            : static::pageTitle();
        $summary = is_array($entry) && !empty($entry['summary'])
            ? (string) $entry['summary']
            : '';

        echo '<div class="wrap prli-locked-page">';
        echo '<div id="prli-notices-app"></div>';
        echo '<div class="prli-pro-locked-card" role="group" aria-label="' . esc_attr($heading) . '">';
        echo '<div class="prli-pro-locked-card__icon" aria-hidden="true">';
        echo '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">';
        echo '<rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>';
        echo '<path d="M7 11V7a5 5 0 0 1 10 0v4"></path>';
        echo '</svg>';
        echo '</div>';
        echo '<h2 class="prli-pro-locked-card__title">' . esc_html($heading) . '</h2>';

        if ($isInstalledInactive) {
            // Add-on is on disk but deactivated. Offer one-click
            // activation via WP's standard `?action=activate` plugins-
            // screen handler — keeps this stub allocation-free (no JS
            // bundle on this page).
            echo '<p class="prli-pro-locked-card__description"><strong>'
                . esc_html__('Already installed.', 'pretty-link')
                . '</strong> '
                . esc_html__('This add-on is on your site but currently deactivated. Activate it to start using it.', 'pretty-link')
                . '</p>';

            $activateUrl = wp_nonce_url(
                self_admin_url('plugins.php?action=activate&plugin=' . urlencode(static::PLUGIN_FILE)),
                'activate-plugin_' . static::PLUGIN_FILE
            );
            echo '<a class="prli-pro-locked-card__cta" href="' . esc_url($activateUrl) . '">';
            echo esc_html__('Activate add-on', 'pretty-link');
            echo '</a>';
        } elseif (ProState::isProInstalled()) {
            // Add-on isn't installed, but the user already has Pro — this
            // is a separate add-on, not Pro itself, so don't pitch an
            // upgrade they've already bought. Send them to the in-product
            // Add-ons page, which sources each add-on's status from the
            // mothership and shows the right action: Install (in their
            // plan) or Upgrade (not in their plan).
            // Always show the "this is an add-on" line so a Pro user gets
            // the explanation even when the catalog summary is empty.
            $description = sprintf(
                // translators: %s: add-on name.
                __('%s is a Pretty Links add-on.', 'pretty-link'),
                $heading
            );
            if ($summary !== '') {
                $description .= ' ' . $summary;
            }
            echo '<p class="prli-pro-locked-card__description">' . esc_html($description) . '</p>';
            $addonsUrl = admin_url('admin.php?page=' . Addons::SLUG);
            echo '<a class="prli-pro-locked-card__cta" href="' . esc_url($addonsUrl) . '">';
            echo esc_html__('View add-ons', 'pretty-link');
            echo '</a>';
        } else {
            // Lite install (no Pro). Pitch the upsell — the internal
            // Add-ons page is a dead end without a license.
            if ($summary !== '') {
                echo '<p class="prli-pro-locked-card__description">' . esc_html($summary) . '</p>';
            }
            $placement = static::SLUG . '-locked';
            $url       = ProUpsell::upgradeUrl($placement, static::FEATURE_ID);
            echo '<a class="prli-pro-locked-card__cta" href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer">';
            echo esc_html(ProUpsell::ctaLabel());
            echo ' <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">';
            echo '<path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path>';
            echo '<polyline points="15 3 21 3 21 9"></polyline>';
            echo '<line x1="10" y1="14" x2="21" y2="3"></line>';
            echo '</svg>';
            echo '</a>';
        }
        echo '</div>';
        echo '</div>';
    }

    /**
     * Page-title text (window title / locked-card heading fallback).
     * Subclasses override — usually the formal product name, possibly
     * longer than the sidebar label.
     */
    abstract protected static function pageTitle(): string;
}
