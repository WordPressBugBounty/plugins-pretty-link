<?php

declare(strict_types=1);

namespace PrettyLinks\GroundLevel\Events\Models;

use PrettyLinks\GroundLevel\Database\Models\PersistedModel;
use PrettyLinks\GroundLevel\Events\EventsServiceProvider;

/**
 * Events model.
 */
class Event extends PersistedModel
{
    /**
     * The table name.
     */
    public const TABLE_NAME = 'events';

    /**
     * The table's name.
     *
     * @var string
     */
    protected string $tableName = self::TABLE_NAME;

    /**
     * The name of the table's database.
     *
     * @var string
     */
    protected string $databaseName = EventsServiceProvider::SERVICE_DATABASE;

    /**
     * Keyname of the item's updated date.
     *
     * If the item does not support an updated date, this should be set to an
     * empty string.
     *
     * @var string
     */
    protected string $keyDateUpdated = '';
}
