<?php

declare(strict_types=1);

namespace PrettyLinks\GroundLevel\QueryBuilder\Clauses;

class JoinClause
{
    public const LEFT  = 'LEFT';
    public const RIGHT = 'RIGHT';
    public const INNER = 'INNER';
    public const FULL  = 'FULL';
    public const USING = 'USING';

    /**
     * Join Type
     *
     * @var string|null
     */
    private ?string $type = null;

    /**
     * Table name.
     *
     * @var string|null
     */
    private ?string $table = null;

    /**
     * Column 1 name.
     *
     * @var string|null
     */
    private ?string $column1 = null;

    /**
     * Column 2 name.
     *
     * @var string|null
     */
    private ?string $column2 = null;

    /**
     * Operator.
     *
     * @var string|null
     */
    private ?string $operator = null;

    /**
     * Constructs a new instance of the class.
     *
     * @param  string $type  The type of the join.
     * @param  string $table The table to join.
     * @throws \InvalidArgumentException If the join type is invalid.
     */
    public function __construct(string $type, string $table)
    {
        $this->assertValidJoinType($type);
        $this->type  = $type;
        $this->table = $table;
    }

    /**
     * Sets the columns and operator for the JOIN clause.
     *
     * @param string      $first    The first column.
     * @param string      $operator The operator to use. Default is USING.
     * @param string|null $second   The second column. Default is null.
     */
    public function on(string $first, string $operator = JoinClause::USING, ?string $second = null): void
    {
        $this->column1 = $first;
        $this->column2 = $second;

        $this->assertValidOperator($operator);

        $this->operator = $operator;
    }


    /**
     * Retrieves the SQL string for the JOIN clause.
     *
     * @return string The SQL string for the JOIN clause.
     */
    public function getSql(): string
    {
        if ($this->operator === JoinClause::USING) {
            $parts = [
                $this->type,
                'JOIN',
                $this->table,
                'USING',
                $this->column1,
            ];
        } else {
            $parts = [
                $this->type,
                'JOIN',
                $this->table,
                'ON',
                $this->column1,
                $this->operator,
                $this->column2,
            ];
        }

        return implode(' ', array_filter($parts));
    }

    /**
     * Validates the given join type.
     *
     * @param  string $type The join type to validate.
     * @throws \InvalidArgumentException If the join type is not allowed.
     */
    private function assertValidJoinType(string $type)
    {
        $allowed = [
            JoinClause::LEFT,
            JoinClause::RIGHT,
            JoinClause::INNER,
            JoinClause::FULL,
        ];
        if (!in_array($type, $allowed, true)) {
            throw new \InvalidArgumentException(sprintf(
                "Invalid JOIN type. Allowed values are: %s. You gave: '%s'",
                implode(', ', $allowed),
                $type
            ));
        }
    }

    /**
     * Validates the given operator.
     *
     * @param  string $operator The operator to validate.
     * @throws \InvalidArgumentException If the operator is not allowed.
     */
    private function assertValidOperator(string $operator)
    {
        $allowed = [
            WhereClause::EQUALS,
            WhereClause::NOTEQUALS,
            WhereClause::GREATER,
            WhereClause::LESS,
            WhereClause::GREATEREQUALS,
            WhereClause::LESSEQUALS,
            JoinClause::USING,
        ];
        if (!in_array($operator, $allowed, true)) {
            throw new \InvalidArgumentException(sprintf(
                "Invalid operator for ON. Allowed values are: %s. You gave: '%s'",
                implode(', ', $allowed),
                $operator
            ));
        }
    }
}
