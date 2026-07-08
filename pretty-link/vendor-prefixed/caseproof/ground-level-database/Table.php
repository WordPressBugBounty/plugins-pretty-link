<?php

declare(strict_types=1);

namespace PrettyLinks\GroundLevel\Database;

use Exception;
use PrettyLinks\GroundLevel\Database\Concerns\UsesConnection;
use PrettyLinks\GroundLevel\Database\Contracts\ConnectionAwareness;
use PrettyLinks\GroundLevel\Database\Concerns\QueriesTable;
use PrettyLinks\GroundLevel\Database\Exceptions\ModelError;
use PrettyLinks\GroundLevel\Database\Exceptions\QueryError;
use PrettyLinks\GroundLevel\Database\Exceptions\TableError;
use PrettyLinks\GroundLevel\Database\Models\InternalTable;
use PrettyLinks\GroundLevel\Support\Time;

/**
 * Manages custom database tables.
 */
class Table implements ConnectionAwareness
{
    use QueriesTable;
    use UsesConnection;

    /**
     * Index type: default.
     */
    public const KEY_DEFAULT = 'default';

    /**
     * Index type: primary key.
     */
    public const KEY_PRIMARY = 'primary';

    /**
     * Index type: unique.
     */
    public const KEY_UNIQUE = 'unique';

    /**
     * References the Database instance for the table.
     *
     * @var Database
     */
    protected Database $db;

    /**
     * Table description.
     *
     * This is used for documentation purposes only.
     *
     * @var string
     */
    protected string $description = '';

    /**
     * The table's unprefixed name.
     *
     * @var string
     */
    protected string $name;

    /**
     * The database table schema definition.
     *
     * @var array
     */
    protected array $schema;

    /**
     * Creates a new Table instance.
     *
     * @param array    $schema   The schema definition for the table.
     * @param Database $database The database instance.
     */
    public function __construct(array $schema, Database $database)
    {
        $this->schema      = $schema;
        $this->name        = $schema['name'];
        $this->description = $schema['description'] ?? '';
        $this->db          = $database;
    }

    /**
     * Creates the table from its schema definition.
     *
     * @return boolean Returns true on success
     * @throws \PrettyLinks\GroundLevel\Database\Exceptions\TableError Throws exception on error.
     */
    public function create(): bool
    {
        try {
            $result = $this->db->query($this->getCreateStatement());
        } catch (QueryError $err) {
            throw new TableError(
                "Could not create table {$this->getPrefixedName()}.",
                TableError::E_TABLE_CREATE,
                $err,
                ['$table' => $this]
            );
        }
        try {
            // PL strauss-fixup: idempotent registry write. The SELECT-then-INSERT
            // in PersistedModel::save() can race concurrent requests during the
            // install window and fatal the loser with a duplicate-key error. The
            // row exists at that point, so treat a duplicate as success.
            InternalTable::init(
                [
                    'name'     => $this->getPrefixedName(),
                    'database' => $this->db->getId(),
                    'version'  => $this->getSchemaVersion() ?? Time::now('Ymd') . '.0',
                ]
            )->save();
        } catch (\Throwable $err) {
            // PersistedModel::create() re-wraps the duplicate-key QueryError as a
            // ModelError before it unwinds back here, so match on the message
            // rather than the exception type. Re-throw anything that isn't a
            // duplicate-key error.
            if (strpos($err->getMessage(), 'Duplicate entry') === false) {
                throw $err;
            }
        }
        return $result;
    }

    /**
     * Attempts to drop the table.
     *
     * @return boolean
     * @throws \PrettyLinks\GroundLevel\Database\Exceptions\TableError Throws exception on error.
     */
    public function drop(): bool
    {
        $name = $this->getPrefixedName();
        try {
            $result = $this->db->query("DROP TABLE IF EXISTS {$name};");
        } catch (QueryError $err) {
            throw new TableError(
                "Could not drop table {$this->getPrefixedName()}.",
                TableError::E_TABLE_DROP,
                $err,
                ['$table' => $this]
            );
        }
        return $result;
    }

