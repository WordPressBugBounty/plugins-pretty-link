<?php

declare(strict_types=1);

namespace PrettyLinks;

defined('ABSPATH') || exit;

use PrettyLinks\GroundLevel\Package\PluginConfig;

/**
 * The main application instance.
 *
 * Called once from the plugin's main entry file. Returns a singleton
 * Bootstrap instance, which owns the dependency injection container.
 *
 * @param  string $mainFile Absolute path to the plugin's main entry file.
 * @return Bootstrap The singleton application instance.
 */
function prettyLinksApp(string $mainFile): Bootstrap
{
    static $app = null;
    if (is_null($app)) {
        $app = new Bootstrap(new PluginConfig($mainFile));
    }
    return $app;
}
