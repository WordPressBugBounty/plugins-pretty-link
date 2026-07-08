<?php

declare(strict_types=1);

namespace PrettyLinks\GroundLevel\Database\Models;

use Closure;
use PrettyLinks\GroundLevel\Database\Database;
use PrettyLinks\GroundLevel\Database\DataType;
use PrettyLinks\GroundLevel\Database\Exceptions\ModelError;
use PrettyLinks\GroundLevel\Database\Exceptions\QueryError;
use PrettyLinks\GroundLevel\Database\Table;
use PrettyLinks\GroundLevel\Database\Concerns\HasRelationships;
use PrettyLinks\GroundLevel\QueryBuilder\Query;
use PrettyLinks\GroundLevel\Support\Casts;
use PrettyLinks\GroundLevel\Support\Models\Model;
use PrettyLinks\GroundLevel\Support\Time;

/**
 * Persisted model.
 */
abstract class PersistedModel extends Model
{
    use HasRelationships;

    /**
     * The database resolver closure for resolving databases by name.
     *
     * @var Closure|null
     */
    protected static ?Closure $databaseResolver = null;

    /**
     * Sets the database resolver for resolving databases.
     *
     * @param Closure $resolver A closure that accepts a database name (string) and returns a Database instance.
     */
    public static function setDatabaseResolver(Closure $resolver): void
    {
        self::$databaseResolver = $resolver;
    }

    /**
     * The table's name.
     *
     * @var string
     */
    protected string $tableName;

    /**
     * The name of the table's database.
     *
     * @var string
     */
    protected string $databaseName;

    /**
     * When true, the created and updated dates won't be set automatically.
     *
     * @var boolean
     */
    protected bool $disableAutomaticTimestamps = false;

    /**
     * Keyname of the item's created date.
     *
     * If the item does not support a created date, this should be set to an
     * empty string.
     *
     * @var string
     */
    protected string $keyDateCreated = 'created';

    /**
     * Keyname of the item's updated date.
     *
     * If the item does not support an updated date, this should be set to an
     * empty string.
     *
     * @var string
     */
    protected string $keyDateUpdated = 'updated';

    /**
     * Maps columns to their respective attribute formats as used by database
     * sanitization methods.
     *
     * @var array
     */
    protected array $attributeFormatsDatabase;

    /**
     * Initializes a new object with data.
     *
     * @param array|string|integer|null $item Accepts an array of item data as key=>val
     *                                        pair or the item ID as an int or string
     *                                        which will be used to setup the object.
     *                                        If null is passed, a new empty object
     *                                        is instantiated.
     */
    public function __construct($item = null)
    {
        $this->idKey                                   = $this->getTable()->getPrimaryKey()->getName();
        $this->attributeAliases[$this->keyDateCreated] = 'dateCreated';
        $this->attributeAliases[$this->keyDateUpdated] = 'dateUpdated';
        $this->configureProperties();
        parent::__construct($item);
    }

    /**
     * Static constructor: initializes a new model instance.
     *
     * @param  array|string|integer|null $item Accepts an array of item data as key=>val
     *                                        pair or the item ID as an int or string
     *                                        which will be used to setup the object.
     *                                        If null is passed, a new empty object
     *                                        is instantiated.
     * @return self
     */
    public static function init($item = null): self
    {
        return new static($item);
    }

    /**
     * Configures class properties and defaults from the table's columns.
     */
    protected function configureProperties(): void
    {
        $formats   = [];
        $formatsDb = [];
        $defaults  = [];
        foreach ($this->getTable()->getColumns() as $col) {
            $name             = $col->getName();
            $type             = $col->getType();
            $default          = $col->getDefault();
            $formats[$name]   = DataType::toCast($type);
            $formatsDb[$name] = DataType::toDataFormat($type);
            if ('NULL' !== $default) {
                $defaults[$name] = $default;
            }
        }
        $this->attributeFormats         = $formats;
        $this->attributeFormatsDatabase = $formatsDb;
        $this->fillAttributes($defaults)->resetChangedAttributes();
    }

