<?php

declare(strict_types=1);

namespace PrettyLinks\Stripe;

defined('ABSPATH') || exit;

// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
// phpcs:disable WordPress.Security.ValidatedSanitizedInput.MissingUnslash
// phpcs:disable WordPress.Security.NonceVerification.Recommended
// $_SERVER values (REMOTE_ADDR, HTTP_USER_AGENT, REQUEST_URI, etc.) are read for
// click tracking / targeting / UI rendering, not form-submission input. State-changing
// operations in this class protect with wp_verify_nonce / check_admin_referer.
use PrettyLinks\Repositories\LinkMetas;
use PrettyLinks\Stripe\Exceptions\HttpException;
use PrettyLinks\Stripe\Exceptions\RemoteException;

/**
 * Visitor hits a pay link → build a Stripe Checkout Session and redirect.
 * 1:1 port of v3 `PrliStripeController::redirect()`:
 *
 *  - Assembles `mode`, `line_items`, success/cancel URLs, metadata, and the
 *    tax/shipping/phone/promo/tax_id collection options from link meta.
 *  - Re-applies the per-link `stripe_custom_text` as the Checkout submit
 *    message, and the per-link or global thank-you page as success_url.
 *  - On HTTP/Remote error: logs (when WP_DEBUG) and falls back to homepage.
 */
final class CheckoutRedirect
{
    /**
     * Registers the redirect-type filter hook.
     *
     * @return void
     */
    public static function loadHooks(): void
    {
        add_filter('prli_handle_redirect_type', [self::class, 'filter'], 10, 5);
    }

