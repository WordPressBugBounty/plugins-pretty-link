<?php

declare(strict_types=1);

namespace PrettyLinks\Stripe;

defined('ABSPATH') || exit;

use PrettyLinks\Admin\Page;
use PrettyLinks\Admin\Pages\Options as OptionsPage;

/**
 * Admin-AJAX callbacks invoked by the stripe.prettylinks.com proxy after
 * OAuth finishes (or by the Settings UI for refresh/disconnect). Matches
 * v3 action names and nonces exactly so the proxy continues to redirect
 * legitimate users straight into a working handler:
 *
 *   - wp_ajax_prli_stripe_connect_update_creds   nonce: stripe-update-creds
 *   - wp_ajax_prli_stripe_connect_refresh        nonce: stripe-refresh
 *   - wp_ajax_prli_stripe_connect_disconnect     nonce: stripe-disconnect
 *
 * The proxy only knows how to call these — renaming would silently break
 * every already-connected site on upgrade.
 */
final class ConnectAjax
{
    /**
     * Registers the Stripe Connect AJAX and option-change hooks.
     *
     * @return void
     */
    public static function loadHooks(): void
    {
        add_action('wp_ajax_prli_stripe_connect_update_creds', [self::class, 'handleUpdateCreds']);
        add_action('wp_ajax_prli_stripe_connect_refresh', [self::class, 'handleRefresh']);
        add_action('wp_ajax_prli_stripe_connect_disconnect', [self::class, 'handleDisconnect']);

        add_action('update_option_home', [self::class, 'onUrlChanged'], 10, 3);
        add_action('update_option_siteurl', [self::class, 'onUrlChanged'], 10, 3);
    }

