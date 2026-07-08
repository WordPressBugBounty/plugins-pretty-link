<?php

declare(strict_types=1);

namespace PrettyLinks\Rest\Controllers;

use PrettyLinks\Admin\Pages\Options as OptionsPage;
use PrettyLinks\Licensing\AuthClient;
use PrettyLinks\Stripe\Client as StripeClient;
use PrettyLinks\Stripe\Connect;
use PrettyLinks\Stripe\CustomerPortal;
use PrettyLinks\Stripe\Exceptions\HttpException;
use PrettyLinks\Stripe\Exceptions\RemoteException;
use PrettyLinks\Stripe\Helper as StripeHelper;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * REST surface for the Pay Links UI. Everything the React form needs to
 * talk to Stripe (product search, create product/price, current connection
 * status, country/currency lists) sits here.
 */
class StripeController extends BaseController
{
    /**
     * Registers the Stripe REST routes.
     *
     * @return void
     */
    public function register(): void
    {
        register_rest_route($this->namespace(), '/stripe/status', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'status'],
                'permission_callback' => $this->permission(),
            ],
        ]);

        register_rest_route($this->namespace(), '/stripe/connect-url', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'connectUrl'],
                'permission_callback' => $this->permission(),
            ],
        ]);

        register_rest_route($this->namespace(), '/stripe/products/search', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'searchProducts'],
                'permission_callback' => $this->permission(),
                'args'                => [
                    'search' => ['type' => 'string'],
                ],
            ],
        ]);

        register_rest_route($this->namespace(), '/stripe/products', [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'createProduct'],
                'permission_callback' => $this->permission(),
            ],
        ]);

        register_rest_route($this->namespace(), '/stripe/shipping-countries', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'shippingCountries'],
                'permission_callback' => $this->permission(),
            ],
        ]);

        register_rest_route($this->namespace(), '/stripe/currencies', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'currencies'],
                'permission_callback' => $this->permission(),
            ],
        ]);

        register_rest_route($this->namespace(), '/stripe/settings', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'getSettings'],
                'permission_callback' => $this->permission(),
            ],
            [
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => [$this, 'updateSettings'],
                'permission_callback' => $this->permission(),
            ],
        ]);

        register_rest_route($this->namespace(), '/stripe/disconnect', [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'triggerDisconnect'],
                'permission_callback' => $this->permission(),
            ],
        ]);

        register_rest_route($this->namespace(), '/stripe/refresh', [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'triggerRefresh'],
                'permission_callback' => $this->permission(),
            ],
        ]);

        register_rest_route($this->namespace(), '/stripe/customer-portal', [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'configureCustomerPortal'],
                'permission_callback' => $this->permission(),
            ],
        ]);
    }

    /**
     * Returns the current PrettyPay settings.
     *
     * @param WP_REST_Request $request The incoming REST request.
     *
     * @return WP_REST_Response
     */
    public function getSettings(WP_REST_Request $request): WP_REST_Response
    {
        unset($request);
        $options = (array) (get_option('prli_options', []) ?: []);

        $portal           = get_option('prli_stripe_customer_portal');
        $portalConfigured = is_array($portal) && !empty($portal['login_page']['url']);

        return new WP_REST_Response([
            'prettypay_enabled'                => !isset($options['prettypay_enabled']) || (bool) $options['prettypay_enabled'],
            'prettypay_thank_you_page_id'      => (int) ($options['prettypay_thank_you_page_id'] ?? 0),
            'prettypay_default_currency'       => (string) ($options['prettypay_default_currency'] ?? StripeHelper::defaultCurrency()),
            'customer_portal_notice_dismissed' => (bool) get_option('prli_customer_portal_notice_dismissed', false),
            'has_recurring_prettypay_link'     => (bool) get_option('prli_has_recurring_prettypay_link', false),
            'customer_portal_configured'       => $portalConfigured,
            'customer_portal_url'              => $portalConfigured ? home_url(CustomerPortal::pageName()) : '',
        ]);
    }

    /**
     * Persists updated PrettyPay settings.
     *
     * @param WP_REST_Request $request The incoming REST request.
     *
     * @return WP_REST_Response
     */
    public function updateSettings(WP_REST_Request $request): WP_REST_Response
    {
        $body    = (array) $request->get_json_params();
        $options = (array) (get_option('prli_options', []) ?: []);
        if (array_key_exists('prettypay_enabled', $body)) {
            $options['prettypay_enabled'] = (bool) $body['prettypay_enabled'];
        }
        if (array_key_exists('prettypay_thank_you_page_id', $body)) {
            $options['prettypay_thank_you_page_id'] = (int) $body['prettypay_thank_you_page_id'];
        }
        if (array_key_exists('prettypay_default_currency', $body)) {
            $currencies = StripeHelper::currencies();
            $code       = strtoupper((string) $body['prettypay_default_currency']);
            if (isset($currencies[$code])) {
                $options['prettypay_default_currency'] = $code;
            }
        }
        update_option('prli_options', $options);

        if (array_key_exists('customer_portal_notice_dismissed', $body)) {
            update_option('prli_customer_portal_notice_dismissed', (bool) $body['customer_portal_notice_dismissed']);
        }
        return $this->getSettings($request);
    }

    /**
     * Create (or refresh) the Stripe Customer Portal configuration so
     * subscription buyers can self-manage their subscription.
     *
     * Ports v3's `PrliStripeController::configure_customer_portal()`: it calls
     * the Stripe `billing_portal/configurations` API with the no-code login
     * page enabled and stores the returned configuration — including its
     * `login_page.url` — in the `prli_stripe_customer_portal` option. That URL
     * is what `Stripe\InvoiceRenderer` surfaces as "Manage your subscription"
     * on the thank-you page. Without this, that option is never set and the
     * link never renders.
     *
     * Stripe's hosted login page is account-level: the default configuration
     * can't be edited, so when Stripe reports `is_default` we create a fresh
     * (PL-owned) configuration to host the login page, mirroring v3.
     *
     * @param WP_REST_Request $request The incoming REST request.
     *
     * @return WP_REST_Response
     */
    public function configureCustomerPortal(WP_REST_Request $request): WP_REST_Response
    {
        unset($request);

        if (!StripeClient::isConnectionActive()) {
            return new WP_REST_Response(
                ['error' => __('Stripe is not connected.', 'pretty-link')],
                400
            );
        }

        $args = [
            'business_profile'   => [
                'headline' => get_bloginfo('name'),
            ],
            'features'           => [
                'customer_update'       => [
                    'enabled'         => 'true',
                    'allowed_updates' => ['email', 'address', 'phone', 'tax_id'],
                ],
                'invoice_history'       => ['enabled' => 'true'],
                'payment_method_update' => ['enabled' => 'true'],
                'subscription_cancel'   => [
                    'enabled'            => 'true',
                    'mode'               => 'at_period_end',
                    'proration_behavior' => 'none',
                ],
            ],
            'default_return_url' => home_url('/'),
            'login_page'         => ['enabled' => 'true'],
            'metadata'           => ['created_by' => 'prettylinks'],
        ];

        $existing = get_option('prli_stripe_customer_portal');
        $endpoint = 'billing_portal/configurations';
        if (is_array($existing) && !empty($existing['id'])) {
            $endpoint       = 'billing_portal/configurations/' . $existing['id'];
            $args['active'] = 'true';
        }

        try {
            $client = new StripeClient();
            /**
             * Stripe billing portal configuration response.
             *
             * @var array<string, mixed> $config
             */
            $config = $client->request($endpoint, $args);

            // The account default configuration can't be edited — create a
            // fresh PL-owned one to carry the login page (v3 parity).
            if (!empty($config['is_default'])) {
                /**
                 * Freshly created PL-owned portal configuration response.
                 *
                 * @var array<string, mixed> $config
                 */
                $config = $client->request('billing_portal/configurations', $args);
            }
        } catch (HttpException | RemoteException $e) {
            return new WP_REST_Response(['error' => $e->getMessage()], 502);
        }

        update_option('prli_stripe_customer_portal', $config);
        update_option('prli_customer_portal_notice_dismissed', true);

        return new WP_REST_Response([
            'login_url'  => (string) ($config['login_page']['url'] ?? ''),
            'portal_url' => home_url(CustomerPortal::pageName()),
        ]);
    }

    /**
     * Browser-accessible companion to ConnectAjax — lets the React UI
     * trigger the v3 admin-ajax disconnect flow via a short redirect.
     *
     * @param WP_REST_Request $request The incoming REST request.
     *
     * @return WP_REST_Response
     */
    public function triggerDisconnect(WP_REST_Request $request): WP_REST_Response
    {
        unset($request);
        $url = add_query_arg(
            [
                'action'   => 'prli_stripe_connect_disconnect',
                '_wpnonce' => wp_create_nonce('stripe-disconnect'),
            ],
            admin_url('admin-ajax.php')
        );
        return new WP_REST_Response(['url' => $url]);
    }

    /**
     * Browser-accessible companion to ConnectAjax — lets the React UI
     * trigger the v3 admin-ajax refresh-credentials flow via a short redirect.
     *
     * @param WP_REST_Request $request The incoming REST request.
     *
     * @return WP_REST_Response
     */
    public function triggerRefresh(WP_REST_Request $request): WP_REST_Response
    {
        unset($request);
        $url = add_query_arg(
            [
                'action'   => 'prli_stripe_connect_refresh',
                '_wpnonce' => wp_create_nonce('stripe-refresh'),
            ],
            admin_url('admin-ajax.php')
        );
        return new WP_REST_Response(['url' => $url]);
    }

    /**
     * Returns the current Stripe connection status.
     *
     * @param WP_REST_Request $request The incoming REST request.
     *
     * @return WP_REST_Response
     */
    public function status(WP_REST_Request $request): WP_REST_Response
    {
        unset($request);
        return new WP_REST_Response([
            'active'           => StripeClient::isConnectionActive(),
            'test_mode'        => StripeClient::isTestMode(),
            'connect_status'   => Connect::status(),
            'account_id'       => Connect::accountId(),
            'account_name'     => Connect::accountName(),
            'publishable_key'  => StripeClient::publishableKey(),
            'default_currency' => StripeHelper::defaultCurrency(),
        ]);
    }

    /**
     * Returns the Stripe Connect onboarding URL.
     *
     * @param WP_REST_Request $request The incoming REST request.
     *
     * @return WP_REST_Response
     */
    public function connectUrl(WP_REST_Request $request): WP_REST_Response
    {
        unset($request);
        $url = Connect::connectUrl();
        if ($url === null) {
            // Site isn't enrolled with the Caseproof auth service yet. Rather
            // than dead-end, hand back an enroll-then-connect URL: the auth
            // service shows its login form, then chains straight into Stripe
            // Connect on return (stripe_connect=true&method_id=… handled by
            // AuthClient::maybeHandleReturn). Mirrors the Onboarding wizard so
            // a never-enrolled site can connect in one click from Payments too.
            $returnUrl = add_query_arg(
                [
                    'page'           => OptionsPage::SLUG,
                    'stripe_connect' => 'true',
                    'method_id'      => Connect::METHOD_ID,
                ],
                admin_url('admin.php')
            );
            $url       = (new AuthClient())->connectUrl($returnUrl);
        }
        return new WP_REST_Response(['url' => $url]);
    }

    /**
     * Search Stripe products / prices for the line-item picker. 1:1 port
     * of v3 `search_stripe_prices`: accepts `price_`, `plan_`, `prod_`
     * prefixes for direct lookup, otherwise does a name~ substring query.
     *
     * @param WP_REST_Request $request The incoming REST request.
     *
     * @return WP_REST_Response
     */
    public function searchProducts(WP_REST_Request $request): WP_REST_Response
    {
        if (!StripeClient::isConnectionActive()) {
            return new WP_REST_Response(['error' => 'not_connected'], 400);
        }

        $search = trim((string) $request->get_param('search'));
        $client = new StripeClient();

        try {
            $prices = $this->searchPrices($client, $search);
        } catch (HttpException | RemoteException $e) {
            return new WP_REST_Response([
                'error'   => 'stripe_error',
                'message' => $e->getMessage(),
            ], 502);
        }

        $grouped = [];
        foreach ($prices as $price) {
            if (!is_array($price) || empty($price['active']) || empty($price['product']) || !is_array($price['product'])) {
                continue;
            }
            $product   = $price['product'];
            $productId = (string) ($product['id'] ?? '');
            if ($productId === '') {
                continue;
            }
            if (!isset($grouped[$productId])) {
                $grouped[$productId] = [
                    'product_id'   => $productId,
                    'product_name' => (string) ($product['name'] ?? ''),
                    'images'       => array_values((array) ($product['images'] ?? [])),
                    'prices'       => [],
                ];
            }
            $grouped[$productId]['prices'][] = [
                'price_id'    => (string) ($price['id'] ?? ''),
                'label'       => StripeHelper::formatPrice($price),
                'currency'    => strtoupper((string) ($price['currency'] ?? '')),
                'unit_amount' => (int) ($price['unit_amount'] ?? 0),
                'recurring'   => isset($price['recurring']) && is_array($price['recurring']) ? [
                    'interval'       => (string) ($price['recurring']['interval'] ?? ''),
                    'interval_count' => (int) ($price['recurring']['interval_count'] ?? 1),
                ] : null,
            ];
        }

        return new WP_REST_Response(['results' => array_values($grouped)]);
    }

    /**
     * Creates a Stripe price (and product) for the line-item picker.
     *
     * @param WP_REST_Request $request The incoming REST request.
     *
     * @return WP_REST_Response
     */
    public function createProduct(WP_REST_Request $request): WP_REST_Response
    {
        if (!StripeClient::isConnectionActive()) {
            return new WP_REST_Response(['error' => 'not_connected'], 400);
        }

        $body  = (array) $request->get_json_params();
        $data  = $this->sanitizeProductData($body);
        $error = $this->validateProductData($data);
        if ($error !== null) {
            return new WP_REST_Response([
                'error'   => 'invalid',
                'message' => $error,
            ], 400);
        }

        $args = [
            'currency'     => strtolower($data['currency']),
            'unit_amount'  => StripeHelper::toZeroDecimalAmount((float) $data['price'], $data['currency']),
            'product_data' => ['name' => $data['name']],
            'tax_behavior' => $data['tax_behavior'],
            'expand'       => ['product'],
        ];

        if ($data['type'] === 'recurring') {
            $args['recurring'] = $this->recurringData($data);
        }

        $client = new StripeClient();
        try {
            /**
             * Created Stripe price response.
             *
             * @var array<string, mixed> $price
             */
            $price = $client->request('prices', $args);
        } catch (HttpException | RemoteException $e) {
            return new WP_REST_Response([
                'error'   => 'stripe_error',
                'message' => $e->getMessage(),
            ], 502);
        }

        $product = (array) ($price['product'] ?? []);

        return new WP_REST_Response([
            'price_id'     => (string) ($price['id'] ?? ''),
            'product_id'   => (string) ($product['id'] ?? ''),
            'product_name' => (string) ($product['name'] ?? ''),
            'label'        => StripeHelper::formatPrice($price),
            'currency'     => strtoupper((string) ($price['currency'] ?? '')),
            'unit_amount'  => (int) ($price['unit_amount'] ?? 0),
            'recurring'    => isset($price['recurring']) && is_array($price['recurring']) ? [
                'interval'       => (string) ($price['recurring']['interval'] ?? ''),
                'interval_count' => (int) ($price['recurring']['interval_count'] ?? 1),
            ] : null,
        ], 201);
    }

    /**
     * Returns the list of supported shipping countries.
     *
     * @param WP_REST_Request $request The incoming REST request.
     *
     * @return WP_REST_Response
     */
    public function shippingCountries(WP_REST_Request $request): WP_REST_Response
    {
        unset($request);
        $countries = StripeHelper::shippingCountries();
        $out       = [];
        foreach ($countries as $code => $name) {
            $out[] = [
                'code' => $code,
                'name' => $name,
            ];
        }
        return new WP_REST_Response($out);
    }

    /**
     * Returns the list of supported currencies.
     *
     * @param WP_REST_Request $request The incoming REST request.
     *
     * @return WP_REST_Response
     */
    public function currencies(WP_REST_Request $request): WP_REST_Response
    {
        unset($request);
        $currencies = StripeHelper::currencies();
        $out        = [];
        foreach ($currencies as $code => $name) {
            $out[] = [
                'code' => $code,
                'name' => $name,
            ];
        }
        return new WP_REST_Response($out);
    }

    /**
     * Searches Stripe prices/products and returns the matching prices.
     *
     * @param StripeClient $client The Stripe API client.
     * @param string       $search The raw search term or ID prefix.
     *
     * @return list<array<string, mixed>>
     */
    private function searchPrices(StripeClient $client, string $search): array
    {
        if (stripos($search, 'price_') === 0 || stripos($search, 'plan_') === 0) {
            try {
                /**
                 * Single Stripe price lookup response.
                 *
                 * @var array<string, mixed> $price
                 */
                $price = $client->request("prices/{$search}", ['expand' => ['product']], 'get');
                return [$price];
            } catch (HttpException | RemoteException $e) {
                return [];
            }
        }

        $productIds = [];
        if (stripos($search, 'prod_') === 0) {
            $productIds = [$search];
        } else {
            $args = [
                'query' => 'active: "true"',
                'limit' => 5,
            ];
            if ($search !== '') {
                // Strip quote delimiters and backslashes to prevent Stripe query injection.
                $safeSearch     = str_replace(['"', '\\'], '', $search);
                $args['query'] .= " AND name~\"{$safeSearch}\"";
            }
            /**
             * Stripe products search response.
             *
             * @var array<string, mixed> $products
             */
            $products = $client->request('products/search', $args, 'get');
            if (isset($products['data']) && is_array($products['data'])) {
                foreach ($products['data'] as $p) {
                    if (isset($p['id'])) {
                        $productIds[] = (string) $p['id'];
                    }
                }
            }
        }

        $args = [
            'expand' => ['data.product'],
            'limit'  => 100,
        ];
        if ($productIds) {
            $conditions    = array_map(static fn(string $id) => "product: \"{$id}\"", $productIds);
            $args['query'] = implode(' OR ', $conditions);
        } else {
            $args['query'] = 'active: "true"';
            $args['limit'] = 20;
        }

        /**
         * Stripe prices search response.
         *
         * @var array<string, mixed> $result
         */
        $result = $client->request('prices/search', $args, 'get');
        if (!isset($result['data']) || !is_array($result['data'])) {
            return [];
        }
        /**
         * The list of Stripe price objects.
         *
         * @var list<array<string, mixed>> $prices
         */
        $prices = $result['data'];
        return $prices;
    }

    /**
     * Sanitizes the raw product creation payload.
     *
     * @param  array<string, mixed> $data The raw request body.
     * @return array<string, string>
     */
    private function sanitizeProductData(array $data): array
    {
        return [
            'name'           => isset($data['name']) ? sanitize_text_field((string) $data['name']) : '',
            'price'          => isset($data['price']) ? sanitize_text_field((string) $data['price']) : '',
            'currency'       => isset($data['currency']) ? sanitize_text_field((string) $data['currency']) : '',
            'type'           => isset($data['type']) ? sanitize_text_field((string) $data['type']) : '',
            'billing_period' => isset($data['billing_period']) ? sanitize_text_field((string) $data['billing_period']) : '',
            'interval'       => isset($data['interval']) ? sanitize_text_field((string) $data['interval']) : '',
            'interval_count' => isset($data['interval_count']) ? sanitize_text_field((string) $data['interval_count']) : '',
            'tax_behavior'   => isset($data['tax_behavior']) ? sanitize_text_field((string) $data['tax_behavior']) : '',
        ];
    }

    /**
     * Validates the sanitized product data.
     *
     * @param array<string, string> $data The sanitized product data.
     *
     * @return string|null Error message, or null when valid.
     */
    private function validateProductData(array $data): ?string
    {
        if ($data['name'] === '') {
            return __('A product name is required', 'pretty-link');
        }
        if ($data['price'] === '') {
            return __('A product price is required', 'pretty-link');
        }
        if (!is_numeric($data['price']) || (float) $data['price'] <= 0) {
            return __('The product price must be numeric and greater than zero', 'pretty-link');
        }
        if ($data['currency'] === '') {
            return __('A product currency is required', 'pretty-link');
        }
        if (!array_key_exists(strtoupper($data['currency']), StripeHelper::currencies())) {
            return __('The given currency is not supported', 'pretty-link');
        }
        if (!in_array($data['type'], ['recurring', 'one_time'], true)) {
            return __('The given product type is not supported', 'pretty-link');
        }
        if ($data['type'] === 'recurring') {
            if (!in_array($data['billing_period'], ['day', 'week', 'month', 'quarter', 'semiannual', 'year', 'custom'], true)) {
                return __('The given billing period is not supported', 'pretty-link');
            }
            if ($data['billing_period'] === 'custom') {
                if (!is_numeric($data['interval_count']) || (int) $data['interval_count'] <= 0) {
                    return __('The given custom billing interval count must be numeric and greater than zero', 'pretty-link');
                }
                if (!in_array($data['interval'], ['day', 'week', 'month'], true)) {
                    return __('The given custom billing interval is not supported', 'pretty-link');
                }
            }
        }
        if (!in_array($data['tax_behavior'], ['unspecified', 'inclusive', 'exclusive'], true)) {
            return __('The given tax behavior is not supported', 'pretty-link');
        }
        return null;
    }

    /**
     * Builds the Stripe `recurring` argument from product data.
     *
     * @param  array<string, string> $data The sanitized product data.
     * @return array<string, int|string>
     */
    private function recurringData(array $data): array
    {
        switch ($data['billing_period']) {
            case 'day':
            case 'week':
            case 'month':
            case 'year':
                return [
                    'interval'       => $data['billing_period'],
                    'interval_count' => 1,
                ];
            case 'quarter':
                return [
                    'interval'       => 'month',
                    'interval_count' => 3,
                ];
            case 'semiannual':
                return [
                    'interval'       => 'month',
                    'interval_count' => 6,
                ];
            default:
                return [
                    'interval'       => $data['interval'],
                    'interval_count' => (int) $data['interval_count'],
                ];
        }
    }
}
