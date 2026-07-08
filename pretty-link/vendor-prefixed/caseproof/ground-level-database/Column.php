<?php

declare(strict_types=1);

namespace PrettyLinks\GroundLevel\Database;

use PrettyLinks\GroundLevel\Support\Contracts\Arrayable;

/**
 * Database table column model.
 */
class Column implements Arrayable
{
    /**
     * Precomposed column type for ID columns.
     *
     * Equivalent to: bigint(20) UNSIGNED NOT NULL
     */
    public const TYPE_ID = 'id';

    /**
     * Precomposed column type for primary ID columns.
     *
     * Equivalent to: bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT
     */
    public const TYPE_PRIMARY_ID = 'primary_id';

    /**
     * Precomposed column type for optional ID columns.
     *
     * Equavalent to: bigint(20) UNSIGNED DEFAULT NULL
     */
    public const TYPE_NULLABLE_ID = 'nullable_id';

    /**
     * Whether or not the column allows a `null` value.
     *
     * @var boolean
     */
    protected bool $allowNull = true;

    /**
     * Whether or not the column is auto-incremented.
     *
     * @var boolean
     */
    protected bool $autoIncrement = false;

    /**
     * The column's default value.
     *
     * @var null|string|integer|float
     */
    protected $default = null;

    /**
     * Column description.
     *
     * This is *not* added to the table as a comment, instead it is used for
     * internal and public-facing documentation of the table's schema.
     *
     * @var string
     */
    protected string $description = '';

    /**
     * The column's length.
     *
     * Data types which don't allow length specification should use `null`.
     *
     * @var integer|array|null
     */
    protected $length = null;

    /**
     * The column's name.
     *
     * @var string
     */
    protected string $name;

    /**
     * The column's type.
     *
     * Any valid MySQL column type may be provided.
     *
     * Additional "pre-composed" types can be specified as a shorthand, allowing
     * a partial column definition, {@see Column::prepareSchema}.
     *
     * @link https://dev.mysql.com/doc/refman/8.0/en/data-types.html
     *
     * @var string
     */
    protected string $type = DataType::VARCHAR;

    /**
     * Whether or not a numeric column is unsigned.
     *
     * @var boolean|null
     */
    protected ?bool $unsigned = null;

    /**
     * Initializes a database table column object.
     *
     * @since [version]
     *
     * @param string $name   The column name.
     * @param array  $schema The column's settings.
     */
    public function __construct(string $name, array $schema = [])
    {
        $this->name = $name;

        // Setup object props.
        foreach ($this->prepareSchema($schema) as $key => $val) {
            if (property_exists($this, $key)) {
                $this->$key = $val;
            }
        }

        if (is_null($this->default) && $this->allowNull) {
            $this->default = 'NULL';
        }
    }

    /**
     * Configures a schema array according to pre-composed types.
     *
     * There are two valid pre-composed types: `id` and `primary_id`. These types
     * follow the WordPress core pattern for column IDs. The `primary_id` is
     * intended to be the primary key on a table and the `id` is used when it
     * is referencing the `primary_id` on another table.
     *
     * For example, the user post meta table uses the `primary_id` type on its
     * `meta_id` column (the primary key) and uses the `id` type for the `user_id`
     * and `post_id` columns which reference the primary IDs on the `wp_users`
     * and `wp_posts` tables, respectively.
     *
     * @since [version]
     *
     * @param  array $schema The provided schema.
     * @return array
     */
    protected function prepareSchema(array $schema): array
    {
        $type = $schema['type'] ?? $this->type;

        $precomposed = $this->getPrecomposedSchemas();
        if (array_key_exists($type, $precomposed)) {
            unset($schema['type']);
            return array_merge($precomposed[$type], $schema);
        }

        return $schema;
    }

    /**
     * Retrieves an SQL statement used when creating the table the column's table.
     *
     * @since [version]
     *
     * @return string
     */
    public function getCreateString(): string
    {
        $type = $this->type;
        if (!empty($this->length)) {
            $length = is_array($this->length) ? implode(',', $this->length) : $this->length;
            $type  .= "({$length})";
        }

        $default = '';
        if (! is_null($this->default)) {
            $unquote    = 'NULL' === $this->default || is_int($this->default) || is_float($this->default);
            $defaultVal = $unquote ? $this->default : "'{$this->default}'";
            $default    = "DEFAULT {$defaultVal}";
        }

        $parts = [
            "`{$this->name}`",
            $type,
            $this->unsigned ? 'UNSIGNED' : '',
            ! $this->allowNull ? 'NOT NULL' : '',
            $default,
            $this->autoIncrement ? 'AUTO_INCREMENT' : '',
        ];

        return implode(' ', array_filter($parts));
    }

    /**
     * Retrieves whether or not `AUTO_INCREMENT` is enabled for the column.
     *
     * @since [version]
     *
     * @return boolean
     */
    public function getAutoIncrement(): bool
    {
        return $this->autoIncrement;
    }

