<?php

declare(strict_types=1);

namespace PrettyLinks\GroundLevel\Mothership\Api;

use PrettyLinks\GroundLevel\Mothership\AbstractPluginConnection;
use PrettyLinks\GroundLevel\Mothership\Credentials;
use PrettyLinks\GroundLevel\Mothership\Util;

/**
 * Request class for the API. This returns the Response object.
 */
class Request
{
    /**
     * The cache key ID for the API cache.
     *
     * @var string
     */
    public const API_CACHE_ID = 'API_CACHE';

    /**
     * The plugin connection.
     *
     * @var AbstractPluginConnection
     */
    private AbstractPluginConnection $plugin;

    /**
     * The credentials instance.
     *
     * @var Credentials
     */
    private Credentials $credentials;

    /**
     * The utility instance.
     *
     * @var \PrettyLinks\GroundLevel\Mothership\Util
     */
    private Util $util;

    /**
     * The cache TTL in seconds.
     *
     * @inject \PrettyLinks\GroundLevel\Mothership\MothershipServiceProvider::PARAM_CACHE_TTL
     * @var    integer
     */
    private int $cacheTtl;

    /**
     * Constructor.
     *
     * @param AbstractPluginConnection $plugin      The plugin connection.
     * @param Credentials              $credentials The credentials instance.
     * @param Util                     $util        The utility instance.
     * @param integer                  $cacheTtl    The cache TTL in seconds.
     */
    public function __construct(
        AbstractPluginConnection $plugin,
        Credentials $credentials,
        Util $util,
        int $cacheTtl = 60
    ) {
        $this->plugin      = $plugin;
        $this->credentials = $credentials;
        $this->util        = $util;
        $this->cacheTtl    = $cacheTtl;
    }

    /**
     * Perform a GET request.
     *
     * @param  string $endpoint The API endpoint to request.
     * @param  array  $params   The query parameters to add to the URL.
     * @return Response
     */
    public function get(string $endpoint, array $params = []): Response
    {
        if (!empty($params)) {
            $endpoint = add_query_arg($params, $endpoint);
        }
        return $this->makeCachedGetRequest($endpoint);
    }

    /**
     * Perform a POST request.
     *
     * @param  string $endpoint The API endpoint to request.
     * @param  array  $body     The body of the request.
     * @return Response
     */
    public function post(string $endpoint, array $body = []): Response
    {
        return $this->makeRequest('POST', $endpoint, $body);
    }

    /**
     * Perform a PATCH request.
     *
     * @param  string $endpoint The API endpoint to request.
     * @param  array  $body     The body of the request.
     * @return Response
     */
    public function patch(string $endpoint, array $body = []): Response
    {
        return $this->makeRequest('PATCH', $endpoint, $body);
    }

    /**
     * Perform a PUT request.
     *
     * @param  string $endpoint The API endpoint to request.
     * @param  array  $body     The body of the request.
     * @return Response
     */
    public function put(string $endpoint, array $body = []): Response
    {
        return $this->makeRequest('PUT', $endpoint, $body);
    }

    /**
     * Perform a DELETE request.
     *
     * @param  string $endpoint The API endpoint to request.
     * @param  array  $body     The body of the request.
     * @return Response
     */
    public function delete(string $endpoint, array $body = []): Response
    {
        return $this->makeRequest('DELETE', $endpoint, $body);
    }

    /**
     * Make an HTTP request.
     *
     * @param  string $method   The HTTP method to use.
     * @param  string $endpoint The API endpoint to request.
     * @param  array  $body     The body of the request.
     * @return Response
     */
    private function makeRequest(string $method, string $endpoint, array $body = []): Response
    {
        $url  = $this->util->getApiBaseUrl() . ltrim($endpoint, '/');
        $args = [
            'method'  => $method,
            'headers' => $this->getAuthHeaders(),
        ];

        if (!empty($body)) {
            $args['body']                    = wp_json_encode($body);
            $args['data_format']             = 'body';
            $args['headers']['Content-Type'] = 'application/json; charset=utf-8';
            $args['headers']['Accept']       = 'application/json';
        }

        $response = wp_remote_request($url, $args);
        return $this->handleResponse($response);
    }

    /**
     * Make a cached GET request.
     *
     * @param  string $endpoint The API endpoint to request.
     * @return Response
     */
    private function makeCachedGetRequest(string $endpoint): Response
    {
        if ($this->cacheTtl > 0) {
            $cacheKey = implode('_', [
                $this->plugin->pluginId,
                'mothership',
                self::API_CACHE_ID,
                md5($endpoint),
            ]);

            $cachedResponse = get_transient($cacheKey);
            if ($cachedResponse instanceof Response) {
                return $cachedResponse;
            }

            $response = $this->makeRequest('GET', $endpoint);
            if (! $response->isError()) {
                set_transient($cacheKey, $response, $this->cacheTtl);
            }
            return $response;
        }
        return $this->makeRequest('GET', $endpoint);
    }

    /**
     * Get authentication headers.
     *
     * @return array The authentication headers.
     */
    protected function getAuthHeaders(): array
    {
        $headers = [];

        $licenseKey       = $this->credentials->getLicenseKey();
        $activationDomain = rawurlencode($this->credentials->getActivationDomain());
        if ($licenseKey && $activationDomain) {
            return [
                'Authorization' => 'Basic ' . base64_encode("$activationDomain:$licenseKey"),
            ];
        }

        $email    = $this->credentials->getEmail();
        $apiToken = $this->credentials->getApiToken();
        if ($email && $apiToken) {
            // Email/API Token authentication.
            if (!empty(Credentials::getProxyLicenseKey())) {
                $headers['X-Proxy-License-Key'] = Credentials::getProxyLicenseKey();
            }
            $headers['Authorization'] = 'Basic ' . base64_encode("$email:$apiToken");
        }
        return $headers;
    }

    /**
     * Handle the API response.
     *
     * @param  array|\WP_Error $response The response from the API.
     * @return Response
     */
    protected function handleResponse($response): Response
    {
        if (is_wp_error($response)) {
            return $this->handleWpError($response);
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body);

        $responseCode = (int) wp_remote_retrieve_response_code($response);

        return new Response($data, $responseCode, $this);
    }

    /**
     * Handle a WP_Error.
     *
     * @param  \WP_Error $response The response from the API.
     * @return Response
     */
    protected function handleWpError($response): Response
    {
        $errorMessage = 'WP_Error : ';
        if (isset($response->errors)) {
            $index = 0;
            foreach ($response->errors as $key => $error) {
                $errorMessage .= sprintf(
                    '%d. %s ',
                    $index + 1,
                    implode(', ', $error)
                );
                ++$index;
            }
        }
        return new Response((object) ['message' => $errorMessage], 500, $this);
    }
}
