<?php

declare(strict_types=1);

namespace PrettyLinks\GroundLevel\Database\Contracts;

interface ConnectionAwareness
{
    /**
     * Sets the database connection.
     *
     * @param  \wpdb|\PrettyLinks\GroundLevel\Database\Contracts\Connection $connection The database connection.
     * @return self
     */
    public function setConnection($connection);

    /**
     * Gets the database connection.
     *
     * @return null|\wpdb|\PrettyLinks\GroundLevel\Database\Contracts\Connection
     */
    public function getConnection();
}
