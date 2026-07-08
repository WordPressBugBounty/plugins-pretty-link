<?php

declare(strict_types=1);

namespace PrettyLinks\GroundLevel\QueryBuilder;

use Closure;
use PrettyLinks\GroundLevel\QueryBuilder\Clauses\BetweenExpression;
use PrettyLinks\GroundLevel\QueryBuilder\Clauses\Clause;
use PrettyLinks\GroundLevel\QueryBuilder\Clauses\Expression;
use PrettyLinks\GroundLevel\QueryBuilder\Clauses\Composite;
use PrettyLinks\GroundLevel\QueryBuilder\Clauses\InExpression;
use PrettyLinks\GroundLevel\QueryBuilder\Clauses\LikeExpression;
use PrettyLinks\GroundLevel\QueryBuilder\Clauses\JoinClause;
use PrettyLinks\GroundLevel\QueryBuilder\Clauses\NullExpression;
use PrettyLinks\GroundLevel\QueryBuilder\Contracts\Language;
use PrettyLinks\GroundLevel\QueryBuilder\Enums\LikeWildcard;
use InvalidArgumentException;

/**
 * Builds SQL queries.
 */
class Query implements Language
{
    /**
     * Query type.
     *
     * See TYPE_* constants for available query types.
     *
     * @var string
     */
    protected string $queryType;

    /**
     * The tablename to query against.
     *
     * @var string
     */
    protected string $table;

    /**
     * List of bindings for the current query.
     *
     * Bindings are used to prevent SQL injection.
     *
     * @var array
     */
    protected array $bindings = [];

    /**
     * The columns to group by when performing a select query.
     *
     * @var string[]
     */
    protected array $groupBy = [];

    /**
     * Having clause manager for the current query.
     *
     * @var \PrettyLinks\GroundLevel\QueryBuilder\Clauses\Composite
     */
    protected Composite $havingClauses;

    /**
     * Query result limit.
     *
     * A null limit indicates no limit is set for the query.
     *
     * @var null|integer
     */
    protected ?int $limit = null;

    /**
     * Query result offset.
     *
     * @var integer
     */
    protected int $offset = 0;

    /**
     * Order by clauses for the current query.
     *
     * @var array[]
     */
    protected array $orderBy = [];

    /**
     * The next operator to use when adding a WHERE or HAVING clause.
     *
     * @var string|null
     */
    protected ?string $nextCombiner = null;

    /**
     * The columns to select when performing a select query.
     *
     * @var array
     */
    protected array $selectColumns = [];

    /**
     * Whether or not to select distinct values.
     *
     * @var boolean
     */
    protected bool $selectDistinct = false;

    /**
     * Where clause manager for the current query.
     *
     * @var \PrettyLinks\GroundLevel\QueryBuilder\Clauses\Composite
     */
    protected Composite $whereClauses;

    // phpcs:disable
    protected array $joins = [];
    // phpcs:enable

    /**
     * Constructor.
     */
    public function __construct()
    {
        $this->havingClauses = new Composite();
        $this->whereClauses  = new Composite();
    }

     /**
      * Retrieves the full SQL string for the current query.
      *
      * @param  boolean $humanReadable If true, returns the SQL string with line breaks and indentation.
      * @return string
      */
    public function getSql(bool $humanReadable = false): string
    {
        $grammar = new Grammar($this);
        return $grammar->write($humanReadable);
    }

    /***************************************************************************
     * **************************************************************************
     *
     * Getters
     *
     *
     * **************************************************************************
     * **************************************************************************
     */

    /**
     * Retrieves the query type.
     *
     * @return string
     */
    public function getQueryType(): string
    {
        return $this->queryType;
    }

    /**
     * Retrieves the query's bindings.
     *
     * @return mixed[]
     */
    public function getBindings(): array
    {
        return $this->bindings;
    }

    /**
     * Retrieves the query's GROUP BY list.
     *
     * @return string[]
     */
    public function getGroupBy(): array
    {
        return $this->groupBy;
    }

    /**
     * Retrieves the HAVING clauses for the query.
     *
     * @return \PrettyLinks\GroundLevel\QueryBuilder\Clauses\Composite
     */
    public function getHavingClauses(): Composite
    {
        return $this->havingClauses;
    }

    /**
     * Retrieves the query's LIMIT.
     *
     * @return integer|null
     */
    public function getLimit(): ?int
    {
        return $this->limit;
    }

    /**
     * Retrieves the query's OFFSET.
     *
     * @return integer
     */
    public function getOffset(): int
    {
        return $this->offset;
    }

    /**
     * Retrieves the query's ORDER BY list.
     *
     * @return array[]
     */
    public function getOrderBy(): array
    {
        return $this->orderBy;
    }

    /**
     * Retrieves the query's SELECT columns.
     *
     * @return string[]
     */
    public function getSelectColumns(): array
    {
        return $this->selectColumns;
    }

