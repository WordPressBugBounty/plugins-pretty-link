<?php

declare(strict_types=1);

namespace PrettyLinks\GroundLevel\Resque;

use PrettyLinks\GroundLevel\Container\Container;
use PrettyLinks\GroundLevel\Container\ServiceProvider;
use PrettyLinks\GroundLevel\Database\DatabaseServiceProvider;
use PrettyLinks\GroundLevel\Support\Models\Hook;
use PrettyLinks\GroundLevel\Support\Str;
use PrettyLinks\GroundLevel\Support\Time;

/**
 * Service provider for the Resque component.
 *
 * Registers the Resque database, Worker, and Cleaner services.
 */
class ResqueServiceProvider extends ServiceProvider
{
    /**
     * Service identifier for the Resque database.
     */
    public const SERVICE_DATABASE = 'resque.database';

    /**
     * Parameter key for the Resque database prefix.
     */
    public const PARAM_DB_PREFIX = 'resque.db.prefix';

    /**
     * Parameter key for the Resque prefix.
     */
    public const PARAM_PREFIX = 'resque.prefix';

    /**
     * Parameter key for jobs retry after (seconds).
     */
    public const PARAM_JOBS_RETRY_AFTER = 'resque.jobs.retry_after';

    /**
     * Parameter key for jobs WP cron interval (seconds).
     */
    public const PARAM_JOBS_INTERVAL = 'resque.jobs.interval';

    /**
     * Parameter key for jobs WP cron interval name.
     */
    public const PARAM_JOBS_INTERVAL_NAME = 'resque.jobs.interval_name';

    /**
     * Parameter key for jobs WP cron action.
     */
    public const PARAM_JOBS_ACTION = 'resque.jobs.action';

    /**
     * Parameter key for jobs cleanup number of retries.
     */
    public const PARAM_JOBS_CLEANUP_NUM_RETRIES = 'resque.jobs_cleanup.num_retries';

    /**
     * Parameter key for jobs cleanup retry after (seconds).
     */
    public const PARAM_JOBS_CLEANUP_RETRY_AFTER = 'resque.jobs_cleanup.retry_after';

    /**
     * Parameter key for jobs cleanup delete completed after (seconds).
     */
    public const PARAM_JOBS_CLEANUP_DELETE_COMPLETED = 'resque.jobs_cleanup.delete_completed_after';

    /**
     * Parameter key for jobs cleanup delete failed after (seconds).
     */
    public const PARAM_JOBS_CLEANUP_DELETE_FAILED = 'resque.jobs_cleanup.delete_failed_after';

    /**
     * Parameter key for jobs cleanup WP cron interval name.
     */
    public const PARAM_JOBS_CLEANUP_INTERVAL_NAME = 'resque.jobs_cleanup.interval_name';

    /**
     * Parameter key for jobs cleanup WP cron interval (seconds).
     */
    public const PARAM_JOBS_CLEANUP_INTERVAL = 'resque.jobs_cleanup.interval';

    /**
     * Parameter key for jobs cleanup WP cron action.
     */
    public const PARAM_JOBS_CLEANUP_ACTION = 'resque.jobs_cleanup.action';

    /**
     * Returns provider dependencies.
     */
    public function dependencies(): array
    {
        return [
            DatabaseServiceProvider::class,
        ];
    }

    /**
     * Returns default parameters.
     */
    public function parameters(): array
    {
        return [
            self::PARAM_DB_PREFIX                     => 'resque_',
            self::PARAM_PREFIX                        => 'resque_',
            self::PARAM_JOBS_RETRY_AFTER              => Time::minutes(30),
            self::PARAM_JOBS_INTERVAL                 => Time::minutes(1),
            self::PARAM_JOBS_INTERVAL_NAME            => 'resque_jobs_interval',
            self::PARAM_JOBS_ACTION                   => 'resque_run_jobs',
            self::PARAM_JOBS_CLEANUP_NUM_RETRIES      => 5,
            self::PARAM_JOBS_CLEANUP_RETRY_AFTER      => Time::hours(1),
            self::PARAM_JOBS_CLEANUP_DELETE_COMPLETED => Time::days(2),
            self::PARAM_JOBS_CLEANUP_DELETE_FAILED    => Time::days(30),
            self::PARAM_JOBS_CLEANUP_INTERVAL_NAME    => 'resque_jobs_cleanup_interval',
            self::PARAM_JOBS_CLEANUP_INTERVAL         => Time::hours(1),
            self::PARAM_JOBS_CLEANUP_ACTION           => 'resque_cleanup_jobs',
        ];
    }

    /**
     * Returns service definitions.
     */
    public function services(): array
    {
        return [
            $this->service(
                self::SERVICE_DATABASE,
                self::SINGLETON,
                static function (Container $c): Database {
                    $database = new Database(ResqueServiceProvider::SERVICE_DATABASE);

                    if ($c->has(DatabaseServiceProvider::PARAM_CONNECTION)) {
                        $database->setConnection($c->get(DatabaseServiceProvider::PARAM_CONNECTION));
                    }

                    return $database
                        ->setPrefix($c->get(ResqueServiceProvider::PARAM_DB_PREFIX))
                        ->registerSchemaPath(
                            Str::trailingslashit(dirname(__FILE__)) . 'schemas'
                        )
                        ->autoRegisterSchemas();
                }
            ),

            $this->service(Worker::class, self::SINGLETON, null, true),
            $this->service(Cleaner::class, self::SINGLETON, null, true),
        ];
    }

    /**
     * Configure hooks.
     *
     * @return array<Hook>
     */
    protected function configureHooks(): array
    {
        return [
            new Hook(
                Hook::TYPE_ACTION,
                'plugins_loaded',
                [$this->container->get(self::SERVICE_DATABASE), 'install'],
                5
            ),
        ];
    }
}
