<?php

declare(strict_types=1);

namespace PrettyLinks\Onboarding;

use PrettyLinks\Admin\Notices;

/**
 * "What's New in 4.0" admin notice.
 *
 * Registered once on the first admin load, then persists in the shared
 * Notices bag (`prli_notices`) until the user dismisses it. `prli_whats_new_shown`
 * is the one-shot guard that prevents re-adding after dismissal.
 */
class WhatsNew
{
    public const NOTICE_ID = 'whats-new-4.0';

    public const OPTION_SHOWN = 'prli_whats_new_shown';

    /**
     * Register the notice if conditions are met. Safe to call on every admin
     * request — idempotent.
     */
    public static function maybeRegister(): void
    {
        if (!is_admin() || wp_doing_ajax()) {
            return;
        }

        if ((bool) get_option(self::OPTION_SHOWN, false)) {
            return;
        }

        $message = sprintf(
            // phpcs:ignore Squiz.Commenting.InlineComment.NotCapital,Squiz.Commenting.InlineComment.InvalidEndChar -- translators comment must remain verbatim.
            // translators: %1$s: open link, %2$s: close link
            __('Pretty Links 4.0 is here — rebuilt admin, faster redirects, and new click analytics. %1$sSee what\'s new%2$s.', 'pretty-link'),
            '<a href="' . esc_url(admin_url('admin.php?page=pretty-link-whats-new')) . '">',
            '</a>'
        );

        Notices::add(self::NOTICE_ID, 'info', $message);
        update_option(self::OPTION_SHOWN, true, false);
    }
}
