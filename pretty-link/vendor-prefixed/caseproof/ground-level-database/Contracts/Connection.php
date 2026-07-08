<?php

declare(strict_types=1);

namespace PrettyLinks\GroundLevel\Database\Contracts;

use PrettyLinks\GroundLevel\Database\DataFormat;

interface Connection
{
    /**
     * Output results as an array of objects where the object keys are the column
     * names and the values are the column values.
     */
    public const OUTPUT_OBJECT = 'OBJECT';

    /**
     * Output results as an array of objects where the object keys are the column
     * names and the values are the column values. The array is indexed by the
     * value of each row's first column's value (generally an ID).
     */
    public const OUTPUT_OBJECT_K = 'OBJECT_K';

    /**
     * Output results as an array of associative arrays where the array keys are
     * the column names and the values are the column values.
     */
    public const OUTPUT_ARRAY_A = 'ARRAY_A';

    /**
     * Output results as an array of numerically indexed arrays where the the values
     * are the column values.
     */
    public const OUTPUT_ARRAY_N = 'ARRAY_N';

    /**
     * Deletes row(s) from the database
     *
     * @link https://developer.wordpress.org/reference/classes/wpdb/delete/
     *
     * @param  string          $table       The table.
     * @param  mixed[]         $where       A named array of WHERE clauses (in column => value pairs).
     * @param  string[]|string $whereFormat Optional. An array of formats to be mapped to each of the
     *                                             value in $data. If string, that format will be used for all
     *                                             of the values in $data. See {@see DataFormat} for allowed
     *                                             types.
     * @return integer The number of rows affected.
     */
    public function delete(string $table, array $where, $whereFormat = DataFormat::STRING): int;

    /**
     * Retrieves the SQL results for the provided query.
     *
     * @param  string $query  The query to perform.
     * @param  string $output The output format, one of the OUTPUT_* constants.
     * @return array
     */
    public function get_results(string $query, string $output = Connection::OUTPUT_OBJECT): array; // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- see the wpdb method.

    /**
     * Retrieves a single variable from the database
     *
     * @link https://developer.wordpress.org/reference/classes/wpdb/get_var/
     *
     * @param  string  $query The query statement.
     * @param  integer $x     Column of value to return. Indexed from 0.
     * @param  integer $y     Row of value to return. Indexed from 0.
     * @return string Returns the query result as a string.
     */
    public function get_var(string $query, int $x = 0, int $y = 0): string; // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- see the wpdb method.

    /**
     * Determines whether or not the connection supports the specified feature.
     *
     * @link https://developer.wordpress.org/reference/classes/wpdb/has_cap/
     *
     * @param  string $cap The capability or feature name.
     * @return boolean
     */
    public function has_cap(string $cap): bool; // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- see the wpdb method.

    /**
     * Inserts a row into the database.
     *
     * @link https://developer.wordpress.org/reference/classes/wpdb/insert/
     *
     * @param  string          $table  The table to insert data into.
     * @param  mixed[]         $data   Data to insert (in column => value pairs).
     * @param  string[]|string $format An array of formats to be mapped to each of the
     *                                value in $data. If string, that format will be used for all
     *                                of the values in $data. See {@see DataFormat} for allowed
     *                                types.
     * @return integer The number of rows affected.
     */
    public function insert(string $table, array $data, $format = DataFormat::STRING): int;

    /**
     * Disables error display for the database connection.
     *
     * @link https://developer.wordpress.org/reference/classes/wpdb/hide_errors
     *
     * @return boolean Returns the value of $show_errors before it was changed.
     */
    public function hide_errors(): bool; // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- see the wpdb method.

    /**
     * Prepares an SQL query string for safe execution.
     *
     * @link https://developer.wordpress.org/reference/classes/wpdb/prepare/
     *
     * @param  string  $query The query string.
     * @param  mixed[] $args  Raw arguments to act as replacements in the query string.
     * @return string
     */
    public function prepare(string $query, array $args = []): string;

    /**
     * Performs a database query.
     *
     * @link https://developer.wordpress.org/reference/classes/wpdb/query/
     *
     * @param  string $query The query statement.
     * @return boolean|integer Returns true for CREATE, ALTER, TRUNCATE and DROP queries
     *                  the number of rows affected/selected for all other queries,
     *                  and false on error.
     */
    public function query(string $query);

    /**
     * Enables error display for the database connection.
     *
     * @link https://developer.wordpress.org/reference/classes/wpdb/show_errors/
     *
     * @param  boolean $show Whether or not to show errors.
     * @return boolean Returns the value of $show_errors before it was changed.
     */
    public function show_errors(bool $show = true): bool; // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- see the wpdb method.

    /**
     * Updates row(s) in the database.
     *
     * @link https://developer.wordpress.org/reference/classes/wpdb/update/
     *
     * @param  string          $table       The table to update.
     * @param  mixed[]         $data        Data to update (in column => value pairs).
     * @param  mixed[]         $where       A named array of WHERE clauses (in column => value pairs).
     * @param  string[]|string $format      An array of formats to be mapped to each of the
     *                                     value in $data. If string, that format will be used for all
     *                                     of the values in $data. See {@see DataFormat} for allowed
     *                                     types.
     * @param  string[]|string $whereFormat An array of formats to be mapped to each of the
     *                                     value in $where. If string, that format will be used for all
     *                                     of the values in $data. See {@see DataFormat} for allowed
     *                                     types.
     * @return integer The number of rows affected.
     */
    public function update(
        string $table,
        array $data,
        array $where,
        $format = DataFormat::STRING,
        $whereFormat = DataFormat::STRING
    ): int;
}
