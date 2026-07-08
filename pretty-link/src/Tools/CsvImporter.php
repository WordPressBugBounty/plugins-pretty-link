<?php

declare(strict_types=1);

namespace PrettyLinks\Tools;

use PrettyLinks\Repositories\Links;

/**
 * CSV import. Each row is upserted:
 *  - If the row has an `id` that matches an existing link → update.
 *  - Otherwise look up by slug → update if found, create if not.
 *
 * Pro hooks `prli_csv_import_link_saved` to handle extra columns
 * (categories, tags, delay) after every successful create or update.
 */
class CsvImporter
{
    /**
     * Boolean columns whose CSV-string values must be normalized before
     * reaching the repository. PHP's `!empty('false')` is true, so a row
     * like `track_me,false` would otherwise store as 1 (enabled) — the
     * exact opposite of what the user wrote.
     *
     * @var list<string>
     */
    private const BOOLEAN_COLUMNS = ['track_me', 'nofollow', 'sponsored', 'new_window'];

    /**
     * String values (case-insensitive, trimmed) that mean "false" in a
     * CSV cell. Anything else non-empty is treated as true.
     *
     * @var list<string>
     */
    private const FALSE_TOKENS = ['', '0', 'false', 'no', 'off'];

    /**
     * Process a chunk of pre-parsed rows. $rowOffset is the 0-based index of
     * the first row in the full CSV (used only for human-readable error row numbers).
     *
     * @param  array<int, array<string, string>> $rows      Pre-parsed CSV rows keyed by column name.
     * @param  integer                           $rowOffset Zero-based index of the first row in the full CSV.
     * @return array{imported: int, updated: int, skipped: int, errors: list<array{row: int, slug: string, message: string}>}
     */
    public function importRows(array $rows, int $rowOffset = 0): array
    {
        $links    = new Links();
        $imported = 0;
        $updated  = 0;
        $skipped  = 0;
        $errors   = [];

        foreach ($rows as $idx => $row) {
            $row    = self::normalizeBooleans($row);
            $rowNum = $rowOffset + $idx + 1;
            $slug   = (string) ($row['slug'] ?? '');

            if (($row['redirect_type'] ?? '') === 'prettypay_link_stripe' || !empty($row['prettypay_link'])) {
                $errors[] = [
                    'row'     => $rowNum,
                    'slug'    => $slug,
                    'message' => 'prettypay_not_supported',
                ];
                continue;
            }

            // Resolve existing link — by ID first, then by slug.
            $existing = null;
            $idVal    = isset($row['id']) ? (int) $row['id'] : 0;
            if ($idVal > 0) {
                $existing = $links->find($idVal);
                if ($existing === null) {
                    $errors[] = [
                        'row'     => $rowNum,
                        'slug'    => $slug ?: (string) $idVal,
                        'message' => 'id_not_found',
                    ];
                    continue;
                }
            } elseif ($slug !== '') {
                $existing = $links->findBySlug($slug);
            }

            if ($existing !== null) {
                $updateData = $row;
                unset($updateData['id']);
                $result = $links->update((int) $existing['id'], $updateData);
                if ($result === null || (is_array($result) && isset($result['error']))) {
                    $errors[] = [
                        'row'     => $rowNum,
                        'slug'    => $slug ?: (string) $idVal,
                        'message' => is_array($result) ? (string) ($result['error'] ?? 'update_failed') : 'update_failed',
                    ];
                } else {
                    do_action('prli_csv_import_link_saved', (int) $existing['id'], $row);
                    ++$updated;
                }
            } else {
                if (empty($row['url'])) {
                    $errors[] = [
                        'row'     => $rowNum,
                        'slug'    => $slug,
                        'message' => 'url_required',
                    ];
                    continue;
                }
                $result = $links->create($row);
                if (is_array($result) && isset($result['error'])) {
                    $errors[] = [
                        'row'     => $rowNum,
                        'slug'    => $slug,
                        'message' => (string) $result['error'],
                    ];
                } else {
                    $newId = (int) ($result['id'] ?? 0);
                    if ($newId > 0) {
                        do_action('prli_csv_import_link_saved', $newId, $row);
                    }
                    ++$imported;
                }
            }
        }

        return [
            'imported' => $imported,
            'updated'  => $updated,
            'skipped'  => $skipped,
            'errors'   => $errors,
        ];
    }

    /**
     * Parse a raw CSV string and import all rows in one pass.
     * Rows with a field count that doesn't match the header are counted as
     * skipped and not forwarded to importRows().
     *
     * @param  string $csv Raw CSV content.
     * @return array{imported: int, updated: int, skipped: int, errors: list<array{row: int, slug: string, message: string}>}
     */
    public function import(string $csv): array
    {
        $csv = trim($csv);
        if ($csv === '') {
            return [
                'imported' => 0,
                'updated'  => 0,
                'skipped'  => 0,
                'errors'   => [],
            ];
        }

        $lines  = preg_split('/\r\n|\r|\n/', $csv) ?: [];
        $header = str_getcsv((string) array_shift($lines));
        $header = array_map('strval', $header);

        $rows        = [];
        $countErrors = [];
        $rowNum      = 1; // Header is row 0; first data row is row 1.
        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }
            $fields = str_getcsv($line);
            if (count($fields) !== count($header)) {
                $countErrors[] = [
                    'row'     => $rowNum,
                    'slug'    => '',
                    'message' => 'field_count_mismatch',
                ];
                ++$rowNum;
                continue;
            }
            $row = array_combine($header, $fields);
            if (is_array($row)) {
                $rows[] = array_map('strval', $row);
            }
            ++$rowNum;
        }

        $result           = $this->importRows($rows);
        $result['errors'] = array_merge($countErrors, $result['errors']);
        return $result;
    }

    /**
     * Coerce CSV-string boolean cells into '0' / '1' so the repository's
     * `!empty()` check produces the value the user actually typed.
     *
     * @param  array<string, string> $row CSV row keyed by column name.
     * @return array<string, string>
     */
    private static function normalizeBooleans(array $row): array
    {
        foreach (self::BOOLEAN_COLUMNS as $col) {
            if (!array_key_exists($col, $row)) {
                continue;
            }
            $token     = strtolower(trim((string) $row[$col]));
            $row[$col] = in_array($token, self::FALSE_TOKENS, true) ? '0' : '1';
        }
        return $row;
    }
}
