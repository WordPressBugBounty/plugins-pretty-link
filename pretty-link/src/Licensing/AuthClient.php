<?php

declare(strict_types=1);

namespace PrettyLinks\Licensing;

/**
 * HTTP client for the Caseproof Auth service (auth.caseproof.com).
 *
 * Implements the OAuth-style connect / disconnect flow documented in
 * v3.x `PrliAuthenticatorController`. Three persistent options track state:
 *
 *   prli_authenticator_site_uuid
 *   prli_authenticator_account_email
 *   prli_authenticator_secret_token
 *   prli_authenticator_user_uuid
 *
 * Option names preserved exactly from 3.x for back-compat.
 */
class AuthClient
{
    public const DEFAULT_DOMAIN = 'https://auth.caseproof.com';

    public const PRODUCT = 'prettylinks';

    public const TIMEOUT = 10;

    public const OPTION_SITE_UUID = 'prli_authenticator_site_uuid';

    public const OPTION_ACCOUNT_EMAIL = 'prli_authenticator_account_email';

    public const OPTION_SECRET_TOKEN = 'prli_authenticator_secret_token';

    public const OPTION_USER_UUID = 'prli_authenticator_user_uuid';

    /**
     * Resolved auth service base domain.
     *
     * @var string
     */
    private string $domain;

    /**
     * Set up the client with an optional explicit domain.
     *
     * @param string|null $domain Optional auth service domain; resolved from config when null.
     */
    public function __construct(?string $domain = null)
    {
        $this->domain = $domain !== null ? rtrim($domain, '/') : self::resolveDomain();
    }

    /**
     * Admin-init handler for the auth service return trip. Auth service
     * redirects the admin back to `?prli-connect=true&nonce=…&site_uuid=…&auth_code=…`
     * — this verifies the nonce, exchanges tokens, and (optionally) chains to
     * a Stripe Connect redirect when the caller requested the combined flow
     * by including `stripe_connect=true&method_id=…` in the original
     * return URL (v3 parity — PrliAuthenticatorController::process_connect).
     */
    public static function maybeHandleReturn(): void
    {
        if (!is_admin() || wp_doing_ajax() || wp_doing_cron()) {
            return;
        }
        if (!isset($_GET['prli-connect']) || (string) $_GET['prli-connect'] !== 'true') {
            return;
        }
        if (!current_user_can('manage_options')) {
            return;
        }
        $nonce = isset($_GET['nonce']) ? sanitize_text_field(wp_unslash((string) $_GET['nonce'])) : '';
        if ($nonce === '' || !wp_verify_nonce($nonce, 'prli-connect')) {
            return;
        }

        $siteUuid = isset($_GET['site_uuid']) ? sanitize_text_field(wp_unslash((string) $_GET['site_uuid'])) : '';
        $authCode = isset($_GET['auth_code']) ? sanitize_text_field(wp_unslash((string) $_GET['auth_code'])) : '';
        if ($siteUuid === '' || $authCode === '') {
            return;
        }

        (new self())->exchangeTokens($siteUuid, $authCode);

        // Optional second leg: kick straight into Stripe Connect so the user
        // doesn't have to click again after enrolling.
        if (isset($_GET['stripe_connect']) && (string) $_GET['stripe_connect'] === 'true' && !empty($_GET['method_id'])) {
            $methodId  = sanitize_key((string) $_GET['method_id']);
            $extraArgs = [];
            if (isset($_GET['from'])) {
                $extraArgs['from'] = sanitize_key((string) $_GET['from']);
            }
            $stripeUrl = \PrettyLinks\Stripe\Connect::connectUrl($methodId, $extraArgs);
            if ($stripeUrl !== null) {
                // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Redirecting to Stripe Connect OAuth URL (external by definition).
                wp_redirect($stripeUrl);
                exit;
            }
        }

        // Otherwise strip the handshake params so a refresh doesn't re-run.
        $clean = remove_query_arg(
            ['prli-connect', 'nonce', 'site_uuid', 'user_uuid', 'auth_code', 'stripe_connect', 'method_id']
        );
        wp_safe_redirect($clean);
        exit;
    }

    /**
     * Build the URL the browser should be redirected to in order to begin a
     * connect handshake. The auth service will redirect back to `returnUrl`
     * appended with the WP-side `prli-connect` nonce and the obtained tokens.
     *
     * @param string               $returnUrl   The URL the auth service should redirect back to.
     * @param array<string, mixed> $extraParams Optional extra query parameters to include.
     *
     * @return string The fully built connect handshake URL.
     */
    public function connectUrl(string $returnUrl, array $extraParams = []): string
    {
        $params = [
            'return_url' => rawurlencode(add_query_arg('prli-connect', 'true', $returnUrl)),
            'nonce'      => wp_create_nonce('prli-connect'),
        ];

        $siteUuid = (string) get_option(self::OPTION_SITE_UUID, '');
        if ($siteUuid !== '') {
            $params['site_uuid'] = $siteUuid;
        }

        if (!empty($extraParams)) {
            $params = array_merge($params, $extraParams);
        }

        return add_query_arg($params, $this->domain . '/connect/' . self::PRODUCT);
    }

