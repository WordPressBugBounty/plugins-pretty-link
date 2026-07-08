<?php

declare(strict_types=1);

namespace PrettyLinks\Rest\Controllers;

use PrettyLinks\GroundLevel\Container\Container;
use PrettyLinks\Rest\Router;

abstract class BaseController
{
    /**
     * The service container instance.
     *
     * @var Container
     */
    protected Container $container;

    /**
     * Constructor.
     *
     * @param Container $container The service container instance.
     */
    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    /**
     * Registers the controller's REST routes.
     *
     * @return void
     */
    abstract public function register(): void;

    /**
     * Permission callback alias.
     *
     * @return callable
     */
    protected function permission(): callable
    {
        return [Router::class, 'permissionCheck'];
    }

    /**
     * Returns the REST namespace for this controller.
     *
     * @return string
     */
    protected function namespace(): string
    {
        return Router::NAMESPACE;
    }

    /**
     * Returns the current tracking mode: 'normal', 'extended', or 'count'.
     */
    protected function trackingMode(): string
    {
        $opts = get_option('prli_options');
        return is_array($opts) && isset($opts['extended_tracking'])
            ? (string) $opts['extended_tracking']
            : 'normal';
    }

    /**
     * True when the site is configured for Simple (count-only) tracking.
     * Controllers use this to short-circuit queries and payload fields
     * that depend on per-visit data, which Simple mode doesn't record.
     */
    protected function isCountMode(): bool
    {
        return $this->trackingMode() === 'count';
    }
}
