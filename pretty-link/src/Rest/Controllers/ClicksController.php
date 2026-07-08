<?php

declare(strict_types=1);

namespace PrettyLinks\Rest\Controllers;

use PrettyLinks\Clicks\Cleaner;
use PrettyLinks\Clicks\ClearClicksJob;
use PrettyLinks\GroundLevel\Resque\ResqueServiceProvider;
use PrettyLinks\Repositories\Clicks as ClicksRepo;
use Throwable;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

class ClicksController extends BaseController
{
    /**
     * Registers the clicks REST routes.
     *
     * @return void
     */
    public function register(): void
    {
        register_rest_route($this->namespace(), '/clicks', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'index'],
                'permission_callback' => $this->permission(),
                'args'                => [
                    'link_id'   => ['type' => 'integer'],
                    'link_ids'  => ['type' => 'string'],
                    'from'      => ['type' => 'string'],
                    'to'        => ['type' => 'string'],
                    'ip'        => ['type' => 'string'],
                    'vuid'      => ['type' => 'string'],
                    'search'    => ['type' => 'string'],
                    'sort'      => [
                        'type' => 'string',
                        'enum' => [
                            'ip',
                            'vuid',
                            'btype',
                            'bversion',
                            'host',
                            'referer',
                            'uri',
                            'created_at',
                            'link',
                        ],
                    ],
                    'direction' => [
                        'type' => 'string',
                        'enum' => ['asc', 'desc'],
                    ],
                    'unique'    => ['type' => 'boolean'],
                    'per_page'  => [
                        'type'    => 'integer',
                        'default' => 30,
                    ],
                    'page'      => [
                        'type'    => 'integer',
                        'default' => 1,
                    ],
                ],
            ],
            [
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => [$this, 'destroy'],
                'permission_callback' => $this->permission(),
                'args'                => [
                    'older_than_days' => ['type' => 'integer'],
                ],
            ],
        ]);

        register_rest_route($this->namespace(), '/clicks/clear-status', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'clearStatus'],
                'permission_callback' => $this->permission(),
            ],
        ]);

        register_rest_route($this->namespace(), '/clicks/(?P<link_id>\d+)', [
            [
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => [$this, 'destroyForLink'],
                'permission_callback' => $this->permission(),
                'args'                => [
                    'link_id' => [
                        'type'     => 'integer',
                        'required' => true,
                    ],
                ],
            ],
        ]);
    }

    /**
     * Returns a paginated list of clicks.
     *
     * @param WP_REST_Request $request The incoming REST request.
     *
     * @return WP_REST_Response
     */
    public function index(WP_REST_Request $request): WP_REST_Response
    {
        if ($this->isCountMode()) {
            return new WP_REST_Response(
                ['message' => 'Click History is not available in Simple tracking mode.'],
                501
            );
        }

        $linkIdsRaw = (string) $request->get_param('link_ids');
        $linkIds    = [];
        if ($linkIdsRaw !== '') {
            foreach (explode(',', $linkIdsRaw) as $piece) {
                $n = (int) trim($piece);
                if ($n > 0) {
                    $linkIds[] = $n;
                }
            }
            $linkIds = array_values(array_unique($linkIds));
        }

        $repo     = new ClicksRepo();
        $result   = $repo->search([
            'link_id'   => $request->get_param('link_id') ? (int) $request->get_param('link_id') : null,
            'link_ids'  => $linkIds,
            'from'      => (string) $request->get_param('from'),
            'to'        => (string) $request->get_param('to'),
            'ip'        => (string) $request->get_param('ip'),
            'vuid'      => (string) $request->get_param('vuid'),
            'search'    => (string) $request->get_param('search'),
            'sort'      => (string) $request->get_param('sort'),
            'direction' => (string) $request->get_param('direction'),
            'unique'    => (bool) $request->get_param('unique'),
            'per_page'  => max(1, min(500, (int) ($request->get_param('per_page') ?: 50))),
            'page'      => max(1, (int) ($request->get_param('page') ?: 1)),
        ]);
        $response = new WP_REST_Response($result['items']);
        $response->header('X-WP-Total', (string) $result['total']);
        $response->header('X-WP-TotalPages', (string) $result['pages']);
        return $response;
    }

    /**
     * Deletes one or more clicks.
     *
     * @param WP_REST_Request $request The incoming REST request.
     *
     * @return WP_REST_Response
     */
    public function destroy(WP_REST_Request $request): WP_REST_Response
    {
        $cleaner = $this->container->get(Cleaner::class);
        $days    = $request->get_param('older_than_days');
        if ($days !== null && $days !== '') {
            $result = $cleaner->clearOlderThan((int) $days);
        } else {
            $result = $cleaner->truncateAll();
        }
        return new WP_REST_Response($result);
    }

    /**
     * Deletes all clicks for a given link.
     *
     * @param WP_REST_Request $request The incoming REST request.
     *
     * @return WP_REST_Response
     */
    public function destroyForLink(WP_REST_Request $request): WP_REST_Response
    {
        $cleaner = $this->container->get(Cleaner::class);
        $result  = $cleaner->resetForLink((int) $request->get_param('link_id'));
        return new WP_REST_Response($result);
    }

    /**
     * Reports whether a background click-clear job is still queued or running.
     *
     * @return WP_REST_Response
     */
    public function clearStatus(): WP_REST_Response
    {
        return new WP_REST_Response(['clearing' => $this->hasPendingClearJob()]);
    }

    /**
     * Checks the Resque jobs table for a pending or working clear job.
     *
     * @return boolean
     */
    private function hasPendingClearJob(): bool
    {
        global $wpdb;

        try {
            $table = $this->container->get(ResqueServiceProvider::SERVICE_DATABASE)->jobsTableName();
        } catch (Throwable $e) {
            return false;
        }

        $pending = (int) $wpdb->get_var(
            $wpdb->prepare(
                // Table name is a trusted, code-derived identifier (not user input).
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT COUNT(*) FROM {$table} WHERE class = %s AND status IN ('pending', 'working')",
                ClearClicksJob::class
            )
        );

        return $pending > 0;
    }
}
