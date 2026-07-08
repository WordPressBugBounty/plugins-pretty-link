<?php

declare(strict_types=1);

namespace PrettyLinks\GroundLevel\Mothership;

use PrettyLinks\GroundLevel\Support\Str;

/**
 * Utility class for Mothership component.
 */
class Util
{
    /**
     * The API base URL.
     *
     * @inject \PrettyLinks\GroundLevel\Mothership\MothershipServiceProvider::PARAM_API_BASE_URL
     * @var    string
     */
    private string $apiBaseUrl;

    /**
     * The plugin connection.
     *
     * @var \PrettyLinks\GroundLevel\Mothership\AbstractPluginConnection
     */
    private AbstractPluginConnection $plugin;

    /**
     * Constructor.
     *
     * @param string                                           $apiBaseUrl The API base URL.
     * @param \PrettyLinks\GroundLevel\Mothership\AbstractPluginConnection $plugin     The plugin connection.
     */
    public function __construct(string $apiBaseUrl, AbstractPluginConnection $plugin)
    {
        $this->apiBaseUrl = $apiBaseUrl;
        $this->plugin     = $plugin;
    }

    /**
     * Composes a constant name by combining a prefix and a name.
     *
     * The prefix is ensured to end with an underscore, and the resulting constant name
     * is converted to uppercase with hyphens, periods, and spaces replaced by underscores.
     *
     * @param  string $name The name to be appended to the prefix.
     * @return string The composed constant name.
     */
    public function composeConstantName(string $name): string
    {
        $prefix = $this->plugin->pluginPrefix;
        $prefix = '_' === substr($prefix, -1) ? $prefix : $prefix . '_';

        return Str::toConstantCase($prefix . $name);
    }

    /**
     * Get the API base URL.
     *
     * Checks for a plugin-defined constant first (e.g., MYPLUGIN_MOTHERSHIP_API_BASE_URL),
     * then falls back to the configured API base URL.
     *
     * @return string The API base URL.
     */
    public function getApiBaseUrl(): string
    {
        $apiBaseUrlConstant = $this->composeConstantName('MOTHERSHIP_API_BASE_URL');

        if (defined($apiBaseUrlConstant)) {
            return constant($apiBaseUrlConstant);
        }

        return $this->apiBaseUrl;
    }
}