    /**
     * Retrieves the query's SELECT DISTINCT flag.
     *
     * @return boolean
     */
    public function getSelectDistinct(): bool
    {
        return $this->selectDistinct;
    }

    /**
     * Retrieves the query's table.
     *
     * @return string
     */
    public function getTable(): string
    {
        return $this->table;
    }

    /**
     * Retrieves the WHERE clauses for the query.
     *
     * @return \PrettyLinks\GroundLevel\QueryBuilder\Clauses\Composite
     */
    public function getWhereClauses(): Composite
    {
        return $this->whereClauses;
    }






    // phpcs:disable
    protected function getJoinSql()
    {
        if (count($this->joins) === 0) {
            return '';
        }

        $joinSql = [];
        foreach ($this->joins as $join) {
            $joinSql[] = $join->getSql();
        }

        return implode(' ', $joinSql);
    }
    // phpcs:enable



    /**
     * Adds a list of bindings to the query.
     *
     * @param  array $bindings Array of bindings to add.
     * @return self
     */
    public function addBindings(array $bindings = []): self
    {
        if (! empty($bindings)) {
            $this->bindings = array_merge($this->bindings, $bindings);
        }
        return $this;
    }


    /***************************************************************************
     * **************************************************************************
     *
     * Query Types
     *
     *
     * **************************************************************************
     * **************************************************************************
     */

    /**
     * Initializes a select query.
     *
     * @param  array $columns Array of column names to select. If empty, all columns
     *                       will be selected.
     * @return self
     */
    public function select(array $columns = []): self
    {
        $columns             = empty($columns) ? [self::ALL_COLUMNS] : $columns;
        $this->queryType     = Language::QUERY_SELECT;
        $this->selectColumns = array_map('strval', $columns);
        return $this;
    }

    /***************************************************************************
     * **************************************************************************
     *
     * Tables
     *
     *
     * **************************************************************************
     * **************************************************************************
     */

    /**
     * Sets query's table.
     *
     * Alias of {@see Query::table()}
     *
     * @param  string      $table The tablename.
     * @param  null|string $as    Optional table alias.
     * @return self
     */
    public function from(string $table, ?string $as = null): self
    {
        return $this->table($table, $as);
    }

    /**
     * Sets query's table.
     *
     * @param  string      $table The tablename.
     * @param  null|string $as    Optional table alias.
     * @return self
     */
    public function table(string $table, ?string $as = null)
    {
        $this->table = $as ? implode(' ', [$table, Language::AS, $as]) : $table;
        return $this;
    }

    /***************************************************************************
     * **************************************************************************
     *
     * WHERE Clauses
     *
     *
     * **************************************************************************
     * **************************************************************************
     */

    /**
     * Adds a WHERE clause to the query.
     *
     * This method has several signatures:
     *
     * - where(string $column, string $operator, mixed $value, string $combiner)
     *   Adds a basic comparison expression to the WHERE clause.
     *    - string $column   The column name.
     *    - string $operator The operator to use, one of {@see Expression::OPERATORS}.
     *    - mixed  $value    The value to compare against.
     *    - string $combiner The combiner to use, either {@see self::AND} or {@see self::OR}.
     *
     * - where(string $column, mixed $value)
     *   Shorthand to add a basic comparison expression to the WHERE clause using
     *   the {@see self::AND} combiner and the {@see Expression::EQUALS} operator.
     *    - string $column The column name.
     *    - mixed  $value  The value to compare against.
     *
     * - where(Closure $closure)
     *   Creates a new grouped WHERE clause. A new Query instance is created and
     *   passed to the callback function as the first parameter. Any of the specified
     *   clauses defined on the new Query instance are added to the current query
     *   as a grouped clause.
     *    - Closure $closure The callback function. A new Query instance is passed
     *                       as the first parameter.
     *
     * - where(Clause $clause)
     *   Adds a predefined WHERE clause to the query.
     *    - Clause $clause The clause to add.
     *
     * - where(array $clauses)
     *   Adds an array of where clauses to the query. Each array item is expanded
     *   and passed to this method to add an individual where clause. Any of the
     *   alternate signatures can be used with this method.
     *    - array[] $clauses    Array of clause definitions.
     *
     * @param  string|array|Closure|Expression $column   The column name, an array of where clauses, a closure
     *                                                   or a predefined where clause.
     * @param  mixed                           $operator The operator to use, or the value to compare against.
     * @param  mixed                           $value    The value to compare against.
     * @param  string                          $combiner The query combiner string, AND or OR.
     * @return self
     */
    public function where($column, $operator = self::EQUALS, $value = null, string $combiner = self::AND): self
    {
        /*
         * Use func_get_args() to ensure that we can check for shorthand signatures
         * determined by the number of arguments passed to the method.
         */
        return $this->handleClause(self::WHERE, ...func_get_args());
    }

