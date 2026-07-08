<?php

declare(strict_types=1);

namespace PrettyLinks\GroundLevel\Database;

/**
 * Database table upgrader.
 */
class TableUpgrade
{
    /**
     * If true, the table upgrade will be performed automatically during package
     * initialization.
     *
     * @var boolean
     */
    protected bool $autoUpgrade = true;

    /**
     * The upgrade steps.
     *
     * @var array
     */
    protected array $steps = [];

    /**
     * Reference to the Table object.
     *
     * @var \PrettyLinks\GroundLevel\Database\Table
     */
    protected Table $table;

    /**
     * The upgrade version string.
     *
     * @var string
     */
    protected string $version;

    /**
     * Constructs a new instance of the class.
     *
     * @param  \PrettyLinks\GroundLevel\Database\Table $table         The table object.
     * @param  array                       $upgradeConfig The upgrade configuration.
     * @return void
     */
    public function __construct(Table $table, array $upgradeConfig = [])
    {
        $this->table = $table;

        $config = array_merge(
            [
                'version'     => '',
                'autoUpgrade' => true,
                'async'       => false,
                'steps'       => [],
            ],
        );
    }

    /**
     * Completes the table upgrade process by updating the version and updated timestamp in the 'tables' table.
     *
     * @throws \Exception If the connection to the internal database fails or if the internal database is not available.
     * @return void
     */
    public function complete(): void
    {
        /*
         * @var Database $db
         */
        $db    = Factory::internalDatabase();
        $table = $db->getTable('tables');
        $db->update(
            $table->getPrefixedName(),
            [
                'version' => $this->version,
                'updated' => gmdate('Y-m-d H:i:s'),
            ]
        );
    }

    /**
     * Dispatches the upgrade.
     *
     * @return void
     */
    public function dispatch(): void
    {
    }
}
