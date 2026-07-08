<?php

declare(strict_types=1);

namespace PrettyLinks\GroundLevel\Database\Exceptions;

use PrettyLinks\GroundLevel\Support\Exceptions\Exception;

/**
 * Errors encountered while performing database $queries
 */
class QueryError extends Exception
{
    /**
     * Error code: Generic query error.
     */
    public const E_GENERIC = 100;

    /**
     * Creates a new generic error instance with an optional message.
     *
     * @param  string $message The error message.
     * @return self
     */
    public static function generic(string $message = ''): self
    {
        return new self(
            $message ? $message : 'An error occurred while performing a database query.',
            self::E_GENERIC
        );
    }
}
