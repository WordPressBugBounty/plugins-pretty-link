<?php

declare(strict_types=1);

namespace PrettyLinks\GroundLevel\Package\Contracts;

use PrettyLinks\GroundLevel\Support\Contracts\Arrayable;

interface Configurable extends Arrayable
{
    public const PROP_AUTHOR       = 'author';
    public const PROP_AUTHOR_URI   = 'authorUri';
    public const PROP_DESCRIPTION  = 'description';
    public const PROP_DOMAIN_PATH  = 'domainPath';
    public const PROP_NAME         = 'name';
    public const PROP_TEMPLATE     = 'template';
    public const PROP_TEXTDOMAIN   = 'textDomain';
    public const PROP_URI          = 'uri';
    public const PROP_VERSION      = 'version';
    public const PROP_REQUIRES_WP  = 'requiresWp';
    public const PROP_REQUIRES_PHP = 'requiresPhp';
    public const PROP_TAGS         = 'tags';
    public const PROP_NETWORK      = 'network';

    public const DEFAULTS = [
        self::PROP_AUTHOR       => '',
        self::PROP_AUTHOR_URI   => '',
        self::PROP_DESCRIPTION  => '',
        self::PROP_DOMAIN_PATH  => '',
        self::PROP_NAME         => '',
        self::PROP_NETWORK      => false,
        self::PROP_TEMPLATE     => '',
        self::PROP_TEXTDOMAIN   => '',
        self::PROP_URI          => '',
        self::PROP_VERSION      => '',
        self::PROP_REQUIRES_WP  => null,
        self::PROP_REQUIRES_PHP => '7.4',
        self::PROP_TAGS         => [],
    ];

    /**
     * Retrieves the package author name.
     *
     * @return string
     */
    public function getAuthor(): string;

    /**
     * Retrieves the package author URI.
     *
     * @return string
     */
    public function getAuthorUri(): string;

    /**
     * Retrieves the package description.
     *
     * @return string
     */
    public function getDescription(): string;

    /**
     * Retrieves the package domain path.
     *
     * @return string
     */
    public function getDomainPath(): string;

    /**
     * Retrieves the package name.
     *
     * @return string
     */
    public function getName(): string;

    /**
     * Retrieves the package network value.
     *
     * @return boolean
     */
    public function getNetwork(): bool;

    /**
     * Retrieves the package template value.
     *
     * @return string
     */
    public function getTemplate(): string;

    /**
     * Retrieves the package text domain.
     *
     * @return string
     */
    public function getTextDomain(): string;

    /**
     * Retrieves the package URI.
     *
     * @return string
     */
    public function getUri(): string;

    /**
     * Retrieves the package version.
     *
     * @return string
     */
    public function getVersion(): string;

    /**
     * Retrieves the package's minimum WP version requirement.
     *
     * @return ?string
     */
    public function getRequiresWP(): ?string;

    /**
     * Retrieves the package's minimum PHP version requirements.
     *
     * @return string
     */
    public function getRequiresPHP(): string;

    /**
     * Retrieves the package tags.
     *
     * @return string[]
     */
    public function getTags(): array;
}
