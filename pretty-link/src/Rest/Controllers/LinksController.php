<?php

declare(strict_types=1);

namespace PrettyLinks\Rest\Controllers;

use PrettyLinks\Repositories\Links as LinksRepo;
use PrettyLinks\Stripe\LinkMeta as StripeLinkMeta;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

class LinksController extends BaseController
{
    /**
     * Registers the links REST routes.
     *
     * @return void
     */
    public function register(): void
    {
        register_rest_route($this->namespace(), '/links', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'index'],
                'permission_callback' => $this->permission(),
                'args'                => [
                    'search'        => ['type' => 'string'],
                    'status'        => [
                        'type' => 'string',
                        'enum' => ['any', 'trashed'],
                    ],
                    'prettypay'     => ['type' => 'integer'],
                    'source'        => ['type' => 'string'],
                    'category'      => ['type' => 'integer'],
                    'tag'           => ['type' => 'integer'],
                    // Exact-match filter from the Links-list redirect-type
                    // dropdown. Accepts any stored redirect_type value
                    // (301, 302, 307, cloak, pixel, prettybar, …). Absent
                    // / 'all' means no filter.
                    'redirect_type' => ['type' => 'string'],
                    'orderby'       => [
                        'type'    => 'string',
                        'default' => 'created_at',
                    ],
                    'order'         => [
                        'type'    => 'string',
                        'enum'    => ['asc', 'desc'],
                        'default' => 'desc',
                    ],
                    'per_page'      => [
                        'type'    => 'integer',
                        'default' => 20,
                    ],
                    'page'          => [
                        'type'    => 'integer',
                        'default' => 1,
                    ],
                    // Restrict the result set to a specific list of link
                    // ids. Used for hydrating saved chip selections (e.g.
                    // Custom Reports source-link picker) without paging
                    // through the whole table. Trashed rows are returned
                    // too so deleted picks still resolve to a name.
                    'include'       => [
                        'type'    => 'array',
                        'items'   => ['type' => 'integer'],
                        'default' => [],
                    ],
                ],
            ],
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'create'],
                'permission_callback' => $this->permission(),
            ],
        ]);

        register_rest_route($this->namespace(), '/links/(?P<id>\d+)', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'show'],
                'permission_callback' => $this->permission(),
            ],
            [
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => [$this, 'update'],
                'permission_callback' => $this->permission(),
            ],
            [
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => [$this, 'delete'],
                'permission_callback' => $this->permission(),
                'args'                => [
                    'force' => [
                        'type'    => 'boolean',
                        'default' => false,
                    ],
                ],
            ],
        ]);

        register_rest_route($this->namespace(), '/links/(?P<id>\d+)/restore', [
            [
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => [$this, 'restore'],
                'permission_callback' => $this->permission(),
            ],
        ]);

        register_rest_route($this->namespace(), '/links/(?P<id>\d+)/duplicate', [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'duplicate'],
                'permission_callback' => $this->permission(),
            ],
        ]);

        register_rest_route($this->namespace(), '/links/bulk', [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'bulk'],
                'permission_callback' => $this->permission(),
            ],
        ]);

        register_rest_route($this->namespace(), '/links/generate-slug', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'generateSlug'],
                'permission_callback' => $this->permission(),
            ],
        ]);
    }

    /**
     * Returns a paginated list of links.
     *
     * @param WP_REST_Request $request The incoming REST request.
     *
     * @return WP_REST_Response
     */
    public function index(WP_REST_Request $request): WP_REST_Response
    {
        $repo      = $this->repo();
        $prettypay = $request->get_param('prettypay');
        $args      = [
            'search'        => (string) $request->get_param('search'),
            'status'        => (string) ($request->get_param('status') ?: 'any'),
            'prettypay'     => ($prettypay === null || $prettypay === '') ? null : (int) $prettypay,
            'source'        => (string) ($request->get_param('source') ?: ''),
            'category'      => $request->get_param('category') ? (int) $request->get_param('category') : null,
            'tag'           => $request->get_param('tag') ? (int) $request->get_param('tag') : null,
            'redirect_type' => (string) ($request->get_param('redirect_type') ?: ''),
            'orderby'       => (string) ($request->get_param('orderby') ?: 'created_at'),
            'order'         => (string) ($request->get_param('order') ?: 'desc'),
            'per_page'      => max(1, min(200, (int) ($request->get_param('per_page') ?: 20))),
            'page'          => max(1, (int) ($request->get_param('page') ?: 1)),
            'include'       => array_values(array_filter(array_map(
                'intval',
                (array) ($request->get_param('include') ?: [])
            ))),
        ];
        /**
         * Filter: prli_links_index_args
         *
         * Allows extensions (e.g. Pro) to inject extra search args derived
         * from the REST request (e.g. a `health` filter) without this
         * controller knowing about Pro-specific params.
         *
         * @param array<string, mixed> $args    Search args built above.
         * @param WP_REST_Request      $request The current REST request.
         */
        $args   = (array) apply_filters('prli_links_index_args', $args, $request);
        $result = $repo->search($args);
        $items  = $result['items'];

        /**
         * Filter: prli_link_response_attach_bulk
         *
         * Plugins append their own per-row fields to every item in the
         * list response (categories, tags, keywords, splitTest, etc.).
         * Receives the full list so plugins can batch their reads into a
         * single query.
         *
         * @param array<int, array<string, mixed>> $items
         */
        $items = (array) apply_filters('prli_link_response_attach_bulk', $items);

        $response = new WP_REST_Response($items);
        $response->header('X-WP-Total', (string) $result['total']);
        $response->header('X-WP-TotalPages', (string) $result['pages']);
        return $response;
    }

    /**
     * Returns a single link by ID.
     *
     * @param WP_REST_Request $request The incoming REST request.
     *
     * @return WP_REST_Response
     */
    public function show(WP_REST_Request $request): WP_REST_Response
    {
        $id   = (int) $request['id'];
        $link = $this->repo()->find($id);
        if ($link === null) {
            return new WP_REST_Response(['error' => 'not_found'], 404);
        }
        return new WP_REST_Response($this->attachAllMeta($id, $link));
    }

    /**
     * Creates a new link through the repository save seam (`Links::save()`).
     *
     * @param WP_REST_Request $request The incoming REST request.
     *
     * @return WP_REST_Response
     */
    public function create(WP_REST_Request $request): WP_REST_Response
    {
        $link = $this->repo()->save((array) $request->get_json_params());
        if (isset($link['error'])) {
            return new WP_REST_Response($link, 400);
        }
        if (isset($link['id'])) {
            $link = $this->attachAllMeta((int) $link['id'], $link);
        }
        return new WP_REST_Response($link, 201);
    }

    /**
     * Updates an existing link through the repository save seam (`Links::save()`).
     *
     * @param WP_REST_Request $request The incoming REST request.
     *
     * @return WP_REST_Response
     */
    public function update(WP_REST_Request $request): WP_REST_Response
    {
        $id = (int) $request['id'];
        // save() treats id 0 as a create; an update route must never create.
        if ($id <= 0) {
            return new WP_REST_Response(['error' => 'not_found'], 404);
        }
        $link = $this->repo()->save((array) $request->get_json_params(), $id);
        if ($link === null) {
            return new WP_REST_Response(['error' => 'not_found'], 404);
        }
        if (isset($link['error'])) {
            return new WP_REST_Response($link, 400);
        }
        return new WP_REST_Response($this->attachAllMeta($id, $link));
    }

    /**
     * Deletes (trashes or hard-deletes) a link.
     *
     * @param WP_REST_Request $request The incoming REST request.
     *
     * @return WP_REST_Response
     */
    public function delete(WP_REST_Request $request): WP_REST_Response
    {
        $id    = (int) $request['id'];
        $force = (bool) $request->get_param('force');
        $ok    = $force ? $this->repo()->hardDelete($id) : $this->repo()->softDelete($id);
        if (!$ok) {
            $reason = LinksRepo::lastTrashBlockReason();
            if ($reason !== '') {
                return new WP_REST_Response(
                    [
                        'success' => false,
                        'error'   => 'blocked',
                        'message' => $reason,
                    ],
                    409
                );
            }
        }
        return new WP_REST_Response(['success' => $ok]);
    }

    /**
     * Restores a trashed link.
     *
     * @param WP_REST_Request $request The incoming REST request.
     *
     * @return WP_REST_Response
     */
    public function restore(WP_REST_Request $request): WP_REST_Response
    {
        $id = (int) $request['id'];
        $ok = $this->repo()->restore($id);
        return new WP_REST_Response(['success' => $ok]);
    }

    /**
     * Duplicates an existing link.
     *
     * @param WP_REST_Request $request The incoming REST request.
     *
     * @return WP_REST_Response
     */
    public function duplicate(WP_REST_Request $request): WP_REST_Response
    {
        $id   = (int) $request['id'];
        $link = $this->repo()->duplicate($id);
        if ($link === null) {
            return new WP_REST_Response(['error' => 'not_found'], 404);
        }
        if (isset($link['id'])) {
            $link = $this->attachAllMeta((int) $link['id'], $link);
        }
        return new WP_REST_Response($link, 201);
    }

    /**
     * Performs a bulk action on multiple links.
     *
     * @param WP_REST_Request $request The incoming REST request.
     *
     * @return WP_REST_Response
     */
    public function bulk(WP_REST_Request $request): WP_REST_Response
    {
        $body    = (array) $request->get_json_params();
        $action  = (string) ($body['action'] ?? '');
        $ids     = array_map('intval', (array) ($body['ids'] ?? []));
        $termIds = array_map('intval', (array) ($body['term_ids'] ?? []));

        // Lite-known bulk actions (trash/restore/delete, flag toggles,
        // taxonomy add/remove). Repo ignores unknown actions by returning
        // `['affected' => 0]`.
        $result = $this->repo()->bulk($action, $ids, $termIds);

        /**
         * Filter: prli_link_bulk_action
         *
         * Allows plugins to handle bulk actions Lite doesn't recognize
         * (e.g. Pro's `bulk_add_categories`, `bulk_add_tags`). Receives
         * the Lite repo's result; plugins inspect `$action` and overwrite
         * when they handle it.
         *
         * @param array<string, mixed> $result  Default from Lite's repo.
         * @param string               $action
         * @param int[]                $ids
         * @param int[]                $termIds
         */
        $result = (array) apply_filters('prli_link_bulk_action', $result, $action, $ids, $termIds);

        return new WP_REST_Response($result);
    }

    /**
     * Generates a unique random slug.
     *
     * @param WP_REST_Request $request The incoming REST request.
     *
     * @return WP_REST_Response
     */
    public function generateSlug(WP_REST_Request $request): WP_REST_Response
    {
        $slug = $this->repo()->generateSlug();
        return new WP_REST_Response(['slug' => $slug]);
    }

    /**
     * Resolves the links repository from the container.
     *
     * @return LinksRepo
     */
    private function repo(): LinksRepo
    {
        return $this->container->get(LinksRepo::class);
    }

    /**
     * Resolves the PrettyPay link meta service from the container.
     *
     * @return StripeLinkMeta
     */
    private function stripeMeta(): StripeLinkMeta
    {
        return $this->container->get(StripeLinkMeta::class);
    }

    /**
     * Attaches all meta (Stripe plus plugin-supplied) to a link payload.
     *
     * @param  integer              $linkId The link ID.
     * @param  array<string, mixed> $link   The link payload.
     * @return array<string, mixed>
     */
    private function attachAllMeta(int $linkId, array $link): array
    {
        $link = $this->stripeMeta()->attach($linkId, $link);
        /**
         * Filter: prli_link_response_attach
         *
         * Plugins append their own fields to the link response payload
         * (taxonomies, keywords, split-test config, template choices, etc.).
         *
         * @param array<string, mixed> $link
         * @param int                  $linkId
         */
        $link = (array) apply_filters('prli_link_response_attach', $link, $linkId);
        return $link;
    }
}
