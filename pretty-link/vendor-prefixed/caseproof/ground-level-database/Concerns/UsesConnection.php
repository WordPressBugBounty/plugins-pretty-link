<?php

declare(strict_types=1);

namespace PrettyLinks\GroundLevel\Database\Concerns;

use PrettyLinks\GroundLevel\Database\Errors;
use PrettyLinks\GroundLevel\Database\Exceptions\QueryError;

/**
 * Trait to provide access to WPDB with some quality of life improvements.
 */
trait UsesConnection
{
    use HasConnection;

    /**
     * Holds previous error states for $connection when disabling and restoring
     * errors.
     *
     * @var array
     */
    protected array $connectionErrorsState = [];

    /**
     * Executes a method on $connection with the specified arguments.
     *
     * This method will suppress any errors that occur during the execution of
     * the method and restore the error state afterwards.
     *
     * By default, \wpdb outputs some error messages, this method will suppress
     * those messages and throw a QueryError exception instead.
     *
     * @param  string $method  The method to execute.
     * @param  mixed  ...$args One or more arguments to pass to the Connection method.
     * @throws QueryError Throws exception when an error is encountered.
     */
    protected function connectionMethod(string $method, ...$args)
    {
        $conn = $this->getConnection();
        $this->disableConnectionErrors();
        $result = $conn->{$method}(...$args);
        $this->restoreConnectionErrors();
        if (! $this->hasError()) {
            return $result;
        }

        throw QueryError::generic(
            $conn->last_error, // phpcs:ignore Squiz.NamingConventions.ValidVariableName.MemberNotCamelCaps -- $conn is an abastraction of $wpdb.
            [
                '$method'   => $method,
                '$args'     => func_get_args(), // phpcs:ignore PHPCompatibility.FunctionUse.ArgumentFunctionsReportCurrentValue.NeedsInspection
                'lastError' => $conn->last_error, // phpcs:ignore Squiz.NamingConventions.ValidVariableName.MemberNotCamelCaps -- $conn is an abastraction of $wpdb.
                'lastQuery' => $conn->last_query, // phpcs:ignore Squiz.NamingConventions.ValidVariableName.MemberNotCamelCaps -- $conn is an abastraction of $wpdb.
            ]
        );
    }

    /**
     * Disables the connection's error output.
     *
     * The connections current error state is stored and can be restored with
     * {@see UsesConnection::restoreConnectionErrors}.
     *
     * @return self
     */
    protected function disableConnectionErrors(): self
    {
        $conn = $this->getConnection();
        $show = $conn->hide_errors();
        // $suppress = $conn->suppress_errors();
        // phpcs:ignore Squiz.Commenting.BlockComment.SingleLine -- for code commenting out purpose.
        $this->connectionErrorsState = compact('show'/*, 'suppress'*/);

        return $this;
    }

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
    protected function executePreparedQuery(string $method, string $query, array $args)
    {
        return $this->connectionMethod(
            $method,
            $this->prepareStatement($query, $args)
        );
    }

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
    protected function executeTableQuery(string $method, string $tableName, ...$args)
    {
        return $this->connectionMethod($method, $tableName, ...$args);
    }

    /**
     * Retrieves the AUTO_INCREMENT ID of the last inserted row.
     *
     * @return integer
     */
    protected function getInsertId(): int
    {
        return $this->getConnection()->insert_id;
    }

    /**
     * Determines if the last query was an error.
     *
     * @return boolean Returns true when the last query produced an error, otherwise
     *                 returns false.
     */
    protected function hasError(): bool
    {
        return ! empty($this->getConnection()->last_error);
    }

    /**
     * Prepares an SQL statement for safe execution.
     *
     * If no arguments are supplied then the $query will be used as a raw string,
     * otherwise the string and arguments are passed to the connection's `prepare()`
     * method.
     *
     * @param  string $query The SQL statement to prepare.
     * @param  array  $args  The arguments to substitute into the query's placeholders.
     * @return string
     */
    protected function prepareStatement(string $query, array $args = []): string
    {
        $query = trim(preg_replace('/\s+/', ' ', $query));
        if (empty($args)) {
            return $query;
        }
        return $this->getConnection()->prepare($query, $args);
    }

    /**
     * Restores the connection's error output and resets the stored error state.
     *
     * If there's no stored error state, this method does nothing.
     *
     * @return self
     */
    protected function restoreConnectionErrors(): self
    {
        if (! empty($this->connectionErrorsState)) {
            $wpdb = $this->getConnection();
            $wpdb->show_errors($this->connectionErrorsState['show']);
            // $wpdb->suppress_errors = $this->connectionErrorsState['suppress'];
            $this->connectionErrorsState = [];
        }

        return $this;
    }
}