    /**
     * Adds a WHERE IS NOT NULL clause to the query.
     *
     * @param  string $column   Column name.
     * @param  string $combiner The query combiner string, either {@see self::AND}
     *                         or {@see self::OR}.
     * @return self
     */
    public function whereNotNull(string $column, string $combiner = self::AND): self
    {
        return $this->whereNull($column, true, $combiner);
    }

    /**
     * Adds a WHERE IS NULL or WHERE IS NOT NULL clause to the query.
     *
     * @param  string  $column   Column name.
     * @param  boolean $not      Null value comparator. If true WHERE IS NOT NULL
     *                          will be used, otherwise WHERE IS NULL.
     * @param  string  $combiner The query combiner string, either {@see self::AND}
     *                          or {@see self::OR}.
     * @return self
     */
    public function whereNull(string $column, bool $not = false, string $combiner = self::AND): self
    {
        return $this->where($column, $not ? self::NOT_EQUALS : self::EQUALS, null, $combiner);
    }

    /**
     * Adds a WHERE BETWEEN or WHERE NOT BETWEEN clause to the query.
     *
     * @param  string  $column   Column Name.
     * @param  array   $values   Array of values to compare against. Values will be
     *                          written in the order specified so the lower value
     *                          should be first. For example SQL equal to "BETWEEN 1 AND 10"
     *                          the supplied values array should be [1, 10].
     * @param  boolean $not      If true, will create a NOT BETWEEN clause.
     * @param  string  $combiner The query combiner string, either {@see self::AND}
     *                          or {@see self::OR}.
     * @return self
     */
    public function whereBetween(string $column, array $values, bool $not = false, string $combiner = self::AND): self
    {
        return $this->where(
            new BetweenExpression(
                $column,
                $values,
                $not
            ),
            null,
            null,
            $combiner
        );
    }

    /**
     * Adds a WHERE NOT BETWEEN clause to the query.
     *
     * @param  string $column   Column Name.
     * @param  array  $values   Array of values to compare against. Values will be
     *                         written in the order specified so the lower value
     *                         should be first. For example SQL equal to "BETWEEN 1 AND 10"
     *                         the supplied values array should be [1, 10].
     * @param  string $combiner The query combiner string, either {@see self::AND}
     *                         or {@see self::OR}.
     * @return self
     */
    public function whereNotBetween(string $column, array $values, string $combiner = self::AND): self
    {
        return $this->whereBetween($column, $values, true, $combiner);
    }

    /**
     * Adds a WHERE IN or WHERE NOT IN clause to the query.
     *
     * @param  string              $column   Column name.
     * @param  array|Query|Closure $values   Array of values to insert into the IN
     *                                      clause, a Query instance to use as a
     *                                      subquery, or a Closure. If a Closure
     *                                      is passed, a new Query instance is passed
     *                                      into the closure and it will be used
     *                                      to build a subquery.
     * @param  boolean             $not      If true, will create a NOT IN clause.
     * @param  string              $combiner The query combiner string, either {@see self::AND}
     *                                      or {@see self::OR}.
     * @return self
     */
    public function whereIn(string $column, $values, bool $not = false, string $combiner = self::AND): self
    {
        if ($values instanceof Closure) {
            $query = new self();
            $values($query);
            $values = $query;
        }

        return $this->where(
            new InExpression(
                $column,
                $values,
                $not
            ),
            null,
            null,
            $combiner
        );
    }

    /**
     * Adds a WHERE NOT IN clause to the query.
     *
     * @param  string              $column   Column name.
     * @param  array|Query|Closure $values   Array of values to insert into the IN
     *                                       clause, a Query instance to use as a
     *                                       subquery, or a Closure. If a Closure
     *                                       is passed, a new Query instance is passed
     *                                       into the closure and it will be used
     *                                       to build a subquery.
     * @param  string              $combiner The query combiner string, either {@see self::AND}
     *                                       or {@see self::OR}.
     * @return self
     */
    public function whereNotIn(string $column, $values, string $combiner = self::AND): self
    {
        return $this->whereIn($column, $values, true, $combiner);
    }

    /**
     * Adds a WHERE LIKE or WHERE NOT LIKE clause to the query.
     *
     * @param  string       $column   Column name.
     * @param  string       $value    Value to compare against.
     * @param  LikeWildcard $wildcard The preformatted wildcard type to use. Default null.
     *                                If specified, wildcard characters in the value will be escaped.
     * @param  boolean      $not      If true, will create a NOT IN clause.
     * @param  string       $combiner The query combiner string, either {@see self::AND}
     *                                or {@see self::OR}.
     * @return self
     */
    public function whereLike(
        string $column,
        string $value,
        ?LikeWildcard $wildcard = null,
        bool $not = false,
        string $combiner = self::AND
    ): self {
        return $this->where(
            new LikeExpression(
                $column,
                $value,
                $wildcard,
                $not,
            ),
            null,
            null,
            $combiner
        );
    }

