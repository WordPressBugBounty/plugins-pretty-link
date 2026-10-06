<?php

declare(strict_types=1);

namespace PrettyLinks\Support;

/**
 * When the current background-worker run has to stop.
 *
 * The Resque worker (patched by `fixupResqueWorkerLimits` in
 * bin/strauss-fixups.php) publishes its deadline in a global while it runs.
 * Jobs that loop over slow work — DNS, HTTP — check it between items, so a
 * single job can't keep the worker past the run budget (#971, #972).
 */
final class JobDeadline
{
    /**
     * The global the worker sets to its deadline: `microtime(true)` seconds,
     * the same clock jobs compare against.
     */
    public const GLOBAL_KEY = 'prli_resque_deadline';

    /**
     * The worker's deadline, or a fresh run budget from now when there's no
     * worker (a job invoked directly).
     *
     * @return float Unix timestamp.
     */
    public static function at(): float
    {
        $deadline = $GLOBALS[self::GLOBAL_KEY] ?? null;
        if (is_int($deadline) || is_float($deadline)) {
            return (float) $deadline;
        }
        return microtime(true) + self::budget();
    }

    /**
     * Seconds one worker run may spend starting jobs (30s, Action Scheduler's
     * default). The single source for the worker and for jobs run directly.
     *
     * @return integer
     */
    public static function budget(): int
    {
        /**
         * Filter: prli_resque_run_budget
         *
         * Seconds a background-worker run may spend starting jobs. Checked
         * between jobs; slow jobs also stop their own batches at it.
         *
         * @param int $seconds Default 30.
         */
        return max(1, (int) apply_filters('prli_resque_run_budget', 30));
    }
}
