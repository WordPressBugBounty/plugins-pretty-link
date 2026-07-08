<?php

declare(strict_types=1);

namespace PrettyLinks\GroundLevel\Resque;

use Exception;
use Throwable;
use PrettyLinks\GroundLevel\Resque\Enums\JobStatus;
use PrettyLinks\GroundLevel\Resque\Models\Job;
use PrettyLinks\GroundLevel\Support\Concerns\Hookable;
use PrettyLinks\GroundLevel\Support\Models\Hook;
use PrettyLinks\GroundLevel\Support\Time;
use PrettyLinks\GroundLevel\Resque\Concerns\NormalizedPrefix;
use PrettyLinks\GroundLevel\QueryBuilder\Query;

/**
 * Worker class for processing queued jobs.
 */
class Worker
{
    use Hookable;
    use NormalizedPrefix;

    /**
     * The hook prefix (used by NormalizedPrefix trait).
     *
     * @inject \PrettyLinks\GroundLevel\Resque\ResqueServiceProvider::PARAM_PREFIX
     *
     * @var string
     */
    protected string $prefix;

    /**
     * The action hook name for job processing.
     *
     * @inject \PrettyLinks\GroundLevel\Resque\ResqueServiceProvider::PARAM_JOBS_ACTION
     *
     * @var string
     */
    protected string $jobsAction;

    /**
     * The cron interval name for job processing.
     *
     * @inject \PrettyLinks\GroundLevel\Resque\ResqueServiceProvider::PARAM_JOBS_INTERVAL_NAME
     *
     * @var string
     */
    protected string $jobsIntervalName;

    /**
     * The cron interval in seconds.
     *
     * @inject \PrettyLinks\GroundLevel\Resque\ResqueServiceProvider::PARAM_JOBS_INTERVAL
     *
     * @var integer
     */
    protected int $jobsInterval;

    /**
     * Number of seconds to wait before retrying a job.
     *
     * @inject \PrettyLinks\GroundLevel\Resque\ResqueServiceProvider::PARAM_JOBS_RETRY_AFTER
     *
     * @var integer
     */
    protected int $jobsRetryAfter;

    /**
     * Number of retries before a job is marked as failed.
     *
     * @inject \PrettyLinks\GroundLevel\Resque\ResqueServiceProvider::PARAM_JOBS_CLEANUP_NUM_RETRIES
     *
     * @var integer
     */
    protected int $numRetries;

