<?php

declare(strict_types=1);

namespace PrettyLinks\GroundLevel\Package\Exceptions;

use Exception;

class RequirementsError extends Exception
{
    /**
     * Error code: PHP version requirement not met.
     */
    public const E_PHP_VERSION = 100;

    /**
     * Error code: WP version requirement not met.
     */
    public const E_WP_VERSION = 200;

    /**
     * Creates a new exception instance denoting the minimum PHP version requirements are not met.
     *
     * @param  string $currVersion     The current PHP version.
     * @param  string $requiredVersion The minimum PHP version required.
     * @return self
     */
    public static function phpVersion(string $currVersion, string $requiredVersion): self
    {
        return new self(
            "The current PHP version, {$currVersion}, is not supported. Requires {$requiredVersion} or higher.",
            self::E_PHP_VERSION
        );
    }

    /**
     * Creates a new exception instance denoting the minimum WP version requirements are not met.
     *
     * @param  string $currVersion     The current WP version.
     * @param  string $requiredVersion The minimum WP version required.
     * @return self
     */
    public static function wpVersion(string $currVersion, string $requiredVersion): self
    {
        return new self(
            "The current WordPress version, {$currVersion}, is not supported. Requires {$requiredVersion} or higher.",
            self::E_WP_VERSION
        );
    }
}