    /**
     * Adds a WHERE NOT LIKE clause to the query.
     *
     * @param  string       $column   Column name.
     * @param  string       $value    Value to compare against.
     * @param  LikeWildcard $wildcard The preformatted wildcard type to use. Default null.
     *                                If specified, wildcard characters in the value will be escaped.
     * @param  string       $combiner The query combiner string, either {@see self::AND}
     *                                or {@see self::OR}.
     * @return self
     */
    public function whereNotLike(
        string $column,
        string $value,
        ?LikeWildcard $wildcard = null,
        string $combiner = self::AND
    ): self {
        return $this->whereLike($column, $value, $wildcard, true, $combiner);
    }

    /**
     * Adds a WHERE LIKE clause in the form of "column LIKE 'value%'" to the query.
     *
     * @param  string $column   Column name.
     * @param  string $value    Value to compare against. Wildcard characters are escaped.
     * @param  string $combiner The query combiner string, either {@see self::AND}
     *                          or {@see self::OR}.
     * @return self
     */
    public function whereStartsWith(string $column, string $value, string $combiner = self::AND): self
    {
        return $this->where(
            new LikeExpression(
                $column,
                $value,
                LikeWildcard::STARTS(),
                false
            ),
            null,
            null,
            $combiner
        );
    }

    /**
     * Adds a WHERE LIKE clause in the form of "column LIKE '%value'" to the query.
     *
     * @param  string $column   Column name.
     * @param  string $value    Value to compare against. Wildcard characters are escaped.
     * @param  string $combiner The query combiner string, either {@see self::AND}
     *                          or {@see self::OR}.
     * @return self
     */
    public function whereEndsWith(string $column, string $value, string $combiner = self::AND): self
    {
        return $this->where(
            new LikeExpression(
                $column,
                $value,
                LikeWildcard::ENDS(),
                false
            ),
            null,
            null,
            $combiner
        );
    }

    /**
     * Adds a WHERE LIKE clause in the form of "column LIKE '%value%'" to the query.
     *
     * @param  string $column   Column name.
     * @param  string $value    Value to compare against. Wildcard characters are escaped.
     * @param  string $combiner The query combiner string, either {@see self::AND}
     *                          or {@see self::OR}.
     * @return self
     */
    public function whereContains(string $column, string $value, string $combiner = self::AND): self
    {
        return $this->where(
            new LikeExpression(
                $column,
                $value,
                LikeWildcard::CONTAINS(),
                false
            ),
            null,
            null,
            $combiner
        );
    }

    /***************************************************************************
     * **************************************************************************
     *
     * Joins
     *
     *
     * **************************************************************************
     * **************************************************************************
     */
    // phpcs:disable
    public function leftJoin($table, $firstColumn, $operator = JoinClause::USING, $secondColumn = null)
    {
        $this->join(JoinClause::LEFT, $table, $firstColumn, $operator, $secondColumn);
        return $this;
    }

    public function rightJoin($table, $firstColumn, $operator = JoinClause::USING, $secondColumn = null)
    {
        $this->join(JoinClause::RIGHT, $table, $firstColumn, $operator, $secondColumn);
        return $this;
    }

    public function innerJoin($table, $firstColumn, $operator = JoinClause::USING, $secondColumn = null)
    {
        $this->join(JoinClause::INNER, $table, $firstColumn, $operator, $secondColumn);
        return $this;
    }

    public function fullJoin($table, $firstColumn, $operator = JoinClause::USING, $secondColumn = null)
    {
        $this->join(JoinClause::FULL, $table, $firstColumn, $operator, $secondColumn);
        return $this;
    }

    protected function join($type, $table, $firstColumn, $operator, $secondColumn)
    {
        $join = new JoinClause($type, $table);
        $join->on($firstColumn, $operator, $secondColumn);
        $this->joins[] = $join;
    }
    // phpcs:enable

    /***************************************************************************
     * **************************************************************************
     *
     * HAVING Clauses
     *
     *
     * **************************************************************************
     * **************************************************************************
     */