    /**
     * Exchange a site_uuid + auth_code for tokens. Persists account email,
     * secret token, and user uuid on success.
     *
     * @param string $siteUuid The site UUID returned by the auth service.
     * @param string $authCode The one-time auth code to exchange for tokens.
     *
     * @return array<string, mixed> The decoded auth service response, or an error array.
     */
    public function exchangeTokens(string $siteUuid, string $authCode): array
    {
        $response = wp_remote_get(
            $this->domain . '/api/tokens/' . rawurlencode($siteUuid),
            [
                'timeout'   => self::TIMEOUT,
                'sslverify' => true,
                'headers'   => [
                    'accept' => 'application/json',
                ],
                'body'      => [
                    'auth_code' => $authCode,
                ],
            ]
        );

        if (is_wp_error($response)) {
            return ['error' => $response->get_error_message()];
        }

        $raw     = (string) wp_remote_retrieve_body($response);
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return ['error' => 'invalid_response'];
        }

        if (!empty($decoded['account_email'])) {
            update_option(self::OPTION_ACCOUNT_EMAIL, sanitize_text_field((string) $decoded['account_email']));
        }
        if (!empty($decoded['secret_token'])) {
            update_option(self::OPTION_SECRET_TOKEN, sanitize_text_field((string) $decoded['secret_token']));
        }
        if (!empty($decoded['user_uuid'])) {
            update_option(self::OPTION_USER_UUID, sanitize_text_field((string) $decoded['user_uuid']));
        }
        if ($siteUuid !== '') {
            update_option(self::OPTION_SITE_UUID, sanitize_text_field($siteUuid));
        }

        return $decoded;
    }

    /**
     * Disconnect from the auth service. Sends a signed JWT so the server
     * knows which (email, site_uuid) pair to invalidate. Clears all local
     * connection options on success.
     *
     * @return array<string, mixed>
     */
    public function disconnect(): array
    {
        $email    = (string) get_option(self::OPTION_ACCOUNT_EMAIL, '');
        $siteUuid = (string) get_option(self::OPTION_SITE_UUID, '');
        $secret   = (string) get_option(self::OPTION_SECRET_TOKEN, '');

        if ($email === '' || $siteUuid === '' || $secret === '') {
            $this->clear();
            return [
                'disconnected' => true,
                'local_only'   => true,
            ];
        }

        $jwt = self::generateJwt([
            'email'     => $email,
            'site_uuid' => $siteUuid,
        ], $secret);

        $response = wp_remote_request(
            $this->domain . '/api/disconnect/' . self::PRODUCT,
            [
                'method'    => 'DELETE',
                'timeout'   => self::TIMEOUT,
                'sslverify' => true,
                'headers'   => [
                    'Authorization' => 'Bearer ' . $jwt,
                    'Accept'        => 'application/json',
                ],
            ]
        );

        if (is_wp_error($response)) {
            // We still wipe local state on transport failure — the user
            // explicitly asked to disconnect.
            $this->clear();
            return [
                'disconnected' => true,
                'error'        => $response->get_error_message(),
            ];
        }

        $raw     = (string) wp_remote_retrieve_body($response);
        $decoded = json_decode($raw, true);
        if (is_array($decoded) && !empty($decoded['disconnected'])) {
            $this->clear();
            return $decoded;
        }

        // Server did not confirm — still clear local state.
        $this->clear();
        return is_array($decoded) ? $decoded : ['disconnected' => true];
    }

    /**
     * Wipe all local auth-connection options.
     */
    public function clear(): void
    {
        delete_option(self::OPTION_SITE_UUID);
        delete_option(self::OPTION_ACCOUNT_EMAIL);
        delete_option(self::OPTION_SECRET_TOKEN);
        delete_option(self::OPTION_USER_UUID);
    }

    /**
     * Determine whether the site is currently connected to the auth service.
     *
     * @return boolean True when both the account email and site UUID are stored.
     */
    public function isConnected(): bool
    {
        return get_option(self::OPTION_ACCOUNT_EMAIL) && get_option(self::OPTION_SITE_UUID);
    }

    /**
     * Generate a JWT signed with HS256 using the stored secret.
     *
     * @param array<string, mixed> $payload The claims to encode into the token body.
     * @param string               $secret  The shared secret used to sign the token.
     *
     * @return string The encoded JWT.
     */
    public static function generateJwt(array $payload, string $secret): string
    {
        $header = [
            'typ' => 'JWT',
            'alg' => 'HS256',
        ];
        $hEnc   = self::base64UrlEncode((string) wp_json_encode($header));
        $pEnc   = self::base64UrlEncode((string) wp_json_encode($payload));

        $signature = hash_hmac('sha256', $hEnc . '.' . $pEnc, $secret);
        $sEnc      = self::base64UrlEncode($signature);

        return $hEnc . '.' . $pEnc . '.' . $sEnc;
    }

    /**
     * Base64URL-encode a string (RFC 4648 url-safe alphabet, no padding).
     *
     * @param string $value The raw string to encode.
     *
     * @return string The base64url-encoded string.
     */
    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /**
     * Resolve the auth service domain from config, falling back to the default.
     *
     * @return string The resolved auth service base domain.
     */
    private static function resolveDomain(): string
    {
        if (defined('PRLI_AUTH_SERVICE_DOMAIN')) {
            $constant = (string) constant('PRLI_AUTH_SERVICE_DOMAIN');
            if ($constant !== '') {
                if (strpos($constant, 'http') !== 0) {
                    $constant = 'https://' . $constant;
                }
                return rtrim($constant, '/');
            }
        }
        return self::DEFAULT_DOMAIN;
    }
}
