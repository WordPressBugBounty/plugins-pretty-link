<?php

declare(strict_types=1);

namespace PrettyLinks\GroundLevel\QueryBuilder;

/**
 * Formats SQL statements and parts.
 */
class Format
{
    /**
     * Indentation string (two spaces).
     */
    public const INDENT = '  ';

    /**
     * Wraps the given string in backticks.
     *
     * Automatically ignores strings in the $disallowed list.
     *
     * Recurses when aliases or dot-separated identifiers are found.
     *
     * @param  string $string The string to wrap.
     * @return string
     */
    public static function backtick(string $string): string
    {
        $as = ' ' . Query::AS . ' ';
        if (false !== strpos($string, $as)) {
            $parts = explode($as, $string);
            return implode($as, array_map([static::class, 'backtick'], $parts));
        }
        if (false !== strpos($string, '.')) {
            $parts = explode('.', $string);
            return implode('.', array_map([static::class, 'backtick'], $parts));
        }
        $disallowed = [Query::ALL_COLUMNS];
        $string     = ltrim(rtrim($string, '`'), '`');
        return in_array($string, $disallowed, true) ? $string : "`{$string}`";
    }

    /**
     * Intends a string by the given number of levels.
     *
     * @param  string  $string The string to indent.
     * @param  integer $level  The number of levels to indent the string.
     * @return string
     */
    public static function indent(string $string, int $level = 1): string
    {
        return str_repeat(static::INDENT, $level) . $string;
    }

    /**
     * Minifies a string by removing all indentation and newlines.
     *
     * @param  string $string The string to minify.
     * @return string
     */
    public static function minify(string $string): string
    {
        /*
         * Array of replacements.
         *
         * The first item in each array is the search string and the second item
         * is it's replacement.
         *
         * Replacements are made from top to bottom and the order *does matter*.
         */
        $replacements = [
            // Remove indentations.
            [static::INDENT, ''],
            // Replace parentheses on their own line with a parenthesis.
            ['(' . PHP_EOL, '('],
            [PHP_EOL . ')', ')'],
            // Replace remaining newlines a single spaces.
            [PHP_EOL, ' '],
        ];

        return trim(
            str_replace(
                array_column($replacements, 0),
                array_column($replacements, 1),
                $string
            )
        );
    }

    /**
     * Formats a subquery to be fully intended a given number of levels.
     *
     * @param  string  $query The subquery string to format.
     * @param  integer $level The number of levels to indent the subquery.
     * @return string
     */
    public static function subquery(string $query, int $level = 1): string
    {
        $parts = explode(PHP_EOL, trim(rtrim($query, ';')));
        $query = implode(
            PHP_EOL,
            array_map(
                [static::class, 'indent'],
                $parts,
                array_fill(0, count($parts), $level)
            )
        );
        return PHP_EOL . $query . PHP_EOL;
    }
}
