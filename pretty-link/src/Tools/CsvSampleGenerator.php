<?php

declare(strict_types=1);

namespace PrettyLinks\Tools;

/**
 * Generates a sample import CSV whose columns match whatever prli_csv_export_columns
 * returns — so Pro columns (link_categories, link_tags, delay) appear automatically
 * when Pro is active.
 *
 * Pro hooks prli_csv_sample_rows to append rows demonstrating its own columns.
 */
class CsvSampleGenerator
{
    /**
     * Build the sample import CSV as a string.
     *
     * @return string The generated CSV content.
     */
    public function generate(): string
    {
        /**
         * Column list, including any Pro columns appended via the filter.
         *
         * @var string[] $columns
         */
        $columns = (array) apply_filters('prli_csv_export_columns', CsvExporter::COLUMNS);

        // Base rows — one per Lite redirect type, covering every boolean combination.
        $rows = [
            [
                'id'               => '',
                'slug'             => 'sample-link-301',
                'url'              => 'https://example.com/product-a',
                'name'             => 'Sample Link (301 Permanent)',
                'description'      => 'Permanent redirect. nofollow + sponsored on.',
                'redirect_type'    => '301',
                'param_forwarding' => '1',
                'track_me'         => '1',
                'nofollow'         => '1',
                'sponsored'        => '1',
                'new_window'       => '0',
                'source'           => 'admin',
            ],
            [
                'id'               => '',
                'slug'             => 'sample-link-302',
                'url'              => 'https://example.com/product-b',
                'name'             => 'Sample Link (302 Temporary)',
                'description'      => 'Temporary redirect. Opens in new window.',
                'redirect_type'    => '302',
                'param_forwarding' => '0',
                'track_me'         => '1',
                'nofollow'         => '0',
                'sponsored'        => '0',
                'new_window'       => '1',
                'source'           => 'admin',
            ],
            [
                'id'               => '',
                'slug'             => 'sample-link-307',
                'url'              => 'https://example.com/product-c',
                'name'             => 'Sample Link (307 Temporary)',
                'description'      => 'Temporary redirect preserving request method.',
                'redirect_type'    => '307',
                'param_forwarding' => '1',
                'track_me'         => '0',
                'nofollow'         => '0',
                'sponsored'        => '0',
                'new_window'       => '0',
                'source'           => 'admin',
            ],
        ];

        /**
         * Filter: prli_csv_sample_rows
         * Allows Pro/extensions to append rows demonstrating extra columns.
         *
         * @param array<int, array<string, string>> $rows    Existing sample rows.
         * @param string[]                          $columns Full column list.
         */
        $rows = (array) apply_filters('prli_csv_sample_rows', $rows, $columns);

        // Ensure every row has a value (empty string) for every column so the
        // CSV is always rectangular regardless of which rows came from where.
        $extraColumns = array_diff($columns, CsvExporter::COLUMNS);
        foreach ($rows as &$row) {
            foreach ($columns as $col) {
                if (!array_key_exists($col, $row)) {
                    $row[$col] = '';
                }
            }
        }
        unset($row);

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Using an in-memory php://temp stream for fputcsv; WP_Filesystem does not provide a stream API.
        $fh = fopen('php://temp', 'w+');
        if ($fh === false) {
            return '';
        }
        fputcsv($fh, $columns);
        foreach ($rows as $row) {
            fputcsv($fh, array_map(static function (string $col) use ($row): string {
                return (string) ($row[$col] ?? '');
            }, $columns));
        }
        rewind($fh);
        $out = stream_get_contents($fh);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the php://temp stream opened above.
        fclose($fh);
        return is_string($out) ? $out : '';
    }
}
