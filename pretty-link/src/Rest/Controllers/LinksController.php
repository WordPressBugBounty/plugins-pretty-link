<?php

declare(strict_types=1);

namespace PrettyLinks\Rest\Controllers;

use PrettyLinks\Repositories\LinkMetas;
use PrettyLinks\Repositories\Links as LinksRepo;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

class LinksController extends BaseController
{
    /**
     * PrettyPay meta keys stored in `prli_link_metas`. Names match v3
     * exactly so an in-place upgrade finds existing rows as-is.
     *
     * @var list<string>
     */
    private const STRIPE_META_KEYS = [
        'stripe_line_items',
        'stripe_automatic_tax',
        'stripe_billing_address_collection',
        'stripe_shipping_address_collection',
        'stripe_shipping_address_allowed_countries',
        'stripe_phone_number_collection',
        'stripe_allow_promotion_codes',
        'stripe_tax_id_collection',
        'stripe_save_payment_details',
        'stripe_include_free_trial',
        'stripe_trial_period_days',
        'stripe_custom_text',
        'stripe_thank_you_page_id',
    ];

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
     * Creates a new link.
     *
     * @param WP_REST_Request $request The incoming REST request.
     *
     * @return WP_REST_Response
     */
    public function create(WP_REST_Request $request): WP_REST_Response
    {
        $data       = (array) $request->get_json_params();
        $original   = $data;
        $stripeMeta = $this->extractStripeMeta($data);

        /**
         * Filter: prli_link_payload_pre_save
         *
         * Plugins remove fields they own from `$data` so the Links repo
         * never sees them (the repo only knows `prli_links` columns).
         * Return the trimmed array.
         *
         * @param array<string, mixed> $data    Payload after Lite strips Stripe meta.
         * @param string               $context 'create' | 'update'
         * @param int                  $linkId  0 on create, link id on update.
         */
        $data = (array) apply_filters('prli_link_payload_pre_save', $data, 'create', 0);

        $validation = $this->runValidationFilter($data, 'create', 0);
        if ($validation !== null) {
            return $validation;
        }

        $link = $this->repo()->create($data);
        if (is_array($link) && isset($link['error'])) {
            return new WP_REST_Response($link, 400);
        }

        if (isset($link['id'])) {
            $linkId = (int) $link['id'];
            $this->persistStripeMeta($linkId, $stripeMeta);
            /**
             * Action: prli_link_after_save
             *
             * Fires after the link row is written. Plugins read their own
             * fields from `$original` (the unmodified request payload)
             * and persist them (categories, tags, keywords, splitTest,
             * etc.).
             *
             * @param int                  $linkId
             * @param array<string, mixed> $original Full unmodified request payload.
             * @param string               $context  'create' | 'update'
             */
            do_action('prli_link_after_save', $linkId, $original, 'create');
            $link = $this->attachAllMeta($linkId, $link);
        }
        return new WP_REST_Response($link, 201);
    }

    /**
     * Updates an existing link.
     *
     * @param WP_REST_Request $request The incoming REST request.
     *
     * @return WP_REST_Response
     */
    public function update(WP_REST_Request $request): WP_REST_Response
    {
        $id         = (int) $request['id'];
        $data       = (array) $request->get_json_params();
        $original   = $data;
        $stripeMeta = $this->extractStripeMeta($data);

        $data = (array) apply_filters('prli_link_payload_pre_save', $data, 'update', $id);

        $validation = $this->runValidationFilter($data, 'update', $id);
        if ($validation !== null) {
            return $validation;
        }

        $link = $this->repo()->update($id, $data);
        if ($link === null) {
            return new WP_REST_Response(['error' => 'not_found'], 404);
        }
        if (is_array($link) && isset($link['error'])) {
            return new WP_REST_Response($link, 400);
        }
        $this->persistStripeMeta($id, $stripeMeta);
        do_action('prli_link_after_save', $id, $original, 'update');
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
        return $this->container->has(LinksRepo::class)
            ? $this->container->get(LinksRepo::class)
            : new LinksRepo();
    }

    /**
     * Resolves the link metas service from the container.
     *
     * @return LinkMetas
     */
    private function metas(): LinkMetas
    {
        return $this->container->has(LinkMetas::class)
            ? $this->container->get(LinkMetas::class)
            : new LinkMetas();
    }

    /**
     * Pulls the Stripe-specific fields out of an incoming payload so the
     * Links repo never sees them (it only knows about `prli_links` columns).
     *
     * @param  array<string, mixed> $data The incoming link payload, by reference.
     * @return array<string, string>|null  null when the payload never mentioned Stripe (preserve existing meta)
     */
    private function extractStripeMeta(array &$data): ?array
    {
        $hasAny = false;
        $out    = [];
        foreach (self::STRIPE_META_KEYS as $key) {
            if (!array_key_exists($key, $data)) {
                continue;
            }
            $hasAny = true;
            $value  = $data[$key];
            unset($data[$key]);
            if ($key === 'stripe_shipping_address_allowed_countries' && is_array($value)) {
                $value = implode(',', array_map('strval', $value));
            }
            if (is_array($value)) {
                // Recursively scrub every scalar in the structure (e.g. the
                // line-items blob) before persisting, mirroring v3's
                // array_walk_recursive + sanitize_text_field pass. Admin-only
                // input that's always escaped on output — defense in depth.
                array_walk_recursive($value, static function (&$item): void {
                    if (is_string($item)) {
                        $item = sanitize_text_field($item);
                    }
                });
                $value = (string) wp_json_encode($value);
            }
            if (is_bool($value)) {
                $value = $value ? '1' : '0';
            }
            $out[$key] = (string) $value;
        }
        return $hasAny ? $out : null;
    }

