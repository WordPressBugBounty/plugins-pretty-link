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
use PrettyLinks\Stripe\Exceptions\HttpException;
use PrettyLinks\Stripe\Exceptions\RemoteException;

/**
 * Renders the post-checkout invoice on the thank-you page. Triggered by
 * `?prli_session_id=cs_*` — Stripe substitutes this token into the
 * success_url we pass during session creation.
 *
 * Mirrors v3 `PrliStripeController::display_invoice()`:
 *  - Filter-based so the page's regular content comes before the invoice.
 *  - Runs at most once per request (a `static $done` guard).
 *  - Respects `prli_disable_prettypay_invoice` so site owners can replace the
 *    render with their own markup.
 */
final class InvoiceRenderer
{
    /**
     * Registers the invoice rendering hooks.
     *
     * @return void
     */
    public static function loadHooks(): void
    {
        add_action('wp_enqueue_scripts', [self::class, 'enqueueStyles']);
        add_filter('the_content', [self::class, 'appendInvoice'], 10);
    }

    /**
     * Enqueues the invoice stylesheet on the thank-you page.
     *
     * @return void
     */
    public static function enqueueStyles(): void
    {
        $sessionId = self::sessionIdFromQuery();
        if ($sessionId === null) {
            return;
        }
        if ((bool) apply_filters('prli_disable_prettypay_invoice', false)) {
            return;
        }
        $baseUrl = defined('PRLI_URL') ? (string) constant('PRLI_URL') : plugins_url('/', dirname(__DIR__));
        wp_enqueue_style(
            'prli-prettypay-invoice',
            rtrim($baseUrl, '/') . '/assets/css/prettypay-invoice.css',
            [],
            \PrettyLinks\Bootstrap::version()
        );
    }

    /**
     * Appends the rendered invoice to the thank-you page content.
     *
     * @param  string $content The post content.
     * @return string
     */
    public static function appendInvoice(string $content): string
    {
        static $done = false;
        if ($done) {
            return $content;
        }
        $sessionId = self::sessionIdFromQuery();
        if ($sessionId === null) {
            return $content;
        }
        if ((bool) apply_filters('prli_disable_prettypay_invoice', false)) {
            return $content;
        }

        // Verify that this browser was the one redirected to Stripe for this
        // session (IDOR guard). The cookie was set in CheckoutRedirect before
        // the wp_redirect() call and is scoped to the buyer's browser only.
        $expected = hash_hmac('sha256', $sessionId, wp_salt('auth'));
        $cookie   = isset($_COOKIE['prli_cs_token']) ? sanitize_text_field(wp_unslash($_COOKIE['prli_cs_token'])) : '';
        if (!hash_equals($expected, $cookie)) {
            return $content;
        }

        $done = true;

        try {
            $client = new Client();
            /**
             * The expanded Stripe Checkout session.
             *
             * @var array<string, mixed> $session
             */
            $session = $client->request(
                "checkout/sessions/{$sessionId}",
                ['expand' => ['line_items.data.price.product', 'subscription.latest_invoice']],
                'get'
            );
        } catch (HttpException | RemoteException $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug log gated on WP_DEBUG.
                error_log('[PrettyLinks] Stripe session fetch failed: ' . $e->getMessage());
            }
            return $content;
        }

