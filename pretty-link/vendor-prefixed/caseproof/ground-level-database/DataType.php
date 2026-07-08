<?php

declare(strict_types=1);

namespace PrettyLinks\GroundLevel\Database;

use PrettyLinks\GroundLevel\Support\Casts;
use PrettyLinks\GroundLevel\Support\Enum;

/**
 * List of available column data types
 *
 * @link https://dev.mysql.com/doc/refman/8.0/en/data-types.html
 *
 * @method static DataType CHAR() Returns the {@see DataType::CHAR} enum case.
 * @method static DataType VARCHAR() Returns the {@see DataType::VARCHAR} enum case.
 * @method static DataType BINARY() Returns the {@see DataType::BINARY} enum case.
 * @method static DataType VARBINARY() Returns the {@see DataType::VARBINARY} enum case.
 * @method static DataType TINYBLOB() Returns the {@see DataType::TINYBLOB} enum case.
 * @method static DataType TINYTEXT() Returns the {@see DataType::TINYTEXT} enum case.
 * @method static DataType TEXT() Returns the {@see DataType::TEXT} enum case.
 * @method static DataType BLOB() Returns the {@see DataType::BLOB} enum case.
 * @method static DataType MEDIUMTEXT() Returns the {@see DataType::MEDIUMTEXT} enum case.
 * @method static DataType MEDIUMBLOB() Returns the {@see DataType::MEDIUMBLOB} enum case.
 * @method static DataType LONGTEXT() Returns the {@see DataType::LONGTEXT} enum case.
 * @method static DataType LONGBLOB() Returns the {@see DataType::LONGBLOB} enum case.
 * @method static DataType ENUM() Returns the {@see DataType::ENUM} enum case.
 * @method static DataType SET() Returns the {@see DataType::SET} enum case.
 * @method static DataType BIT() Returns the {@see DataType::BIT} enum case.
 * @method static DataType TINYINT() Returns the {@see DataType::TINYINT} enum case.
 * @method static DataType BOOL() Returns the {@see DataType::BOOL} enum case.
 * @method static DataType BOOLEAN() Returns the {@see DataType::BOOLEAN} enum case.
 * @method static DataType SMALLINT() Returns the {@see DataType::SMALLINT} enum case.
 * @method static DataType MEDIUMINT() Returns the {@see DataType::MEDIUMINT} enum case.
 * @method static DataType INT() Returns the {@see DataType::INT} enum case.
 * @method static DataType INTEGER() Returns the {@see DataType::INTEGER} enum case.
 * @method static DataType BIGINT() Returns the {@see DataType::BIGINT} enum case.
 * @method static DataType FLOAT() Returns the {@see DataType::FLOAT} enum case.
 * @method static DataType DOUBLE() Returns the {@see DataType::DOUBLE} enum case.
 * @method static DataType DECIMAL() Returns the {@see DataType::DECIMAL} enum case.
 * @method static DataType DEC() Returns the {@see DataType::DEC} enum case.
 * @method static DataType DATE() Returns the {@see DataType::DATE} enum case.
 * @method static DataType DATETIME() Returns the {@see DataType::DATETIME} enum case.
 * @method static DataType TIMESTAMP() Returns the {@see DataType::TIMESTAMP} enum case.
 * @method static DataType TIME() Returns the {@see DataType::TIME} enum case.
 * @method static DataType YEAR() Returns the {@see DataType::YEAR} enum case.
 */
class DataType extends Enum
{
    /**************************************
     * String and Time Data Types.
     **************************************/

    /**
     * Fixed length strings with a maximum length of 255 characters.
     */
    public const CHAR = 'char';

    /**
     * Variable length strings with a maximum length of 65,535 characters.
     */
    public const VARCHAR = 'varchar';

    /**
     * Equal to CHAR, but stores binary byte strings.
     */
    public const BINARY = 'binary';

    /**
     * Equal to VARCHAR, but stores binary byte strings.
     */
    public const VARBINARY = 'varbinary';

    /**
     *  For BLOBs (Binary Large OBjects). Max length: 255 bytes
     */
    public const TINYBLOB = 'tinyblob';

    /**
     *  Holds a string with a maximum length of 255 characters.
     */
    public const TINYTEXT = 'tinytext';

    /**
     * Holds a string with a maximum length of 65,535 bytes.
     */
    public const TEXT = 'text';

    /**
     * For BLOBs (Binary Large OBjects). Holds up to 65,535 bytes of data.
     */
    public const BLOB = 'blob';

    /**
     *  Holds a string with a maximum length of 16,777,215 characters.
     */
    public const MEDIUMTEXT = 'mediumtext';

    /**
     *  For BLOBs (Binary Large OBjects). Holds up to 16,777,215 bytes of data.
     */
    public const MEDIUMBLOB = 'mediumblob';

    /**
     *  Holds a string with a maximum length of 4,294,967,295 characters.
     */
    public const LONGTEXT = 'longtext';

    /**
     *  For BLOBs (Binary Large OBjects). Holds up to 4,294,967,295 bytes of data.
     */
    public const LONGBLOB = 'longblob';

    /**
     * A string object that can have only one value, chosen from a list of possible
     * values. You can list up to 65535 values in an ENUM list. If a value is inserted
     * that is not in the list, a blank value will be inserted. The values are sorted
     * in the order you enter them
     */
    public const ENUM = 'enum';

    /**
     * A string object that can have 0 or more values, chosen from a list of possible
     * values. You can list up to 64 values in a SET list
     */
    public const SET = 'set';

    /**************************************
     * Numeric data types
     **************************************/

