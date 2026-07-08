<?php

declare(strict_types=1);

namespace PrettyLinks\Licensing;

use PrettyLinks\GroundLevel\Mothership\AbstractPluginConnection;

/**
 * Ground Level Mothership plugin connector for Pretty Links.
 *
 * Wraps the v3.x-compatible license options so the Ground Level
 * Mothership service (and, via that, In-Product Notifications) can read
 * and write the same credentials already managed by {@see LicenseManager}.
 * The option keys are preserved from v3.x — REWRITE-PLAN.md Appendix A.
 */
class MothershipConnector extends AbstractPluginConnection
{
    /**
     * Mothership plugin identifier.
     *
     * @var string
     */
    protected string $pluginId = 'pretty-link';

    /**
     * Option key prefix used for stored license data.
     *
     * @var string
     */
    protected string $pluginPrefix = 'prli_';

    /**
     * Mothership product identifier.
     *
     * @var string
     */
    protected string $productId = 'pretty-links';

    /**
     * Set up the connector and compute the plugin file path.
     */
    public function __construct()
    {
        // Mothership's LicenseManager uses pluginFile for plugin update
        // integration (matching $extra['plugin'] in update payloads). Compute
        // from PRLI_FILE so it tracks the actual install location rather than
        // hardcoding 'pretty-link/pretty-link.php'.
        $this->pluginFile = plugin_basename(PRLI_FILE);
    }

    /**
     * Get the stored license activation status.
     *
     * @return boolean True when the license is marked activated.
     */
    public function getLicenseActivationStatus(): bool
    {
        return (bool) get_option('prli_activated', false);
    }

    /**
     * Persist the license activation status.
     *
     * @param boolean $status The activation status to store.
     *
     * @return boolean True on success, false otherwise.
     */
    public function updateLicenseActivationStatus(bool $status): bool
    {
        return update_option('prli_activated', $status, false);
    }

    /**
     * Get the stored license key.
     *
     * @return string The license key, or an empty string when none is set.
     */
    public function getLicenseKey(): string
    {
        $license = get_option('plp_mothership_license');
        if (is_array($license) && !empty($license['license_key'])) {
            return (string) $license['license_key'];
        }
        $legacy = get_option('prli_license_key');
        return is_string($legacy) ? $legacy : '';
    }

    /**
     * Persist the license key.
     *
     * @param string $licenseKey The license key to store.
     *
     * @return boolean True on success, false otherwise.
     */
    public function updateLicenseKey(string $licenseKey): bool
    {
        $license                = (array) (get_option('plp_mothership_license') ?: []);
        $license['license_key'] = $licenseKey;
        return update_option('plp_mothership_license', $license, false);
    }

    /**
     * Get the stored authenticator account email.
     *
     * @return string The account email, or an empty string when none is set.
     */
    public function getEmail(): string
    {
        $email = get_option('prli_authenticator_account_email');
        return is_string($email) ? $email : '';
    }

    /**
     * Get the stored authenticator secret API token.
     *
     * @return string The API token, or an empty string when none is set.
     */
    public function getApiToken(): string
    {
        $token = get_option('prli_authenticator_secret_token');
        return is_string($token) ? $token : '';
    }
}