    /**
     * Attempts to truncate the table.
     *
     * @return boolean
     * @throws \PrettyLinks\GroundLevel\Database\Exceptions\TableError Throws exception on error.
     */
    public function truncate(): bool
    {
        $name = $this->getPrefixedName();
        try {
            $result = $this->db->query("TRUNCATE TABLE {$name};");
        } catch (QueryError $err) {
            throw new TableError(
                "Could not truncate table {$this->getPrefixedName()}.",
                TableError::E_TABLE_TRUNCATE,
                $err,
                ['$table' => $this]
            );
        }
        return $result;
    }

    /**
     * Retrieves a column object for a registered column by column name.
     *
     * @param  string $column The column name.
     * @return null|Column Returns the Column instance or null if the column
     *                     doesn't exist.
     */
    public function getColumn(string $column)
    {
        $schema = $this->schema['columns'][$column] ?? null;
        if (is_null($schema)) {
            return null;
        }
        return new Column($column, $schema);
    }

    /**
     * Retrieves an array of Column objects for the columns defined in the table.
     *
     * @return Column[] An array of column objects keyed by the column name/ID.
     */
    public function getColumns(): array
    {
        $keys = array_keys($this->schema['columns']);
        $cols = array_map(
            [$this, 'getColumn'],
            $keys
        );
        return array_combine($keys, $cols);
    }

    /**
     * Retrieves a table key/index creation string.
     *
     * @param  string       $name The key/index name.
     * @param  string|array $cfg  The key configuration array or a shorthand string.
     *                           More at {@see Table::setupKey}.
     * @return string
     */
    protected function getCreateKeyString(string $name, $cfg = []): string
    {
        $cfg = $this->setupKey($name, $cfg);

        $key = 'KEY';
        if (in_array($cfg['type'], [self::KEY_PRIMARY, self::KEY_UNIQUE], true)) {
            $key = strtoupper($cfg['type']) . " {$key}";
        }

        $parts = [];
        foreach ($cfg['parts'] as $col => $len) {
            $part    = "`{$col}`";
            $parts[] = is_null($len) ? $part : "$part({$len})";
        }

        $parts = implode(',', $parts);
        $name  = self::KEY_PRIMARY === $cfg['type'] ? '' : "`{$name}` ";

        return "{$key} {$name}({$parts})";
    }

    /**
     * Retrieves a table creation SQL statement.
     *
     * @return string Returns the table creation statement.
     */
    public function getCreateStatement(): string
    {
        $opts = $this->db->getTableOptions();
        $name = $this->getPrefixedName();
        $cols = array_map(
            function (string $key): string {
                return $this->getColumn($key)->getCreateString();
            },
            array_keys($this->schema['columns'])
        );
        $keys = array_map(
            [$this, 'getCreateKeyString'],
            array_keys($this->schema['keys']),
            array_values($this->schema['keys'])
        );

        $str  = "CREATE TABLE IF NOT EXISTS `{$name}` (" . PHP_EOL . '  ';
        $str .= implode(
            ',' . PHP_EOL . '  ',
            array_filter(
                array_merge($cols, $keys)
            )
        );
        $str .= PHP_EOL . ") {$opts};";

        return $str;
    }

    /**
     * Retrieves the current version of the database as stored in the internal
     * table meta table.
     *
     * @return null|string The version number as a string. If there's no version stored
     *                     in the database, null is returned, this would occur if the
     *                     table hasn't been installed yet.
     * @throws \PrettyLinks\GroundLevel\Database\Exceptions\QueryError Throws exception on error.
     */
    public function getCurrentVersion(): ?string
    {
        $model   = $this->getPersistedModel();
        $version = $model ? $model->getAttribute('version') : null;
        return ! empty($version) ? (string) $version : null;
    }

    /**
     * Retrieves the table's database instance.
     *
     * @return Database
     */
    public function getDatabase(): Database
    {
        return $this->db;
    }

    /**
     * Retrieves the table's description property.
     *
     * @return string
     */
    public function getDescription(): string
    {
        return $this->description;
    }

    /**
     * Retrieves the table's name.
     *
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Retrieves an InternalTable object for the table.
     *
     * @return \PrettyLinks\GroundLevel\Database\Models\InternalTable|null
     * @throws \PrettyLinks\GroundLevel\Database\Exceptions\ModelError Throws exception on model not found.
     */
    protected function getPersistedModel(): ?InternalTable
    {
        try {
            $model = InternalTable::find($this->getName());
        } catch (ModelError $err) {
            if (! $err->isRecordNotFound()) {
                throw $err;
            }
            $model = null;
        }
        return $model;
    }

