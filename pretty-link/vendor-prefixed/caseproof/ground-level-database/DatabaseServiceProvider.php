<?php

declare(strict_types=1);

namespace PrettyLinks\GroundLevel\Database;

use PrettyLinks\GroundLevel\Container\Container;
use PrettyLinks\GroundLevel\Container\ServiceProvider;
use PrettyLinks\GroundLevel\Database\Models\PersistedModel;
use PrettyLinks\GroundLevel\Support\Models\Hook;
use PrettyLinks\GroundLevel\Support\Str;

/**
 * Service provider for the Database component.
 *
 * Registers the internal database service and sets up the database resolver
 * for PersistedModel access.
 */
class DatabaseServiceProvider extends ServiceProvider
{
    /**
     * Parameter key for the internal database prefix.
     */
    public const PARAM_PREFIX = 'database.prefix';

    /**
     * Parameter key for the database connection.
     */
    public const PARAM_CONNECTION = 'database.connection';

    /**
     * Returns default parameters.
     */
    public function parameters(): array
    {
        return [
            self::PARAM_PREFIX => 'grdlvl_',
        ];
    }

    /**
     * Returns the service definitions.
     */
    public function services(): array
    {
        return [
            $this->service(
                Database::class,
                self::SINGLETON,
                static function (Container $c): Database {
                    $database = new Database(Database::class);

                    if ($c->has(self::PARAM_CONNECTION)) {
                        $database->setConnection($c->get(self::PARAM_CONNECTION));
                    }

                    return $database
                        ->setPrefix($c->get(self::PARAM_PREFIX))
                        ->registerSchemaPath(
                            Str::trailingslashit(dirname(__FILE__)) . 'schemas'
                        )
                        ->autoRegisterSchemas();
                }
            ),
        ];
    }

    /**
     * Boot the provider.
     *
     * Sets up the database resolver for PersistedModel access.
     */
    public function boot(): void
    {
        // Set database resolver for PersistedModel static methods.
        PersistedModel::setDatabaseResolver(
            fn (string $name) => $this->container->get($name)
        );

        parent::boot();
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
                [$this->container->get(Database::class), 'install'],
                3
            ),
        ];
    }
}