    /**
     * Adds a HAVING clause to the query.
     *
     * This method has several signatures:
     *
     * - having(string $column, string $operator, mixed $value, string $combiner)
     *   Adds a basic comparison expression to the HAVING clause.
     *    - string $column   The column name.
     *    - string $operator The operator to use, one of {@see Expression::OPERATORS}.
     *    - mixed  $value    The value to compare against.
     *    - string $combiner The combiner to use, either {@see self::AND} or {@see self::OR}.
     *
     * - having(string $column, mixed $value)
     *   Shorthand to add a basic comparison expression to the HAVING clause using
     *   the {@see self::AND} combiner and the {@see Expression::EQUALS} operator.
     *    - string $column The column name.
     *    - mixed  $value  The value to compare against.
     *
     * - having(Closure $closure)
     *   Creates a new grouped HAVING clause. A new Query instance is created and
     *   passed to the callback function as the first parameter. Any of the specified
     *   clauses defined on the new Query instance are added to the current query
     *   as a grouped clause.
     *    - Closure $closure The callback function. A new Query instance is passed
     *                       as the first parameter.
     *
     * - having(Clause $clause)
     *   Adds a predefined HAVING clause to the query.
     *    - Clause $clause The clause to add.
     *
     * - having(array $clauses)
     *   Adds an array of having clauses to the query. Each array item is expanded
     *   and passed to this method to add an individual having clause. Any of the
     *   alternate signatures can be used with this method.
     *    - array[] $clauses    Array of clause definitions.
     *
     * @param  string|array|Closure|Expression $column   The column name, an array of having clauses, a closure
     *                                                   or a predefined having clause.
     * @param  mixed                           $operator The operator to use, or the value to compare against.
     * @param  mixed                           $value    The value to compare against.
     * @param  string                          $combiner The query combiner string, AND or OR.
     * @return self
     */
    public function having($column, $operator = self::EQUALS, $value = null, string $combiner = self::AND): self
    {
        /*
         * Use func_get_args() to ensure that we can check for shorthand signatures
         * determined by the number of arguments passed to the method.
         */
        return $this->handleClause(self::HAVING, ...func_get_args());
    }

    /**
     * Adds a HAVING IS NOT NULL clause to the query.
     *
     * @param  string $column   Column name.
     * @param  string $combiner The query combiner string, either {@see self::AND}
     *                         or {@see self::OR}.
     * @return self
     */
    public function havingNotNull(string $column, string $combiner = self::AND): self
    {
        return $this->havingNull($column, true, $combiner);
    }

    /**
     * Adds a HAVING IS NULL or HAVING IS NOT NULL clause to the query.
     *
     * @param  string  $column   Column name.
     * @param  boolean $not      Null value comparator. If true HAVING IS NOT NULL
     *                          will be used, otherwise HAVING IS NULL.
     * @param  string  $combiner The query combiner string, either {@see self::AND}
     *                          or {@see self::OR}.
     * @return self
     */
    public function havingNull(string $column, bool $not = false, string $combiner = self::AND): self
    {
        return $this->having($column, $not ? self::NOT_EQUALS : self::EQUALS, null, $combiner);
    }

    /**
     * Adds a HAVING BETWEEN or HAVING NOT BETWEEN clause to the query.
     *
     * @param  string  $column   Column Name.
     * @param  array   $values   Array of values to compare against. Values will be
     *                          written in the order specified so the lower value
     *                          should be first. For example SQL equal to "BETWEEN 1 AND 10"
     *                          the supplied values array should be [1, 10].
     * @param  boolean $not      If true, will create a NOT BETWEEN clause.
     * @param  string  $combiner The query combiner string, either {@see self::AND}
     *                          or {@see self::OR}.
     * @return self
     */
    public function havingBetween(string $column, array $values, bool $not = false, string $combiner = self::AND): self
    {
        return $this->having(
            new BetweenExpression(
                $column,
                $values,
                $not
            ),
            null,
            null,
            $combiner
        );
    }

    /**
     * Adds a HAVING NOT BETWEEN clause to the query.
     *
     * @param  string $column   Column Name.
     * @param  array  $values   Array of values to compare against. Values will be
     *                         written in the order specified so the lower value
     *                         should be first. For example SQL equal to "BETWEEN 1 AND 10"
     *                         the supplied values array should be [1, 10].
     * @param  string $combiner The query combiner string, either {@see self::AND}
     *                         or {@see self::OR}.
     * @return self
     */
    public function havingNotBetween(string $column, array $values, string $combiner = self::AND): self
    {
        return $this->havingBetween($column, $values, true, $combiner);
    }

    /**
     * Adds a HAVING IN or HAVING NOT IN clause to the query.
     *
     * @param  string              $column   Column name.
     * @param  array|Query|Closure $values   Array of values to insert into the IN
     *                                      clause, a Query instance to use as a
     *                                      subquery, or a Closure. If a Closure
     *                                      is passed, a new Query instance is passed
     *                                      into the closure and it will be used
     *                                      to build a subquery.
     * @param  boolean             $not      If true, will create a NOT IN clause.
     * @param  string              $combiner The query combiner string, either {@see self::AND}
     *                                      or {@see self::OR}.
     * @return self
     */
    public function havingIn(string $column, $values, bool $not = false, string $combiner = self::AND): self
    {
        if ($values instanceof Closure) {
            $query = new self();
            $values($query);
            $values = $query;
        }

        return $this->having(
            new InExpression(
                $column,
                $values,
                $not
            ),
            null,
            null,
            $combiner
        );
    }

