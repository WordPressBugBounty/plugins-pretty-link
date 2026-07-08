<?php

declare(strict_types=1);

namespace PrettyLinks\GroundLevel\QueryBuilder\Enums;

use PrettyLinks\GroundLevel\Support\Enum;

/**
 * Wildcard wrappers for the LIKE keyword.
 *
 * @method static LikeWildcard STARTS() Returns the {@see LikeWrapper::STARTS} enum case.
 * @method static LikeWildcard ENDS() Returns the {@see LikeWrapper::ENDS} enum case.
 * @method static LikeWildcard CONTAINS() Returns the {@see LikeWrapper::CONTAINS} enum case.
 */
class LikeWildcard extends Enum
{
    /**
     * Type: Starts.
     */
    public const STARTS = 'starts';

    /**
     * Type: Ends.
     */
    public const ENDS = 'ends';

    /**
     * Type: Begins.
     */
    public const CONTAINS = 'contains';
}
