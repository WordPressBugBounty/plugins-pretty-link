<?php

declare(strict_types=1);

namespace PrettyLinks\Admin\Upsell;

use PrettyLinks\Admin\Page;
use PrettyLinks\Licensing\ProState;

/**
 * Lite-side locked placeholder for the "Custom Reports" Pro page.
 *
 * Registers the same slug the Pro Custom Reports page would register, so
 * the submenu slot is taken on Lite installs. Self-suppresses when Pro is on disk —
 * Pro's own page then wins the slug. The CTA is tied to the catalog
 * entry in {@see ProUpsell::features()} so copy/UTM stays in sync with
 * every other upsell surface.
 *
 * This is the third intentional exception to the "Lite has no knowledge
 * of Pro" rule (after WhatsNew and ProUpsell itself): we surface a Pro
 * page name in the Lite menu by design.
 */
class CustomReportsLocked
{
    public const SLUG = 'pretty-link-custom-reports';

    /**
     * Register the locked Custom Reports submenu on Lite installs.
     *
     * @return string|null The page hook suffix, or null when Pro is on disk.
     */
    public static function register(): ?string
    {
        if (ProState::isProInstalled()) {
            return null;
        }

        // The menu_title is output unescaped inside the anchor's text
        // content, so the "(PRO)" suffix renders as a real styled chip
        // via the `.prli-menu-pro-badge` class hooked in admin-chrome.css.
        $menuTitle = sprintf(
            '%s <span class="prli-menu-pro-badge">%s</span>',
            esc_html__('Custom Reports', 'pretty-link'),
            esc_html__('PRO', 'pretty-link')
        );

        return (string) add_submenu_page(
            Page::SLUG,
            esc_html__('Custom Reports', 'pretty-link'),
            $menuTitle,
            Page::capability(),
            self::SLUG,
            [self::class, 'render'],
            55
        );
    }

    /**
     * Render the locked Custom Reports upsell card.
     *
     * @return void
     */
    public static function render(): void
    {
        $entry   = ProUpsell::feature('reports-custom');
        $heading = is_array($entry) && !empty($entry['label'])
            ? (string) $entry['label']
            : __('Custom reports & conversions', 'pretty-link');
        $summary = is_array($entry) && !empty($entry['summary'])
            ? (string) $entry['summary']
            : '';
        $url     = ProUpsell::upgradeUrl('custom-reports-locked', 'reports-custom');

        echo '<div class="wrap prli-locked-page">';
        echo '<div id="prli-notices-app"></div>';
        echo '<div class="prli-pro-locked-card" role="group" aria-label="' . esc_attr($heading) . '">';
        echo '<div class="prli-pro-locked-card__icon" aria-hidden="true">';
        // Inline SVG lock glyph — matches the React Icon lock path so the
        // server-rendered page visually matches the React-rendered version.
        echo '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">';
        echo '<rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>';
        echo '<path d="M7 11V7a5 5 0 0 1 10 0v4"></path>';
        echo '</svg>';
        echo '</div>';
        echo '<h2 class="prli-pro-locked-card__title">' . esc_html($heading) . '</h2>';
        if ($summary !== '') {
            echo '<p class="prli-pro-locked-card__description">' . esc_html($summary) . '</p>';
        }
        echo '<a class="prli-pro-locked-card__cta" href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer">';
        echo esc_html(ProUpsell::ctaLabel());
        echo ' <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">';
        echo '<path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path>';
        echo '<polyline points="15 3 21 3 21 9"></polyline>';
        echo '<line x1="10" y1="14" x2="21" y2="3"></line>';
        echo '</svg>';
        echo '</a>';
        echo '</div>';
        echo '</div>';
    }
}
