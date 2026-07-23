<?php

declare(strict_types=1);

namespace PrettyLinks\Rest\Controllers;

use PrettyLinks\Integrations\CookieYes;
use PrettyLinks\Options\Store as OptionsStore;
use PrettyLinks\Tools\CsvExporter;
use PrettyLinks\Tools\CsvImporter;
use PrettyLinks\Tools\CsvSampleGenerator;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

class ToolsController extends BaseController
{
    /**
     * Registers the tools REST routes.
     *
     * @return void
     */
    public function register(): void
    {
        register_rest_route($this->namespace(), '/tools/export', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'export'],
                'permission_callback' => $this->permission(),
            ],
        ]);

        register_rest_route($this->namespace(), '/tools/import/sample', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'importSample'],
                'permission_callback' => $this->permission(),
            ],
        ]);

        register_rest_route($this->namespace(), '/tools/import', [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'import'],
                'permission_callback' => $this->permission(),
            ],
        ]);

        register_rest_route($this->namespace(), '/tools/cookieyes', [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'cookieYesToggle'],
                'permission_callback' => $this->permission(),
            ],
        ]);
    }

    /**
     * Toggles the CookieYes integration on or off.
     *
     * @param WP_REST_Request $request The incoming REST request.
     *
     * @return WP_REST_Response
     */
    public function cookieYesToggle(WP_REST_Request $request): WP_REST_Response
    {
        $body    = (array) $request->get_json_params();
        $enabled = !empty($body['enabled']);

        $integration = new CookieYes(new OptionsStore());
        $integration->setEnabled($enabled);

        return new WP_REST_Response($integration->status());
    }

    /**
     * Returns a sample CSV for import.
     *
     * @return WP_REST_Response
     */
    public function importSample(): WP_REST_Response
    {
        $csv = (new CsvSampleGenerator())->generate();
        return new WP_REST_Response(['csv' => $csv]);
    }

    /**
     * Exports links as CSV (count, chunked, or single-shot).
     *
     * Accepts the same filter args as GET /links (search, status,
     * prettypay, redirect_type, category, tag, plus Pro filters via
     * `prli_links_index_args`) so the links-list Export CSV button can
     * dump the current view. Omit filters for the legacy Tools
     * "export everything" behaviour.
     *
     * @param WP_REST_Request $request The incoming REST request.
     *
     * @return WP_REST_Response
     */
    public function export(WP_REST_Request $request): WP_REST_Response
    {
        $exporter = new CsvExporter();
        $args     = $this->exportArgs($request);

        // Preflight: cheap row-count for the client's "large export"
        // warning. Returned before any rows are read so the warning
        // dialog can show without burning the first chunk's work.
        if ($request->get_param('count_only')) {
            return new WP_REST_Response(['total' => $exporter->total($args)]);
        }

        // Chunked path: client orchestrates the loop with offset/limit and
        // accumulates the chunks into a Blob in browser memory. Avoids
        // blocking a PHP-FPM worker for the whole export.
        $offsetParam = $request->get_param('offset');
        if ($offsetParam !== null && $offsetParam !== '') {
            $offset = (int) $offsetParam;
            $limit  = (int) ($request->get_param('limit') ?: CsvExporter::DEFAULT_CHUNK_SIZE);
            return new WP_REST_Response($exporter->chunk($args, $offset, $limit));
        }

        // Single-shot path (back-compat).
        return new WP_REST_Response(['csv' => $exporter->fullCsv($args)]);
    }

    /**
     * Build link-export filter args from the REST request.
     *
     * Mirrors `LinksController::index` so list filters and export stay in
     * lockstep (including `prli_links_index_args`). When the post-filter
     * args have no active filters, returns `[]` so `CsvExporter` keeps the
     * legacy Tools "all live non-PrettyPay" scope.
     *
     * @param  WP_REST_Request $request The incoming REST request.
     * @return array<string, mixed>
     */
    private function exportArgs(WP_REST_Request $request): array
    {
        // Match GET /links: omitted prettypay → null (no prettypay WHERE).
        // List UI always sends prettypay=0|1; Tools sends nothing → [].
        $prettypay = $request->get_param('prettypay');
        $args      = [
            // Cast only — do not use ?: here. PHP treats "0" as empty, and a
            // list search for that string must survive into the CSV export
            // the same way GET /links keeps it (LinksController::index).
            'search'        => (string) $request->get_param('search'),
            'status'        => (string) ($request->get_param('status') ?: 'any'),
            'prettypay'     => ($prettypay === null || $prettypay === '') ? null : (int) $prettypay,
            'source'        => (string) ($request->get_param('source') ?: ''),
            'category'      => $request->get_param('category') ? (int) $request->get_param('category') : null,
            'tag'           => $request->get_param('tag') ? (int) $request->get_param('tag') : null,
            'redirect_type' => (string) ($request->get_param('redirect_type') ?: ''),
        ];

        /**
         * Filter: prli_links_index_args
         *
         * Same extension point as GET /links — Pro injects health / expired /
         * split_test without Lite knowing those param names. Applied before
         * the legacy empty-args decision so extension-only filters still
         * count as a filtered export.
         *
         * @param array<string, mixed> $args    Export filter args.
         * @param WP_REST_Request      $request The current REST request.
         */
        $args = (array) apply_filters('prli_links_index_args', $args, $request);

        if (!$this->hasExportFilters($args)) {
            return [];
        }

        return $args;
    }

    /**
     * True when export args carry an active list filter (core or extension).
     *
     * @param  array<string, mixed> $args Post-`prli_links_index_args` args.
     * @return boolean
     */
    private function hasExportFilters(array $args): bool
    {
        if (($args['search'] ?? '') !== '') {
            return true;
        }
        $status = (string) ($args['status'] ?? 'any');
        if ($status !== '' && $status !== 'any') {
            return true;
        }
        if (array_key_exists('prettypay', $args) && $args['prettypay'] !== null && $args['prettypay'] !== '') {
            return true;
        }
        if (($args['source'] ?? '') !== '') {
            return true;
        }
        if (!empty($args['category'])) {
            return true;
        }
        if (!empty($args['tag'])) {
            return true;
        }
        $redirectType = (string) ($args['redirect_type'] ?? '');
        if ($redirectType !== '' && $redirectType !== 'all') {
            return true;
        }

        // Extension-owned keys (health, expired, split_test, …).
        $core = [
            'search',
            'status',
            'prettypay',
            'source',
            'category',
            'tag',
            'redirect_type',
            'orderby',
            'order',
            'per_page',
            'page',
            'include',
        ];
        foreach ($args as $key => $value) {
            if (in_array($key, $core, true)) {
                continue;
            }
            // Treat 0 / "0" as inactive — Pro flags like expired/split_test
            // only apply on "1", and a default numeric zero must not force
            // the filtered-export path (which skips legacy prettypay=0).
            if ($this->isActiveExtensionFilterValue($value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether an extension-owned filter value should count as "active".
     *
     * @param  mixed $value Extension arg value.
     * @return boolean
     */
    private function isActiveExtensionFilterValue($value): bool
    {
        if ($value === null || $value === '' || $value === false) {
            return false;
        }
        if ($value === 0 || $value === '0') {
            return false;
        }
        if (is_array($value) && $value === []) {
            return false;
        }

        return true;
    }

    /**
     * Imports links from CSV rows.
     *
     * @param WP_REST_Request $request The incoming REST request.
     *
     * @return WP_REST_Response
     */
    public function import(WP_REST_Request $request): WP_REST_Response
    {
        $body = (array) $request->get_json_params();

        // Chunked import: client pre-parses CSV and sends rows as JSON objects.
        // row_offset is the 0-based index of the first row in the full file,
        // used only for human-readable error reporting.
        if (isset($body['rows']) && is_array($body['rows'])) {
            $rows      = array_values(array_filter($body['rows'], 'is_array'));
            $rowOffset = isset($body['row_offset']) ? (int) $body['row_offset'] : 0;
            $summary   = (new CsvImporter())->importRows($rows, $rowOffset);
            return new WP_REST_Response($summary);
        }

        // Legacy: raw CSV string (kept for backward compatibility).
        $csv     = (string) ($body['csv'] ?? '');
        $summary = (new CsvImporter())->import($csv);
        return new WP_REST_Response($summary);
    }
}
