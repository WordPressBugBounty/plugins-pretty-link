<?php

declare(strict_types=1);

namespace PrettyLinks\Rest\Controllers;

use PrettyLinks\Admin\ReviewNotice;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

class ReviewNoticeController extends BaseController
{
    /**
     * Registers the review-notice REST routes.
     *
     * @return void
     */
    public function register(): void
    {
        register_rest_route($this->namespace(), '/review-ask/dismiss', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'dismiss'],
            'permission_callback' => $this->permission(),
            'args'                => [
                'type' => [
                    'required'          => true,
                    'type'              => 'string',
                    'enum'              => ['delay', 'remove'],
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);
    }

    /**
     * Dismisses the review-ask notice.
     *
     * @param WP_REST_Request $request The incoming REST request.
     *
     * @return WP_REST_Response
     */
    public function dismiss(WP_REST_Request $request): WP_REST_Response
    {
        ReviewNotice::dismiss((string) $request['type']);
        return new WP_REST_Response(['success' => true]);
    }
}
