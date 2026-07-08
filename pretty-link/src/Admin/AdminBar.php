<?php

declare(strict_types=1);

namespace PrettyLinks\Admin;

use PrettyLinks\Admin\Pages\PayLinks as PayLinksPage;
use WP_Admin_Bar;

/**
 * Adds Pretty Link entries under the WP admin bar's "+ New" menu.
 *
 * V3 got the Pretty Link entry for free from the pretty-link CPT's
 * `show_in_admin_bar => true` registration; v4 dropped the CPT so we wire
 * it manually. PrettyPay™ Link is added as a second node, styled with the
 * brand green to match the admin sidebar treatment.
 */
class AdminBar
{
    /**
     * Add the Pretty Link (and PrettyPay™ Link) entries to the "+ New" menu.
     *
     * @param  WP_Admin_Bar $wpAdminBar The admin bar instance to add nodes to.
     * @return void
     */
    public static function register(WP_Admin_Bar $wpAdminBar): void
    {
        if (!current_user_can(Page::capability())) {
            return;
        }

        $wpAdminBar->add_node([
            'parent' => 'new-content',
            'id'     => 'new-pretty-link',
            'title'  => esc_html__('Pretty Link', 'pretty-link'),
            'href'   => admin_url('admin.php?page=pretty-link-add-new'),
        ]);

        if (PayLinksPage::enabled()) {
            $wpAdminBar->add_node([
                'parent' => 'new-content',
                'id'     => 'new-pretty-pay-link',
                'title'  => esc_html__('PrettyPay™ Link', 'pretty-link'),
                'href'   => admin_url('admin.php?page=pretty-link-add-new&prettypay=1'),
                'meta'   => [
                    'class' => 'prli-bar-prettypay',
                ],
            ]);
        }
    }

    /**
     * Color the PrettyPay admin-bar item with the brand green. Runs on
     * (admin_)enqueue_scripts so it's attached to WP's admin-bar stylesheet
     * BEFORE that stylesheet is printed in <head>. Calling it later (e.g.
     * inside admin_bar_menu) is silently dropped — the stylesheet is
     * already out the door.
     */
    public static function enqueueStyles(): void
    {
        if (!is_admin_bar_showing()) {
            return;
        }
        if (!current_user_can(Page::capability())) {
            return;
        }
        wp_add_inline_style(
            'admin-bar',
            '#wpadminbar .prli-bar-prettypay > .ab-item,'
            . '#wpadminbar .prli-bar-prettypay:hover > .ab-item,'
            . '#wpadminbar .prli-bar-prettypay > .ab-item:hover,'
            . '#wpadminbar .prli-bar-prettypay > .ab-item:focus {'
            . ' color: #89F336 !important;'
            . ' }'
        );
    }
}
