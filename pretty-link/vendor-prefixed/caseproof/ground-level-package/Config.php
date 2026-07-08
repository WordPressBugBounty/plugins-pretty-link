<?php

declare(strict_types=1);

namespace PrettyLinks\GroundLevel\Package;

use BadMethodCallException;
use PrettyLinks\GroundLevel\Package\Contracts\Configurable;
use PrettyLinks\GroundLevel\Support\Str;
use Iterator;

/**
 * Base Config class.
 */
class Config implements Configurable
{
    /**
     * The package base name.
     *
     * @var string
     */
    protected string $baseName;

    /**
     * The path to the package's root directory.
     *
     * @var string
     */
    protected string $basePath;

    /**
     * The URL to the package's root directory.
     *
     * @var string|null
     */
    protected ?string $baseUrl;

    /**
     * Package configuration properties.
     *
     * @var array
     */
    protected array $props;

    /**
     * Constructor.
     *
     * @param string      $baseName The package base name.
     * @param string      $basePath The path to the package's root directory.
     * @param string|null $baseUrl  The URL to the package's root directory.
     * @param array       $props    Package configuration properties.
     */
    public function __construct(string $baseName, string $basePath, ?string $baseUrl, array $props = [])
    {
        $this->baseName = $baseName;
        $this->basePath = Str::trailingslashit($basePath);
        $this->baseUrl  = $baseUrl ? Str::trailingslashit($baseUrl) : null;
        $this->props    = array_merge(static::DEFAULTS, $props);
    }

    /**
     * Call magic method.
     *
     * Allows for retrieving custom configuration properties via a get{Property}() method.
     *
     * For a property named 'fooBar', the method would be getFooBar().
     *
     * @param  string $name The property name.
     * @param  array  $args Array of arguments passed to the method.
     * @throws BadMethodCallException If the corresponding property does not exist.
     */
    public function __call(string $name, array $args)
    {
        if ('get' === substr($name, 0, 3) && strlen($name) > 3) {
            $prop = Str::toCamelCase(ltrim($name, 'get'));
            if (array_key_exists($prop, $this->props)) {
                return $this->props[$prop];
            }
        }
        throw new BadMethodCallException(
            sprintf(
                'Call to undefined method %s::%s()',
                static::class,
                $name
            )
        );
    }

    /**
     * Retrieves the package author name.
     *
     * @return string
     */
    public function getAuthor(): string
    {
        return $this->props[static::PROP_AUTHOR];
    }

    /**
     * Retrieves the package author URI.
     *
     * @return string
     */
    public function getAuthorUri(): string
    {
        return $this->props[static::PROP_AUTHOR_URI];
    }

    /**
     * Retrieves the package base name.
     *
     * @return string
     */
    public function getBaseName(): string
    {
        return $this->baseName;
    }

    /**
     * Retrieves the package base path.
     *
     * @return string
     */
    public function getBasePath(): string
    {
        return $this->basePath;
    }

    /**
     * Retrieves the package base URL.
     *
     * @return ?string
     */
    public function getBaseUrl(): ?string
    {
        return $this->baseUrl;
    }

    /**
     * Retrieves the package description.
     *
     * @return string
     */
    public function getDescription(): string
    {
        return $this->props[static::PROP_DESCRIPTION];
    }

    /**
     * Retrieves the package domain path.
     *
     * @return string
     */
    public function getDomainPath(): string
    {
        return $this->props[static::PROP_DOMAIN_PATH];
    }

    /**
     * Retrieves the package template.
     *
     * @return string
     */
    public function getTemplate(): string
    {
        return $this->props[static::PROP_TEMPLATE] ?? '';
    }

    /**
     * Retrieves the package name.
     *
     * @return string
     */
    public function getName(): string
    {
        return $this->props[static::PROP_NAME];
    }

    /**
     * Retrieves the network value.
     *
     * @return string
     */
    public function getNetwork(): bool
    {
        return $this->props[static::PROP_NETWORK];
    }

    /**
     * Retrieves the package text domain.
     *
     * @return string
     */
    public function getTextDomain(): string
    {
        return $this->props[static::PROP_TEXTDOMAIN];
    }

    /**
     * Retrieves the package URI.
     *
     * @return string
     */
    public function getUri(): string
    {
        return $this->props[static::PROP_URI];
    }

    /**
     * Retrieves the package version.
     *
     * @return string
     */
    public function getVersion(): string
    {
        return $this->props[static::PROP_VERSION];
    }

    /**
     * Retrieves the package's minimum WP version requirement.
     *
     * @return ?string
     */
    public function getRequiresWP(): ?string
    {
        return $this->props[static::PROP_REQUIRES_WP];
    }

    /**
     * Retrieves the package's minimum PHP version requirements.
     *
     * @return string
     */
    public function getRequiresPHP(): string
    {
        return $this->props[static::PROP_REQUIRES_PHP];
    }

    /**
     * Retrieves the package tags.
     *
     * @return string[]
     */
    public function getTags(): array
    {
        return $this->props[static::PROP_TAGS];
    }

    /**
     * Converts the configuration to an associative array.
     *
     * @return array
     */
    public function toArray(): array
    {
        $arr = [
            'baseName' => $this->getBaseName(),
            'basePath' => $this->getBasePath(),
            'baseUrl'  => $this->getBaseUrl(),
        ];
        foreach ($this->props as $key => $value) {
            $arr[$key] = $value;
        }
        return $arr;
    }
}
