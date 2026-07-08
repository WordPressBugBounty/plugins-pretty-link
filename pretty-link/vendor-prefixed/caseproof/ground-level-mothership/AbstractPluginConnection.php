<?php

declare(strict_types=1);

namespace PrettyLinks\GroundLevel\Mothership;

/**
 * This class is used to serve as a contract to set how the plugin gets/sets data (license key, domain, email, api token) used to connect to the mothership.
 *
 * Two authentication methods are supported:
 * 1. License Key and Domain.
 * 2. Email and API Token.
 *
 * The License Key and Domain method is the default and is used to authenticate the plugin.
 *
 * The consumer plugin may override the public methods to implement its own logic for retrieving
 * and storing the data used to connect to the Mothership API.
 *
 * @property string $pluginId     The ID of the plugin using this component.
 * @property string $pluginPrefix The prefix of the plugin using this component.
 * @property string $productId    The ID of the product using this component.
 * @property string $pluginFile   The plugin basename relative to the plugins directory.
 */
abstract class AbstractPluginConnection
{
    public const AUTOMATIC_UPDATE_ALL   = 'all';
    public const AUTOMATIC_UPDATE_MINOR = 'minor';
    public const AUTOMATIC_UPDATE_NONE  = 'none';

    /**
     * The ID of the plugin using this component.
     *
     * @var string
     */
    protected string $pluginId;

    /**
     * Used to set the constants for the plugin's license key, domain, email, and API token for local development.
     *
     * A trailing underscore is automatically added to the prefix if it is not already present.
     *
     * @var string
     */
    protected string $pluginPrefix;

    /**
     * Used to connect to the Mothership API.
     *
     * @var string
     */
    protected string $productId = '';

    /**
     * The plugin basename relative to the plugins directory.
     *
     * For example: 'ground-level/ground-level.php'. This is the same format returned
     * by WordPress's `plugin_basename()` function.
     *
     * @var string
     */
    protected string $pluginFile = '';

    /**
     * Magic method to get the property of the class.
     *
     * @param  string $name The name of the property.
     * @return mixed|null The value of the property or null if the property does not exist.
     */
    public function __get(string $name)
    {
        if (property_exists($this, $name)) {
            return $this->$name;
        }
        return null;
    }

    /**
     * Gets the license activation status.
     *
     * @return boolean
     */
    public function getLicenseActivationStatus(): bool
    {
        return (bool) get_option($this->pluginId . '_license_active', false);
    }


    /**
     * Updates the license activation status.
     *
     * @param  boolean $status The new status of the license activation.
     * @return boolean Whether the license activation status was updated successfully.
     */
    public function updateLicenseActivationStatus(bool $status): bool
    {
        return update_option($this->pluginId . '_license_active', $status);
    }

    /**
     * Gets the license key.
     *
     * @return string The license key.
     */
    public function getLicenseKey(): string
    {
        return (string) get_option($this->pluginId . '_license_key', '');
    }

    /**
     * Updates the license key.
     *
     * @param  string $licenseKey The license key.
     * @return boolean Whether the license key was updated successfully.
     */
    public function updateLicenseKey(string $licenseKey): bool
    {
        return update_option($this->pluginId . '_license_key', $licenseKey);
    }

    /**
     * Whether to include prerelease versions (alpha, beta, custom or release_candidate) when checking for updates.
     *
     * @return boolean
     */
    public function allowPrereleaseVersions(): bool
    {
        /**
         * Filters whether prerelease versions are included when checking for updates.
         *
         * @param bool $allow Whether to allow prerelease versions. Default false.
         */
        return (bool) apply_filters("{$this->pluginId}_allow_prerelease_versions", false);
    }

    /**
     * Controls which updates are applied automatically during WordPress background updates.
     *
     * Possible values:
     * - {@see self::AUTOMATIC_UPDATE_ALL}   - auto-update for all versions including major bumps.
     * - {@see self::AUTOMATIC_UPDATE_MINOR} - auto-update for minor and patch only, blocks major bumps.
     * - {@see self::AUTOMATIC_UPDATE_NONE}  - never auto-update.
     *
     * @return string
     */
    public function automaticUpdates(): string
    {
        /**
         * Filters the automatic update level for the plugin.
         *
         * @param string $level The automatic update level. Default self::AUTOMATIC_UPDATE_MINOR.
         */
        return (string) apply_filters("{$this->pluginId}_automatic_updates", self::AUTOMATIC_UPDATE_MINOR);
    }

    /**
     * Gets the account URL.
     *
     * @return string The account URL.
     */
    public function getAccountUrl(): string
    {
        return '';
    }

    /**
     * Gets the domain.
     *
     * @return string The domain.
     */
    public function getDomain(): string
    {
        return parse_url(get_home_url(), PHP_URL_HOST);
    }

    /**
     * Gets the email.
     *
     * @return string The email.
     */
    public function getEmail(): string
    {
        return '';
    }

    /**
     * Gets the API token.
     *
     * @return string The API token.
     */
    public function getApiToken(): string
    {
        return '';
    }
}
