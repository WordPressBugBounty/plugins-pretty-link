<?php

declare(strict_types=1);

namespace PrettyLinks\GroundLevel\Mothership\Api\Request;

use PrettyLinks\GroundLevel\Mothership\Api\Request;
use PrettyLinks\GroundLevel\Mothership\Api\Response;

/**
 * This class is used to interact with the user addons API.
 *
 * @link https://licenses.caseproof.com/help/api-reference#users
 */
class UserAddons
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
     * Create a new user addon.
     *
     * @param  string $userUUID  The user UUID.
     * @param  array  $addonData The data to create the user addon.
     * @return \PrettyLinks\GroundLevel\Mothership\Api\Response
     */
    public function create(string $userUUID, array $addonData): Response
    {
        return $this->request->post('users/' . $userUUID . '/addons', $addonData);
    }

    /**
     * Get all user addons.
     *
     * @param  string $userUUID The user UUID.
     * @param  array  $params   The parameters to get the user addons for.
     * @return \PrettyLinks\GroundLevel\Mothership\Api\Response
     */
    public function list(string $userUUID, array $params = []): Response
    {
        return $this->request->get('users/' . $userUUID . '/addons', $params);
    }

    /**
     * Get a user addon by user UUID and addon UUID.
     *
     * @param  string $userUUID  The user UUID.
     * @param  string $addonUUID The addon UUID.
     * @param  array  $params    The parameters to get the user addon for.
     * @return \PrettyLinks\GroundLevel\Mothership\Api\Response
     */
    public function get(string $userUUID, string $addonUUID, array $params = []): Response
    {
        return $this->request->get('users/' . $userUUID . '/addons/' . $addonUUID, $params);
    }

    /**
     * Update a user addon.
     *
     * @param  string $userUUID  The user UUID.
     * @param  string $addonUUID The addon UUID.
     * @param  array  $addonData The data to update the user addon.
     * @return \PrettyLinks\GroundLevel\Mothership\Api\Response
     */
    public function update(string $userUUID, string $addonUUID, array $addonData): Response
    {
        return $this->request->patch('users/' . $userUUID . '/addons/' . $addonUUID, $addonData);
    }
}
