<?php

declare(strict_types=1);

namespace PrettyLinks\Tools;

/**
 * Shared chunked-CSV exporter base. Concrete exporters (CsvExporter,
 * ClicksCsvExporter) implement the page-of-rows query and column list;
 * this class handles CSV stitching, header-on-first-chunk, the
 * back-compat single-shot path, and the chunk response shape that the
 * shared JS downloader (`src-js/shared/csv-download.js`) consumes.
 *
 * Why chunking exists: a single large export blocks a PHP-FPM worker for
 * the whole query and risks server-side timeouts and memory pressure.
 * The chunked path lets the client orchestrate the work — one short
 * REST call per page — and accumulate the result into a Blob in
 * browser memory before triggering the file download.
 */
abstract class ChunkedCsvExporter
{
    /**
     * Page size for client-orchestrated chunked export. Concrete classes
     * may override (Clicks rows are wider than Links rows, so Clicks
     * uses a smaller default).
     */
    public const DEFAULT_CHUNK_SIZE = 5000;

    /**
     * Header columns for this chunk. May depend on $args (e.g. Clicks
     * extended-mode columns are toggled by the caller's tracking mode).
     *
     * @param  array<string, mixed> $args Export arguments.
     * @return string[]
     */
    abstract protected function columns(array $args): array;

    /**
     * Total row count matching $args. Used to compute progress and the
     * `done` flag without the client needing to know the data shape.
     *
     * @param array<string, mixed> $args Export arguments.
     */
    abstract protected function totalRows(array $args): int;

    /**
     * Fetch one page of rows. Each row is an associative array keyed by
     * the column names returned from `columns()`. The base passes in the
     * already-resolved column list so the concrete class doesn't have to
     * re-derive it (and re-fire `prli_csv_export_columns`).
     *
     * @param  array<string, mixed> $args    Export arguments.
     * @param  integer              $offset  Row offset.
     * @param  integer              $limit   Maximum rows to fetch.
     * @param  string[]             $columns Resolved column list from columns().
     * @return array<int, array<string, mixed>>
     */
    abstract protected function fetchPage(array $args, int $offset, int $limit, array $columns): array;

    /**
     * Per-cell formatter. Default returns the raw value as a string;
     * concrete classes can override to add escaping (e.g. CSV-injection
     * `'`-prefix in Clicks export).
     *
     * @param string               $col Column name.
     * @param array<string, mixed> $row Row data keyed by column name.
     */
    protected function formatCell(string $col, array $row): string
    {
        return (string) ($row[$col] ?? '');
    }

    /**
     * Cheap row-count preflight. Used by the JS layer to surface a
     * "this export is large, continue?" warning before kicking off a
     * potentially-slow chunked download. Wraps `totalRows()` so the
     * REST layer doesn't need to know the protected method exists.
     *
     * @param array<string, mixed> $args Export arguments.
     */
    public function total(array $args): int
    {
        return $this->totalRows($args);
    }

    /**
     * Build a single CSV chunk. Header row is included only on the first
     * chunk (offset === 0) so the client can concatenate chunks without
     * post-processing. Returns a structured response so the REST layer
     * can forward it directly to the JS downloader.
     *
     * @param  array<string, mixed> $args   Export arguments.
     * @param  integer              $offset Row offset for this chunk.
     * @param  integer              $limit  Maximum rows in this chunk.
     * @return array{csv_chunk: string, processed: int, total: int, done: bool}
     */
    public function chunk(array $args, int $offset, int $limit): array
    {
        $offset  = max(0, $offset);
        $limit   = max(1, $limit);
        $columns = $this->columns($args);
        $total   = $this->totalRows($args);
        $rows    = $this->fetchPage($args, $offset, $limit, $columns);

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- in-memory php://temp; WP_Filesystem has no stream API.
        $fh = fopen('php://temp', 'w+');
        if ($fh === false) {
            return [
                'csv_chunk' => '',
                'processed' => $offset,
                'total'     => $total,
                'done'      => true,
            ];
        }
        if ($offset === 0) {
            fputcsv($fh, $columns);
        }
        foreach ($rows as $row) {
            fputcsv($fh, array_map(
                function (string $col) use ($row): string {
                    return $this->formatCell($col, $row);
                },
                $columns
            ));
        }
        rewind($fh);
        $out = stream_get_contents($fh);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        fclose($fh);

        $processed = $offset + count($rows);
        $done      = $processed >= $total || count($rows) === 0;

        return [
            'csv_chunk' => is_string($out) ? $out : '',
            'processed' => $processed,
            'total'     => $total,
            'done'      => $done,
        ];
    }

    /**
     * Single-shot CSV. Back-compat shim for callers that don't drive
     * the chunked loop themselves (sample download, third-party REST
     * consumers, internal integrations).
     *
     * @param array<string, mixed> $args Export arguments.
     */
    public function fullCsv(array $args = []): string
    {
        $columns = $this->columns($args);
        $total   = $this->totalRows($args);

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        $fh = fopen('php://temp', 'w+');
        if ($fh === false) {
            return '';
        }
        fputcsv($fh, $columns);

        $batch = static::DEFAULT_CHUNK_SIZE;
        for ($offset = 0; $offset < $total; $offset += $batch) {
            $rows = $this->fetchPage($args, $offset, $batch, $columns);
            if (!$rows) {
                break;
            }
            foreach ($rows as $row) {
                fputcsv($fh, array_map(
                    function (string $col) use ($row): string {
                        return $this->formatCell($col, $row);
                    },
                    $columns
                ));
            }
        }

        rewind($fh);
        $out = stream_get_contents($fh);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        fclose($fh);
        return is_string($out) ? $out : '';
    }
}
