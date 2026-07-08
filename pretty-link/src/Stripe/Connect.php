<?php

declare(strict_types=1);

namespace PrettyLinks\Stripe;

/**
 * OAuth proxy to stripe.prettylinks.com + webhook URL generation.
 *
 * Drop-in port of v3 `PrliStripeConnect`:
 *   - Same method_id (`prli7tr1pe`) so existing connected accounts work.
 *   - Same option keys (`prli_stripe_connect_status`, `prli_stripe_status`).
 *   - Same JWT payload shape so the proxy accepts requests unchanged.
 *   - Same notifier URL structure so webhooks registered under v3 keep
 *     resolving to us after upgrade.
 */
final class Connect
{
    public const METHOD_ID      = 'prli7tr1pe';
    public const SERVICE_DOMAIN = 'stripe.prettylinks.com';
    public const SERVICE_URL    = 'https://stripe.prettylinks.com';

    /**
     * Maps notify-URL action slugs to their handler names.
     *
     * @var array<string, string>
     */
    private const NOTIFIERS = [
        'whk'                 => 'listener',
        'stripe-service-whk'  => 'service_listener',
        'update-billing.html' => 'churn_buster',
    ];

    /**
     * Generate the Stripe Connect OAuth URL. Returns null if the site isn't
     * enrolled with the Caseproof auth service yet (no site UUID → nothing
     * to JWT-sign).
     *
     * @param string                $methodId   The payment method identifier.
     * @param array<string, string> $returnArgs Extra query args appended to
     *     the admin-ajax return URL so the callback (ConnectAjax::handleUpdateCreds)
     *     can make context-aware decisions — e.g. onboarding passes
     *     ['from' => 'onboarding'] so the post-connect redirect lands back
     *     on the wizard instead of the Options page.
     */
    public static function connectUrl(string $methodId = self::METHOD_ID, array $returnArgs = []): ?string
    {
        $siteUuid = (string) get_option('prli_authenticator_site_uuid');
        if ($siteUuid === '') {
            return null;
        }

        $baseReturnUrl = add_query_arg(
            array_merge(
                [
                    'action'   => 'prli_stripe_connect_update_creds',
                    '_wpnonce' => wp_create_nonce('stripe-update-creds'),
                ],
                $returnArgs
            ),
            admin_url('admin-ajax.php')
        );

        $errorUrl = add_query_arg(['prli-action' => 'error'], $baseReturnUrl);

        $payload = [
            'method_id'           => $methodId,
            'site_uuid'           => $siteUuid,
            'user_uuid'           => (string) get_option('prli_authenticator_user_uuid'),
            'return_url'          => $baseReturnUrl,
            'error_url'           => $errorUrl,
            'webhook_url'         => self::notifyUrl($methodId, 'whk'),
            'service_webhook_url' => self::notifyUrl($methodId, 'stripe-service-whk'),
            'mp_version'          => \PrettyLinks\Bootstrap::version(),
        ];

        $jwt = Jwt::encode($payload);

        return self::SERVICE_URL . "/connect/{$siteUuid}/{$methodId}/{$jwt}";
    }

    /**
     * Build the webhook callback URL matching v3's scheme so connected
     * accounts keep delivering events after upgrade.
     *
     * Pretty permalinks ON → site_url('/prettylinks/notify/{method}/{action}')
     * Pretty permalinks OFF → site_url('/index.php?plugin=prli&pmt={method}&action={action}')
     *
     * @param string  $methodId The payment method identifier.
     * @param string  $action   The notify action slug.
     * @param boolean $forceSsl Whether to force an HTTPS scheme.
     *
     * @return string|null
     */
    public static function notifyUrl(string $methodId, string $action, bool $forceSsl = false): ?string
    {
        if (!isset(self::NOTIFIERS[$action])) {
            return null;
        }

        $permalinkStructure = (string) get_option('permalink_structure');
        $forceUgly          = (bool) get_option('prli_force_ugly_gateway_notify_urls');

        if ($forceUgly || $permalinkStructure === '') {
            $url = site_url('/index.php?plugin=prli') . "&pmt={$methodId}&action={$action}";
        } else {
            $structure = (string) apply_filters(
                'prli_gateway_notify_url_structure',
                '/prettylinks/notify/%gatewayid%/%action%'
            );
            $path      = str_replace(['%gatewayid%', '%action%'], [$methodId, $action], $structure);
            $url       = site_url($path);
        }

        if ($forceSsl) {
            $url = (string) preg_replace('/^http:/', 'https:', $url);
        }

        /**
         * Filtered gateway notify URL.
         *
         * @var string $url
         */
        $url = apply_filters('prli_gateway_notify_url', $url, self::METHOD_ID, $action, $methodId);
        return (string) apply_filters(
            'prli_gateway_' . self::METHOD_ID . '_' . $action . '_notify_url',
            $url,
            $methodId
        );
    }

    /**
     * Returns the current Stripe Connect status.
     *
     * @return string
     */
    public static function status(): string
    {
        return (string) get_option('prli_stripe_connect_status', 'not-connected');
    }

    /**
     * Returns the connected Stripe account name.
     *
     * @return string
     */
    public static function accountName(): string
    {
        return (string) get_option('prli_stripe_service_account_name', '');
    }

    /**
     * Returns the connected Stripe account ID.
     *
     * @return string
     */
    public static function accountId(): string
    {
        return (string) get_option('prli_stripe_service_account_id', '');
    }
}
