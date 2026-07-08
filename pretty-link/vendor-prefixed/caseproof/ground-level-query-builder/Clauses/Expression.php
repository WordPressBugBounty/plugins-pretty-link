<?php

declare(strict_types=1);

namespace PrettyLinks\GroundLevel\QueryBuilder\Clauses;

use PrettyLinks\GroundLevel\QueryBuilder\Format;
use InvalidArgumentException;

class Expression extends Clause
{
    /**
     * Lists of allowed operators.
     *
     * @var string[]
     */
    public const OPERATORS = [
        self::EQUALS,
        self::NOT_EQUALS,
        self::GREATER,
        self::LESS,
        self::GREATER_EQUALS,
        self::LESS_EQUALS,
    ];

    /**
     * The column name.
     *
     * @var string
     */
    protected string $column;

    /**
     * The comparison operator.
     *
     * @var string
     */
    protected string $operator;

    /**
     * Constructor.
     *
     * @param  string $column   Column name.
     * @param  string $operator Comparison operator.
     * @param  mixed  $value    The value.
     * @throws InvalidArgumentException When the operator is invalid.
     */
    public function __construct(string $column, string $operator, $value)
    {
        if (! $this->isValidOperator($operator)) {
            throw new InvalidArgumentException(
                sprintf(
                    'Invalid operator: %1$s. Must be one of %2$s.',
                    $operator,
                    implode('|', static::OPERATORS)
                )
            );
        }

        $this->column   = $column;
        $this->operator = $operator;
        $this->bindings = [$value];
    }

    /**
     * Retrieves the SQL for the WHERE clause.
     *
     * @return string
     */
    public function getSql(): string
    {
        return implode(
            ' ',
            [
                Format::backtick($this->column),
                $this->operator,
                $this->getPlaceholder($this->bindings[0]),
            ]
        );
    }

    /**
     * Determines if the operator is valid.
     *
     * @param  string $operator An operator string.
     * @return boolean
     */
    protected function isValidOperator(string $operator): bool
    {
        return in_array($operator, self::OPERATORS, true);
    }
}
