<?php

declare(strict_types=1);

namespace PrettyLinks\GroundLevel\Package;

use PrettyLinks\GroundLevel\Package\Contracts\Configurable;

class PluginConfig extends Config implements Configurable
{
    /**
     * Maps configurable properties to plugin file header properties.
     *
     * @var string[]
     */
    public const HEADER_MAP = [
        self::PROP_AUTHOR       => 'Author',
        self::PROP_AUTHOR_URI   => 'AuthorURI',
        self::PROP_DESCRIPTION  => 'Description',
        self::PROP_DOMAIN_PATH  => 'DomainPath',
        self::PROP_NAME         => 'Name',
        self::PROP_NETWORK      => 'Network',
        self::PROP_TEXTDOMAIN   => 'TextDomain',
        self::PROP_URI          => 'PluginURI',
        self::PROP_VERSION      => 'Version',
        self::PROP_REQUIRES_WP  => 'RequiresWP',
        self::PROP_REQUIRES_PHP => 'RequiresPHP',
    ];

    /**
     * The plugin's main file path.
     *
     * @var string
     */
    protected string $pluginFile;

    /**
     * Constructor.
     *
     * Creates a new configuration from a WordPress plugin main file.
     *
     * @param string $pluginFile The plugin main file.
     * @param array  $props      Additional custom properties to add to the configuration.
     */
    public function __construct(string $pluginFile, array $props = [])
    {
        if (!function_exists('get_plugin_data') && defined('ABSPATH')) {
            require_once ABSPATH . '/wp-admin/includes/plugin.php';
        }

        $this->pluginFile = $pluginFile;

        $pluginData = get_plugin_data($this->pluginFile, false, false);
        foreach (static::HEADER_MAP as $configProp => $headerProp) {
            if (isset($pluginData[$headerProp])) {
                $props[$configProp] = $pluginData[$headerProp] ?? '';
            }
        }

        parent::__construct(
            plugin_basename($pluginFile),
            plugin_dir_path($pluginFile),
            plugins_url('/', $pluginFile),
            $props
        );
    }

    /**
     * Retrieves the plugin's main file path.
     *
     * @return string
     */
    public function getPluginFile(): string
    {
        return $this->pluginFile;
    }

    /**
     * Converts the configuration to an associative array.
     *
     * @return array
     */
    public function toArray(): array
    {
        $arr               = parent::toArray();
        $arr['pluginFile'] = $this->getPluginFile();
        return $arr;
    }
}
