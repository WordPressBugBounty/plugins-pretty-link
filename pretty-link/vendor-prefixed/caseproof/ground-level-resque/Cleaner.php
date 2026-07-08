<?php

declare(strict_types=1);

namespace PrettyLinks\GroundLevel\Resque;

use PrettyLinks\GroundLevel\Resque\Models\Job;
use PrettyLinks\GroundLevel\Resque\Enums\JobStatus;
use PrettyLinks\GroundLevel\Support\Concerns\Hookable;
use PrettyLinks\GroundLevel\Support\Models\Hook;
use PrettyLinks\GroundLevel\Support\Time;
use PrettyLinks\GroundLevel\Resque\Concerns\NormalizedPrefix;

/**
 * Cleaner class for managing job cleanup tasks.
 */
class Cleaner
{
    use Hookable;
    use NormalizedPrefix;

    /**
     * The Resque database instance.
     *
     * @inject \PrettyLinks\GroundLevel\Resque\ResqueServiceProvider::SERVICE_DATABASE
     *
     * @var Database
     */
    protected Database $database;

    /**
     * The hook prefix (used by NormalizedPrefix trait).
     *
     * @inject \PrettyLinks\GroundLevel\Resque\ResqueServiceProvider::PARAM_PREFIX
     *
     * @var string
     */
    protected string $prefix;

    /**
     * The action hook name for cleanup.
     *
     * @inject \PrettyLinks\GroundLevel\Resque\ResqueServiceProvider::PARAM_JOBS_CLEANUP_ACTION
     *
     * @var string
     */
    protected string $cleanupAction;

    /**
     * The cron interval name for cleanup.
     *
     * @inject \PrettyLinks\GroundLevel\Resque\ResqueServiceProvider::PARAM_JOBS_CLEANUP_INTERVAL_NAME
     *
     * @var string
     */
    protected string $cleanupIntervalName;

    /**
     * The cron interval in seconds.
     *
     * @inject \PrettyLinks\GroundLevel\Resque\ResqueServiceProvider::PARAM_JOBS_CLEANUP_INTERVAL
     *
     * @var integer
     */
    protected int $cleanupInterval;

    /**
     * Number of retries before a job is marked as failed.
     *
     * @inject \PrettyLinks\GroundLevel\Resque\ResqueServiceProvider::PARAM_JOBS_CLEANUP_NUM_RETRIES
     *
     * @var integer
     */
    protected int $numRetries;

    /**
     * Number of seconds to wait before retrying lingering jobs.
     *
     * @inject \PrettyLinks\GroundLevel\Resque\ResqueServiceProvider::PARAM_JOBS_CLEANUP_RETRY_AFTER
     *
     * @var integer
     */
    protected int $retryAfter;

    /**
     * Number of seconds after which completed jobs are deleted.
     *
     * @inject \PrettyLinks\GroundLevel\Resque\ResqueServiceProvider::PARAM_JOBS_CLEANUP_DELETE_COMPLETED
     *
     * @var integer
     */
    protected int $deleteCompletedAfter;

    /**
     * Number of seconds after which failed jobs are deleted.
     *
     * @inject \PrettyLinks\GroundLevel\Resque\ResqueServiceProvider::PARAM_JOBS_CLEANUP_DELETE_FAILED
     *
     * @var integer
     */
    protected int $deleteFailedAfter;

    /**
     * Constructor.
     *
     * @param Database $database             The Resque database instance.
     * @param string   $prefix               The hook prefix.
     * @param string   $cleanupAction        The action hook name for cleanup.
     * @param string   $cleanupIntervalName  The cron interval name.
     * @param integer  $cleanupInterval      The cron interval in seconds.
     * @param integer  $numRetries           Number of retries before job fails.
     * @param integer  $retryAfter           Seconds to wait before retrying.
     * @param integer  $deleteCompletedAfter Seconds after which completed jobs are deleted.
     * @param integer  $deleteFailedAfter    Seconds after which failed jobs are deleted.
     */
    public function __construct(
        Database $database,
        string $prefix,
        string $cleanupAction,
        string $cleanupIntervalName,
        int $cleanupInterval,
        int $numRetries,
        int $retryAfter,
        int $deleteCompletedAfter,
        int $deleteFailedAfter
    ) {
        $this->database             = $database;
        $this->prefix               = $prefix;
        $this->cleanupAction        = $cleanupAction;
        $this->cleanupIntervalName  = $cleanupIntervalName;
        $this->cleanupInterval      = $cleanupInterval;
        $this->numRetries           = $numRetries;
        $this->retryAfter           = $retryAfter;
        $this->deleteCompletedAfter = $deleteCompletedAfter;
        $this->deleteFailedAfter    = $deleteFailedAfter;
        $this->addHooks();
        $this->scheduleEvents();
    }

