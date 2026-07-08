<?php

declare(strict_types=1);

namespace PrettyLinks\GroundLevel\Resque\Models;

use Throwable;
use Exception;
use BadMethodCallException;
use PrettyLinks\GroundLevel\Resque\Database;
use PrettyLinks\GroundLevel\Resque\Enums\JobStatus;
use PrettyLinks\GroundLevel\Database\Models\PersistedModel;
use PrettyLinks\GroundLevel\Database\Exceptions\ModelError;
use PrettyLinks\GroundLevel\Support\Time;
use PrettyLinks\GroundLevel\Resque\ResqueServiceProvider;
use PrettyLinks\GroundLevel\Resque\Exceptions\InvalidJobStatusTableError;

/**
 * Job.
 */
class Job extends PersistedModel
{
    /**
     * The base table name.
     */
    public const BASE_TABLE_NAME = 'jobs';

    /**
     * Keyname of the item's updated date.
     *
     * If the item does not support an updated date, this should be set to an
     * empty string.
     *
     * @var string
     */
    protected string $keyDateUpdated = '';

    /**
     * Keyname of the item's created date.
     *
     * If the item does not support a created date, this should be set to an
     * empty string.
     *
     * @var string
     */
    protected string $keyDateCreated = 'created_at';

    /**
     * The name of the table's database.
     *
     * @var string
     */
    protected string $databaseName = ResqueServiceProvider::SERVICE_DATABASE;

    /**
     * Initializes a new object with data.
     *
     * @param  object|array|string|integer|null         $item   Accepts an array of item data as key=>val
     *                                                          pair or the item ID as an int or string
     *                                                          which will be used to setup the object.
     *                                                          If null is passed, a new empty object
     *                                                          is instantiated.
     * @param  \PrettyLinks\GroundLevel\Resque\Enums\JobStatus|null $status The status of job, any of the JobStatus enum.
     *                                                          If `null` it will default to `Enum\JobStatus::PENDING()` value.
     * @throws \PrettyLinks\GroundLevel\Database\Exceptions\ModelError When the job status is different from any of the `Enum\JobStatus` enum.
     */
    public function __construct($item = null, ?JobStatus $status = null)
    {
        if (!is_null($status)) {
            $status = $status->getValue();
        } else {
            $status = $item->status ?? $item['status'] ?? JobStatus::PENDING()->getValue();
        }

        switch ($status) {
            case JobStatus::WORKING()->getValue():
            case JobStatus::PENDING()->getValue():
                $this->tableName = Database::jobsTableNameNoDBPrefix();
                break;
            case JobStatus::FAILED()->getValue():
                $this->tableName                  = Database::failedJobsTableNameNoDBPrefix();
                $this->disableAutomaticTimestamps = true;
                break;
            case JobStatus::COMPLETE()->getValue():
                $this->tableName                  = Database::completedJobsTableNameNoDBPrefix();
                $this->disableAutomaticTimestamps = true;
                break;
            default:
                $modelType = $this->getModelType();
                throw new ModelError(
                    "The model '{$modelType}' cannot be created, with the specified '{$status}' status.",
                    ModelError::E_DB_NOT_FOUND,
                    null,
                    [
                        'databaseName' => $this->databaseName,
                        'modelType'    => $modelType,
                        'status'       => $status,
                    ]
                );
        }
        parent::__construct($item);
    }

    /**
     * Enqueue a job in the jobs table after a certain time interval from now.
     *
     * @param  integer $in       Interval.
     * @param  string  $unit     See `GroundLevel\Support\Time::UNIT_*` constants.
     * @param  array   $args     Job args.
     * @param  integer $priority Job priority.
     * @return integer|false The Job ID or false.
     * @throws \PrettyLinks\GroundLevel\Resque\Exceptions\InvalidJobStatusTableError When the methods is called on a Job in the completed|failed table.
     *                                 See `self::ensureJobTable()`.
     */
    public function enqueueIn(int $in, string $unit = Time::UNIT_MINUTES, array $args = [], int $priority = 10)
    {
        $when = Time::now(Time::FORMAT_TIMESTAMP, true) + Time::intervalToSeconds($in, $unit);
        return $this->enqueue($args, $when, $priority);
    }

