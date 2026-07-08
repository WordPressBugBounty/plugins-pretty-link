<?php

declare(strict_types=1);

namespace PrettyLinks\Rest\Controllers;

use PrettyLinks\Admin\Notices;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

class NoticesController extends BaseController
{
    /**
     * Registers the notices REST routes.
     *
     * @return void
     */
    public function register(): void
    {
        register_rest_route($this->namespace(), '/notices/(?P<id>[a-z0-9_\-.]+)/dismiss', [
            [
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => [$this, 'dismiss'],
                'permission_callback' => $this->permission(),
            ],
        ]);
    }

    /**
     * Dismisses an admin notice by ID.
     *
     * @param WP_REST_Request $request The incoming REST request.
     *
     * @return WP_REST_Response
     */
    public function dismiss(WP_REST_Request $request): WP_REST_Response
    {
        $id = (string) $request['id'];
        Notices::dismiss($id);
        return new WP_REST_Response(['success' => true]);
    }
}