    /**
     * Retrieves the full table name with prefixes.
     *
     * @return string
     */
    public function getPrefixedName(): string
    {
        return $this->getConnection()->prefix . $this->getDatabase()->getPrefix() . $this->name;
    }

    /**
     * Retrieves the column object for the primary key column.
     *
     * @return null|Column Returns the table's primary key Column or null if a primary
     *                     key isn't defined.
     */
    public function getPrimaryKey(): ?Column
    {
        foreach ($this->getSchema(false)['keys'] as $key => $cfg) {
            if (self::KEY_PRIMARY === $cfg['type']) {
                return $this->getColumn($key);
            }
        }
        return null;
    }

    /**
     * Retrieves a filter upgrade array containing only the upgrades that need to
     * be run on the table.
     *
     * Note that this method will return an empty array if the table isn't installed,
     * therefore you should always check if the table is installed before checking
     * if it needs an upgrade.
     *
     * @return array[]
     */
    public function getRequiredUpgrades(): array
    {
        $version  = $this->getCurrentVersion();
        $upgrades = $this->getUpgrades();
        if (is_null($version) || empty($upgrades)) {
            return [];
        }

        $required = array_filter(
            $upgrades,
            function (string $upgradeVersion) use ($version): bool {
                return version_compare($version, $upgradeVersion, '<');
            },
            ARRAY_FILTER_USE_KEY
        );
        return $required;
    }

    /**
     * Retrieves the table's schema definition array.
     *
     * @param  boolean $raw Whether to return the raw schema or to return the fully
     *                      computed schema. Computing the schema will return
     *                      the composed versions of any pre-composed column types
     *                      and explicitly return default values which may be left
     *                      undefined on the raw schema.
     * @return array
     */
    public function getSchema(bool $raw = true): array
    {
        if ($raw) {
            return $this->schema;
        }

        $schema = $this->schema;
        foreach ($schema['columns'] as $key => &$col) {
            $col = $this->getColumn($key)->toArray();
        }

        foreach ($schema['keys'] as $key => &$col) {
            $col = $this->setupKey($key, $col);
        }

        return $schema;
    }

    /**
     * Retrieves the table's latest version as defined in the schema.
     *
     * @return null|string The defined schema version or null if no version is defined.
     */
    public function getSchemaVersion(): ?string
    {
        return $this->schema['version'] ?? null;
    }

    /**
     * Retrieves the full table name with prefixes.
     *
     * @return string
     */
    protected function getTableName(): string
    {
        return $this->getPrefixedName();
    }

    /**
     * Returns an array of table upgrades as defined on the table's schema.
     *
     * @return array[] An array of table upgrade arrays. Each array key corresponds
     *                 to the database version and the array value should be the
     *                 classname of the table's upgrade class.
     */
    public function getUpgrades(): array
    {
        return $this->schema['upgrades'] ?? [];
    }

    /**
     * Creates the table in the database.
     *
     * @return boolean Returns true on success or false when the table is already installed.
     * @throws \PrettyLinks\GroundLevel\Database\Exceptions\QueryError Throws exception on error.
     */
    public function install(): bool
    {
        if (! $this->isInstalled()) {
            return $this->create();
        }
        return false;
    }

    /**
     * Determines if the table is installed.
     *
     * @return boolean
     */
    public function isInstalled(): bool
    {
        $result = (int) $this->db->query(
            'SHOW TABLES LIKE %s',
            [$this->getPrefixedName()]
        );
        return 1 === $result;
    }

    /**
     * Determines if the table has required upgrades.
     *
     * Note that this method will return an false array if the table is not yet
     * so it's advised to check if a table is installed before checking if upgrades
     * are required.
     *
     * @return boolean
     */
    public function requiresUpgrades(): bool
    {
        return ! empty($this->getRequiredUpgrades());
    }

    /**
     * Sets the table's database instance.
     *
     * @param  Database $db The database instance.
     * @return Table
     */
    public function setDatabase(Database $db): Table
    {
        $this->db = $db;
        return $this;
    }

