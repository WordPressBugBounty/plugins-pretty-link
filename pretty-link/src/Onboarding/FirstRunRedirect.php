<?php

declare(strict_types=1);

namespace PrettyLinks\Onboarding;

// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
// phpcs:disable WordPress.Security.ValidatedSanitizedInput.MissingUnslash
// phpcs:disable WordPress.Security.NonceVerification.Recommended
// $_SERVER values (REMOTE_ADDR, HTTP_USER_AGENT, REQUEST_URI, etc.) are read for
// click tracking / targeting / UI rendering, not form-submission input. State-changing
// operations in this class protect with wp_verify_nonce / check_admin_referer.
use PrettyLinks\Admin\Pages\Onboarding as OnboardingPage;

/**
 * Fresh-install auto-redirect: on the first admin page load after activation
 * (where `prli_onboarding_done` is still false and the legacy
 * `prli_options.activation_complete` flag is not present), redirect the admin
 * to the onboarding wizard.
 *
 * The Activator sets a transient (`prli_redirect_to_onboarding`) on activate;
 * this class consumes the transient exactly once.
 */
class FirstRunRedirect
{
    public const TRANSIENT = 'prli_redirect_to_onboarding';

    /**
     * Redirect to the onboarding wizard once after activation when appropriate.
     *
     * @return void
     */
    public static function maybeRedirect(): void
    {
        if (!is_admin() || wp_doing_ajax() || wp_doing_cron()) {
            return;
        }
        if (!get_transient(self::TRANSIENT)) {
            return;
        }
        delete_transient(self::TRANSIENT);

        if (!Wizard::shouldAutoLaunch()) {
            return;
        }

        if (isset($_GET['page']) && $_GET['page'] === OnboardingPage::SLUG) {
            return;
        }

        wp_safe_redirect(admin_url('admin.php?page=' . OnboardingPage::SLUG));
        exit;
    }
}
