<?php

declare(strict_types=1);

namespace PrettyLinks\Support;

use PrettyLinks\GroundLevel\Container\Container;

/**
 * Marks a class that exposes a statically-set DI container.
 *
 * Mirrors the StaticContainerAwareness contract that ground-level-container 1.x
 * shipped and 5.x dropped. Kept locally so admin classes can resolve services
 * from static method contexts (WP hook callbacks) without rewiring every
 * consumer to instance-based access.
 */
interface StaticContainerAwareness
{
    /**
     * Returns the shared service container.
     *
     * @return Container Shared service container.
     */
    public static function getContainer(): Container;

    /**
     * Sets the shared service container.
     *
     * @param Container $container Service container to store.
     *
     * @return void
     */
    public static function setContainer(Container $container): void;
}
