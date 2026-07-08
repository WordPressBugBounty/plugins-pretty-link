<?php

declare(strict_types=1);

namespace PrettyLinks\GroundLevel\Package;

use PrettyLinks\GroundLevel\Package\Contracts\Configurable;

class ThemeConfig extends Config implements Configurable
{
    /**
     * Maps configurable properties to theme style.css header properties.
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
        self::PROP_TEMPLATE     => 'Template',
        self::PROP_TEXTDOMAIN   => 'TextDomain',
        self::PROP_URI          => 'ThemeURI',
        self::PROP_VERSION      => 'Version',
        self::PROP_REQUIRES_WP  => 'RequiresWP',
        self::PROP_REQUIRES_PHP => 'RequiresPHP',
    ];

    /**
     * Instance of WP_Theme.
     *
     * @var string
     */
    protected \WP_Theme $wpTheme;

    /**
     * Constructor.
     *
     * Creates a new configuration from a WordPress theme main file.
     *
     * @param string $stylesheet Directory name for the theme. Defaults to active theme.
     * @param string $themeRoot  Absolute path of the theme root to look in.
     *                           If not specified, get_raw_theme_root() is used to calculate the theme
     *                           root for the $stylesheet provided (or active theme).
     * @param array  $props      Additional custom properties to add to the configuration.
     */
    public function __construct(string $stylesheet = '', string $themeRoot = '', array $props = [])
    {
        if (!function_exists('wp_get_theme') && defined('ABSPATH')) {
            require_once \ABSPATH . '/wp-includes/theme.php';
        }

        $this->wpTheme = wp_get_theme($stylesheet, $themeRoot);
        foreach (static::HEADER_MAP as $configProp => $headerProp) {
            $props[$configProp] = $this->wpTheme->get($headerProp);
        }

        parent::__construct(
            $this->wpTheme->get_stylesheet(),
            $this->wpTheme->get_stylesheet_directory(),
            $this->wpTheme->get_stylesheet_directory_uri(),
            $props
        );
    }

    /**
     * Retrieves the WP_Theme instance of the theme.
     *
     * @return WP_Theme
     */
    public function getWpTheme(): \WP_Theme
    {
        return $this->wpTheme;
    }
}
