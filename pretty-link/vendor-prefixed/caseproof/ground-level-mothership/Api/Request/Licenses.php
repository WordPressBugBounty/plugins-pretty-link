<?php

declare(strict_types=1);

namespace PrettyLinks\GroundLevel\Mothership\Api\Request;

use PrettyLinks\GroundLevel\Mothership\Api\Request;
use PrettyLinks\GroundLevel\Mothership\Api\Response;

/**
 * This class is used to interact with the licenses API.
 *
 * @link https://licenses.caseproof.com/help/api-reference#licenses
 */
class Licenses
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
     * Create a new license.
     *
     * @param  array $licenseData The data to create the license.
     * @return \PrettyLinks\GroundLevel\Mothership\Api\Response
     */
    public function create(array $licenseData): Response
    {
        return $this->request->post('licenses', $licenseData);
    }

    /**
     * Get all licenses.
     *
     * @param  array $params The parameters to pass to the API.
     * @return \PrettyLinks\GroundLevel\Mothership\Api\Response
     */
    public function list(array $params = []): Response
    {
        return $this->request->get('licenses', $params);
    }

    /**
     * Get a license by license key.
     *
     * @param string $licenseKey The license key.
     * @param array  $params     Additional parameters for the request.
     *
     * @return \PrettyLinks\GroundLevel\Mothership\Api\Response
     */
    public function get(string $licenseKey, array $params = []): Response
    {
        return $this->request->get('licenses/' . $licenseKey, $params);
    }

    /**
     * Update a license by license key.
     *
     * @param  string $licenseKey  The license key.
     * @param  array  $licenseData The data to update the license with.
     * @return \PrettyLinks\GroundLevel\Mothership\Api\Response
     */
    public function update(string $licenseKey, array $licenseData): Response
    {
        return $this->request->patch('licenses/' . $licenseKey, $licenseData);
    }
}
