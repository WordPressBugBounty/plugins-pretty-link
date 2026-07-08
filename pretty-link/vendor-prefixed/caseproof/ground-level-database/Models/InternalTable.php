<?php

declare(strict_types=1);

namespace PrettyLinks\GroundLevel\Database\Models;

use PrettyLinks\GroundLevel\Database\Database;

/**
 * Internal database table model.
 */
class InternalTable extends PersistedModel
{
    /**
     * The table name.
     */
    public const TABLE_NAME = 'tables';

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
    protected string $databaseName = Database::class;

    /**
     * The model's type.
     *
     * @var string
     */
    protected string $type = 'InternalTable';
}
