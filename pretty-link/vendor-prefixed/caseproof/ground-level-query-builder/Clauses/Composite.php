<?php

declare(strict_types=1);

namespace PrettyLinks\GroundLevel\QueryBuilder\Clauses;

use PrettyLinks\GroundLevel\QueryBuilder\Contracts\Clause as ClauseContract;
use PrettyLinks\GroundLevel\QueryBuilder\Contracts\Language;
use PrettyLinks\GroundLevel\QueryBuilder\Format;
use InvalidArgumentException;

/**
 * Builds the SQL for a WHERE clause made up of one or more parts.
 */
class Composite extends Clause implements ClauseContract, Language
{
    /**
     * Array of clauses contained within the clause.
     *
     * @var Clause[]
     */
    protected array $clauses = [];

    /**
     * Adds a clause.
     *
     * @param  Clause $clause   The where clause to add.
     * @param  string $operator The operator, either {@see WhereClause::AND
     *                         or {@see WhereClause::OR}.
     * @throws InvalidArgumentException If the operator is invalid.
     */
    public function add(Clause $clause, string $operator = self::AND): void
    {
        $allowedOperators = [self::AND, self::OR];
        if (! in_array($operator, $allowedOperators, true)) {
            throw new InvalidArgumentException(
                sprintf(
                    'Invalid operator: %1$s. Must be one of %2$s.',
                    $operator,
                    implode('|', $allowedOperators)
                )
            );
        }
        $this->clauses[] = [$operator, $clause];
    }

    /**
     * Determines if the WHERE clause is empty.
     *
     * @return boolean
     */
    protected function isEmpty(): bool
    {
        return count($this->clauses) === 0;
    }

    /**
     * Retrieves the SQL string for the WHERE clause.
     *
     * @return string
     */
    public function getSql(): string
    {
        if ($this->isEmpty()) {
            return '';
        }

        $parts = [];
        foreach ($this->clauses as $i => $whereClause) {
            list($operator, $clause) = $whereClause;

            if (0 === $i) {
                $operator = '';
            }

            $sql = $clause->getSql();
            if ($clause instanceof Composite) {
                $nestedSqlParts = explode(PHP_EOL, $sql);
                $nested         = [
                    '(',
                    PHP_EOL,
                    implode(
                        PHP_EOL,
                        array_map(
                            [Format::class, 'indent'],
                            $nestedSqlParts
                        )
                    ),
                    PHP_EOL,
                    Format::indent(')'),
                ];
                $sql            = implode('', $nested);
            }

            $parts[]        = Format::indent(ltrim("{$operator} {$sql}"));
            $this->bindings = array_merge(
                $this->bindings,
                $clause->getBindings()
            );
        }

        return implode(PHP_EOL, $parts);
    }
}
