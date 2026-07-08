<?php

declare(strict_types=1);

namespace PrettyLinks\Support;

use PrettyLinks\GroundLevel\Container\Container;

/**
 * Implements StaticContainerAwareness with a single static container reference.
 *
 * Mirrors the HasStaticContainer trait that ground-level-container 1.x shipped
 * and 5.x dropped.
 */
trait HasStaticContainer
{
    /**
     * Shared service container reference.
     *
     * @var Container
     */
    protected static Container $container;

    /**
     * Returns the shared service container.
     *
     * @return Container Shared service container.
     */
    public static function getContainer(): Container
    {
        return static::$container;
    }

    /**
     * Sets the shared service container.
     *
     * @param Container $container Service container to store.
     *
     * @return void
     */
    public static function setContainer(Container $container): void
    {
        static::$container = $container;
    }
}
