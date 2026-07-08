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
     * @param WP_REST_Request $request The incoming REST request.
     *
     * @return WP_REST_Response
     */
    public function export(WP_REST_Request $request): WP_REST_Response
    {
        $exporter = new CsvExporter();

        // Preflight: cheap row-count for the client's "large export"
        // warning. Returned before any rows are read so the warning
        // dialog can show without burning the first chunk's work.
        if ($request->get_param('count_only')) {
            return new WP_REST_Response(['total' => $exporter->total([])]);
        }

        // Chunked path: client orchestrates the loop with offset/limit and
        // accumulates the chunks into a Blob in browser memory. Avoids
        // blocking a PHP-FPM worker for the whole export.
        $offsetParam = $request->get_param('offset');
        if ($offsetParam !== null && $offsetParam !== '') {
            $offset = (int) $offsetParam;
            $limit  = (int) ($request->get_param('limit') ?: CsvExporter::DEFAULT_CHUNK_SIZE);
            return new WP_REST_Response($exporter->chunk([], $offset, $limit));
        }

        // Single-shot path (back-compat).
        return new WP_REST_Response(['csv' => $exporter->allLinks()]);
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
