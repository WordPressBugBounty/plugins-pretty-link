<?php

declare(strict_types=1);

namespace PrettyLinks\GroundLevel\Mothership\Api\Request;

use PrettyLinks\GroundLevel\Mothership\Api\Request;
use PrettyLinks\GroundLevel\Mothership\Api\Response;

/**
 * This class is used to interact with the products API.
 *
 * @link https://licenses.caseproof.com/help/api-reference#products
 */
class Products
{
    /**
     * The request instance.
     *
     * @var \PrettyLinks\GroundLevel\Mothership\Api\Request
     */
    private Request $request;

    /**
     * Constructor.
     *
     * @param \PrettyLinks\GroundLevel\Mothership\Api\Request $request The request instance.
     */
    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    /**
     * Get product by slug.
     *
     * @param string $slug The product slug.
     * @param array  $args Additional arguments for the request.
     *
     * @return \PrettyLinks\GroundLevel\Mothership\Api\Response The product data.
     */
    public function get(string $slug = '', array $args = []): Response
    {
        $endpoint = 'products/' . $slug;
        return $this->request->get($endpoint, $args);
    }

    /**
     * Get notifications for a product.
     *
     * @param  string $slug The product slug.
     * @param  array  $args Additional arguments for the request.
     * @return \PrettyLinks\GroundLevel\Mothership\Api\Response The response from the API.
     */
    public function getNotifications(string $slug, array $args = []): Response
    {
        $endpoint = 'products/' . $slug . '/notifications';
        return $this->request->get($endpoint, $args);
    }

    /**
     * List all products.
     *
     * @param array $args Additional arguments for the request.
     *
     * @return \PrettyLinks\GroundLevel\Mothership\Api\Response The list of products.
     */
    public function list(array $args = []): Response
    {
        return $this->get('', $args);
    }

    /**
     * Get product by product slug and version.
     *
     * @param string $slug    The product slug.
     * @param string $version The product version.
     * @param array  $args    Additional arguments for the request.
     *
     * @return \PrettyLinks\GroundLevel\Mothership\Api\Response The response from the API.
     */
    public function getVersion(string $slug, string $version, array $args = []): Response
    {
        $endpoint = 'products/' . $slug . '/versions/' . $version;
        return $this->request->get($endpoint, $args);
    }

    /**
     * Get the latest version for a product.
     *
     * @param string $slug The product slug.
     * @param array  $args Additional arguments for the request.
     *
     * @return \PrettyLinks\GroundLevel\Mothership\Api\Response The response from the API.
     */
    public function getVersionLatest(string $slug, array $args = []): Response
    {
        $endpoint = 'products/' . $slug . '/versions/latest';
        return $this->request->get($endpoint, $args);
    }

    /**
     * Get the latest version check for a product. Requests without a valid license
     * are permitted, but may not utilize embeds.
     *
     * @param string $slug The product slug.
     * @param array  $args Additional arguments for the request.
     *
     * @return \PrettyLinks\GroundLevel\Mothership\Api\Response The response from the API.
     */
    public function getVersionCheck(string $slug, array $args = []): Response
    {
        $endpoint = 'products/' . $slug . '/versions/check';
        return $this->request->get($endpoint, $args);
    }

    /**
     * Get all versions for a product.
     *
     * @param string $slug The product slug.
     * @param array  $args Additional arguments for the request.
     *
     * @return \PrettyLinks\GroundLevel\Mothership\Api\Response The response from the API.
     */
    public function getVersions(string $slug, array $args = []): Response
    {
        $endpoint = 'products/' . $slug . '/versions';
        return $this->request->get($endpoint, $args);
    }

    /**
     * Get relations for a product.
     *
     * @param string $slug The product slug.
     * @param array  $args Additional arguments for the request.
     *
     * @return \PrettyLinks\GroundLevel\Mothership\Api\Response The response from the API.
     */
    public function getRelations(string $slug, array $args = []): Response
    {
        $endpoint = 'products/' . $slug . '/relations';
        return $this->request->get($endpoint, $args);
    }

    /**
     * Deploy a version of a product.
     *
     * @param string $slug    The product slug.
     * @param string $version The product version.
     *
     * @return \PrettyLinks\GroundLevel\Mothership\Api\Response The response from the API.
     */
    public function deployVersion(string $slug, string $version): Response
    {
        $endpoint = 'products/' . $slug . '/versions/' . $version . '/deploy';
        return $this->request->post($endpoint);
    }
}
