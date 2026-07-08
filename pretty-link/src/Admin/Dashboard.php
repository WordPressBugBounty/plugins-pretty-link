<?php

declare(strict_types=1);

namespace PrettyLinks\Admin;

use PrettyLinks\Admin\Pages\Links as LinksPage;
use PrettyLinks\Slug\Generator as SlugGenerator;

/**
 * "Pretty Links — Quick Add" widget on the WordPress admin dashboard
 * (`/wp-admin/index.php`). Lets admins shorten a URL without leaving the
 * dashboard.
 *
 * Submission is REST-driven (`POST /pretty-links/v1/links`) — same
 * endpoint the React admin uses. The widget's own JS handles the fetch
 * and either redirects to the Links list on success or renders the error
 * inline. No `admin-post.php` round-trip, no server-side handler here.
 */
class Dashboard
{
    public const WIDGET_ID = 'prli_quick_add';

    /**
     * Register the "Quick Add" dashboard widget for capable users.
     *
     * @return void
     */
    public static function register(): void
    {
        if (!current_user_can(Page::capability())) {
            return;
        }

        wp_add_dashboard_widget(
            self::WIDGET_ID,
            esc_html__('Pretty Links — Quick Add', 'pretty-link'),
            [self::class, 'render']
        );
    }

    /**
     * Enqueue the widget's CSS and JS on the dashboard screen only.
     *
     * @param  string $hookSuffix Current admin page hook suffix.
     * @return void
     */
    public static function enqueueAssets(string $hookSuffix): void
    {
        if ($hookSuffix !== 'index.php') {
            return;
        }
        if (!current_user_can(Page::capability())) {
            return;
        }

        $relPath  = 'assets/css/dashboard-widget.css';
        $filePath = self::basePath() . $relPath;
        if (is_file($filePath)) {
            wp_enqueue_style(
                'prli-dashboard-widget',
                self::baseUrl() . $relPath,
                [],
                (string) filemtime($filePath)
            );
        }

        $jsRel  = 'assets/js/dashboard-widget.js';
        $jsPath = self::basePath() . $jsRel;
        if (is_file($jsPath)) {
            wp_enqueue_script(
                'prli-dashboard-widget',
                self::baseUrl() . $jsRel,
                ['wp-api-fetch'],
                (string) filemtime($jsPath),
                true
            );
            wp_set_script_translations('prli-dashboard-widget', 'pretty-link');
            wp_localize_script(
                'prli-dashboard-widget',
                'prliQuickAdd',
                [
                    'restRoot'     => esc_url_raw(rest_url('pretty-links/v1/links')),
                    'restNonce'    => wp_create_nonce('wp_rest'),
                    'linksListUrl' => admin_url('admin.php?page=' . LinksPage::SLUG),
                ]
            );
        }
    }

    /**
     * Render the widget markup (intro, notice slot, and quick-add form).
     *
     * @return void
     */
    public static function render(): void
    {
        // The generated slug already has any configured prefix baked in.
        // Base URL for the preview honors the pro "alternate shortlink
        // domain" so the hint matches where the link actually resolves
        // (falls back to home_url when the alt domain is off).
        $slugPrefix = trailingslashit(\PrettyLinks\Helpers\LinkUrl::build(''));
        $suggested  = SlugGenerator::generate();
        ?>
        <div class="prli-quick-add">
            <div class="prli-quick-add__intro">
                <svg class="prli-quick-add__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                    <path d="M10 13a5 5 0 0 0 7.07 0l3-3a5 5 0 1 0-7.07-7.07l-1 1" />
                    <path d="M14 11a5 5 0 0 0-7.07 0l-3 3a5 5 0 0 0 7.07 7.07l1-1" />
                </svg>
                <p class="prli-quick-add__hint">
                    <?php esc_html_e('Shorten and track a URL in seconds.', 'pretty-link'); ?>
                </p>
            </div>

            <div
                class="prli-quick-add__notice"
                role="status"
                aria-live="polite"
                hidden
                data-prli-quick-add-notice
            ></div>

            <form class="prli-quick-add__form" data-prli-quick-add-form>
                <div class="prli-quick-add__field">
                    <label class="prli-quick-add__label" for="prli-quick-add-target-url">
                        <?php esc_html_e('Target URL', 'pretty-link'); ?>
                    </label>
                    <input
                        type="url"
                        id="prli-quick-add-target-url"
                        name="target_url"
                        class="prli-quick-add__input"
                        placeholder="https://example.com/long/path"
                        required
                    />
                </div>

                <div class="prli-quick-add__field">
                    <label class="prli-quick-add__label" for="prli-quick-add-slug">
                        <?php esc_html_e('Custom slug', 'pretty-link'); ?>
                        <span class="prli-quick-add__label-meta"><?php esc_html_e('optional', 'pretty-link'); ?></span>
                    </label>
                    <div class="prli-quick-add__slug-wrap">
                        <span class="prli-quick-add__slug-prefix" title="<?php echo esc_attr($slugPrefix); ?>">
                            <?php echo esc_html($slugPrefix); ?>
                        </span>
                        <input
                            type="text"
                            id="prli-quick-add-slug"
                            name="slug"
                            class="prli-quick-add__input prli-quick-add__input--slug"
                            value="<?php echo esc_attr($suggested); ?>"
                        />
                    </div>
                </div>

                <div class="prli-quick-add__actions">
                    <button type="submit" class="prli-quick-add__submit" data-prli-quick-add-submit>
                        <svg class="prli-quick-add__submit-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                            <line x1="12" y1="5" x2="12" y2="19" />
                            <line x1="5" y1="12" x2="19" y2="12" />
                        </svg>
                        <span data-prli-quick-add-submit-label><?php esc_html_e('Create Pretty Link', 'pretty-link'); ?></span>
                    </button>
                </div>
            </form>
        </div>
        <?php
    }

    /**
     * Absolute filesystem path to the plugin root, trailing-slashed.
     *
     * @return string
     */
    private static function basePath(): string
    {
        if (defined('PRLI_PATH')) {
            return (string) constant('PRLI_PATH');
        }
        return plugin_dir_path(dirname(__DIR__, 2)) . '';
    }

    /**
     * Public URL to the plugin root, trailing-slashed.
     *
     * @return string
     */
    private static function baseUrl(): string
    {
        if (defined('PRLI_FILE')) {
            return plugin_dir_url((string) constant('PRLI_FILE'));
        }
        return plugin_dir_url(dirname(__DIR__, 2));
    }
}
