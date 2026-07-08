<?php

declare(strict_types=1);

namespace PrettyLinks\GroundLevel\Database;

use PrettyLinks\GroundLevel\Support\Enum;

/**
 * Relationship type enum.
 *
 * @method static RelationshipType HAS_ONE() Returns the {@see RelationshipType::HAS_ONE} enum case.
 * @method static RelationshipType HAS_MANY() Returns the {@see RelationshipType::HAS_MANY} enum case.
 * @method static RelationshipType BELONGS_TO_ONE() Returns the {@see RelationshipType::BELONGS_TO_ONE} enum case.
 * @method static RelationshipType BELONGS_TO_MANY() Returns the {@see RelationshipType::BELONGS_TO_MANY} enum case.
 */
class RelationshipType extends Enum
{
    /**
     * Type: Has One.
     *
     * The model has exactly one of the related model.
     */
    public const HAS_ONE = 'has_one';

    /**
     * Type: Has Many.
     *
     * The model has one or more of the related model.
     */
    public const HAS_MANY = 'has_many';

    /**
     * Type: Belongs To.
     *
     * The model belongs to exactly one of the related model.
     *
     * This is the inverse relationship of {@see self::HAS_ONE}.
     */
    public const BELONGS_TO_ONE = 'belongs_to_one';

    /**
     * Type: Belongs To Many.
     *
     * The model belongs to one or more of the related model.
     */
    public const BELONGS_TO_MANY = 'belongs_to_many';
}