    /**
     * Enqueue a job in the jobs table.
     *
     * @param  array          $args     Job args.
     * @param  integer|string $when     Date timestamp or the string 'now'.
     * @param  integer        $priority Job priority.
     * @return integer|false The Job ID or false.
     * @throws \PrettyLinks\GroundLevel\Resque\Exceptions\InvalidJobStatusTableError When the methods is called on a Job in the completed|failed table.
     *                                 See `self::ensureJobTable()`.
     */
    public function enqueue(array $args = [], $when = 'now', int $priority = 10)
    {
        $this->ensureJobsTable();
        $when = 'now' === $when ? Time::now(Time::FORMAT_TIMESTAMP, true) : $when;

        $attributes = [
            'runtime'  => gmdate(Time::FORMAT_ISO_8601, $when),
            'firstrun' => gmdate(Time::FORMAT_ISO_8601, $when),
            'priority' => $priority,
            'tries'    => 0,
            'class'    => get_class($this),
            'reason'   => '',
            'status'   => JobStatus::PENDING()->getValue(),
            'lastrun'  => Time::now(Time::FORMAT_ISO_8601, true),
        ];
        if (! empty($args)) {
            $attributes['args'] = json_encode($args);
        }
        $this->fillAttributes($attributes);

        try {
            $this->save();
            return $this->getId();
        } catch (ModelError $e) {
            return false;
        }
    }

    /**
     * Dequeue a job from the jobs table.
     *
     * @return boolean
     * @throws \PrettyLinks\GroundLevel\Resque\Exceptions\InvalidJobStatusTableError When the methods is called on a Job in the completed|failed table.
     *                                 See `self::ensureJobTable()`.
     */
    public function dequeue(): bool
    {
        $this->ensureJobsTable();
        return $this->delete();
    }

    /**
     * Work the job.
     *
     * Sets the status to working, increments the tries, sets the lastrun.
     *
     * @return self
     * @throws \PrettyLinks\GroundLevel\Resque\Exceptions\InvalidJobStatusTableError When the methods is called on a Job in the completed|failed table.
     *                                 See `self::ensureJobTable()`.
     */
    public function work(): Job
    {
        $this->ensureJobsTable();

        $attributes = [
            'tries'   => $this->getAttribute('tries') + 1,
            'status'  => JobStatus::WORKING()->getValue(),
            'lastrun' => Time::now(Time::FORMAT_ISO_8601, true),
        ];
        $this->fillAttributes($attributes);

        return $this->save();
    }

    /**
     * Retry the job.
     *
     * Sets the status to pending, sets the retry reason and the runtime based on the retry after config param.
     *
     * @param  integer $retryIn Retry in the specified seconds.
     * @param  string  $reason  Reason for retrying string.
     * @return self
     * @throws \PrettyLinks\GroundLevel\Resque\Exceptions\InvalidJobStatusTableError When the methods is called on a Job in the completed|failed table.
     *                                 See `self::ensureJobTable()`.
     */
    public function retry(int $retryIn = 60, string $reason = ''): Job
    {
        $this->ensureJobsTable();

        $when = Time::now(Time::FORMAT_TIMESTAMP, true) + $retryIn;

        $attributes = [
            'reason'  => $reason,
            'status'  => JobStatus::PENDING()->getValue(),
            'runtime' => gmdate(Time::FORMAT_ISO_8601, $when),
        ];
        $this->fillAttributes($attributes);

        return $this->save();
    }

    /**
     * Complete the job.
     *
     * Sets the status to complete, and resets the reason.
     *
     * @return self
     * @throws \PrettyLinks\GroundLevel\Resque\Exceptions\InvalidJobStatusTableError When the methods is called on a Job in the completed|failed table.
     *                                 See `self::ensureJobTable()`.
     */
    public function complete(): Job
    {
        $this->ensureJobsTable();

        $attributes = [
            'reason' => '',
            'status' => JobStatus::COMPLETE()->getValue(),
        ];
        $this->fillAttributes($attributes);

        return $this->save();
    }