    /**
     * Adds a HAVING NOT IN clause to the query.
     *
     * @param  string              $column   Column name.
     * @param  array|Query|Closure $values   Array of values to insert into the IN
     *                                      clause, a Query instance to use as a
     *                                      subquery, or a Closure. If a Closure
     *                                      is passed, a new Query instance is passed
     *                                      into the closure and it will be used
     *                                      to build a subquery.
     * @param  string              $combiner The query combiner string, either {@see self::AND}
     *                                      or {@see self::OR}.
     * @return self
     */
    public function havingNotIn(string $column, $values, string $combiner = self::AND): self
    {
        return $this->havingIn($column, $values, true, $combiner);
    }

    /**
     * Adds a HAVING LIKE or HAVING NOT LIKE clause to the query.
     *
     * @param  string       $column   Column name.
     * @param  string       $value    Value to compare against.
     * @param  LikeWildcard $wildcard The preformatted wildcard type to use. Default null.
     *                                If specified, wildcard characters in the value will be escaped.
     * @param  boolean      $not      If true, will create a NOT IN clause.
     * @param  string       $combiner The query combiner string, either {@see self::AND}
     *                                or {@see self::OR}.
     * @return self
     */
    public function havingLike(
        string $column,
        string $value,
        ?LikeWildcard $wildcard = null,
        bool $not = false,
        string $combiner = self::AND
    ): self {
            return $this->having(
                new LikeExpression(
                    $column,
                    $value,
                    $wildcard,
                    $not
                ),
                null,
                null,
                $combiner
            );
    }

    /**
     * Adds a HAVING NOT LIKE clause to the query.
     *
     * @param  string       $column   Column name.
     * @param  string       $value    Value to compare against.
     * @param  LikeWildcard $wildcard The preformatted wildcard type to use. Default null.
     *                                If specified, wildcard characters in the value will be escaped.
     * @param  string       $combiner The query combiner string, either {@see self::AND}
     *                                or {@see self::OR}.
     * @return self
     */
    public function havingNotLike(
        string $column,
        string $value,
        ?LikeWildcard $wildcard = null,
        string $combiner = self::AND
    ): self {
            return $this->havingLike($column, $value, $wildcard, true, $combiner);
    }

    /**
     * Adds a HAVING LIKE clause in the form of "column LIKE 'value%'" to the query.
     *
     * @param  string $column   Column name.
     * @param  string $value    Value to compare against. Wildcard characters are escaped.
     * @param  string $combiner The query combiner string, either {@see self::AND}
     *                          or {@see self::OR}.
     * @return self
     */
    public function havingStartsWith(string $column, string $value, string $combiner = self::AND): self
    {
        return $this->having(
            new LikeExpression(
                $column,
                $value,
                LikeWildcard::STARTS(),
                false
            ),
            null,
            null,
            $combiner
        );
    }

    /**
     * Adds a HAVING LIKE clause in the form of "column LIKE '%value'" to the query.
     *
     * @param  string $column   Column name.
     * @param  string $value    Value to compare against. Wildcard characters are escaped.
     * @param  string $combiner The query combiner string, either {@see self::AND}
     *                          or {@see self::OR}.
     * @return self
     */
    public function havingEndsWith(string $column, string $value, string $combiner = self::AND): self
    {
        return $this->having(
            new LikeExpression(
                $column,
                $value,
                LikeWildcard::ENDS(),
                false
            ),
            null,
            null,
            $combiner
        );
    }

    /**
     * Adds a HAVING LIKE clause in the form of "column LIKE '%value%'" to the query.
     *
     * @param  string $column   Column name.
     * @param  string $value    Value to compare against. Wildcard characters are escaped.
     * @param  string $combiner The query combiner string, either {@see self::AND}
     *                          or {@see self::OR}.
     * @return self
     */
    public function havingContains(string $column, string $value, string $combiner = self::AND): self
    {
        return $this->having(
            new LikeExpression(
                $column,
                $value,
                LikeWildcard::CONTAINS(),
                false
            ),
            null,
            null,
            $combiner
        );
    }

    /***************************************************************************
     * **************************************************************************
     *
     * Pagination
     *
     *
     * **************************************************************************
     * **************************************************************************
     */

    /**
     * Sets the result limit for the query.
     *
     * @param  integer|null $limit The number of results to return, or null to remove the limit.
     * @return self
     */
    public function limit(?int $limit): self
    {
        if (! is_null($limit)) {
            $limit = $limit <= 0 ? null : $limit;
        }
        $this->limit = $limit;
        return $this;
    }

    /**
     * Alias for {@see Query::limit()}.
     *
     * @param null|integer $limit The number of results to return, or null to remove the limit.
     */
    public function take(?int $limit): self
    {
        return $this->limit($limit);
    }

    /**
     * The number of records to offset the query by.
     *
     * @param  integer $offset The offset value.
     * @return self
     */
    public function offset(int $offset): self
    {
        $this->offset = max(0, $offset);
        return $this;
    }

    /**
     * Alias for {@see Query::offset()}.
     *
     * @param  integer $offset The offset value.
     * @return self
     */
    public function skip(int $offset): self
    {
        return $this->offset($offset);
    }