    // phpcs:disable Squiz.Commenting.FunctionComment.ParamCommentNotCapital
    /**
     * Configures a table key.
     *
     * Allows usage of shorthand schema definitions for table keys.
     *
     * @param string       $name The key name. If defining a primary key, this should be the
     *                           name of a column in the table.
     * @param string|array $cfg  {
     *     The key configuration array or a shorthand string. When passing a string it should
     *     be one of the `Table::KEY_*` constants. If a string is passed,
     *     the rest of the configuration is assumed to be the default.
     *
     * @type   string   $type   The key type, one of {@see Table::KEY_*} constants.
     * @type   null|int $length The key length. If not supplied no length restraint will be imposed.
     * @type   array    $parts  An associative array of key parts. The array key must correspond to
     *                            a table column and the array value should be the key part length. As
     *                            with `$length`, if not supplied no length restraint will be imposed.
     *                            If not supplied, the array defaults to the keys name and defined length.
     * }
     * @return array Returns a full configuration array as defined by the $cfg parameter.
     */
    protected function setupKey(string $name, $cfg = []): array
    {
        $cfg = is_string($cfg) ? ['type' => $cfg] : $cfg;
        $cfg = array_merge(
            [
                'type'   => self::KEY_DEFAULT,
                'length' => null,
                'parts'  => [],
            ],
            $cfg
        );

        if (empty($cfg['parts'])) {
            $cfg['parts'][ $name ] = $cfg['length'];
        }

        return $cfg;
    }
    // phpcs:enable Squiz.Commenting.FunctionComment.ParamCommentNotCapital

    /**
     * Upgrades the table if it is installed and requires upgrades.
     *
     * @return boolean Returns true if the table is upgraded successfully.
     * @throws Exception If the table class extending the base table class doesn't override it.
     */
    public function upgrade(): bool
    {
        throw new Exception('Not implemented');
        return true;

        // phpcs:disable Squiz.Commenting.InlineComment.NotCapital
        // phpcs:disable Squiz.Commenting.InlineComment.InvalidEndChar
        // if (! $this->isInstalled()) {
        // throw new DatabaseTableError(
        // "Cannot upgrade uninstalled table {$this->getPrefixedName()}.",
        // Errors::TABLE_NOT_FOUND,
        // null,
        // [
        // '$table' => $this,
        // ],
        // );
        // }
        // if (! $this->requiresUpgrades()) {
        // return true;
        // }
        // $upgrades = $this->getRequiredUpgrades();
        // foreach ($upgrades as $version => $upgradeConfig) {
        // $upgrader = new TableUpgrade($this, $upgradeConfig);
        // }
        // return true;
        // phpcs:enable Squiz.Commenting.InlineComment.NotCapital
        // phpcs:enable Squiz.Commenting.InlineComment.InvalidEndChar
    }

    /**
     * Validates the table schema.
     *
     * The table name must be valid according to the database identifier specifications
     * via {@see Database::validateIdentifier}.
     *
     * Additionally, all columns must validate via {@see Column::validate}.
     *
     * @return array Returns an empty array when the table is valid, otherwise
     *               returns an array of arrays containing error codes for the
     *               validation errors found. Array keys will define where the
     *               error was encountered. Either 'name' if the table name was
     *               the issue or 'column-$colName' where $colName is the ID of
     *               the column where the validation issue was found. All the
     *               error codes can be found on the {@see Errors} class.
     */
    public function validate(): array
    {
        $errors = [];

        $nameErrors = Database::validateIdentifier($this->getPrefixedName());
        if (! empty($nameErrors)) {
            $errors['name'] = $nameErrors;
        }

        foreach ($this->getColumns() as $colName => $col) {
            $colErrors = $col->validate();
            if (! empty($colErrors)) {
                $errors["column-{$colName}"] = $colErrors;
            }
        }

        foreach (array_keys($this->schema['keys']) as $indexName) {
            $indexErrors = Database::validateIdentifier($indexName);
            if (! empty($indexErrors)) {
                $errors["key-{$indexName}"] = $indexErrors;
            }
        }

        return $errors;
    }
}
