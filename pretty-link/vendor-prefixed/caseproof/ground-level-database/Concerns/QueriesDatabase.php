<?php

declare(strict_types=1);

namespace PrettyLinks\GroundLevel\Database\Concerns;

use PrettyLinks\GroundLevel\Database\Exceptions\QueryError;

/**
 * Trait to provide access to WPDB with some quality of life improvements.
 */
trait QueriesDatabase
{
    /**
     * Prepares and executes a database query.
     *
     * @param  string $queryMethod The query method.
     * @param  string $query       The query statement. This string can be a fully prepared
     *                            query string or a string with placeholders to be replaced
     *                            by {@see wpdb::prepare}.
     * @param  array  $args        Variables to substitute into the query's placeholders. If
     *                            no arguments are supplied then the $query will be used
     *                            as a raw string.
     * @return mixed Returns the query result
     * @throws QueryError Throws exception when an error is encountered.
     */
    abstract protected function executePreparedQuery(string $queryMethod, string $query, array $args);

    /**
     * Retrieves a single variable from the database using {@see wpdb::get_var}.
     *
     * @link https://developer.wordpress.org/reference/classes/wpdb/get_var/
     *
     * @param  string $query The query statement. This string can be a fully prepared
     *                      query string or a string with placeholders to be replaced
     *                      by {@see wpdb::prepare}.
     * @param  array  $args  Variables to substitute into the query's placeholders. If
     *                      no arguments are supplied then the $query will be used
     *                      as a raw string.
     * @return mixed Returns the variable value or null if none found.
     * @throws QueryError Throws exception when an error is encountered.
     */
    public function getVar(string $query, array $args = [])
    {
        return $this->executePreparedQuery('get_var', $query, $args);
    }

    /**
     * Performs a database query via {@see wpdb::query}.
     *
     * This method is best used for table CRUD operations, use {@see PerformsQueries::select}
     * to read records from tables, {@see PeformsQueries::insert} and {@see PerformsQueries::update}
     * to write records to tables, and {@see PerformsQueries::delete} to remove records
     * from tables.
     *
     * @link https://developer.wordpress.org/reference/classes/wpdb/query/
     *
     * @param  string $query The query statement. This string can be a fully prepared
     *                      query string or a string with placeholders to be replaced
     *                      by {@see wpdb::prepare}.
     * @param  array  $args  Variables to substitute into the query's placeholders. If
     *                      no arguments are supplied then the $query will be used
     *                      as a raw string.
     * @return boolean|integer Returns true for CREATE, ALTER, TRUNCATE, and DROP queries,
     *                  returns the number of affected/selected rows as an integer
     *                  for other queries.
     * @throws QueryError Throws exception when an error is encountered.
     */
    public function query(string $query, array $args = [])
    {
        return $this->executePreparedQuery('query', $query, $args);
    }

    /**
     * Retrieves results for the given query and arguments.
     *
     * @param  string $query The query.
     * @param  array  $args  Arguments to inject into the query.
     * @return array
     */
    public function getResults(string $query, array $args = []): array
    {
        return $this->executePreparedQuery('get_results', $query, $args);
    }
}
