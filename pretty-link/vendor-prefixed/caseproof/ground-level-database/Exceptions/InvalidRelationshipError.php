<?php

declare(strict_types=1);

namespace PrettyLinks\GroundLevel\Database\Exceptions;

use PrettyLinks\GroundLevel\Support\Exceptions\Exception;

/**
 * Errors encountered when working with {@see \GroundLevel\Database\Models\Relationship}.
 */
class InvalidRelationshipError extends Exception
{
    /**
     * Error code: The relationship classes must be unique.
     */
    public const E_INVALID_IDENTICAL = 10005;

    /**
     * Error code: The relationship class could not be found.
     */
    public const E_INVALID_NOT_FOUND = 100010;

    /**
     * Error code: The relationship class must be a subclass of {@see \GroundLevel\Database\Models\PersistedModel}.
     */
    public const E_INVALID_SUBCLASS = 10015;
}
