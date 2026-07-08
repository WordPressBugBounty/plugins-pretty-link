<?php

declare(strict_types=1);

namespace PrettyLinks\GroundLevel\Database;

use PrettyLinks\GroundLevel\Database\Concerns\UsesConnection;
use PrettyLinks\GroundLevel\Database\Contracts\ConnectionAwareness;
use PrettyLinks\GroundLevel\Database\Exceptions\ConnectionError;
use PrettyLinks\GroundLevel\Database\Concerns\QueriesDatabase;
use PrettyLinks\GroundLevel\Support\Str;

/**
 * Main interface for managing a package's database tables
 */
class Database implements ConnectionAwareness
{
    use QueriesDatabase;
    use UsesConnection;

    /**
     * The maximum character count for database identifiers such as table, column,
     * and index names.
     *
     * @link https://dev.mysql.com/doc/refman/8.0/en/identifier-length.html
     *
     * @var int
     */
    public const IDENTIFIER_MAX_LENGTH = 64;

    /**
     * Regex pattern which defines the character allowed in a database identifier
     * such as table, column, and index names.
     *
     * @link https://dev.mysql.com/doc/refman/8.0/en/identifiers.html
     */
    public const IDENTIFIER_ALLOWED_CHARS = '0-9a-z,A-Z$_';

    /**
     * Error code: A specified data type is invalid.
     *
     * Valid types defined in {@see DataType} or must be a precomposed type defined
     * on {@see Column}.
     */
    public const E_DATA_TYPE_INVALID = 500;

    /**
     * The database identifier is too long.
     *
     * Used to validate identifiers such as table, column, and index names.
     */
    public const E_IDENTIFIER_LENGTH = 10001;

    /**
     * Identifier contains invalid characters.
     *
     * Valid characters are defined in {@see Database::IDENTIFIER_INVALID_CHARS}.
     */
    public const E_IDENTIFIER_INVALID_CHARS = 10002;

    /**
     * Database ID.
     *
     * @var string
     */
    protected string $id;

    /**
     * Table prefix.
     *
     * @var string
     */
    protected string $prefix;

    /**
     * List of directories where schema files are stored.
     *
     * @var array
     */
    protected array $schemaPaths = [];

    /**
     * An array of registered table schemas.
     *
     * @var array[]
     */
    protected array $tables = [];

    /**
     * The database table options string.
     *
     * @var string
     */
    protected ?string $tableOptions;

    /**
     * Constructor
     *
     * @param string $id The database ID.
     */
    public function __construct(string $id)
    {
        $this->id = $id;
        $this->setPrefix();
    }

    /**
     * Registers all tables contained within the database's registered schema paths.
     *
     * @return self
     */
    public function autoRegisterSchemas(): self
    {
        foreach ($this->schemaPaths as $schemaDir) {
            foreach (glob("{$schemaDir}*.php", GLOB_NOSORT) as $schemaFile) {
                $schema = require $schemaFile;
                $this->registerTable($schema['name']);
            }
        }
        return $this;
    }

    /**
     * Retrieves the value of the ID property.
     *
     * @return string
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * Retrieves the internal table prefix.
     *
     * @return string
     */
    public function getPrefix(): string
    {
        return $this->prefix;
    }

    /**
     * Retrieves a table's schema.
     *
     * @param  string $tableName The unprefixed table name.
     * @return boolean|array Returns the table's schema or false if the table's
     *                       schema can't be found.
     */
    public function getSchema(string $tableName)
    {
        if ($this->isTableRegistered($tableName)) {
            return $this->tables[$tableName];
        }

        $schemaFile = $this->locateSchemaFile($tableName);
        if (! $schemaFile) {
            return false;
        }

        $schema = require $schemaFile;
        return $schema;
    }

    /**
     * Retrieves the table object for a given table.
     *
     * @param  string $tableName The table's name.
     * @return Table|false Returns the table object or false if the table's schema
     *                     cannot be found.
     */
    public function getTable(string $tableName)
    {
        $schema = $this->getSchema($tableName);
        if (! $schema) {
            return false;
        }
        return (new Table($schema, $this))
                ->setConnection($this->getConnection());
    }

    /**
     * Retrieves a list of the registered tables.
     *
     * @param  boolean $names If true, returns an array of table names, otherwise
     *                       returns an array of Table objects.
     * @return string[]|Table[]
     */
    public function getTables(bool $names = false): array
    {
        $ids = array_keys($this->tables);
        if ($names) {
            return $ids;
        }
        return array_combine(
            $ids,
            array_map(
                [$this, 'getTable'],
                $ids
            )
        );
    }

