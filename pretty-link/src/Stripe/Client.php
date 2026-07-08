<?php

declare(strict_types=1);

namespace PrettyLinks\Stripe;

use PrettyLinks\Stripe\Exceptions\HttpException;
use PrettyLinks\Stripe\Exceptions\RemoteException;

/**
 * Low-level Stripe API client. 1:1 port of v3 `send_stripe_request()`:
 * Basic auth with secret key, API version 2022-11-15, body = form-encoded
 * associative args, identical filter names so any v3 customizations carry
 * over.
 */
final class Client
{
    public const API_VERSION = '2022-11-15';
    public const API_BASE    = 'https://api.stripe.com/v1/';

    /**
     * Sends a request to the Stripe API and returns the decoded response.
     *
     * @param  string               $endpoint       The Stripe API endpoint, relative to the base.
     * @param  array<string, mixed> $args           The request body arguments.
     * @param  string               $method         The HTTP method.
     * @param  boolean              $blocking       Whether to wait for the response.
     * @param  string|null          $idempotencyKey Optional idempotency key.
     * @return array<string, mixed>|true
     *
     * @throws HttpException   When the HTTP request fails.
     * @throws RemoteException When the remote server returns an invalid or error response.
     */
    public function request(
        string $endpoint,
        array $args = [],
        string $method = 'post',
        bool $blocking = true,
        ?string $idempotencyKey = null
    ) {
        $uri = self::API_BASE . ltrim($endpoint, '/');

        /**
         * Filtered Stripe request body arguments.
         *
         * @var array<string, mixed> $args
         */
        $args = apply_filters('prli_stripe_request_args', $args);

        $requestArgs = [
            'method'   => strtoupper($method),
            'body'     => $args,
            'timeout'  => 15,
            'blocking' => $blocking,
            'headers'  => $this->headers(),
        ];

        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $requestArgs['headers']['Idempotency-Key'] = $idempotencyKey;
        }

        /**
         * Filtered WordPress HTTP request arguments.
         *
         * @var array<string, mixed> $requestArgs
         */
        $requestArgs = apply_filters('prli_stripe_request', $requestArgs);

        $response = wp_remote_request($uri, $requestArgs);

        if (!$blocking) {
            return true;
        }

        if (is_wp_error($response)) {
            throw new HttpException(esc_html__('HTTP error', 'pretty-link'));
        }

        $body   = wp_remote_retrieve_body($response);
        $parsed = json_decode($body, true);

        if (!is_array($parsed)) {
            throw new RemoteException(esc_html__('Invalid response from the remote server', 'pretty-link'));
        }

        if (isset($parsed['error'])) {
            $message = (string) ($parsed['error']['message'] ?? 'Unknown');
            $type    = (string) ($parsed['error']['type'] ?? 'stripe_error');
            throw new RemoteException(esc_html("{$message} ({$type})"));
        }

        return $parsed;
    }

    /**
     * Returns true when the active-mode secret key is present, mirroring
     * v3 `PrliStripeHelper::is_connection_active()`.
     */
    public static function isConnectionActive(): bool
    {
        return self::secretKey() !== '';
    }

    /**
     * Returns true when Stripe test mode is enabled.
     *
     * @return boolean
     */
    public static function isTestMode(): bool
    {
        return defined('PRLI_STRIPE_TEST_MODE') && (bool) constant('PRLI_STRIPE_TEST_MODE');
    }

    /**
     * Returns the active-mode Stripe secret key.
     *
     * @return string
     */
    public static function secretKey(): string
    {
        return (string) get_option(
            self::isTestMode() ? 'prli_stripe_test_secret_key' : 'prli_stripe_live_secret_key'
        );
    }

    /**
     * Returns the active-mode Stripe publishable key.
     *
     * @return string
     */
    public static function publishableKey(): string
    {
        return (string) get_option(
            self::isTestMode() ? 'prli_stripe_test_publishable_key' : 'prli_stripe_live_publishable_key'
        );
    }

    /**
     * Builds the Stripe API request headers.
     *
     * @return array<string, string>
     */
    private function headers(): array
    {
        $userAgent = [
            'lang'         => 'php',
            'lang_version' => phpversion(),
            'publisher'    => 'prettylinks',
            'uname'        => function_exists('php_uname') ? php_uname() : '',
            'application'  => [
                'name'    => 'Pretty Links Connect',
                'version' => \PrettyLinks\Bootstrap::version(),
                'url'     => 'https://prettylinks.com/',
            ],
        ];

        $app = $userAgent['application'];

        /**
         * Filtered Stripe request headers.
         *
         * @var array<string, string> $headers
         */
        $headers = apply_filters('prli_stripe_request_headers', [
            'Authorization'              => 'Basic ' . base64_encode(self::secretKey() . ':'),
            'Stripe-Version'             => self::API_VERSION,
            'User-Agent'                 => $app['name'] . '/' . $app['version'] . ' (' . $app['url'] . ')',
            'X-Stripe-Client-User-Agent' => (string) wp_json_encode($userAgent),
        ]);

        return $headers;
    }
}
