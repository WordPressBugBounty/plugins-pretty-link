<?php

declare(strict_types=1);

namespace PrettyLinks\Admin;

use PrettyLinks\Admin\Pages\Addons as AddonsPage;
use PrettyLinks\Admin\Pages\Options as OptionsPage;

/**
 * Plugins-screen row customizations: action links and footer text.
 */
class PluginRow
{
    /**
     * Prepend Settings, Docs, and Manage License links to the plugin row.
     *
     * @param  array<string, string> $links Existing plugin action links.
     * @return array<string, string>
     */
    public static function actionLinks(array $links): array
    {
        $custom = [
            'settings' => '<a href="' . esc_url(admin_url('admin.php?page=' . OptionsPage::SLUG)) . '">'
                . esc_html__('Settings', 'pretty-link')
                . '</a>',
            'docs'     => '<a href="https://prettylinks.com/docs" target="_blank" rel="noopener noreferrer">'
                . esc_html__('Docs', 'pretty-link')
                . '</a>',
            'license'  => '<a href="' . esc_url(admin_url('admin.php?page=' . AddonsPage::SLUG)) . '">'
                . esc_html__('Manage License', 'pretty-link')
                . '</a>',
        ];

        return array_merge($custom, $links);
    }

    /**
     * Replace the admin footer text with a review prompt on our screens.
     *
     * @param  string $text Current admin footer text.
     * @return string
     */
    public static function footerText(string $text): string
    {
        if (!self::onPrettyLinksScreen()) {
            return $text;
        }

        return sprintf(
            // Translators: %s: link to the WordPress.org review page.
            __('Please rate %s on WordPress.org', 'pretty-link'),
            '<a href="https://wordpress.org/support/plugin/pretty-link/reviews/#new-post" target="_blank" rel="noopener noreferrer">'
            . esc_html__('Pretty Links', 'pretty-link')
            . '</a>'
        );
    }

    /**
     * Whether the current admin screen is a Pretty Links screen.
     *
     * @return boolean
     */
    private static function onPrettyLinksScreen(): bool
    {
        if (!function_exists('get_current_screen')) {
            return false;
        }
        $screen = get_current_screen();
        if (!$screen || !is_string($screen->id)) {
            return false;
        }
        return strpos($screen->id, 'pretty-link') !== false;
    }
}