    /**
     * Shorthand to set the limit and offset for a query based on a page number
     * and a number of items per page.
     *
     * @param  integer $page    The page number, >= 1.
     * @param  integer $perPage The number of items per page, defaults to 10, Must be >= 1.
     * @return self
     */
    public function forPage(int $page, int $perPage = 10): self
    {
        $page    = max(1, $page);
        $perPage = max(1, $perPage);
        return $this->offset(($page - 1) * $perPage)
                    ->limit($perPage);
    }

    /**
     * Shorthand to retrieve the first result of the query.
     *
     * @return self
     */
    public function first(): self
    {
        return $this->limit(1);
    }

    /***************************************************************************
     * **************************************************************************
     *
     * Ordering
     *
     *
     * **************************************************************************
     * **************************************************************************
     */

    /**
     * Adds an ORDERBY clause to the query for the specified column and direction.
     *
     * @param  string $column    The column name.
     * @param  string $direction The direction to order by, either @see self::ORDER_ASC
     *                          or {@see self::ORDER_DESC}.
     * @return self
     * @throws InvalidArgumentException If an invalid direction is passed.
     */
    public function orderBy(string $column, string $direction = Language::ORDER_ASC): self
    {
        $direction   = strtoupper($direction);
        $allowedDirs = [Language::ORDER_ASC, Language::ORDER_DESC];
        if (! in_array($direction, $allowedDirs, true)) {
            throw new InvalidArgumentException(
                sprintf(
                    'Invalid order direction: %1$s. Must be one of %2$s.',
                    $direction,
                    implode('|', $allowedDirs)
                )
            );
        }
        $this->orderBy[] = "$column $direction";
        return $this;
    }

    /**
     * Adds an ORDERBY clause to the query for the specified column in ascending order.
     *
     * @param  string $column The column name.
     * @return self
     */
    public function orderByDesc(string $column): self
    {
        return $this->orderBy($column, Language::ORDER_DESC);
    }

    /**
     * Adds an ORDERBY clause to the query for the specified column in descending order.
     *
     * @param  string $column The column name.
     * @return self
     */
    public function orderByAsc(string $column): self
    {
        return $this->orderBy($column, Language::ORDER_ASC);
    }

    /**
     * Adds an ORDERBY RAND() clause to the query.
     *
     * @return self
     */
    public function orderByRand(): self
    {
        $this->orderBy[] = Language::RAND;
        return $this;
    }

    /***************************************************************************
     * **************************************************************************
     *
     * Grouping
     *
     *
     * **************************************************************************
     * **************************************************************************
     */

    /**
     * Adds a GROUP BY clause to the query for the specified column.
     *
     * @param string ...$columns One or more column names to group by.
     */
    public function groupBy(string ...$columns): self
    {
        $this->groupBy = array_values(
            array_unique(
                array_merge(
                    $this->groupBy,
                    $columns
                )
            )
        );
        return $this;
    }

    /***************************************************************************
     * **************************************************************************
     *
     * Query Modifiers
     *
     *
     * **************************************************************************
     * **************************************************************************
     */

    /**
     * Modifies the query to select distinct values.
     *
     * @return self
     */
    public function distinct(): self
    {
        $this->selectDistinct = true;
        return $this;
    }

    /**
     * Sets the query's next WHERE or HAVING clause to use the OR operator.
     *
     * @return self
     */
    public function or(): self
    {
        $this->nextCombiner = Language::OR;
        return $this;
    }

    /**
     * Sets the query's next WHERE or HAVING clause to use the AND operator.
     *
     * @return self
     */
    public function and(): self
    {
        $this->nextCombiner = Language::AND;
        return $this;
    }

    /**
     * Returns the query's next WHERE or HAVING clause combiner and resets it.
     *
     * @return string|null
     */
    protected function useNextCombiner(): ?string
    {
        $combiner = $this->nextCombiner;
        // Use the next combiner if set.
        if (! is_null($this->nextCombiner)) {
            $this->nextCombiner = null;
        }
        return $combiner;
    }