    /**
     * Constructor.
     *
     * @param string  $prefix           The hook prefix.
     * @param string  $jobsAction       The action hook name for job processing.
     * @param string  $jobsIntervalName The cron interval name.
     * @param integer $jobsInterval     The cron interval in seconds.
     * @param integer $jobsRetryAfter   Seconds to wait before retrying a job.
     * @param integer $numRetries       Number of retries before job fails.
     */
    public function __construct(
        string $prefix,
        string $jobsAction,
        string $jobsIntervalName,
        int $jobsInterval,
        int $jobsRetryAfter,
        int $numRetries
    ) {
        $this->prefix           = $prefix;
        $this->jobsAction       = $jobsAction;
        $this->jobsIntervalName = $jobsIntervalName;
        $this->jobsInterval     = $jobsInterval;
        $this->jobsRetryAfter   = $jobsRetryAfter;
        $this->numRetries       = $numRetries;
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
        $normalizedPrefix = $this->getNormalizedPrefix();
        return [
            new Hook(
                Hook::TYPE_FILTER,
                'cron_schedules',
                [$this, 'intervals']
            ),
            new Hook(
                Hook::TYPE_ACTION,
                $this->jobsAction,
                [$this, 'run']
            ),
            // Bind job events.
            new Hook(
                Hook::TYPE_ACTION,
                "{$normalizedPrefix}_job_complete",
                static function ($job): void {
                    if (\method_exists($job, 'onComplete')) {
                        $job->onComplete();
                    }
                }
            ),
            new Hook(
                Hook::TYPE_ACTION,
                "{$normalizedPrefix}_job_failed",
                static function ($job): void {
                    if (\method_exists($job, 'onFail')) {
                        $job->onFail();
                    }
                }
            ),
            new Hook(
                Hook::TYPE_ACTION,
                "{$normalizedPrefix}_job_retried",
                static function ($job): void {
                    if (\method_exists($job, 'onRetry')) {
                        $job->onRetry();
                    }
                }
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
     *     @type array ...$0 {
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
        $schedules[$this->jobsIntervalName] = [
            'interval' => $this->jobsInterval,
            'display'  => \esc_html__('Resque Jobs Worker', 'pretty-link'),
        ];

        return $schedules;
    }

    /**
     * Schedule events.
     */
    public function scheduleEvents(): void
    {
        if (!\wp_next_scheduled($this->jobsAction)) {
            \wp_schedule_event(
                Time::now(Time::FORMAT_TIMESTAMP, true) + $this->jobsInterval,
                $this->jobsIntervalName,
                $this->jobsAction
            );
        }
    }

    /**
     * Unschedule events.
     */
    public function unscheduleEvents(): void
    {
        \wp_unschedule_event(
            \wp_next_scheduled($this->jobsAction),
            $this->jobsAction
        );
    }

    /**
     * Worker run.
     */
    public function run(): void
    {
        $startTime        = Time::now(Time::FORMAT_TIMESTAMP);
        $maxExecutionTime = (int)(ini_get('max_execution_time') ?? 0);
        $maxExecutionTime = $maxExecutionTime > 0 ? $maxExecutionTime : 60;
        $maxRunTime       = floor($maxExecutionTime * 0.75); // Allow some buffering.

        while ((Time::now(Time::FORMAT_TIMESTAMP) - $startTime ) <= $maxRunTime) {
            $jobRaw = $this->nextJobRaw();
            if (empty($jobRaw)) {
                break;
            }

            $workedJob = false;
            $job       = null;

            try {
                if (isset($jobRaw->class)) {
                    $job = Job::fetch($jobRaw->class, (int)$jobRaw->id);
                    $this->workJob($job);
                    $workedJob = true;
                    $job->run(); // Run the job's run method.
                    $this->completeJob($job); // When we're successful we complete the job.
                } else {
                    // If there's no class specified in the job config, the job is gonna be fail.
                    $job = new Job((int)$jobRaw->id, JobStatus::PENDING());
                    $this->workJob($job);
                    $workedJob = true;
                    $this->failJob(
                        $job,
                        \esc_html__('No class was specified in the job config', 'pretty-link')
                    );
                }
            } catch (Throwable $e) {
                $job = $job ?? new Job((int)$jobRaw->id, JobStatus::PENDING());
                if (!$workedJob) {
                    $this->workJob($job);
                }
                $this->failJob($job, $e->getMessage());
            }
        }
    }

    /**
     * Work a job.
     *
     * @param  \PrettyLinks\GroundLevel\Resque\Models\Job $job The job to complete.
     * @throws \InvalidArgumentException When the methods is called for a Job in the completed|failed table.
     *                                 See `\GroundLevel\Resque\Models\Job::ensureJobTable()`.
     */
    public function workJob(Job $job): void
    {
        $job->work();
    }

    /**
     * Retry a job.
     *
     * @param  \PrettyLinks\GroundLevel\Resque\Models\Job $job    The job to complete.
     * @param  string                         $reason The retry reason.
     * @throws \InvalidArgumentException When the methods is called for a Job in the completed|failed table.
     *                                 See `\GroundLevel\Resque\Models\Job::ensureJobTable()`.
     */
    public function retryJob(Job $job, string $reason = ''): void
    {
        $job->retry(
            $this->jobsRetryAfter,
            $reason
        );

        $hookPrefix = $this->getNormalizedPrefix();
        /**
         * Fires after the job has been retried.
         *
         * The dynamic part of this hook `{$hookPrefix}` refers to the service prefix.
         *
         * @param Job $retriedJob Instance of the retried job.
         */
        \do_action("{$hookPrefix}_job_retried", $job);
    }

    /**
     * Complete a job.
     *
     * @param  \PrettyLinks\GroundLevel\Resque\Models\Job $job The job to complete.
     * @throws \InvalidArgumentException When the methods is called for a Job in the completed|failed table.
     *                                 See `\GroundLevel\Resque\Models\Job::ensureJobTable()`.
     */
    public function completeJob(Job $job): void
    {
        $job->complete();

        $completedJobClass      = get_class($job);
        $completedJobAttributes = $job->toArray();
        $job->dequeue();

        // Creates a completed job, since the status of the job is 'completed', it will be saved in the completed jobs table.
        $completedJob = (new $completedJobClass($completedJobAttributes))->save();

        $hookPrefix = $this->getNormalizedPrefix();
        /**
         * Fires after the job is completed.
         *
         * The dynamic part of this hook `{$hookPrefix}` refers to the service prefix.
         *
         * @param Job $completedJob Instance of the completed job.
         */
        \do_action("{$hookPrefix}_job_complete", $completedJob);
    }

    /**
     * Fail a job.
     *
     * @param  \PrettyLinks\GroundLevel\Resque\Models\Job $job    The job to fail.
     * @param  string                         $reason Failing reason.
     * @throws \InvalidArgumentException When the methods is called for a Job in the completed|failed table.
     *                                   See `\GroundLevel\Resque\Models\Job::ensureJobTable()`.
     */
    public function failJob(Job $job, string $reason = ''): void
    {
        // We fail and then re-enqueue for an hour later 5 times before giving up.
        if ($job->getAttribute('tries') >= $this->numRetries) {
            $job->fail($reason);

            $failedJobClass      = get_class($job);
            $failedJobAttributes = $job->toArray();
            $job->dequeue();

            // Creates a failed job, since the status of the job is 'failed', it will be saved in the failed jobs table.
            $failedJob = (new $failedJobClass($failedJobAttributes))->save();

            $hookPrefix = $this->getNormalizedPrefix();
            /**
             * Fires after the job has failed.
             *
             * The dynamic part of this hook `{$hookPrefix}` refers to the service prefix.
             *
             * @param Job $failedJob Instance of the failed job.
             */
            \do_action("{$hookPrefix}_job_failed", $failedJob);
        } else {
            // Retry.
            $this->retryJob($job, $reason);
        }
    }

    /**
     * Return a full list of all the pending jobs in the queue.
     *
     * @return null|array Database query results, array of objects, or null on failure.
     */
    public function queue(): ?array
    {
        $results = Job::init()->getTable()->select(
            function (Query $query): void {
                $query
                    ->where('status', Query::EQUALS, JobStatus::PENDING()->getValue())
                    ->and()
                    ->where('runtime', Query::LESS_EQUALS, Time::now('mysql', true))
                ->orderByAsc('priority')
                ->orderByAsc('runtime');
            }
        );
        return empty($results) ? null : $results;
    }

    /**
     * Return the next job in the queue.
     *
     * @return null|object Database query result or null on failure.
     */
    public function nextJobRaw(): ?object
    {
        $results = Job::init()->getTable()->select(
            function (Query $query): void {
                $query
                    ->where('status', Query::EQUALS, JobStatus::PENDING()->getValue())
                    ->and()
                    ->where('runtime', Query::LESS_EQUALS, Time::now('mysql', true))
                ->orderByAsc('priority')
                ->orderByAsc('runtime')
                ->limit(1);
            }
        );
        return empty($results) ? null : $results[0];
    }

    /**
     * Dequeue a job given its ID.
     *
     * @param  integer $jobId The ID of the job to dequeue.
     * @return boolean
     * @throws \PrettyLinks\GroundLevel\Database\Exceptions\ModelError When the job doesn't exist.
     */
    public function dequeue(int $jobId): bool
    {
        $job = Job::find($jobId);

        try {
            // Try to instantiate the actual extending job's class instance.
            $job = Job::fetch($job->getAttribute('class'), $job->getId());
        } catch (Exception $e) {
            /**
             * There's no class specified in the job config or the class doesn't exists
             * nothing to do, dequeue the base Job.
             */
        }

        return $job->dequeue();
    }
}