    /**
     * Handles the post-connect credentials update callback.
     *
     * @return void
     */
    public static function handleUpdateCreds(): void
    {
        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash((string) $_GET['_wpnonce'])), 'stripe-update-creds')) {
            wp_die(esc_html__('Sorry, updating your credentials failed. (security)', 'pretty-link'));
        }

        if (isset($_GET['error'])) {
            wp_die(esc_html(sanitize_text_field(wp_unslash((string) $_GET['error']))));
        }

        if (!isset($_GET['pmt'])) {
            wp_die(esc_html__('Sorry, updating your credentials failed. (pmt)', 'pretty-link'));
        }

        if (!current_user_can(Page::capability())) {
            wp_die(esc_html__('Sorry, you don\'t have permission to do this.', 'pretty-link'));
        }

        self::fetchAndStoreCredentials();

        $action = isset($_GET['stripe-action'])
            ? sanitize_text_field(wp_unslash((string) $_GET['stripe-action']))
            : 'updated';

        // Honor the `from` hint so flows that initiated the connect from
        // somewhere other than the Options page (e.g. the onboarding wizard)
        // land back where they started.
        $from = isset($_GET['from']) ? sanitize_key(wp_unslash((string) $_GET['from'])) : '';
        if ($from === 'onboarding') {
            // Onboarding always offers PrettyPay. If the site had previously
            // disabled it (from a prior install or a quick toggle-off),
            // connecting Stripe here flips the master switch back on so the
            // menu + admin-bar shortcut reappear once onboarding finishes.
            $options = (array) (get_option('prli_options', []) ?: []);
            if (empty($options['prettypay_enabled'])) {
                $options['prettypay_enabled'] = true;
                update_option('prli_options', $options);
            }
            wp_safe_redirect(admin_url('admin.php?page=pretty-link-onboarding&step=5'));
            exit;
        }

        wp_safe_redirect(self::optionsReturnUrl($action));
        exit;
    }

    /**
     * Handles the credentials-refresh AJAX request.
     *
     * @return void
     */
    public static function handleRefresh(): void
    {
        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash((string) $_GET['_wpnonce'])), 'stripe-refresh')) {
            wp_die(esc_html__('Sorry, the refresh failed.', 'pretty-link'));
        }
        if (!current_user_can(Page::capability())) {
            wp_die(esc_html__('Sorry, you don\'t have permission to do this.', 'pretty-link'));
        }

        $methodId = Connect::METHOD_ID;
        $siteUuid = (string) get_option('prli_authenticator_site_uuid');
        $jwt      = Jwt::encode(['site_uuid' => $siteUuid]);

        $response = wp_remote_post(Connect::SERVICE_URL . "/api/refresh/{$methodId}", [
            'headers' => Jwt::header($jwt, Connect::SERVICE_DOMAIN),
        ]);

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (!is_array($body) || ($body['connect_status'] ?? '') !== 'refreshed') {
            wp_die(esc_html__('Sorry, the refresh failed.', 'pretty-link'));
        }

        self::persistCredentials($body);

        wp_safe_redirect(self::optionsReturnUrl('refreshed'));
        exit;
    }

    /**
     * Handles the disconnect AJAX request.
     *
     * @return void
     */
    public static function handleDisconnect(): void
    {
        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash((string) $_GET['_wpnonce'])), 'stripe-disconnect')) {
            wp_die(esc_html__('Sorry, the disconnect failed.', 'pretty-link'));
        }
        if (!current_user_can(Page::capability())) {
            wp_die(esc_html__('Sorry, you don\'t have permission to do this.', 'pretty-link'));
        }

        if (!self::requestRemoteDisconnect()) {
            wp_die(esc_html__('Sorry, the disconnect failed.', 'pretty-link'));
        }

        update_option('prli_stripe_connect_status', 'disconnected');
        update_option('prli_stripe_status', 0);
        Fee::forgetAccountCountry();

        wp_safe_redirect(self::optionsReturnUrl('disconnected'));
        exit;
    }

    /**
     * When the site URL changes, inform the auth service so the registered
     * domain stays in sync and webhook URLs continue to resolve.
     *
     * @param mixed  $oldValue The previous option value.
     * @param mixed  $newValue The new option value.
     * @param string $option   The option name being updated.
     *
     * @return void
     */
    public static function onUrlChanged($oldValue, $newValue, string $option): void
    {
        unset($option);
        if ((string) $oldValue === (string) $newValue) {
            return;
        }

        $oldSiteUrl = (string) get_option('prli_old_site_url', get_site_url());
        if ($oldSiteUrl === get_site_url()) {
            return;
        }

        if (!defined('PRLI_AUTH_SERVICE_URL') || !defined('PRLI_AUTH_SERVICE_DOMAIN')) {
            return;
        }

        $jwt = Jwt::encode(['site_uuid' => (string) get_option('prli_authenticator_site_uuid')]);
        wp_remote_post(constant('PRLI_AUTH_SERVICE_URL') . '/api/domains/update', [
            'headers' => Jwt::header($jwt, (string) constant('PRLI_AUTH_SERVICE_DOMAIN')),
            'body'    => ['domain' => (string) wp_parse_url(get_site_url(), PHP_URL_HOST)],
        ]);

        update_option('prli_old_site_url', get_site_url());
    }

    /**
     * Fetches credentials from the auth service and stores them.
     *
     * @return void
     */
    private static function fetchAndStoreCredentials(): void
    {
        $methodId = Connect::METHOD_ID;
        $siteUuid = (string) get_option('prli_authenticator_site_uuid');
        $jwt      = Jwt::encode(['site_uuid' => $siteUuid]);

        $response = wp_remote_get(Connect::SERVICE_URL . "/api/credentials/{$methodId}", [
            'headers' => Jwt::header($jwt, Connect::SERVICE_DOMAIN),
        ]);

        $creds = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($creds)) {
            wp_die(esc_html__('Sorry, updating your credentials failed.', 'pretty-link'));
        }

        update_option('prli_stripe_status', 1);
        self::persistCredentials($creds);
    }

    /**
     * Persists Stripe credentials and account details to options.
     *
     * @param array<string, mixed> $creds The credentials payload from the auth service.
     *
     * @return void
     */
    private static function persistCredentials(array $creds): void
    {
        update_option('prli_stripe_status', 1);
        update_option('prli_stripe_test_secret_key', sanitize_text_field((string) ($creds['test_secret_key'] ?? '')));
        update_option('prli_stripe_test_publishable_key', sanitize_text_field((string) ($creds['test_publishable_key'] ?? '')));
        update_option('prli_stripe_live_secret_key', sanitize_text_field((string) ($creds['live_secret_key'] ?? '')));
        update_option('prli_stripe_live_publishable_key', sanitize_text_field((string) ($creds['live_publishable_key'] ?? '')));
        update_option('prli_stripe_connect_status', 'connected');
        update_option('prli_stripe_service_account_id', sanitize_text_field((string) ($creds['service_account_id'] ?? '')));
        update_option('prli_stripe_service_account_name', sanitize_text_field((string) ($creds['service_account_name'] ?? '')));
        // New account/keys — drop any cached country so the fee gate re-reads
        // it for this connection.
        Fee::forgetAccountCountry();
    }

    /**
     * Requests a remote disconnect from the auth service.
     *
     * @return boolean
     */
    private static function requestRemoteDisconnect(): bool
    {
        $methodId = Connect::METHOD_ID;
        $siteUuid = (string) get_option('prli_authenticator_site_uuid');
        $jwt      = Jwt::encode([
            'method_id' => $methodId,
            'site_uuid' => $siteUuid,
        ]);

        $response = wp_remote_request(Connect::SERVICE_URL . "/api/disconnect/{$methodId}", [
            'method'  => 'DELETE',
            'headers' => Jwt::header($jwt, Connect::SERVICE_DOMAIN),
        ]);

        $body = json_decode(wp_remote_retrieve_body($response), true);
        return is_array($body) && ($body['connect_status'] ?? '') === 'disconnected';
    }

    /**
     * Builds the Options-page return URL with a status action.
     *
     * @param string $action The status action slug.
     *
     * @return string
     */
    private static function optionsReturnUrl(string $action): string
    {
        return add_query_arg(
            ['stripe-action' => $action],
            admin_url('admin.php?page=' . OptionsPage::SLUG)
        ) . '#payments';
    }
}
