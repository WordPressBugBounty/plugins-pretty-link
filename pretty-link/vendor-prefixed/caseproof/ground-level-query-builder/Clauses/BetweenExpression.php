<?php

declare(strict_types=1);

namespace PrettyLinks\GroundLevel\QueryBuilder\Clauses;

use PrettyLinks\GroundLevel\QueryBuilder\Contracts\Clause as ClauseContract;
use PrettyLinks\GroundLevel\QueryBuilder\Contracts\Language;
use PrettyLinks\GroundLevel\QueryBuilder\Format;
use InvalidArgumentException;

class BetweenExpression extends Clause implements ClauseContract, Language
{
    /**
     * The column name.
     *
     * @var string
     */
    protected string $column;

    /**
     * The operator.
     *
     * Valid operators are {@see self::BETWEEN} and {@see self::NOT_BETWEEN}.
     *
     * @var string
     */
    protected string $operator;

    /**
     * Undocumented function
     *
     * @param  string  $column The column name.
     * @param  array   $values The values, must contain exactly two items.
     * @param  boolean $not    If true, the operator will be NOT BETWEEN. Otherwise, BETWEEN.
     * @throws InvalidArgumentException If the $values array does not contain exactly two items.
     */
    public function __construct(string $column, array $values, bool $not = false)
    {
        $this->column   = $column;
        $this->operator = $not ? self::NOT_BETWEEN : self::BETWEEN;
        $this->bindings = $values;

        if (2 !== count($this->bindings)) {
            throw new InvalidArgumentException(
                'Invalid argument $values. The array must contain exactly two items.'
            );
        }
    }

    /**
     * Builds the SQL string for the expression.
     *
     * @return string
     */
    public function getSql(): string
    {
        $bindings = $this->getBindings();
        return implode(
            ' ',
            [
                Format::backtick($this->column),
                $this->operator,
                $this->getPlaceholder($bindings[0]),
                'AND',
                $this->getPlaceholder($bindings[1]),
            ]
        );
    }
}
