<?php

declare(strict_types=1);

namespace PrettyLinks\GroundLevel\Mothership;

/**
 * The Credentials class provides the interface for storing and retrieving credentials
 * based on environment variables, constants, and database (WordPress Database).
 */
class Credentials
{
    /**
     * The basename for the license key used in the License key authentication strategy.
     */
    public const LICENSE_KEY_BASENAME = 'license_key';

    /**
     * The basename for the domain used in the License key authentication strategy.
     */
    public const DOMAIN_BASENAME = 'domain';

    /**
     * The basename for the email used in the Email/Token authentication strategy.
     */
    public const EMAIL_BASENAME = 'email';

    /**
     * The basename for the API token used in the Token authentication strategy.
     */
    public const API_TOKEN_BASENAME = 'api_token';

    /**
     * The proxy license key used in the Email/Token strategy.
     *
     * @var string
     */
    private static string $proxyLicenseKey = '';

    /**
     * The plugin connection.
     *
     * @var AbstractPluginConnection
     */
    private AbstractPluginConnection $plugin;

    /**
     * The utility instance.
     *
     * @var Util
     */
    private Util $util;

    /**
     * Constructor.
     *
     * @param AbstractPluginConnection $plugin The plugin connection.
     * @param Util                     $util   The utility instance.
     */
    public function __construct(AbstractPluginConnection $plugin, Util $util)
    {
        $this->plugin = $plugin;
        $this->util   = $util;
    }

    /**
     * Get the mothership license key.
     *
     * @return string
     */
    public function getLicenseKey(): string
    {
        return $this->getCredential(self::LICENSE_KEY_BASENAME);
    }

    /**
     * Get the activation domain.
     *
     * @return string
     */
    public function getActivationDomain(): string
    {
        $domain = $this->getCredential(self::DOMAIN_BASENAME);

        // No domains provided? Let's set something as the default domain via the $_SERVER data.
        if (!$domain && isset($_SERVER['HTTP_HOST'])) {
            $domain = sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST']));
        }
        return $domain;
    }

    /**
     * Get the email for the email/token strategy.
     *
     * @return string
     */
    public function getEmail(): string
    {
        return $this->getCredential(self::EMAIL_BASENAME);
    }

    /**
     * Get the API token for the email/token strategy.
     *
     * @return string
     */
    public function getApiToken(): string
    {
        return $this->getCredential(self::API_TOKEN_BASENAME);
    }

    /**
     * Store License Key credentials in the database using the plugin's way of storing dbOptions.
     *
     * @param  string $licenseKey The mothership license key.
     * @throws \Exception If the credentials are already stored in environment variables or constants.
     * @return void
     */
    public function storeLicenseKey(string $licenseKey): void
    {
        if ($this->isCredentialSetInEnvironmentOrConstants(self::LICENSE_KEY_BASENAME)) {
            throw new \Exception(
                esc_html__(
                    'Cannot store credentials in database; found in environment variables or constants.',
                    'pretty-link'
                )
            );
        }
        $this->plugin->updateLicenseKey($licenseKey);
    }

    /**
     * Get credentials from environment variables, constants, or database in that order.
     *
     * @param  string $credentialName The base name of the credential to retrieve.
     * @return string
     */
    private function getCredential(string $credentialName): string
    {
        $credential = $this->isCredentialSetInEnvironmentOrConstants($credentialName);

        if ($credential) {
            return $credential;
        }
        switch ($credentialName) {
            case self::LICENSE_KEY_BASENAME:
                return (string) $this->plugin->getLicenseKey();
            case self::DOMAIN_BASENAME:
                return (string) $this->plugin->getDomain();
            case self::EMAIL_BASENAME:
                return (string) $this->plugin->getEmail();
            case self::API_TOKEN_BASENAME:
                return (string) $this->plugin->getApiToken();
            default:
                return '';
        }
    }

    /**
     * Checks and returns credentials if they are set in environment variables or constants otherwise returns false.
     *
     * The key used for the environment variable and constant is the $credentialName prefixed with
     * {@see \GroundLevel\Mothership\Util::composeConstantName()}.
     *
     * - An underscore is used as a separtor between the two strings
     * - The whole string is converted to uppercase
     * - Any dashes, dots, or spaces are converted to underscores
     *
     * For example, MemberCore uses the "MECO_" prefix, resulting in the following keys: MECO_LICENSE_KEY or MECO_DOMAIN
     *
     * @param  string $credentialName The credential name to check.
     * @return false|string String of credentials if stored in environment variables or constants, otherwise false.
     */
    public function isCredentialSetInEnvironmentOrConstants(string $credentialName)
    {
        $constantKey = $this->util->composeConstantName($credentialName);

        // Check if $constantKey is an environment variable.
        $envValue = getenv($constantKey);
        if (false !== $envValue) {
            return (string) $envValue;
        }

        // Check if $constantKey is a constant.
        if (defined($constantKey)) {
            return (string) constant($constantKey);
        }

        return false;
    }

    /**
     * Gets the proxy license key.
     *
     * @return string
     */
    public static function getProxyLicenseKey(): string
    {
        return self::$proxyLicenseKey;
    }

    /**
     * Set the proxy license key.
     *
     * This will be used as the user's temporary license key in the Email/Token authentication strategy.
     * It will be supplied in the X-Proxy-License-Key header.
     *
     * @param string $proxyLicenseKey The proxy license key.
     */
    public static function setProxyLicenseKey(string $proxyLicenseKey): void
    {
        self::$proxyLicenseKey = $proxyLicenseKey;
    }
}
