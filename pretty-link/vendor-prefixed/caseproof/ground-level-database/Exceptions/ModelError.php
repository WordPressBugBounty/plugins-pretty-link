<?php

declare(strict_types=1);

namespace PrettyLinks\GroundLevel\Database\Exceptions;

use PrettyLinks\GroundLevel\Support\Exceptions\Exception;
use Throwable;

/**
 * Errors encountered when interacting with the {@see PersistedModel} class.
 */
class ModelError extends Exception
{
    /**
     * Error code: The database specified by the model cannot be found.
     */
    public const E_DB_NOT_FOUND = 100;

    /**
     * Error code: The model cannot be found in the database.
     */
    public const E_RECORD_NOT_FOUND = 200;

    /**
     * Error code: The model could not be created in the database.
     */
    public const E_RECORD_CREATE = 205;

    /**
     * Error encountered when attempting to insert a new model into the database.
     *
     * @param  string     $modelType The model type.
     * @param  \Throwable $prev      The previous exception, usually {@see QueryError}.
     * @param  array      $data      Additional data to add to the exception.
     * @return self
     */
    public static function create(string $modelType, Throwable $prev, array $data = []): self
    {
        return new self(
            "Error persisting new {$modelType} to the database: {$prev->getMessage()}",
            self::E_RECORD_CREATE,
            $prev,
            $data
        );
    }

    /**
     * Error encountered when the model can't be found in the database.
     *
     * @param  string         $modelType The model type.
     * @param  string|integer $id        The value of the model's ID/primary key.
     * @param  string         $keyName   The name of the primary key.
     * @return self
     */
    public static function recordNotFound(string $modelType, $id, string $keyName = 'ID'): self
    {
        return new self(
            "Could not find {$modelType} with {$keyName}: {$id}.",
            self::E_RECORD_NOT_FOUND
        );
    }

    /**
     * Determines if the error is a record not found error.
     *
     * @return boolean
     */
    public function isRecordNotFound(): bool
    {
        return $this->getCode() === self::E_RECORD_NOT_FOUND;
    }
}
