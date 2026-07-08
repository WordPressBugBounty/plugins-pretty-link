<?php

declare(strict_types=1);

namespace PrettyLinks\Redirect;

defined('ABSPATH') || exit;

use PrettyLinks\Clicks\Cleaner;
use wpdb;

/**
 * Wipes all recorded click data when the tracking mode crosses the Simple
 * boundary (Simple ↔ Normal/Extended).
 *
 * Rationale: Simple and Normal/Extended use disjoint storage — Simple
 * writes `static-clicks` / `static-uniques` meta rows in `prli_link_metas`;
 * Normal/Extended writes `prli_clicks` rows and bumps the
 * `prli_links.clicks` / `prli_links.uniques` fast-read counters. Carrying
 * counters across the boundary yields stale frozen values and orphaned
 * rows, so a boundary crossing is treated as a fresh start.
 *
 * The wipe is always bidirectional (same effect regardless of direction):
 *   - DELETE every `prli_clicks` row.
 *   - DELETE every `static-clicks` and `static-uniques` meta row.
 *   - Reset `prli_links.clicks` and `prli_links.uniques` to zero for all
 *     links.
 *   - Fire `prli_click_data_wiped` so extensions can clear their own
 *     click-derived tables (e.g. rotation/split-test per-click rows,
 *     custom-report aggregates).
 *
 * Confirmation is the UI's job — the caller must have the user's consent
 * before the option update lands here. A double-confirm dialog in the
 * Options page covers the admin flow; direct option writes (WP-CLI,
 * programmatic update_option) fire the wipe unconditionally, which is
 * correct: changing mode without the wipe leaves the database inconsistent.
 */
class ClickDataWiper
{
    /**
     * WordPress database handle.
     *
     * @var wpdb
     */
    private wpdb $db;

    /**
     * Constructor.
     *
     * @param wpdb $db WordPress database handle.
     */
    public function __construct(wpdb $db)
    {
        $this->db = $db;
    }

    /**
     * Hook the option-update listener that detects boundary crossings.
     *
     * @return void
     */
    public function register(): void
    {
        // `update_option_prli_options` fires only when the value actually
        // changes, with the old/new values as args — perfect for detecting
        // a Simple-boundary crossing.
        add_action('update_option_prli_options', [$this, 'onOptionUpdate'], 10, 2);
    }

    /**
     * Wipe click data when the tracking mode crosses the Simple boundary.
     *
     * @param  mixed $oldValue Previous `prli_options` value.
     * @param  mixed $newValue Updated `prli_options` value.
     * @return void
     */
    public function onOptionUpdate($oldValue, $newValue): void
    {
        $oldMode = is_array($oldValue) && isset($oldValue['extended_tracking'])
            ? (string) $oldValue['extended_tracking']
            : 'normal';
        $newMode = is_array($newValue) && isset($newValue['extended_tracking'])
            ? (string) $newValue['extended_tracking']
            : 'normal';

        if ($oldMode === $newMode) {
            return;
        }
        // Only Simple ↔ non-Simple transitions require the wipe.
        $wasCount = $oldMode === 'count';
        $isCount  = $newMode === 'count';
        if ($wasCount === $isCount) {
            return;
        }
        $this->wipe();
    }

    /**
     * Delete all recorded click data and fire `prli_click_data_wiped`.
     *
     * @return void
     */
    public function wipe(): void
    {
        // Delegate to Cleaner: instant TRUNCATE where the host allows it,
        // otherwise a batched delete (inline when small, handed to the Resque
        // background worker when large) so a mode switch never freezes the
        // request. Keeps the wipe logic in one place.
        //
        // The `true` argument tells Cleaner to fire `prli_click_data_wiped`
        // only after the wipe has fully completed — from the background job
        // when the delete is async — so extensions (e.g. Pro's
        // prli_clicks_rotations, custom-report aggregates) never purge their
        // own click-derived tables before core click data is actually gone.
        (new Cleaner($this->db))->truncateAll(true);
    }
}