    /**
     * Configures the service's hooks.
     *
     * @return array
     */
    protected function configureHooks(): array
    {
        return [
            new Hook(
                Hook::TYPE_FILTER,
                'cron_schedules',
                [$this, 'intervals']
            ),
            new Hook(
                Hook::TYPE_ACTION,
                $this->cleanupAction,
                [$this, 'cleanup']
            ),
        ];
    }

    /**
     * WP Cron Intervals definition.
     *
     * phpcs:disable -- Squiz.Commenting.DocCommentAlignment.SpaceAfterStar
     * @param array $schedules Schedules {
     *     An array of non-default cron schedules keyed by the schedule name. Default empty array.
     *
     * @type array ...$0 {
     *         Cron schedule information.
     *
     *         @type   integer $interval The schedule interval in seconds.
     *         @type   string  $display  The schedule display name.
     *     }
     * phpcs:enable -- Squiz.Commenting.DocCommentAlignment.SpaceAfterStar
     * }
     * @return array
     */
    public function intervals(array $schedules = []): array
    {
        $schedules[$this->cleanupIntervalName] = [
            'interval' => $this->cleanupInterval,
            'display'  => \esc_html__('Resque Jobs Cleanup', 'pretty-link'),
        ];

        return $schedules;
    }

    /**
     * Schedule events.
     */
    public function scheduleEvents(): void
    {
        if (!\wp_next_scheduled($this->cleanupAction)) {
            \wp_schedule_event(
                Time::now(Time::FORMAT_TIMESTAMP, true) + $this->cleanupInterval,
                $this->cleanupIntervalName,
                $this->cleanupAction
            );
        }
    }

    /**
     * Unschedule events.
     */
    public function unscheduleEvents(): void
    {
        \wp_unschedule_event(
            \wp_next_scheduled($this->cleanupAction),
            $this->cleanupAction
        );
    }

    /**
     * Clean up the jobs table.
     *
     * - Retries lingering jobs
     * - Deletes completed jobs that have been in the system for over a day
     * - Delete jobs that have been retried and are still in a working state
     */
    public function cleanup(): void
    {
        // Retry lingering jobs.
        $this->retryLingeringJobs();

        // Delete completed jobs that have been in the system for over a day?
        $this->deleteCompletedOldJobs();

        // Delete jobs that have been retried and are still in a working state.
        $this->deleteRetriedButStillOnWorkingJobs();
    }

    /**
     * Retry lingering jobs.
     */
    private function retryLingeringJobs(): void
    {
        $jobsTableName = Job::init()->getTable()->getPrefixedName();

        $query = 'UPDATE ' . $jobsTableName . '
                SET status = %s
                WHERE status IN (%s,%s)
                AND tries <= %d
                AND TIMESTAMPDIFF(SECOND,lastrun,%s) >= %d';

        // The `update` method doesn't allow for such complex where clauses, so we can't use the QueryBuilder.
        $this->database->query(
            $query,
            [
                JobStatus::PENDING()->getValue(), // Set status to pending.
                JobStatus::WORKING()->getValue(), // If status = working or.
                JobStatus::FAILED()->getValue(), // Status = failed and.
                $this->numRetries, // Number of tries <= num_retries.
                Time::now('mysql', true),
                $this->retryAfter, // And the correct number of seconds since lastrun has elapsed.
            ]
        );
    }

    /**
     * Delete completed jobs that have been in the system for over `deleteCompletedAfter` seconds.
     */
    private function deleteCompletedOldJobs(): void
    {
        $jobsTableName = Job::init()->getTable()->getPrefixedName();

        // Delete completed jobs that have been in the system for over a day?
        $query = 'DELETE FROM ' . $jobsTableName . '
                WHERE status = %s
                AND TIMESTAMPDIFF(SECOND,lastrun,%s) >= %d';

        // The `delete` method doesn't allow for such complex where clauses, so we can't use the QueryBuilder.
        $this->database->query(
            $query, // Delete jobs.
            [
                JobStatus::COMPLETE()->getValue(), // Which have a status = complete.
                Time::now('mysql', true),
                $this->deleteCompletedAfter, // And the correct number of seconds since lastrun has elapsed.
            ]
        );
    }

    /**
     * Delete jobs that have been retried and are still in a working state.
     */
    private function deleteRetriedButStillOnWorkingJobs(): void
    {
        $jobsTableName = Job::init()->getTable()->getPrefixedName();

        $query = 'DELETE FROM ' . $jobsTableName . '
            WHERE tries > %d
            AND TIMESTAMPDIFF(SECOND,lastrun,%s) >= %d';

        // The `delete` method doesn't allow for such complex where clauses, so we can't use the QueryBuilder.
        $this->database->query(
            $query, // Delete jobs.
            [
                $this->numRetries, // Which have only been 'n' retries.
                Time::now('mysql', true),
                $this->deleteFailedAfter, // And the correct number of seconds since lastrun has elapsed.
            ]
        );
    }
}
