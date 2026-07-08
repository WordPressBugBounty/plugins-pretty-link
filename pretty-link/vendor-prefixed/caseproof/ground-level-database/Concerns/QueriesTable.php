<?php

declare(strict_types=1);

namespace PrettyLinks\GroundLevel\Database\Concerns;

use Closure;
use PrettyLinks\GroundLevel\Database\Exceptions\QueryError;
use PrettyLinks\GroundLevel\QueryBuilder\Query;

/**
 * Trait to provide access to WPDB with some quality of life improvements.
 */
trait QueriesTable
{
    /**
     * Retrieves the AUTO_INCREMENT ID of the last inserted row.
     *
     * @return integer
     */
    abstract protected function getInsertId(): int;

    /**
     * Retrieves the fully-qualified table identifier.
     *
     * @return string
     */
    abstract protected function getTableName(): string;

    /**
     * Prepares and executes a database query via the specified connection method.
     *
     * This method is best used with connection methods like {@see Connection::get_results},
     * {@see Connection::query}, etc... These methods accept a query and query arguments.
     *
     * @param  string $method The query method.
     * @param  string $query  The query statement. This string can be a fully prepared
     *                        query string or a string with placeholders to be replaced
     *                        by {@see wpdb::prepare}.
     * @param  array  $args   Variables to substitute into the query's placeholders. If
     *                        no arguments are supplied then the $query will be used
     *                        as a raw string.
     * @return mixed Returns the query result
     * @throws QueryError Throws exception when an error is encountered.
     */
    abstract protected function executePreparedQuery(string $method, string $query, array $args);

    /**
     * Executes a query on the database connection against the specified table via
     * the specified connection method.
     *
     * This method is best used with connection methods like {@see Connection::insert},
     * {@see Connection::update}, {@see Connection::delete}, etc... These methods
     * accept arguments which will be used to generate a query against a specific table.
     *
     * @param  string $method    The connection query method.
     * @param  string $tableName The table name.
     * @param  mixed  ...$args   Additional arguments passed to the query method.
     * @return mixed Returns the query result
     * @throws QueryError Throws exception when an error is encountered.
     */
    abstract protected function executeTableQuery(string $method, string $tableName, ...$args);

    /**
     * Initializes a new {@see Query} object for the table.
     *
     * @return \PrettyLinks\GroundLevel\QueryBuilder\Query
     */
    public function initQuery(): Query
    {
        return (new Query())->from($this->getTableName());
    }

    /**
     * Deletes records from the table.
     *
     * @param  array      $where  An associative array of WHERE clauses where
     *                           the key is the column name and the value is
     *                           the column value to check.
     * @param  array|null $format An indexed array of formats used to format the
     *                           $data array.
     * @return boolean|integer Returns the number of affected rows or false on error.
     * @throws QueryError Throws exception when an error is encountered.
     */
    public function delete(array $where, array $format = []): int
    {
        $rows = $this->executeTableQuery('delete', $this->getTableName(), $where, $format);
        return false !== $rows ? $rows : false;
    }

    /**
     * Retrieves the most recent record for the given key.
     *
     * @param  string $colName The column name.
     * @param  mixed  $value   The value of the column.
     * @param  array  $columns The columns to return, defaults to all columns.
     * @return null|object The record object or null if not found.
     */
    public function findBy(string $colName, $value, array $columns = [])
    {
        $res = $this->select(
            function (Query $query) use ($colName, $value, $columns): void {
                $query
                    ->select($columns)
                    ->where($colName, $value)
                    ->orderBy($colName, 'DESC')
                    ->limit(1);
            }
        );
        return $res ? $res[0] : null;
    }

    /**
     * Inserts a record with the supplied data into the table.
     *
     * @param  array $data   An associative array of data to insert into the table
     *                      where the key is the column name and the value is the
     *                      column value.
     * @param  array $format An indexed array of formats used to format the inserted
     *                      $data. Accepts %s for strings, %d for integers, and %f
     *                      for floats.
     * @return integer Returns the ID of the inserted record.
     * @throws QueryError Throws exception when an error is encountered.
     */
    public function insert(array $data, array $format = []): int
    {
        $this->executeTableQuery('insert', $this->getTableName(), $data, $format);
        return $this->getInsertId();
    }

    /**
     * Selects records from the table.
     *
     * @param  array|Closure $colsOrClosure An associative array of columns to pass to {@see Query::select}
     *                                     or a closure which is passed a new {@see Query}
     *                                     instance for the table. When using a closure, the
     *                                     resulting query will be used to perform the select.
     *                                     Using this method allows for more complex queries,
     *                                     including pagination, joins, orderby, expression
     *                                     clauses, and more.
     * @return array
     */
    public function select($colsOrClosure = []): array
    {
        $query = $this->initQuery();
        $query->select(is_array($colsOrClosure) ? $colsOrClosure : []);
        if ($colsOrClosure instanceof Closure) {
            $colsOrClosure($query);
        }
        return $this->executePreparedQuery('get_results', $query->getSql(), $query->getBindings());
    }

    /**
     * Updates records matching the supplied criteria with the supplied data.
     *
     * @param  array      $data        An associative array of data to update the
     *                                matching records with. The key is the column
     *                                name and the value is the column value.
     * @param  array      $where       An associative array of WHERE clauses where
     *                                the key is the column name and the value is
     *                                the column value to check.
     * @param  array|null $format      An indexed array of formats used to format the
     *                                $data array.
     * @param  array|null $whereFormat An indexed array of formats used to format the
     *                                $where array.
     * @return boolean|integer Returns the number of affected rows or false on error.
     * @throws QueryError Throws exception when an error is encountered.
     */
    public function update(array $data, array $where, array $format = [], array $whereFormat = [])
    {
        $rows = $this->executeTableQuery('update', $this->getTableName(), $data, $where, $format, $whereFormat);
        return false !== $rows ? $rows : false;
    }
}
