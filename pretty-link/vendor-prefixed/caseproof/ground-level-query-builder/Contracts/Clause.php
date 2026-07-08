<?php

declare(strict_types=1);

namespace PrettyLinks\GroundLevel\QueryBuilder\Contracts;

interface Clause
{
    /**
     * Retrieves the SQL for the clause.
     *
     * @return string
     */
    public function getSql(): string;

    /**
     * Retrieves the bindings present in the clause.
     *
     * @return mixed[]
     */
    public function getBindings(): array;
}
