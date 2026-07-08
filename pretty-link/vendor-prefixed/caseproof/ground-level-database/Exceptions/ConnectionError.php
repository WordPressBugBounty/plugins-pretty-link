<?php

declare(strict_types=1);

namespace PrettyLinks\GroundLevel\Database\Exceptions;

use PrettyLinks\GroundLevel\Support\Exceptions\Exception;

/**
 * Database connection errors.
 */
class ConnectionError extends Exception
{
    /**
     * Error code: The database connection has not been established.
     */
    public const E_NOT_CONNECTED = 100;

    /**
     * Error code: The supplied database connection object is invalid.
     */
    public const E_INVALID = 200;

    /**
     * Creates a new error instance for when the provided database connection is
     * invalid.
     *
     * @return self
     */
    public static function invalid(): self
    {
        return new self(
            'The supplied database connection object is invalid.',
            self::E_INVALID
        );
    }

    /**
     * Creates a new error instance for when the database connection has not been
     * established.
     *
     * @return self
     */
    public static function notConnected(): self
    {
        return new self(
            'The database connection has not been established.',
            self::E_NOT_CONNECTED
        );
    }
}
