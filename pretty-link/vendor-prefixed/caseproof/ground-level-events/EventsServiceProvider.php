<?php

declare(strict_types=1);

namespace PrettyLinks\GroundLevel\Events;

use PrettyLinks\GroundLevel\Container\Container;
use PrettyLinks\GroundLevel\Container\ServiceProvider;
use PrettyLinks\GroundLevel\Database\Database;
use PrettyLinks\GroundLevel\Database\DatabaseServiceProvider;
use PrettyLinks\GroundLevel\Support\Models\Hook;
use PrettyLinks\GroundLevel\Support\Str;

/**
 * Service provider for the Events component.
 *
 * Registers the Events database service.
 */
class EventsServiceProvider extends ServiceProvider
{
    /**
     * Service identifier for the Events database.
     */
    public const SERVICE_DATABASE = 'events.database';

    /**
     * Parameter key for the events database prefix.
     */
    public const PARAM_PREFIX = 'events.prefix';

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
            self::PARAM_PREFIX => 'events_',
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
                    $database = new Database(EventsServiceProvider::SERVICE_DATABASE);

                    if ($c->has(DatabaseServiceProvider::PARAM_CONNECTION)) {
                        $database->setConnection($c->get(DatabaseServiceProvider::PARAM_CONNECTION));
                    }

                    return $database
                        ->setPrefix($c->get(EventsServiceProvider::PARAM_PREFIX))
                        ->registerSchemaPath(
                            Str::trailingslashit(dirname(__FILE__)) . 'schemas'
                        )
                        ->autoRegisterSchemas();
                }
            ),
        ];
    }

    /**
     * Configure WordPress hooks.
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
                5 // After the DB module which installs at 3.
            ),
        ];
    }
}
