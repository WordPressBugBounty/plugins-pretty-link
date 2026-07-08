<?php

declare(strict_types=1);

namespace PrettyLinks\GroundLevel\Database\Models;

use Closure;
use PrettyLinks\GroundLevel\Database\Exceptions\InvalidRelationshipError;
use PrettyLinks\GroundLevel\Database\RelationshipType;
use PrettyLinks\GroundLevel\QueryBuilder\Query;
use PrettyLinks\GroundLevel\Support\Str;

class Relationship
{
    /**
     * The relationship type.
     *
     * @var \PrettyLinks\GroundLevel\Database\RelationshipType
     */
    protected RelationshipType $type;

    /**
     * The primary model.
     *
     * @var \PrettyLinks\GroundLevel\Database\Models\PersistedModel
     */
    protected PersistedModel $model;

    /**
     * The related model's class.
     *
     * @var string
     */
    protected string $relationClass;

    /**
     * The foreign key.
     *
     * @var string
     */
    protected string $foreignKey;

    /**
     * The local key.
     *
     * @var string
     */
    protected string $localKey;

    /**
     * Constructor.
     *
     * @param \PrettyLinks\GroundLevel\Database\RelationshipType $type          The relationship type.
     * @param PersistedModel                         $model         The primary model.
     * @param string                                 $relationClass The related model's class.
     * @param string                                 $foreignKey    The foreign key. Defaults to {$model->modelType_id} in snake case format.
     * @param string                                 $localKey      The local key. Defaults to the {$model->idKey}.
     */
    public function __construct(
        RelationshipType $type,
        PersistedModel $model,
        string $relationClass,
        string $foreignKey = '',
        string $localKey = ''
    ) {
        $this->validate($model, $relationClass);

        $this->type          = $type;
        $this->model         = $model;
        $this->relationClass = $relationClass;

        $this->foreignKey = $foreignKey ? $foreignKey : $model->getForeignKey();
        $this->localKey   = $localKey ? $localKey : $model->getPrimaryKey();
    }

    /**
     * Returns the default local key for the relationship.
     *
     * If the relationship type is either BELONGS_TO_ONE or BELONGS_TO_MANY,
     * the default local key is the snake case of the model type appended with '_id'.
     * Otherwise, the default local key is the primary key of the model.
     *
     * @return string The default local key.
     */
    protected function getDefaultLocalKey(): string
    {
        if ($this->type->oneOf([RelationshipType::BELONGS_TO_ONE(), RelationshipType::BELONGS_TO_MANY()])) {
            return Str::toSnakeCase($this->model->getModelType() . '_id');
        }
        return $this->model->getPrimaryKey();
    }

    /**
     * Initailizes a new QueryBuilder Query instance for the relationship.
     *
     * @return \PrettyLinks\GroundLevel\QueryBuilder\Query
     */
    public function query(): Query
    {
        $isHasRelation = $this->type->oneOf([RelationshipType::HAS_ONE(), RelationshipType::HAS_MANY()]);

        /*
         * @var \PrettyLinks\GroundLevel\Database\Models\PersistedModel $relatedModel
         */
        $relatedModel = new $this->relationClass();
        $query        = $relatedModel->getTable()->initQuery();

        // Initialize the query's where clause.
        if ($isHasRelation) {
            $query->where($this->foreignKey, $this->model->getAttribute($this->localKey));
        } else {
            $query->where($this->localKey, $this->model->getAttribute($this->foreignKey));
        }

        if ($this->type->oneOf([RelationshipType::HAS_ONE(), RelationshipType::BELONGS_TO_ONE()])) {
            $query->limit(1);
        }

        return $query;
    }

    /**
     * Selects records from the database based on the given closure or columns.
     *
     * @param  array|Closure $closureOrColumns The closure or columns to filter the records.
     * @return array Returns an array of initialized models.
     */
    public function select($closureOrColumns = []): array
    {
        /*
         * @var \PrettyLinks\GroundLevel\Database\Models\PersistedModel $model
         */
        $model = new $this->class();
        $query = $this->query();
        if (is_array($closureOrColumns)) {
            $query->select($closureOrColumns);
        }
        if ($closureOrColumns instanceof Closure) {
            $query->where($closureOrColumns);
        }
        return array_map(
            $this->relationClass . '::init',
            $model->getTable()->select($query)
        );
    }

    /**
     * Validates the supplied relationship classes.
     *
     * Relationships must:
     *   - Not be identical to each other
     *   - Be existing classes
     *   - Extend the {@see \GroundLevel\Database\Models\PersistedModel} class
     *
     * @param  PersistedModel $model         The model's class.
     * @param  string         $relationClass The related model's class.
     * @return boolean
     * @throws InvalidRelationshipError If the relationship is invalid.
     */
    protected function validate(PersistedModel $model, string $relationClass): bool
    {
        if (get_class($model) === $relationClass) {
            throw new InvalidRelationshipError(
                'Invalid relationship provided: Relationship classes cannot be identical to each other.',
                InvalidRelationshipError::E_INVALID_IDENTICAL,
                null,
                compact('model', 'relationClass')
            );
        }

        if (! class_exists($relationClass)) {
            throw new InvalidRelationshipError(
                sprintf(
                    'Invalid relationship class "%s" provided. Class does not exist.',
                    $relationClass
                ),
                InvalidRelationshipError::E_INVALID_NOT_FOUND,
                null,
                compact('relationClass')
            );
        }

        if (! is_a($relationClass, PersistedModel::class, true)) {
            throw new InvalidRelationshipError(
                sprintf(
                    'Invalid relationship class "%1$s" provided. Class must be a subclass of "%2$s".',
                    $relationClass,
                    PersistedModel::class
                ),
                InvalidRelationshipError::E_INVALID_SUBCLASS,
                null,
                compact('relationClass')
            );
        }

        return true;
    }
}
