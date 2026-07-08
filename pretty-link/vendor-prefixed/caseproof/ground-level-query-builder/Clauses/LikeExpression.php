<?php

declare(strict_types=1);

namespace PrettyLinks\GroundLevel\QueryBuilder\Clauses;

use PrettyLinks\GroundLevel\QueryBuilder\Contracts\Clause as ClauseContract;
use PrettyLinks\GroundLevel\QueryBuilder\Contracts\Language;
use PrettyLinks\GroundLevel\QueryBuilder\Enums\LikeWildcard;
use PrettyLinks\GroundLevel\QueryBuilder\Format;
use InvalidArgumentException;

class LikeExpression extends Clause implements ClauseContract, Language
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
     * Valid operators are {@see self::LIKE} and {@see self::NOT_LIKE}.
     *
     * @var string
     */
    protected string $operator;

    /**
     * The supplied value.
     *
     * @var string
     */
    protected $value;

    /**
     * Constructor
     *
     * @param string            $column   The column name.
     * @param string            $value    The value to be compared against.
     * @param LikeWildcard|null $wildcard The preformatted wildcard type, if any.
     * @param boolean           $not      True for NOT LIKE, false for LIKE, default false.
     */
    public function __construct(
        string $column,
        string $value,
        ?LikeWildcard $wildcard = null,
        bool $not = false
    ) {
        $this->column   = $column;
        $this->operator = $not ? self::NOT_LIKE : self::LIKE;

        if ($wildcard instanceof LikeWildcard) {
            $wildcard = $wildcard->getValue();
            $value    = self::escapeWildcard($value);

            switch ($wildcard) {
                case LikeWildcard::STARTS()->getValue():
                    $value = $value . '%';
                    break;
                case LikeWildcard::ENDS()->getValue():
                    $value = '%' . $value;
                    break;
                case LikeWildcard::CONTAINS()->getValue():
                    $value = '%' . $value . '%';
                    break;
            }
        }

        $this->value    = $this->getPlaceholder($value);
        $this->bindings = [$value];
    }

    /**
     * Escapes wildcard characters in the supplied search string.
     *
     * @param  string $value The search string to be escaped.
     * @return string
     */
    public static function escapeWildcard(string $value): string
    {
        return addcslashes($value, '_%\\');
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
                $this->value,
            ]
        );
    }
}
