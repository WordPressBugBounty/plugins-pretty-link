<?php

declare(strict_types=1);

namespace PrettyLinks\Clicks;

use PrettyLinks\GroundLevel\Resque\Models\Job;
use RuntimeException;

/**
 * Background job that clears click rows in small batches via the Resque
 * worker, so large deletes never block a web request, hold a table lock for
 * minutes, or die silently when the admin browses away.
 *
 * Each run deletes one batch; while rows remain it re-enqueues itself, and on
 * the final batch Cleaner runs the scope's finalizer (counter resets /
 * recount). The Resque worker's own time budget keeps chewing through the
 * re-enqueued jobs within a single cron fire, then picks up where it left off
 * on the next one. See Cleaner::processBatch() for the actual SQL.
 *
 * Args (JSON payload set at enqueue time):
 *   - mode:    'all' | 'age' | 'link'
 *   - days:    int (mode 'age')
 *   - link_id: int (mode 'link')
 */
class ClearClicksJob extends Job
{
    /**
     * Deletes one batch for the configured scope; re-enqueues until drained.
     *
     * @return void
     *
     * @throws RuntimeException When the payload is invalid or the next batch
     *                         cannot be queued, so the worker retries/fails the
     *                         job instead of recording a partial clear as done.
     */
    protected function perform(): void
    {
        $args = json_decode((string) $this->getAttribute('args'), true);
        if (!is_array($args)) {
            // A corrupt/missing payload would delete nothing — fail loudly so
            // the worker records a failure instead of a silent "clear".
            throw new RuntimeException('ClearClicksJob received an invalid args payload.');
        }

        global $wpdb;
        $moreRemain = (new Cleaner($wpdb))->processBatch($args);

        // Queue the next batch. If the queue write fails, throw so the worker
        // retries this job rather than abandoning a half-cleared scope (which
        // would leave counters unfinalized).
        if ($moreRemain && (new self())->enqueue($args) === false) {
            throw new RuntimeException('ClearClicksJob could not enqueue the next batch.');
        }
    }
}