    /**
     * Retrieves the database table options SQL string.
     *
     * @return string
     * @throws ConnectionError Thrown if the database is not connected.
     */
    public function getTableOptions(): string
    {
        if (is_null($this->getConnection())) {
            throw ConnectionError::notConnected();
        }

        if (isset($this->tableOptions)) {
            return $this->tableOptions;
        }

        $conn = $this->getConnection();

        if (! $conn->has_cap('collation')) {
            $this->tableOptions = '';
            return $this->tableOptions;
        }

        $opts = [];
        if (! empty($conn->charset)) {
            $opts[] = "DEFAULT CHARACTER SET {$conn->charset}";
        }
        if (! empty($conn->collate)) {
            $opts[] = "COLLATE {$conn->collate}";
        }

        $this->tableOptions = implode(' ', $opts);
        return $this->tableOptions;
    }

    /**
     * Determines if the specified table is registered.
     *
     * @param  string $tableName The table name.
     * @return boolean
     */
    public function isTableRegistered(string $tableName): bool
    {
        return array_key_exists($tableName, $this->tables);
    }

    /**
     * Installs all tables regisetered with the database.
     *
     * @return boolean Returns true on success.
     * @throws \PrettyLinks\GroundLevel\Database\Exceptions\QueryError When a query error occurs.
     */
    public function install(): bool
    {
        foreach ($this->getTables() as $table) {
            $table->install();
        }
        return true;
    }

    /**
     * Locates a schema file for the specified table.
     *
     * @param  string $tableName The table name.
     * @return boolean|string The full path to the schema file or false if the
     *                        no schema file could be found.
     */
    protected function locateSchemaFile(string $tableName)
    {
        $tableName = str_replace('_', '-', $tableName);
        foreach ($this->schemaPaths as $path) {
            $file = $path . $tableName . '.php';
            if (file_exists($file)) {
                return $file;
            }
        }
        return false;
    }

    /**
     * Adds a directory to the list of directories to use for schema file lookups.
     *
     * @param  string $path The directory path.
     * @return self
     */
    public function registerSchemaPath(string $path): self
    {
        $this->schemaPaths[] = Str::trailingslashit($path);
        return $this;
    }

    /**
     * Registers a table by name.
     *
     * @param  string $tableName The name of the table.
     * @return boolean|null Returns true on success, false if the schema can't be
     *                      found, and null if the table is already registered.
     * @throws ConnectionError When not connected.
     */
    public function registerTable(string $tableName): ?bool
    {
        $conn = $this->getConnection();
        if (is_null($conn)) {
            throw ConnectionError::notConnected();
        }

        // Table is already registered.
        if ($this->isTableRegistered($tableName)) {
            return null;
        }

        $schema = $this->getSchema($tableName);
        // Schema definition doesn't exist for the table.
        if (! $schema) {
            return false;
        }

        // Schema doesn't belong to this database.
        if (($schema['database'] ?? null) !== $this->getId()) {
            return false;
        }

        // Register the table.
        $this->tables[$tableName] = $schema;

        // Register the table with the Connection object database.
        $prefixed        = $this->prefix . $tableName;
        $conn->$prefixed = $conn->prefix . $prefixed;
        $conn->tables[]  = $prefixed;

        return true;
    }

    /**
     * Sets the table prefix string.
     *
     * The table prefix, like the table name, may only contain Latin letters, numbers,
     * the dollar sign character, and the underscore character. All invalid characters
     * are automatically stripped from the supplied prefix string.
     *
     * If the prefix doesn't end in an underscore, an underscore will automatically
     * be appended to the end of the string.
     *
     * @link https://dev.mysql.com/doc/refman/8.0/en/identifiers.html
     *
     * @param  string|null $prefix The table prefix string.
     * @return self
     */
    public function setPrefix(?string $prefix = null): self
    {
        $prefix = str_replace('-', '_', $prefix ? $prefix : $this->id);
        $prefix = preg_replace(
            '/[^' . self::IDENTIFIER_ALLOWED_CHARS . ']+/',
            '',
            strtolower($prefix)
        );

        $this->prefix = '_' === substr($prefix, -1) ? $prefix : $prefix . '_';
        return $this;
    }

    /**
     * Validates an identifier string.
     *
     * This is used to validate table identifiers such as the table or column names
     *
     * This isn't a full validation against the MySQL specifications but a simplified
     * specification.
     *
     * A valid identifier is 64 or fewer Latin characters, numbers, and the dollar
     * sign and underscore characters.
     *
     * @link https://dev.mysql.com/doc/refman/8.0/en/identifiers.html
     *
     * @param  string $identifier The identifier string to validate.
     * @return array
     */
    public static function validateIdentifier(string $identifier): array
    {
        $errors = [];

        if (! preg_match('/^[' . self::IDENTIFIER_ALLOWED_CHARS . ']+$/', $identifier)) {
            $errors[] = self::E_IDENTIFIER_INVALID_CHARS;
        }
        if (strlen($identifier) > self::IDENTIFIER_MAX_LENGTH) {
            $errors[] = self::E_IDENTIFIER_LENGTH;
        }

        return $errors;
    }
}
