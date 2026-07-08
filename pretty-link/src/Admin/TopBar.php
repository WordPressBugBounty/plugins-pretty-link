<?php

declare(strict_types=1);

namespace PrettyLinks\Admin;

use PrettyLinks\Support\HasStaticContainer;
use PrettyLinks\Support\StaticContainerAwareness;

/**
 * Server-rendered top bar for Pretty Links admin screens.
 *
 * Emits the brand logo, an In-Product Notifications slot, and a Support
 * link above the React mount points. The inbox itself is fully owned by
 * the GroundLevel InProductNotifications View service — firing the
 * `prli_ipn_render` action inside the slot is all that's needed; IPN
 * enqueues its own JS, renders the bell + popover, and handles dismissals
 * via its own AJAX endpoint.
 *
 * Fires via `in_admin_header` at priority 20 so it lands just above the
 * first `<h1>` on every Pretty Links admin screen.
 */
class TopBar implements StaticContainerAwareness
{
    use HasStaticContainer;

    public const IPN_RENDER_HOOK = 'prli_ipn_render';

    /**
     * Render the top bar on Pretty Links screens, skipping focused flows.
     *
     * @return void
     */
    public static function maybeRender(): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || !is_string($screen->id) || strpos($screen->id, 'pretty-link') === false) {
            return;
        }
        // Focused flows render their own header — don't double up.
        if (
            strpos($screen->id, 'pretty-link-onboarding') !== false
            || strpos($screen->id, 'pretty-link-whats-new') !== false
        ) {
            return;
        }
        self::render();
    }

    /**
     * Emit the brand bar markup: logo, IPN slot, and support link.
     *
     * @return void
     */
    public static function render(): void
    {
        $baseUrl   = self::getContainer()->get('BASE_URL');
        $dashboard = admin_url('admin.php?page=' . Page::SLUG);
        /**
         * Filter: prli_support_url
         *
         * Lets extensions swap the top-bar support link. Lite defaults to
         * the wordpress.org plugin support forum; Pro overrides via this
         * filter so paid users land on prettylinks.com/support. Lite
         * intentionally doesn't probe for a Pro class here so swapping
         * the pro/ directory never fires Lite's classmap autoloader for
         * a missing file.
         */
        $supportUrl = (string) apply_filters(
            'prli_support_url',
            'https://wordpress.org/support/plugin/pretty-link/'
        );
        $logoSrc    = $baseUrl . 'assets/images/logo-horizontal.svg';
        $brandName  = esc_html__('Pretty Links', 'pretty-link');

        ?>
        <div class="prli-brandbar" role="banner">
            <div class="prli-brandbar__inner">
                <a
                    href="<?php echo esc_url($dashboard); ?>"
                    class="prli-brandbar__brand"
                    aria-label="<?php echo esc_attr($brandName); ?>"
                >
                    <img
                        src="<?php echo esc_url($logoSrc); ?>"
                        alt="<?php echo esc_attr($brandName); ?>"
                        class="prli-brandbar__logo"
                    />
                </a>

                <div class="prli-brandbar__actions">
                    <div class="prli-brandbar__ipn-slot">
                        <?php do_action(self::IPN_RENDER_HOOK); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Constant resolves to 'prli_ipn_render' (prefixed). ?>
                    </div>

                    <a
                        class="prli-brandbar__support"
                        href="<?php echo esc_url($supportUrl); ?>"
                        target="_blank"
                        rel="noopener noreferrer"
                    >
                        <span class="prli-brandbar__support-icon" aria-hidden="true">
                            <svg
                                xmlns="http://www.w3.org/2000/svg"
                                width="14"
                                height="14"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="2"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                            >
                                <circle cx="12" cy="12" r="10"></circle>
                                <path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"></path>
                                <line x1="12" y1="17" x2="12.01" y2="17"></line>
                            </svg>
                        </span>
                        <span><?php esc_html_e('Support', 'pretty-link'); ?></span>
                    </a>
                </div>
            </div>
        </div>
        <?php
    }
}
