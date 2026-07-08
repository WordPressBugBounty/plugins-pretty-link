<?php

declare(strict_types=1);

namespace PrettyLinks\GroundLevel\QueryBuilder\Contracts;

interface Language
{
    /**
     * Table and column alias separator.
     */
    public const AS = 'AS';

    /**
     * Query type: select
     */
    public const QUERY_SELECT = 'SELECT';

    /**
     * Clause type: WHERE
     */
    public const WHERE = 'WHERE';

    /**
     * Clause type: HAVING
     */
    public const HAVING = 'HAVING';

    /**
     * Wildcard character used to specify all columns during a select query.
     */
    public const ALL_COLUMNS = '*';

    /**
     * Sort order: ascending
     */
    public const ORDER_ASC = 'ASC';

    /**
     * Sort order: descending.
     */
    public const ORDER_DESC = 'DESC';

    /**
     * Function: RAND().
     */
    public const RAND = 'RAND()';

    /**
     * Clause operator: AND.
     */
    public const AND = 'AND';

    /**
     * Clause operator: OR.
     */
    public const OR = 'OR';

    /**
     * Equals operator: =
     */
    public const EQUALS = '=';

    /**
     * Not equals operator: !=
     */
    public const NOT_EQUALS = '!=';

    /**
     * IN operator.
     */
    public const IN = 'IN';

    /**
     * NOT IN operator.
     */
    public const NOT_IN = 'NOT IN';

    /**
     * Greater than operator: >
     */
    public const GREATER = '>';

    /**
     * Greater than or equals operator: >=
     */
    public const GREATER_EQUALS = '>=';

    /**
     * Less than operator: <
     */
    public const LESS = '<';

    /**
     * Less than or equals operator: <=
     */
    public const LESS_EQUALS = '<=';

    /**
     * IS NULL operator.
     */
    public const IS_NULL = 'IS NULL';

    /**
     * IS NOT NULL operator.
     */
    public const IS_NOT_NULL = 'IS NOT NULL';

    /**
     * BETWEEN operator.
     */
    public const BETWEEN = 'BETWEEN';

    /**
     * NOT BETWEEN operator.
     */
    public const NOT_BETWEEN = 'NOT BETWEEN';

    /**
     * LIKE operator.
     */
    public const LIKE = 'LIKE';

    /**
     * NOT LIKE operator.
     */
    public const NOT_LIKE = 'NOT LIKE';
}