    /**
     * Creates the model in the database.
     *
     * @return self
     * @throws ModelError When the model could not be created in the database.
     */
    protected function create(): self
    {
        if (
            ! $this->disableAutomaticTimestamps &&
            ! $this->hasAttributeChanged($this->keyDateCreated) &&
            empty($this->getAttribute($this->keyDateCreated))
        ) {
            $this->setDateCreated();
        }
        $data = $this->toArray();
        try {
            $insertId = $this->getTable()->insert(
                $data,
                array_values(
                    array_merge($data, array_intersect_key($this->attributeFormatsDatabase, $data))
                )
            );
            if ($insertId && empty($this->getId())) {
                $this->setId($insertId);
            }
        } catch (QueryError $err) {
            throw ModelError::create($this->getModelType(), $err, compact('data'));
        }
        return $this
            ->resetChangedAttributes();
    }

    /**
     * Deletes the model from the database.
     *
     * @return boolean
     */
    public function delete(): bool
    {
        $res = $this->getTable()->delete(
            [
                $this->idKey => $this->getId(),
            ],
            [
                $this->attributeFormatsDatabase[$this->idKey],
            ]
        );
        return $res ? true : false;
    }

    /**
     * Determines if the item exists in the database.
     *
     * @return boolean
     * @throws ModelError When the model could not be read from the database.
     */
    public function exists(): bool
    {
        $id = $this->getId();
        if (empty($id)) {
            return false;
        }
        try {
            $model = new static($id);
            $find  = $model->read([$this->idKey]);
        } catch (ModelError $err) {
            if (ModelError::E_RECORD_NOT_FOUND !== $err->getCode()) {
                throw $err;
            }
            $find = false;
        }
        return ! empty($find);
    }

    /**
     * Retrieves the Database instance for the model.
     *
     * @return \PrettyLinks\GroundLevel\Database\Database
     * @throws ModelError When the database could not be found.
     */
    public function getDatabase(): Database
    {
        if (self::$databaseResolver === null) {
            $type = $this->getModelType();
            throw new ModelError(
                'Database resolver not configured. Call PersistedModel::setDatabaseResolver() ' .
                "before using model '{$type}'.",
                ModelError::E_DB_NOT_FOUND,
                null,
                [
                    'databaseName' => $this->databaseName,
                    'modelType'    => $type,
                ]
            );
        }

        try {
            return (self::$databaseResolver)($this->databaseName);
        } catch (\Exception $err) {
            $type = $this->getModelType();
            throw new ModelError(
                "The database '{$this->databaseName}' for model '{$type}' could not be found.",
                ModelError::E_DB_NOT_FOUND,
                $err,
                [
                    'databaseName' => $this->databaseName,
                    'modelType'    => $type,
                ]
            );
        }
    }

    /**
     * Retrieves the Table instance for the model.
     *
     * @return \PrettyLinks\GroundLevel\Database\Table
     */
    public function getTable(): Table
    {
        return $this->getDatabase()->getTable($this->tableName);
    }

    /**
     * Find an item by its primary key/id.
     *
     * @param  string|integer $id The model's primary key/id.
     * @return self
     * @throws ModelError When the item could not be found in the database.
     */
    public static function find($id): self
    {
        $model = new static($id);
        return $model->read();
    }

    /**
     * Performs a query against the tables database and returns an array of matching
     * objects.
     *
     * @param  \Closure $callback Callback function which we receives a QueryBuilder instance
     *                           as its first argument. The resulting query is used
     *                           to perform the query against the database.
     * @return self[] Returns an array of matching objects.
     */
    public static function query(Closure $callback): array
    {
        $model = new static();
        return array_map(
            [static::class, 'init'],
            $model->getTable()->select($callback)
        );
    }

    /**
     * Reads item attributes from the database.
     *
     * @param  string[] $columns An array of columns to retrieve, if empty retrieves all columns.
     * @return self
     * @throws ModelError When the item could not be found in the database.
     */
    public function read(array $columns = []): self
    {
        $id = $this->getId();
        if (! empty($id)) {
            $read = $this
                ->getTable()
                ->findBy(
                    $this->idKey,
                    // Ensure the correct placeholder is used when writing the query.
                    $this->castAttribute($this->idKey, $id),
                    $columns
                );
            if (empty($read)) {
                throw ModelError::recordNotFound(
                    $this->getModelType(),
                    $id,
                    $this->getPrimaryKey()
                );
            }
            $read = get_object_vars($read);
            unset($read[$this->idKey]); // ID's are immutable.
            if (! empty($read)) {
                $this->fillAttributes($read);
                $this->changedAttributes = array_diff(
                    $this->changedAttributes,
                    array_keys($read)
                );
            }
        }
        return $this;
    }

