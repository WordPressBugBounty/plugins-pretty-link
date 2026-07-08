<?php

declare(strict_types=1);

namespace PrettyLinks\GroundLevel\Database\Concerns;

use PrettyLinks\GroundLevel\Database\Contracts\Connection;
use PrettyLinks\GroundLevel\Database\Exceptions\ConnectionError;

trait HasConnection
{
    /**
     * Database connection.
     *
     * @var Connection|\wpdb
     */
    protected $connection;

    /**
     * Gets the database connection.
     *
     * @return null|\wpdb|\PrettyLinks\GroundLevel\Database\Contracts\Connection
     */
    public function getConnection()
    {
        return isset($this->connection) ? $this->connection : null;
    }

    /**
     * Sets the instance's database connection.
     *
     * @param  \wpbd|Connection $connection An instance of \wpdb or a class that implements
     *                                     the Connection interface.
     * @return self
     * @throws \PrettyLinks\GroundLevel\Database\Exceptions\ConnectionError If the connection is invalid.
     */
    public function setConnection($connection): self
    {
        if (! $connection instanceof \wpdb && ! $connection instanceof Connection) {
            throw ConnectionError::invalid();
        }
        $this->connection = $connection;
        return $this;
    }
}
