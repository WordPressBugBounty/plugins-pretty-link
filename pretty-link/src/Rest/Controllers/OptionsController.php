<?php

declare(strict_types=1);

namespace PrettyLinks\Rest\Controllers;

use PrettyLinks\Options\Store;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

class OptionsController extends BaseController
{
    /**
     * Registers the options REST routes.
     *
     * @return void
     */
    public function register(): void
    {
        register_rest_route($this->namespace(), '/options', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'index'],
                'permission_callback' => $this->permission(),
            ],
            [
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => [$this, 'update'],
                'permission_callback' => $this->permission(),
            ],
        ]);
    }

    /**
     * Returns the current options payload.
     *
     * @return WP_REST_Response
     */
    public function index(): WP_REST_Response
    {
        $store = $this->store();
        return new WP_REST_Response($this->buildPayload($store->all()));
    }

    /**
     * Persists updated options from the request body.
     *
     * @param WP_REST_Request $request The incoming REST request.
     *
     * @return WP_REST_Response
     */
    public function update(WP_REST_Request $request): WP_REST_Response
    {
        $body  = (array) $request->get_json_params();
        $store = $this->store();
        if (!empty($body['lite']) && is_array($body['lite'])) {
            $store->merge(array_intersect_key($body['lite'], $store->defaults()));
        }
        /**
         * Action: prli_options_update
         *
         * Fires on every /options PUT. Plugins read their own sub-payloads
         * from `$body` and persist whatever they own (e.g. Pro merges the
         * `pro` key into its own store). Lite only owns the `lite` key.
         *
         * @param array<string, mixed> $body Full request body.
         */
        do_action('prli_options_update', $body);

        return new WP_REST_Response($this->buildPayload($store->all()));
    }

    /**
     * Build the GET/PUT response. Lite supplies the `lite` key; plugins
     * hook `prli_options_response` to attach their own top-level keys
     * (e.g. Pro attaches `pro`).
     *
     * @param  array<string, mixed> $liteAll The Lite-owned options.
     * @return array<string, mixed>
     */
    private function buildPayload(array $liteAll): array
    {
        $payload = ['lite' => $liteAll];
        /**
         * Filtered options response payload.
         *
         * @var array<string, mixed> $payload
         */
        $payload = apply_filters('prli_options_response', $payload);
        return $payload;
    }

    /**
     * Resolves the options store from the container.
     *
     * @return Store
     */
    private function store(): Store
    {
        return $this->container->has(Store::class)
            ? $this->container->get(Store::class)
            : new Store();
    }
}