    /**
     * Persists the model to the database in its current state.
     *
     * If the model doesn't have an ID property set, it'll create it, otherwise it
     * will update the existing record.
     *
     * @param  boolean $force If true, forces the model to be saved even if no attributes
     *                       have changed.
     * @return self
     */
    public function save(bool $force = false): self
    {
        $exists = $this->exists();
        if (! $exists || $this->hasChangedAttributes() || $force) {
            return $exists ? $this->update() : $this->create();
        }
        return $this;
    }

    /**
     * Sets the created date of the model.
     *
     * @param  null|string|integer|DateTime $datetime      The created date in UTC time. If not
     *                                                     supplied the current time is used.
     * @param  boolean                      $copyToUpdated If true, copies the supplied
     *                                                    $datetime to the updated date.
     * @return self
     */
    public function setDateCreated($datetime = null, bool $copyToUpdated = true): self
    {
        if (empty($this->keyDateCreated)) {
            return $this;
        }
        $datetime = Casts::cast(Casts::DATE_MYSQL, $datetime ?? Time::now());
        $this->setAttributeSafe(
            $this->keyDateCreated,
            $datetime
        );
        if ($copyToUpdated) {
            $this->setDateUpdated($datetime);
        }
        return $this;
    }

    /**
     * Sets the updated date of the model.
     *
     * @param  null|string|integer|DateTime $datetime The updated date in UTC time. If not
     *                                               supplied the current time is used.
     * @return self
     */
    public function setDateUpdated($datetime = null): self
    {
        if (empty($this->keyDateUpdated)) {
            return $this;
        }
        $this->setAttributeSafe(
            $this->keyDateUpdated,
            Casts::cast(Casts::DATE_MYSQL, $datetime ?? Time::now())
        );
        return $this;
    }

    /**
     * Updates a record.
     *
     * @return self
     */
    protected function update(): self
    {
        // Set the updated timestamp if it's not disabled and the attribute hasn't already been updated.
        if (! $this->disableAutomaticTimestamps && ! $this->hasAttributeChanged($this->keyDateUpdated)) {
            $this->setDateUpdated();
        }

        $data = array_intersect_key(
            $this->toArray(),
            array_fill_keys($this->changedAttributes, 1)
        );

        $this->getTable()->update(
            $data,
            [
                $this->idKey => $this->getId(),
            ],
            array_values(
                array_merge($data, array_intersect_key($this->attributeFormatsDatabase, $data))
            ),
            [
                $this->attributeFormatsDatabase[$this->idKey],
            ]
        );

        return $this->resetChangedAttributes();
    }

    /**
     * Performs a where query against the tables database and returns an array of
     * matching objects.
     *
     * Arguments are ultimately passed to {@see QueryBuilder::where} which supports
     * several method signatures, read the full documentation of the method for
     * information on using alternate signatures.
     *
     * @param  string|array|Closure|Expression $key      The column name, an array of where clauses, a closure
     *                                                   or a predefined where clause.
     * @param  mixed                           $operator The operator to use, or the value to compare against.
     * @param  mixed                           $value    The value to compare against.
     * @return self[] Returns an array of matching objects.
     */
    public static function where(string $key, $operator = Query::EQUALS, $value = null): array
    {
        $model = new static();
        return array_map(
            [static::class, 'init'],
            $model->getTable()->select(
                static function (Query $query) use ($key, $operator, $value): void {
                    $query->where($key, $operator, $value);
                }
            )
        );
    }

    /**
     * Retrieves the count of records in the database table.
     *
     * @return integer The number of records in the table.
     */
    public static function count(): int
    {
        $model     = new static();
        $table     = $model->getTable();
        $tableName = $table->getPrefixedName();
        return (int) $model->getDatabase()->getVar("SELECT COUNT(*) FROM {$tableName};");
    }

    /**
     * Retrieves all records in the database table.
     *
     * Ordered by the primary key, ascending.
     *
     * @return self[] Returns an array of objects.
     */
    public static function all(): array
    {
        $model      = new static();
        $primaryKey = $model->getPrimaryKey();
        return array_map(
            [static::class, 'init'],
            $model->getTable()->select(
                static function (Query $query) use ($primaryKey): void {
                    $query->orderBy($primaryKey);
                }
            )
        );
    }
}
