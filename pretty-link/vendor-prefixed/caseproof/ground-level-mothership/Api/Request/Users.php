<?php

declare(strict_types=1);

namespace PrettyLinks\GroundLevel\Mothership\Api\Request;

use PrettyLinks\GroundLevel\Mothership\Api\Request;
use PrettyLinks\GroundLevel\Mothership\Api\Response;

/**
 * This class is used to interact with the users API.
 *
 * @link https://licenses.caseproof.com/help/api-reference#users
 */
class Users
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
     * Create a new user.
     *
     * @param  array $userData The data to create the user.
     * @return \PrettyLinks\GroundLevel\Mothership\Api\Response
     */
    public function create(array $userData): Response
    {
        return $this->request->post('users', $userData);
    }

    /**
     * Get all users.
     *
     * @param  array $params The parameters to get the users for.
     * @return \PrettyLinks\GroundLevel\Mothership\Api\Response
     */
    public function list(array $params = []): Response
    {
        return $this->request->get('users', $params);
    }

    /**
     * Get a user by user UUID.
     *
     * @param  string $userUUID The user UUID.
     * @param  array  $args     Additional arguments for the request.
     * @return \PrettyLinks\GroundLevel\Mothership\Api\Response
     */
    public function get(string $userUUID, array $args = []): Response
    {
        return $this->request->get('users/' . $userUUID, $args);
    }

    /**
     * Update a user.
     *
     * @param  string $userUUID The user UUID.
     * @param  array  $userData The data to update the user.
     * @return \PrettyLinks\GroundLevel\Mothership\Api\Response
     */
    public function update(string $userUUID, array $userData): Response
    {
        return $this->request->patch('users/' . $userUUID, $userData);
    }

    /**
     * Get a user by email.
     *
     * @param string $email The email of the user.
     * @param array  $args  Additional arguments for the request.
     *
     * @return \PrettyLinks\GroundLevel\Mothership\Api\Response
     */
    public function getByEmail(string $email, array $args = []): Response
    {
        $users = $this->request->get('users', array_merge($args, ['search' => $email]));
        if ($users->isSuccess()) {
            $userList = $users->getData('users', []);
            if (count($userList) > 0) {
                $users = new Response($userList[0]);
            } else {
                $users = new Response((object) ['message' => 'No users found matching email: ' . $email], 404);
            }
        }
        return $users;
    }

    /**
     * Update a user by email.
     *
     * @param  string $email    The email of the user.
     * @param  array  $userData The data to update the user.
     * @return \PrettyLinks\GroundLevel\Mothership\Api\Response
     */
    public function updateByEmail(string $email, array $userData): Response
    {
        $user = $this->getByEmail($email);
        $uuid = $user->getData('uuid');
        if ($user->isError() || null === $uuid) {
            return $user;
        }
        return $this->update($uuid, $userData);
    }
}
