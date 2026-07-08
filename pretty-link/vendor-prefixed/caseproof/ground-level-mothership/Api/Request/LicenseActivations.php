<?php

declare(strict_types=1);

namespace PrettyLinks\GroundLevel\Mothership\Api\Request;

use PrettyLinks\GroundLevel\Mothership\Api\Request;
use PrettyLinks\GroundLevel\Mothership\Api\Response;

/**
 * This class is used to interact with the license activations API.
 *
 * @link https://licenses.caseproof.com/help/api-reference#license-activations
 */
class LicenseActivations
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
     * Activates the license.
     *
     * @param  string $product    The Product to Activate.
     * @param  string $licenseKey The license key to activate.
     * @param  string $domain     The domain to activate the license on.
     * @return \PrettyLinks\GroundLevel\Mothership\Api\Response The response from the API.
     */
    public function activate(string $product, string $licenseKey, string $domain): Response
    {
        $data     = compact('domain', 'product');
        $endpoint = 'licenses/' . $licenseKey . '/activate';
        return $this->request->post($endpoint, $data);
    }

    /**
     * Deactivate the license.
     *
     * @param  string $licenseKey The license key to deactivate.
     * @param  string $domain     The domain to deactivate the license on.
     * @return \PrettyLinks\GroundLevel\Mothership\Api\Response The response from the API.
     */
    public function deactivate(string $licenseKey, string $domain): Response
    {
        $endpoint = 'licenses/' . $licenseKey . '/activations/' . rawurlencode($domain) . '/deactivate';
        return $this->request->patch($endpoint, compact('domain'));
    }

    /**
     * Retrieve a license activation.
     *
     * @param string $licenseKey The license key to retrieve the activation for.
     * @param string $domain     The domain to retrieve the activation for.
     * @param array  $args       Additional arguments for the request.
     *
     * @return \PrettyLinks\GroundLevel\Mothership\Api\Response The response from the API.
     */
    public function retrieveLicenseActivation(string $licenseKey, string $domain, array $args = []): Response
    {
        $endpoint = 'licenses/' . $licenseKey . '/activations/' . rawurlencode($domain);
        return $this->request->get($endpoint, $args);
    }

    /**
     * Retrieve metadata about a license's activations.
     *
     * @param string $licenseKey The license key to retrieve the metadata for.
     * @param array  $args       Additional arguments for the request.
     *
     * @return \PrettyLinks\GroundLevel\Mothership\Api\Response The response from the API.
     */
    public function retrieveLicenseActivationsMeta(string $licenseKey, array $args = []): Response
    {
        $endpoint = 'licenses/' . $licenseKey . '/activations/meta';
        return $this->request->get($endpoint, $args);
    }

    /**
     * List all activations for a license.
     *
     * @param string $licenseKey The license key to list activations for.
     * @param array  $args       Additional arguments for the request.
     *
     * @return \PrettyLinks\GroundLevel\Mothership\Api\Response The response from the API.
     */
    public function list(string $licenseKey, array $args = []): Response
    {
        $endpoint = 'licenses/' . $licenseKey . '/activations';
        return $this->request->get($endpoint, $args);
    }
}