    /**
     * A bit-value type. The size parameter can hold a value from 1 to 64. The default
     * value for size is 1.
     */
    public const BIT = 'bit';

    /**
     * A very small integer. Signed range is from -128 to 127. Unsigned range is
     * from 0 to 255. The size parameter specifies the maximum display width (which
     * is 255).
     */
    public const TINYINT = 'tinyint';

    /**
     * Zero is considered as false, nonzero values are considered as true.
     */
    public const BOOL = 'bool';

    /**
     * Equal to BOOL.
     */
    public const BOOLEAN = 'boolean';

    /**
     * A small integer. Signed range is from -32768 to 32767. Unsigned range is
     * from 0 to 65535. The size parameter specifies the maximum display width (which
     * is 255).
     */
    public const SMALLINT = 'smallint';

    /**
     * A medium integer. Signed range is from -8388608 to 8388607. Unsigned range
     * is from 0 to 16777215. The size parameter specifies the maximum display width
     * (which is 255).
     */
    public const MEDIUMINT = 'mediumint';

    /**
     * A medium integer. Signed range is from -2147483648 to 2147483647. Unsigned
     * range is from 0 to 4294967295. The size parameter specifies the maximum display
     * width (which is 255).
     */
    public const INT = 'int';

    /**
     * Equal to INT(size)
     */
    public const INTEGER = 'integer';

    /**
     * A large integer. Signed range is from -9223372036854775808 to 9223372036854775807.
     * Unsigned range is from 0 to 18446744073709551615. The size parameter specifies
     * the maximum display width (which is 255)
     */
    public const BIGINT = 'bigint';

    /**
     * A floating point number. The total number of digits is specified in size.
     * The number of digits after the decimal point is specified in the d parameter.
     * This syntax is deprecated in MySQL 8.0.17, and it will be removed in future
     * MySQL versions
     */
    public const FLOAT = 'float';

    /**
     * A normal-size floating point number.
     */
    public const DOUBLE = 'double';

    /**
     * An exact fixed-point number.
     */
    public const DECIMAL = 'decimal';

    /**
     * Equal to DECIMAL(size,d)
     */
    public const DEC = 'dec';

    /**************************************
     * Date and Time Data Types.
     **************************************/

    /**
     * A date in YYYY-MM-DD format.
     */
    public const DATE = 'date';

    /**
     * A date and time combination in YYYY-MM-DD hh:mm format.
     */
    public const DATETIME = 'datetime';

    /**
     * A UNIX timestamp integer.
     */
    public const TIMESTAMP = 'timestamp';

    /**
     * A time in hh:mm:ss format.
     */
    public const TIME = 'time';

    /**
     * A year in four-digit format.
     */
    public const YEAR = 'year';

    /**
     * Retrieves the default enum case, {@see self::VARCHAR}.
     *
     * @return static
     */
    public static function default()
    {
        return static::VARCHAR();
    }

    /**
     * Maps a DataType to its {@see Casts} type equivalent.
     *
     * @param  DataType|string $type The DataType.
     * @return Casts The equivalent cast.
     */
    public static function toCast($type): Casts
    {
        $type = (new static($type))->getValue();
        switch ($type) {
            case self::BIT:
            case self::TINYINT:
            case self::BOOL:
            case self::BOOLEAN:
            case self::SMALLINT:
            case self::MEDIUMINT:
            case self::INT:
            case self::INTEGER:
            case self::BIGINT:
            case self::TIMESTAMP:
                return Casts::INTEGER();
                break;
            case self::FLOAT:
            case self::DOUBLE:
            case self::DECIMAL:
            case self::DEC:
                return Casts::FLOAT();
                break;
            case self::CHAR:
            case self::VARCHAR:
            case self::BINARY:
            case self::VARBINARY:
            case self::TINYBLOB:
            case self::TINYTEXT:
            case self::TEXT:
            case self::BLOB:
            case self::MEDIUMTEXT:
            case self::MEDIUMBLOB:
            case self::LONGTEXT:
            case self::LONGBLOB:
            case self::ENUM:
            case self::SET:
            case self::DATE:
            case self::TIME:
            case self::DATETIME:
            case self::YEAR:
                return Casts::STRING();
        }
    }

    /**
     * Maps a DataType to it's equivalent database sanitization format.
     *
     * @param  DataType|string $type The DataType.
     * @return DataFormat The equivalent database format.
     */
    public static function toDataFormat($type): DataFormat
    {
        $type = (new static($type))->getValue();
        switch ($type) {
            case self::BIT:
            case self::TINYINT:
            case self::BOOL:
            case self::BOOLEAN:
            case self::SMALLINT:
            case self::MEDIUMINT:
            case self::INT:
            case self::INTEGER:
            case self::BIGINT:
            case self::TIMESTAMP:
                return DataFormat::INTEGER();
                break;
            case self::FLOAT:
            case self::DOUBLE:
            case self::DECIMAL:
            case self::DEC:
                return DataFormat::FLOAT();
                break;
            case self::CHAR:
            case self::VARCHAR:
            case self::BINARY:
            case self::VARBINARY:
            case self::TINYBLOB:
            case self::TINYTEXT:
            case self::TEXT:
            case self::BLOB:
            case self::MEDIUMTEXT:
            case self::MEDIUMBLOB:
            case self::LONGTEXT:
            case self::LONGBLOB:
            case self::ENUM:
            case self::SET:
            case self::DATE:
            case self::TIME:
            case self::DATETIME:
            case self::YEAR:
                return DataFormat::STRING();
        }
    }
}