    /**
     * Retrieves whether column value can be `null`.
     *
     * @since [version]
     *
     * @return boolean
     */
    public function getAllowNull(): bool
    {
        return $this->allowNull;
    }

    /**
     * Retrieves the column's default value.
     *
     * A `null` return denotes there is no default value.
     *
     * If the default value is `null`, the string `NULL` will be returned.
     *
     * @since [version]
     *
     * @return null|string|float|integer
     */
    public function getDefault()
    {
        return $this->default;
    }

    /**
     * Retrieves the column's default string as displayed by a MySql `DESCRIBE` query.
     *
     * @return string
     */
    public function getDescribeDefault(): string
    {
        return (string) $this->getDefault() ?? '';
    }

    /**
     * Retrieves the column's Extra string as displayed by a MySql `DESCRIBE` query.
     *
     * @return string
     */
    public function getDescribeExtra(): string
    {
        return $this->getAutoIncrement() ? 'auto_increment' : '';
    }

    /**
     * Retrieves the column's Key string as displayed by a MySql `DESCRIBE` query.
     *
     * @param  array $keys Array of keys from the table's schema.
     * @return string
     */
    public function getDescribeKey(array $keys): string
    {
        $keyConfig = $keys[$this->getName()] ?? null;
        if (is_null($keyConfig)) {
            return '';
        }

        if (Table::KEY_DEFAULT === $keyConfig['type']) {
            return 'MUL';
        }

        return strtoupper(substr($keyConfig['type'], 0, 3));
    }

    /**
     * Retrieves the column's Null string as displayed by a MySql `DESCRIBE` query.
     *
     * @return string
     */
    public function getDescribeNull(): string
    {
        return $this->getAllowNull() ? 'YES' : 'NO';
    }

    /**
     * Retrieves the column's type string as displayed by a MySql `DESCRIBE` query.
     *
     * @return string
     */
    public function getDescribeType(): string
    {
        $str    = $this->getType();
        $length = $this->getLength();
        if ($length) {
            $length = is_array($length) ? implode(',', $length) : $length;
            $str   .= "({$length})";
        }

        if ($this->getUnsigned()) {
            $str .= ' unsigned';
        }

        return $str;
    }

    /**
     * Retrieves the column's description string.
     *
     * @return string
     */
    public function getDescription(): string
    {
        return $this->description;
    }

    /**
     * Retrieves the columns length.
     *
     * Columns that don't support lengths, such as `datetime` will return `null`.
     *
     * @since [version]
     *
     * @return integer|int[]|null
     */
    public function getLength()
    {
        return $this->length;
    }

    /**
     * Retrieves the column name.
     *
     * @since [version]
     *
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Retrieves schema defnition arrays for all pre-composed types.
     *
     * @return array
     */
    protected function getPrecomposedSchemas(): array
    {
        $id = [
            'type'      => 'bigint',
            'length'    => 20,
            'unsigned'  => true,
            'allowNull' => false,
        ];

        return [
            self::TYPE_ID          => $id,
            self::TYPE_PRIMARY_ID  => array_merge(
                $id,
                ['autoIncrement' => true]
            ),
            self::TYPE_NULLABLE_ID => array_merge(
                $id,
                ['allowNull' => true]
            ),
        ];
    }

    /**
     * Retrieves the columns type.
     *
     * @since [version]
     *
     * @return string
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * Retrieves whether or not the column is unsigned.
     *
     * Non-numeric column types will return `null`.
     *
     * @since [version]
     *
     * @return boolean|null
     */
    public function getUnsigned()
    {
        return $this->unsigned;
    }

    /**
     * Converts the object into an associative array.
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'allowNull'     => $this->getAllowNull(),
            'description'   => $this->getDescription(),
            'default'       => $this->getDefault(),
            'autoIncrement' => $this->getAutoIncrement(),
            'length'        => $this->getLength(),
            'name'          => $this->getName(),
            'type'          => $this->getType(),
            'unsigned'      => $this->getUnsigned(),
        ];
    }

    /**
     * Validates the column schema.
     *
     * Ensures that the column name is a valid length and doesn't contain any illegal
     * characters and ensures the column type is a valid defined column type.
     *
     * @return int[] Returns an empty array for a valid schema, otherwise returns an array
     *               of error codes. See self::E_* constants for possible error codes.
     */
    public function validate(): array
    {
        // Name validation.
        $errors = Database::validateIdentifier($this->getName());

        // Type validation.
        $types = array_merge(
            array_values(DataType::cases()),
            array_keys($this->getPrecomposedSchemas())
        );
        if (! in_array($this->getType(), $types, true)) {
            $errors[] = Database::E_DATA_TYPE_INVALID;
        }

        return $errors;
    }
}