    /**
     * Intercepts PrettyPay link redirects and routes them to Stripe Checkout.
     *
     * @param  boolean              $handled Whether a prior filter already handled the redirect.
     * @param  array<string, mixed> $link    The link record.
     * @param  string               $target  The resolved redirect target URL.
     * @param  integer              $status  The HTTP redirect status code.
     * @param  string               $type    The redirect type.
     * @return boolean
     */
    public static function filter(bool $handled, array $link, string $target, int $status, string $type): bool
    {
        unset($target, $status);
        if ($handled) {
            return $handled;
        }
        if ($type !== 'prettypay_link_stripe' && (int) ($link['prettypay_link'] ?? 0) !== 1) {
            return false;
        }
        if (!Client::isConnectionActive()) {
            // Nothing we can do — bounce to homepage instead of 307'ing to an
            // URL field that, for pay links, is typically a placeholder.
            wp_safe_redirect(home_url('/'));
            exit;
        }

        try {
            self::redirectToCheckout($link);
        } catch (HttpException | RemoteException $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug log gated on WP_DEBUG.
                error_log('[PrettyLinks] Stripe checkout creation failed: ' . $e->getMessage());
            }
            wp_safe_redirect(home_url('/'));
            exit;
        }
        return true;
    }

    /**
     * Creates a Stripe Checkout session and redirects the buyer to it.
     *
     * @param array<string, mixed> $link The link record.
     *
     * @return void
     */
    private static function redirectToCheckout(array $link): void
    {
        $linkId = (int) ($link['id'] ?? 0);
        $metas  = new LinkMetas();
        $meta   = $metas->all($linkId);

        $lineItemsRaw = $meta['stripe_line_items'] ?? '[]';
        $lineItems    = json_decode($lineItemsRaw, true);
        if (!is_array($lineItems) || $lineItems === []) {
            wp_safe_redirect(home_url('/'));
            exit;
        }

        $apiLineItems = [];
        $hasRecurring = false;
        $oneTimeTotal = 0;
        foreach ($lineItems as $item) {
            if (!is_array($item)) {
                continue;
            }
            // The canonical stored shape nests the id at price.id (matches v3's
            // PrliStripeController and STRIPE_FOUNDATION.md §5); the v4 form also
            // writes a top-level price_id. Read nested first, fall back to
            // top-level, so migrated v3 links and newly-created v4 links both work.
            $priceId = (string) ($item['price']['id'] ?? $item['price_id'] ?? '');
            if ($priceId === '') {
                continue;
            }
            $apiLineItems[] = [
                'price'    => $priceId,
                'quantity' => max(1, (int) ($item['quantity'] ?? 1)),
            ];
            if (isset($item['price']['recurring']) && is_array($item['price']['recurring'])) {
                $hasRecurring = true;
            } elseif (isset($item['price']['unit_amount'])) {
                $oneTimeTotal += (int) $item['price']['unit_amount'];
            }
        }
        if ($apiLineItems === []) {
            wp_safe_redirect(home_url('/'));
            exit;
        }

        $mode = $hasRecurring ? 'subscription' : 'payment';

        $args = [
            'mode'        => $mode,
            'line_items'  => $apiLineItems,
            'success_url' => self::successUrl($meta),
            'cancel_url'  => home_url('/'),
            'metadata'    => [
                'created_by'    => 'prettylinks',
                'prettylink_id' => $linkId,
                'site_url'      => site_url(),
            ],
        ];

        if (self::bool($meta, 'stripe_automatic_tax')) {
            // Stripe's form-encoded API requires the string 'true', not PHP
            // true — wp_remote_request uses http_build_query which converts
            // PHP true to integer 1, triggering "Invalid boolean: 1". Matches
            // v3 PrliStripeController which sent 'enabled' => 'true' verbatim.
            $args['automatic_tax'] = ['enabled' => 'true'];
        }
        if (self::bool($meta, 'stripe_billing_address_collection')) {
            $args['billing_address_collection'] = 'required';
        }
        if (self::bool($meta, 'stripe_shipping_address_collection')) {
            $countries = self::countryList($meta['stripe_shipping_address_allowed_countries'] ?? '');
            if ($countries !== []) {
                $args['shipping_address_collection'] = ['allowed_countries' => $countries];
            }
        }
        if (self::bool($meta, 'stripe_phone_number_collection')) {
            $args['phone_number_collection'] = ['enabled' => 'true'];
        }
        if (self::bool($meta, 'stripe_allow_promotion_codes')) {
            $args['allow_promotion_codes'] = 'true';
        }
        if (self::bool($meta, 'stripe_tax_id_collection')) {
            $args['tax_id_collection'] = ['enabled' => 'true'];
        }
        $customText = (string) ($meta['stripe_custom_text'] ?? '');
        if ($customText !== '') {
            $args['custom_text'] = ['submit' => ['message' => $customText]];
        }

        if ($mode === 'subscription') {
            $subData = ['metadata' => $args['metadata']];
            if (self::bool($meta, 'stripe_include_free_trial')) {
                $days = (int) ($meta['stripe_trial_period_days'] ?? 0);
                if ($days > 0) {
                    $subData['trial_period_days'] = $days;
                }
            }
            $args['subscription_data'] = $subData;
        } else {
            $paymentData = ['metadata' => $args['metadata']];
            if (self::bool($meta, 'stripe_save_payment_details')) {
                $paymentData['setup_future_usage'] = 'off_session';
            }
            $args['payment_intent_data'] = $paymentData;
        }

        Fee::apply($args, $mode, $oneTimeTotal);

        /**
         * Filter: prli_stripe_checkout_session_args
         *
         * @param array<string, mixed> $args Stripe Checkout Session creation args.
         * @param array<string, mixed> $link The pay link row.
         */
        $args = (array) apply_filters('prli_stripe_checkout_session_args', $args, $link);

        $client = new Client();
        /**
         * The created Stripe Checkout session.
         *
         * @var array<string, mixed> $session
         */
        $session = $client->request('checkout/sessions', $args);

        $url = (string) ($session['url'] ?? '');
        if ($url === '') {
            wp_safe_redirect(home_url('/'));
            exit;
        }

        /**
 * Fires after a Stripe Checkout session is created for a pay link.
*/
        do_action('prli_prettypay_link_stripe_redirect', $link, $session);

        // Bind this checkout session to the buyer's browser so InvoiceRenderer
        // can verify ownership on the thank-you page (prevents IDOR via shared
        // or guessed session IDs). Must be set BEFORE the redirect.
        $sessionId = (string) ($session['id'] ?? '');
        if ($sessionId !== '') {
            setcookie(
                'prli_cs_token',
                hash_hmac('sha256', $sessionId, wp_salt('auth')),
                [
                    'expires'  => time() + 3600,
                    'path'     => '/',
                    'secure'   => is_ssl(),
                    'httponly' => true,
                    'samesite' => 'Lax',
                ]
            );
        }

        // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Redirecting to Stripe Checkout session URL (external by definition).
        wp_redirect($url);
        exit;
    }

    /**
     * Builds the Checkout success URL for a pay link.
     *
     * @param array<string, string> $meta The link's Stripe meta.
     *
     * @return string
     */
    private static function successUrl(array $meta): string
    {
        $linkPageId = (int) ($meta['stripe_thank_you_page_id'] ?? 0);
        if ($linkPageId > 0) {
            $url = (string) get_permalink($linkPageId);
        } else {
            $globalPageId = Helper::thankYouPageId();
            $url          = $globalPageId > 0 ? (string) get_permalink($globalPageId) : '';
        }
        if ($url === '') {
            $url = home_url('/');
        }
        // Stripe requires the literal placeholder — do not urlencode.
        return add_query_arg(['prli_session_id' => '{CHECKOUT_SESSION_ID}'], $url);
    }

    /**
     * Reads a boolean-ish meta value.
     *
     * @param  array<string, string> $meta The link's Stripe meta.
     * @param  string                $key  The meta key to read.
     * @return boolean
     */
    private static function bool(array $meta, string $key): bool
    {
        return isset($meta[$key]) && $meta[$key] === '1';
    }

    /**
     * Parses a comma-separated country list into an array.
     *
     * @param  string $raw The raw comma-separated list.
     * @return list<string>
     */
    private static function countryList(string $raw): array
    {
        if ($raw === '') {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }
}
