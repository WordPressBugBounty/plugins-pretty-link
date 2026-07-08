<?php

declare(strict_types=1);

namespace PrettyLinks\GroundLevel\QueryBuilder\Clauses;

use PrettyLinks\GroundLevel\QueryBuilder\Contracts\Clause as ClauseContract;
use PrettyLinks\GroundLevel\QueryBuilder\Contracts\Language;
use PrettyLinks\GroundLevel\QueryBuilder\Format;
use PrettyLinks\GroundLevel\QueryBuilder\Query;

class InExpression extends Clause implements ClauseContract, Language
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
     * Valid operators are {@see self::IN} and {@see self::NOT_IN}.
     *
     * @var string
     */
    protected string $operator;

    /**
     * The supplied values.
     *
     * @var Query|array
     */
    protected $values;

    /**
     * Undocumented function
     *
     * @param string      $column The column name.
     * @param Query|array $values An array of values or a Query object to use as a subquery.
     * @param boolean     $not    If true, the operator will be NOT IN. Otherwise, IN.
     */
    public function __construct(string $column, $values, bool $not = false)
    {
        $this->column   = $column;
        $this->operator = $not ? self::NOT_IN : self::IN;

        if ($values instanceof Query) {
            $this->values   = Format::subquery($values->getSql(true), 2) . Format::INDENT;
            $this->bindings = $values->getBindings();
        } else {
            $values         = (array) $values;
            $this->values   = implode(', ', array_map([$this, 'getPlaceholder'], $values));
            $this->bindings = $values;
        }
    }

    /**
     * Builds the SQL string for the expression.
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
                "({$this->values})",
            ]
        );
    }
}