    /**
     * Persists the extracted Stripe meta for a link.
     *
     * @param integer                    $linkId The link ID.
     * @param array<string, string>|null $meta   The extracted Stripe meta, or null to leave unchanged.
     *
     * @return void
     */
    private function persistStripeMeta(int $linkId, ?array $meta): void
    {
        if ($meta === null) {
            return;
        }
        $metas = $this->metas();
        foreach (self::STRIPE_META_KEYS as $key) {
            if (!array_key_exists($key, $meta)) {
                continue;
            }
            $value = $meta[$key];
            if ($value === '' || $value === '0') {
                $metas->delete($linkId, $key);
            } else {
                $metas->set($linkId, $key, $value);
            }
        }

        // Mirror v3: flag `prli_has_recurring_prettypay_link` whenever a
        // subscription-type price is saved so Settings → Payments can prompt
        // the merchant to configure the Stripe Customer Portal.
        if (isset($meta['stripe_line_items'])) {
            $decoded = json_decode($meta['stripe_line_items'], true);
            if (is_array($decoded)) {
                foreach ($decoded as $item) {
                    if (
                        is_array($item)
                        && isset($item['price']['recurring'])
                        && is_array($item['price']['recurring'])
                    ) {
                        update_option('prli_has_recurring_prettypay_link', true);
                        break;
                    }
                }
            }
        }
    }

    /**
     * Attaches stored Stripe meta values to a link payload.
     *
     * @param  integer              $linkId The link ID.
     * @param  array<string, mixed> $link   The link payload.
     * @return array<string, mixed>
     */
    private function attachStripeMeta(int $linkId, array $link): array
    {
        $all = $this->metas()->all($linkId);
        foreach (self::STRIPE_META_KEYS as $key) {
            $raw = $all[$key] ?? null;
            if ($raw === null) {
                $link[$key] = $this->defaultMetaValue($key);
                continue;
            }
            $link[$key] = $this->decodeMeta($key, $raw);
        }
        return $link;
    }

    /**
     * Returns the default value for a Stripe meta key.
     *
     * @param  string $key The Stripe meta key.
     * @return mixed
     */
    private function defaultMetaValue(string $key)
    {
        switch ($key) {
            case 'stripe_line_items':
                return [];
            case 'stripe_shipping_address_allowed_countries':
                return [];
            case 'stripe_trial_period_days':
            case 'stripe_thank_you_page_id':
                return 0;
            case 'stripe_custom_text':
                return '';
            default:
                return false;
        }
    }

    /**
     * Decodes a stored Stripe meta value into its typed form.
     *
     * @param  string $key The Stripe meta key.
     * @param  string $raw The raw stored meta value.
     * @return mixed
     */
    private function decodeMeta(string $key, string $raw)
    {
        if ($key === 'stripe_line_items') {
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : [];
        }
        if ($key === 'stripe_shipping_address_allowed_countries') {
            return $raw === '' ? [] : array_values(array_filter(array_map('trim', explode(',', $raw))));
        }
        if ($key === 'stripe_custom_text') {
            return $raw;
        }
        if ($key === 'stripe_trial_period_days' || $key === 'stripe_thank_you_page_id') {
            return (int) $raw;
        }
        // Boolean-ish toggles.
        return $raw === '1';
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
        $link = $this->attachStripeMeta($linkId, $link);
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

    /**
     * Run the `prli_validate_link` extension filter. Extensions push error
     * strings into the array; a non-empty result aborts the save.
     *
     * Signature (back-compatible with v3's one-arg `$errors`):
     *   apply_filters('prli_validate_link', array $errors, array $data, string $context, int $linkId)
     *
     * Legacy listeners declaring a single `$errors` parameter still fire —
     * PHP ignores the extra args. New listeners can inspect the payload
     * and the save context.
     *
     * @param  array<string, mixed> $data    Payload post-pre_save filter.
     * @param  string               $context Save context, 'create' or 'update'.
     * @param  integer              $linkId  Zero on create, link id on update.
     * @return WP_REST_Response|null 400 response when validation errors
     *                               exist, null when clean to proceed.
     */
    private function runValidationFilter(array $data, string $context, int $linkId): ?WP_REST_Response
    {
        /**
         * Filter: prli_validate_link
         *
         * Third-party validation. Push error-message strings into the
         * array; a non-empty return aborts the save with a 400.
         *
         * @param array<int, string>   $errors  Start empty, add messages.
         * @param array<string, mixed> $data    Payload about to be saved.
         * @param string               $context 'create' | 'update'.
         * @param int                  $linkId  0 on create, link id on update.
         */
        $errors = (array) apply_filters('prli_validate_link', [], $data, $context, $linkId);
        if ($errors === []) {
            return null;
        }
        $messages = array_values(array_filter(array_map(
            static fn ($e) => is_string($e) ? trim($e) : '',
            $errors
        )));
        if ($messages === []) {
            return null;
        }
        return new WP_REST_Response([
            'error'    => 'validation_failed',
            'messages' => $messages,
        ], 400);
    }
}
