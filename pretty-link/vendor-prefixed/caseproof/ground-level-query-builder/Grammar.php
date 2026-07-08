<?php

declare(strict_types=1);

namespace PrettyLinks\GroundLevel\QueryBuilder;

use Exception;
use PrettyLinks\GroundLevel\QueryBuilder\Contracts\Language;
use PrettyLinks\GroundLevel\QueryBuilder\Format;

/**
 * Formats SQL statements and parts.
 */
class Grammar implements Language
{
    /**
     * The query object.
     *
     * @var Query
     */
    protected Query $query;

    /**
     * Constructor.
     *
     * @param Query $query The query instance.
     */
    public function __construct(Query $query)
    {
        $this->query = $query;
    }

    /**
     * Writes the SQL statement.
     *
     * @param  boolean $humanReadable If true, the SQL will be formatted for human readability.
     * @return string
     */
    public function write(bool $humanReadable = false): string
    {
        $sql = $this->getSql();
        if (! $humanReadable) {
            $sql = Format::minify($sql);
        }
        return $sql;
    }

    /**
     * Combines SQL parts into a single string.
     *
     * @param  array $parts The SQL parts.
     * @return string
     */
    protected function combineParts(array $parts): string
    {
        return implode(PHP_EOL, array_filter($parts));
    }

    /**
     * Retrieves the SQL string for the current query based on it's type.
     *
     * @return string
     * @throws Exception If the query type is unknown.
     */
    protected function getSql(): string
    {
        $type   = $this->query->getQueryType();
        $method = 'write' . ucfirst(strtolower($type)) . 'Sql';
        if (! method_exists($this, $method)) {
            throw new Exception('Unknown query type: ' . $type);
        }
        return $this->$method();
    }

    /**
     * Writes the select query.
     *
     * @return string
     */
    protected function writeSelectSql(): string
    {
        $parts = [
            $this->getSelectColumnsSql(),
            $this->getFromSql(),
            // $this->getJoinSql(),
            $this->getWhereSql(),
            $this->getGroupBySql(),
            $this->getOrderBySql(),
            $this->getPaginationSql(),
        ];

        foreach ($parts as $index => &$part) {
            if (empty($part)) {
                unset($parts[$index]);
                continue;
            }
            if (is_array($part)) {
                $part = $this->combineParts($part);
            }
        }

        return $this->combineParts($parts) . ';';
    }

    /**
     * Formats a list of fields into SQL.
     *
     * Used to format select columns, group by columns, etc.
     *
     * @param  string[] $fieldsList Array of fields.
     * @return string
     */
    protected function getFieldsListSql(array $fieldsList): string
    {
        $fields = array_map(
            function (string $field): string {
                $field = Format::backtick($field);
                return Format::indent("{$field}");
            },
            $fieldsList
        );
        return implode(',' . PHP_EOL, $fields);
    }

    /**
     * Retrieves the GROUP BY clause SQL part.
     *
     * @return array
     */
    protected function getGroupBySql(): array
    {
        $groupBy = $this->query->getGroupBy();
        if (empty($groupBy)) {
            return [];
        }
        return ['GROUP BY', $this->getFieldsListSql($groupBy)];
    }

    /**
     * Retrieves the SQL part for the limit and offset clauses.
     *
     * @return string
     */
    protected function getPaginationSql(): string
    {
        $limit = $this->query->getLimit();
        if ($limit <= 0) {
            return '';
        }

        $offset = $this->query->getOffset();
        if ($offset > 0) {
            return sprintf('LIMIT %1$d, %2$d', $offset, $limit);
        }
        return sprintf('LIMIT %d', $limit);
    }

    /**
     * Retrieves the ORDER BY SQL clause for the query.
     *
     * @return array
     */
    protected function getOrderBySql(): array
    {
        $orderBy = $this->query->getOrderBy();
        if (empty($orderBy)) {
            return [];
        }
        $orderBy = array_map(
            function (string $orderBy): string {
                $parts = explode(' ', $orderBy);
                if (Language::RAND !== $parts[0]) {
                    $parts[0] = Format::backtick($parts[0]);
                }
                return Format::indent(implode(' ', $parts));
            },
            $orderBy
        );
        return ['ORDER BY', implode(',' . PHP_EOL, $orderBy)];
    }

    /**
     * Retrieves the SQL for the query's FROM clause.
     *
     * @return array
     */
    protected function getFromSql(): array
    {
        return [
            'FROM',
            Format::indent(
                Format::backtick($this->query->getTable())
            ),
        ];
    }

    /**
     * Retrieves the SQL part for the specified columns.
     *
     * @return array
     */
    protected function getSelectColumnsSql(): array
    {
        return [
            'SELECT',
            $this->getFieldsListSql($this->query->getSelectColumns()),
        ];
    }

    /**
     * Retrieves the SQL part for the query's WHERE clauses.
     *
     * @return array
     */
    protected function getWhereSql(): array
    {
        $clauses = $this->query->getWhereClauses();
        $sql     = $clauses->getSql();
        if (! $sql) {
            return [];
        }
        $this->query->addBindings($clauses->getBindings());
        return ['WHERE', $sql];
    }
}
