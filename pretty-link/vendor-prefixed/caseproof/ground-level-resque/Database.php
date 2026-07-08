<?php

declare(strict_types=1);

namespace PrettyLinks\GroundLevel\Resque;

use PrettyLinks\GroundLevel\Database\Database as BaseDatabase;
use PrettyLinks\GroundLevel\Resque\Models\Job;

/**
 * Database class.
 */
#[\AllowDynamicProperties]
class Database extends BaseDatabase
{
    /**
     * (Working/Pending) Jobs Table Prefix (excluding the DB prefix).
     */
    public const JOBS_TABLE_PREFIX = '';

    /**
     * Completed Jobs Table Prefix (excluding the DB prefix).
     */
    public const COMPLETED_JOBS_TABLE_PREFIX = 'completed_';

    /**
     * Failed Jobs Table Prefix (excluding the DB prefix).
     */
    public const FAILED_JOBS_TABLE_PREFIX = 'failed_';

    /**
     * Jobs Table name.
     *
     * @return string
     */
    public function jobsTableName(): string
    {
        return $this->getPrefix() . self::jobsTableNameNoDBPrefix();
    }

    /**
     * Completed jobs Table name.
     *
     * @return string
     */
    public function completedJobsTableName(): string
    {
        return $this->getPrefix() . self::completedJobsTableNameNoDBPrefix();
    }

    /**
     * Failed jobs Table name.
     *
     * @return string
     */
    public function failedJobsTableName(): string
    {
        return $this->getPrefix() . self::failedJobsTableNameNoDBPrefix();
    }

    /**
     * Jobs Table name without the DB prefix.
     *
     * @return string
     */
    public static function jobsTableNameNoDBPrefix(): string
    {
        return self::JOBS_TABLE_PREFIX . Job::BASE_TABLE_NAME;
    }

    /**
     * Completed Jobs Table name without the DB prefix.
     *
     * @return string
     */
    public static function completedJobsTableNameNoDBPrefix(): string
    {
        return self::COMPLETED_JOBS_TABLE_PREFIX . Job::BASE_TABLE_NAME;
    }

    /**
     * Failed Jobs Table name without the DB prefix.
     *
     * @return string
     */
    public static function failedJobsTableNameNoDBPrefix(): string
    {
        return self::FAILED_JOBS_TABLE_PREFIX . Job::BASE_TABLE_NAME;
    }
}