        $html = self::renderInvoiceHtml($session);
        /**
         * The filtered invoice HTML.
         *
         * @var string $filtered
         */
        $filtered = apply_filters('prli_prettypay_invoice_html', $html, $session);
        return $content . $filtered;
    }

    /**
     * Extracts a validated Checkout session ID from the query string.
     *
     * @return string|null
     */
    private static function sessionIdFromQuery(): ?string
    {
        if (!isset($_GET['prli_session_id'])) {
            return null;
        }
        $raw = sanitize_text_field((string) wp_unslash($_GET['prli_session_id']));
        if (strpos($raw, 'cs_') !== 0) {
            return null;
        }
        return $raw;
    }

    /**
     * Renders the invoice HTML from a Stripe Checkout session.
     *
     * @param array<string, mixed> $session The expanded Stripe Checkout session.
     *
     * @return string
     */
    private static function renderInvoiceHtml(array $session): string
    {
        $lineItems = (array) ($session['line_items']['data'] ?? []);
        $totals    = (array) ($session['total_details'] ?? []);
        $currency  = strtoupper((string) ($session['currency'] ?? 'USD'));
        $subtotal  = (int) ($session['amount_subtotal'] ?? 0);
        $discount  = (int) ($totals['amount_discount'] ?? 0);
        $tax       = (int) ($totals['amount_tax'] ?? 0);
        $total     = (int) ($session['amount_total'] ?? 0);
        $paid      = (string) ($session['payment_status'] ?? '') === 'paid';

        $orderId = (string) ($session['payment_intent']
            ?? $session['subscription']['latest_invoice']['payment_intent']
            ?? $session['id']
            ?? '');

        $out  = '<div class="prli-prettypay-invoice">';
        $out .= '<svg class="prli-check" aria-hidden="true" viewBox="0 0 24 24" width="40" height="40">'
            . '<circle cx="12" cy="12" r="11" fill="' . ($paid ? '#059669' : '#d1d5db') . '"/>'
            . '<path d="M7 12.5l3.2 3.2L17 9" stroke="#fff" stroke-width="2.4" fill="none" stroke-linecap="round" stroke-linejoin="round"/>'
            . '</svg>';

        $out .= '<p>';
        $out .= esc_html(
            $paid
                ? __('Thanks — your payment is complete.', 'pretty-link')
                : __('Your order is being processed.', 'pretty-link')
        );
        $out .= '</p>';

        if ($orderId !== '') {
            $out .= '<p class="prli-muted">'
                . esc_html__('Order ID:', 'pretty-link')
                . ' <code>' . esc_html($orderId) . '</code></p>';
        }

        $out .= '<table class="prli-prettypay-invoice-table">';
        foreach ($lineItems as $item) {
            if (!is_array($item)) {
                continue;
            }
            $desc = (string) ($item['description'] ?? ($item['price']['product']['name'] ?? ''));
            $amt  = (int) ($item['amount_subtotal'] ?? 0);
            $out .= '<tr class="prli-line-item-row">';
            $out .= '<td>' . esc_html($desc) . '</td>';
            $out .= '<td class="prli-text-align-right">' . esc_html($currency) . ' ' . esc_html(Helper::formatUnitAmount((float) $amt, $currency)) . '</td>';
            $out .= '</tr>';
        }

        $out .= self::totalsRow(__('Subtotal', 'pretty-link'), $subtotal, $currency, 'prli-subtotal-row');
        if ($discount > 0) {
            $out .= self::totalsRow(__('Discount', 'pretty-link'), -$discount, $currency, 'prli-subtotal-row');
        }
        if ($tax > 0) {
            $out .= self::totalsRow(__('Tax', 'pretty-link'), $tax, $currency, 'prli-subtotal-row');
        }
        $out .= self::totalsRow(__('Total', 'pretty-link'), $total, $currency, 'prli-total-row');
        $out .= '</table>';

        $portalUrl = '';
        $portalOpt = get_option('prli_stripe_customer_portal');
        if (is_array($portalOpt) && !empty($portalOpt['login_page']['url'])) {
            $portalUrl = (string) $portalOpt['login_page']['url'];
        }
        if (
            $portalUrl !== '' && isset($session['subscription']) && is_array($session['subscription'])
            && apply_filters('prli_display_invoice_portal_link', true)
        ) {
            $out .= '<p><a href="' . esc_url($portalUrl) . '">'
                . esc_html__('Manage your subscription', 'pretty-link') . '</a></p>';
        }

        $out .= '</div>';
        return $out;
    }

    /**
     * Renders a single totals row of the invoice table.
     *
     * @param  string  $label    The row label.
     * @param  integer $amount   The amount in the smallest currency unit.
     * @param  string  $currency The ISO currency code.
     * @param  string  $cls      The CSS class for the row.
     * @return string
     */
    private static function totalsRow(string $label, int $amount, string $currency, string $cls): string
    {
        return '<tr class="' . esc_attr($cls) . '">'
            . '<td>' . esc_html($label) . '</td>'
            . '<td class="prli-text-align-right">' . esc_html($currency) . ' ' . esc_html(Helper::formatUnitAmount((float) $amount, $currency)) . '</td>'
            . '</tr>';
    }
}