    /**
     * Fail the job.
     *
     * Sets the status to failed, and resets the reason.
     *
     * @param  string $reason Reason for failure.
     * @return self
     * @throws \PrettyLinks\GroundLevel\Resque\Exceptions\InvalidJobStatusTableError When the methods is called on a Job in the completed|failed table.
     *                                   See `self::ensureJobTable()`.
     */
    public function fail(string $reason = ''): Job
    {
        $this->ensureJobsTable();

        $attributes = [
            'reason' => $reason,
            'status' => JobStatus::FAILED()->getValue(),
        ];
        $this->fillAttributes($attributes);

        return $this->save();
    }

    /**
     * Run the job.
     *
     * @throws \BadMethodCallException When called on a class that doesn't override the `perform()` method.
     * @throws \PrettyLinks\GroundLevel\Resque\Exceptions\InvalidJobStatusTableError When the methods is called on a Job in the completed|failed table.
     *                                   See `self::ensureJobTable()`.
     */
    public function run(): void
    {
        $this->ensureJobsTable();
        $this->perform();
    }

    /**
     * Perform the job.
     *
     * @throws \BadMethodCallException When called directly on a class that doesn't override it.
     */
    protected function perform(): void
    {
        throw new BadMethodCallException(
            sprintf(
                'Method %1$s not implemented. Must be overridden in subclass.',
                __METHOD__
            )
        );
    }

    /**
     * Determines if the item exists in the database.
     *
     * @return boolean
     * @throws ModelError When the model could not be read from the database.
     */
    public function exists(): bool
    {
        $id = $this->getId();
        if (empty($id)) {
            return false;
        }

        try {
            $model = new static($id, $this->getInitStatus());
            $find  = $model->read([$this->idKey]);
        } catch (ModelError $err) {
            if (ModelError::E_RECORD_NOT_FOUND !== $err->getCode()) {
                throw $err;
            }
            $find = false;
        }
        return ! empty($find);
    }

    /**
     * We just return a JSON encoding of the job properties.
     *
     * @return string JSON encoding of the job properties.
     */
    public function __toString(): string
    {
        return json_encode($this);
    }

    /**
     * Returns a job instance given a class, and a Job ID.
     *
     * @param  string  $class The classname of the job implementation.
     * @param  integer $id    Job ID.
     * @return \PrettyLinks\GroundLevel\Resque\Models\Job Instance of the Job.
     * @throws \Exception When the job class was not found or not a valid job instance.
     */
    public static function fetch(string $class, int $id): self
    {
        if (!class_exists($class)) {
            // Translators: %s job class name.
            throw new Exception(sprintf(esc_html__('Job class was not found for %s', 'pretty-link'), $class));
        }

        if ($class === __CLASS__) {
            throw new Exception(
                esc_html__(
                    'Cannot fetch a job as instance of the base Job class. A job must be an instance of a subclass.',
                    'pretty-link'
                )
            );
        }

        try {
            // Fetch jobs from the 'jobs' table only.
            $job = (new $class($id, JobStatus::PENDING()))->read();
            if (!( $job instanceof Job )) {
                throw new Exception();
            }
        } catch (Throwable $e) {
            // Translators: %s job class name.
            throw new Exception(sprintf(esc_html__('%s is not a valid job object.', 'pretty-link'), $class));
        }

        return $job;
    }

    /**
     * Ensures we're on the Jobs table.
     *
     * @throws \PrettyLinks\GroundLevel\Resque\Exceptions\InvalidJobStatusTableError When called on a Job in the completed|failed table.
     */
    private function ensureJobsTable(): void
    {
        // Only valid on the 'jobs' table.
        if (! $this->usingJobsTable()) {
            throw InvalidJobStatusTableError::create(
                $this->tableName
            );
        }
    }

    /**
     * Checks if the current table is the "jobs" table.
     *
     * @return boolean Returns true if the current table is the "jobs" table, false otherwise.
     */
    private function usingJobsTable(): bool
    {
        return Database::jobsTableNameNoDBPrefix() === $this->tableName;
    }

    /**
     * Get init status.
     */
    private function getInitStatus(): JobStatus
    {
        $status = JobStatus::PENDING();
        if (Database::completedJobsTableNameNoDBPrefix() === $this->tableName) {
            $status = JobStatus::COMPLETE();
        } elseif (Database::failedJobsTableNameNoDBPrefix() === $this->tableName) {
            $status = JobStatus::FAILED();
        }
        return $status;
    }
}