    /**
     * Adds a clause to the query.
     *
     * This method has several signatures:
     *
     * - handleClause(string $clauseType, string $column, string $operator, mixed $value, string $combiner)
     *   Adds a basic comparison expression to the clause.
     *    - string $clauseType The clause type, either {@see self::WHERE} or {@see self::HAVING}.
     *    - string $column     The column name.
     *    - string $operator   The operator to use, one of {@see Expression::OPERATORS}.
     *    - mixed  $value      The value to compare against.
     *    - string $combiner   The combiner to use, either {@see self::AND} or {@see self::OR}.
     *
     * - handleClause(string $clauseType, string $column, mixed $value)
     *   Shorthand to add a basic comparison expression to the clause using the {@see self::AND} combiner
     *   and the {@see Expression::EQUALS} operator.
     *    - string $clauseType The clause type, either {@see self::WHERE} or {@see self::HAVING}.
     *    - string $column     The column name.
     *    - mixed  $value      The value to compare against.
     *
     * - handleClause(string $clauseType, Closure $closure)
     *   Creates a new grouped clause. A new Query instance is created and
     *   passed to the callback function as the first parameter. Any of the specified
     *   clauses defined on the new Query instance are added to the current query
     *   as a grouped clause.
     *    - string  $clauseType The clause type, either {@see self::WHERE} or {@see self::HAVING}.
     *    - Closure $closure    The callback function. A new Query instance is passed
     *                          as the first parameter.
     *
     * - handleClause(string $clauseType, Clause $clause)
     *   Adds a predefined clause to the query.
     *    - string $clauseType The clause type, either {@see self::WHERE} or {@see self::HAVING}.
     *    - Clause $clause     The clause to add.
     *
     * - handleClause(string $clauseType, array $clauses)
     *   Adds an array of clauses to the query. Each array item is expanded
     *   and passed to this method to add an individual where clause. Any of the
     *   alternate signatures can be used with this method.
     *    - string  $clauseType The clause type, either {@see self::WHERE} or {@see self::HAVING}.
     *    - array[] $clauses    Array of clause definitions.
     *
     * @param  string                          $clauseType The clause type, either {@see self::WHERE} or
     *                                                    {@see self::HAVING}.
     * @param  string|array|Closure|Expression $column     The column name, an array of where clauses, a closure or a
     *                                                    predefined where clause.
     * @param  mixed                           $operator   The operator to use, or the value to compare against.
     * @param  mixed                           $value      The value to compare against.
     * @param  string                          $combiner   The query combiner string, AND or OR.
     * @return self
     */
    protected function handleClause(
        string $clauseType,
        $column,
        $operator = self::EQUALS,
        $value = null,
        string $combiner = self::AND
    ): self {
        $combiner = $this->useNextCombiner() ?? $combiner;

        /*
         * If $column is an instance of Clause it is added to the query
         * using the specified $combiner.
         */
        if ($column instanceof Clause) {
            return $this->addClause($clauseType, $column, $combiner);
        }

        /*
         * If $column is an array of clauses.
         *
         * Each array item is expanded and it's values are passed to this method.
         */
        if (is_array($column)) {
            foreach ($column as $value) {
                /*
                 * Use combiner from array item if set, otherwise use the one passed
                 * to the method. This allows passing arrays of full or partial
                 * where clauses and ensures that if the combiner is passed in
                 * an array it is always used and still allows shorthand arrays
                 * to be passed for automatic equals.
                 */
                $clauseCombiner = $value[3] ?? null;
                if (is_null($clauseCombiner)) {
                    $clauseCombiner = $combiner;
                }
                $combinerMethod = self::OR === $clauseCombiner ? 'or' : 'and';
                $this->{$combinerMethod}()->handleClause($clauseType, ...$value);
            }
            return $this;
        }

        /*
         * If $column is a closure a new Query instance is created and passed to
         * the closure. Any where clauses defined in the closure are added to the
         * current query.
         */
        if ($column instanceof Closure) {
            $query = new self();
            $column($query);
            $clauseGetter = sprintf('get%sClauses', ucfirst(strtolower($clauseType)));
            $this->handleClause($clauseType, $query->{$clauseGetter}(), $operator, null, $combiner);
            return $this;
        }

        /*
         * If only three (two to the original) arguments are passed to the method
         * the operator is assumed to be self::EQUALS and the second argument is treated as the $value.
         */
        if (3 === func_num_args()) {
            $value    = $operator;
            $operator = self::EQUALS;
        }

        if (is_null($value)) {
            return $this->addClause(
                $clauseType,
                new NullExpression(
                    $column,
                    self::EQUALS !== $operator
                ),
                $combiner
            );
        }

        return $this->addClause(
            $clauseType,
            new Expression(
                $column,
                $operator,
                $value
            ),
            $combiner
        );
    }

    /**
     * Add a clause to the query using the specified combiner.
     *
     * @param  string $clauseType The clause type, either {@see self::WHERE} or
     *                            {@see self::HAVING}.
     * @param  Clause $clause     The clause to add.
     * @param  string $combiner   The query combiner, either {@see self::AND} or
     *                            {@see self::OR}.
     * @return self
     */
    public function addClause(string $clauseType, Clause $clause, string $combiner): self
    {
        $clauseType = strtolower($clauseType) . 'Clauses';
        $this->$clauseType->add($clause, $combiner);
        return $this;
    }

    /**
     * Writes the query to a string and prepares it for execution.
     *
     * @return string
     */
    public function writePrepared(): string
    {
        $sql = $this->write();
        if (count($this->bindings) > 0) {
            $sql = $this->db->prepare($sql, $this->bindings);
        }
        return $sql;
    }
}
