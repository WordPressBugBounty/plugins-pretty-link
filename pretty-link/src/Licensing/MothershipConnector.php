<?php

declare(strict_types=1);

namespace PrettyLinks\Licensing;

use PrettyLinks\GroundLevel\Mothership\AbstractPluginConnection;

/**
 * Ground Level Mothership plugin connector for Pretty Links.
 *
 * The single source of truth that tells ground-level-mothership how Pretty
 * Links stores and reads its license credentials, which Mothership product to
 * check for updates, and how to behave for prerelease/automatic updates.
 *
 * Credential storage maps onto the v3.x-compatible option keys
 * (`plp_mothership_license`, `prli_activated`, the authenticator options) so
 * existing installs keep one source of truth shared with the Pretty Links
 * admin UI — REWRITE-PLAN.md Appendix A.
 *
 * Consumer code MUST NOT call the `resolve*()`/`store*()` methods directly —
 * they are the storage contract invoked by {@see Credentials}. Read/write
 * credentials through the container's `Credentials` service instead.
 */
class MothershipConnector extends AbstractPluginConnection
{
    /**
     * Plugin identifier. Drives the GL hook prefix (`pretty-link_*`), the
     * `update_plugins_pretty-link` filter, and the `Update URI: pretty-link`
     * plugin header that routes WordPress update checks to GL.
     *
     * @var string
     */
    protected string $pluginId = 'pretty-link';

    /**
     * Credential constant/env prefix. Yields `PRLI_LICENSE_KEY`, `PRLI_DOMAIN`,
     * and `PRLI_MOTHERSHIP_API_BASE_URL` (staging override) via Util.
     *
     * @var string
     */
    protected string $pluginPrefix = 'prli_';

    /**
     * A key that stands in for the stored one for the duration of one call.
     *
     * @var string|null
     */
    private static $licenseKeyOverride = null;

    /**
     * Sets the per-edition product ID and the plugin file used for updates.
     */
    public function __construct()
    {
        // The Mothership product ID used for version/update checks. The build
        // swaps PRLI_EDITION per edition ZIP (lite, beginner, marketer,
        // executive, …) and each edition is a distinct Mothership product, so
        // updates resolve the correct edition's package. Mirrors membercore's
        // `productId = MECO_EDITION`.
        $this->productId = defined('PRLI_EDITION') ? (string) constant('PRLI_EDITION') : 'pretty-link-lite';

        // Mothership keys update integration off pluginFile (matches WP's
        // plugin basename in update payloads). Compute from PRLI_FILE so it
        // tracks the actual install location.
        $this->pluginFile = plugin_basename(PRLI_FILE);
    }

    /**
     * Account URL surfaced by GL admin notices (e.g. license-revoked notices).
     *
     * @return string The account dashboard URL.
     */
    public function getAccountUrl(): string
    {
        return 'https://prettylinks.com/account/';
    }

    /**
     * Include prerelease (edge) builds when checking for updates. Mirrors
     * {@see LicenseManager::edgeEnabled()} — the `PRETTYLINK_EDGE` constant or
     * the `plp_edge_updates` option opt in.
     *
     * @return boolean Whether prerelease versions are allowed.
     */
    public function allowPrereleaseVersions(): bool
    {
        $edge = (defined('PRETTYLINK_EDGE') && constant('PRETTYLINK_EDGE'))
            || (bool) get_option(LicenseManager::OPTION_EDGE_UPDATES, false);

        // See AbstractPluginConnection::allowPrereleaseVersions() for the filter contract.
        return (bool) apply_filters("{$this->pluginId}_allow_prerelease_versions", $edge);
    }

    /**
     * Canonical activation domain. Mirrors the v3.x `PrliUtils::site_domain()`
     * shape (siteurl host plus any subdirectory path) so subdirectory installs
     * activate under a stable, unique domain.
     *
     * @return string The activation domain.
     */
    public function resolveDomain(): string
    {
        $url   = (string) get_option('siteurl');
        $parts = wp_parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return (string) wp_parse_url(home_url(), PHP_URL_HOST);
        }
        $host = (string) $parts['host'];
        if (!empty($parts['path'])) {
            $host .= rtrim((string) $parts['path'], '/');
        }
        return $host;
    }

    /**
     * Whether the license is currently marked active for this site.
     *
     * @return boolean
     */
    public function getLicenseActivationStatus(): bool
    {
        return (bool) get_option('prli_activated', false);
    }

    /**
     * Stores the license activation status.
     *
     * Returns true when the stored status already matches, because
     * `update_option()` answers false for an unchanged value — it cannot tell
     * "nothing to do" apart from "the write failed". That distinction became
     * load-bearing in ground-level-mothership 9.1.2, whose deactivateLicense()
     * now aborts with a failure when this returns false, so deactivating an
     * already-inactive licence reported "Failed to deactivate the license" and
     * skipped the rest of the teardown.
     *
     * @param  boolean $status The new activation status.
     * @return boolean Whether the stored status is now $status.
     */
    public function setLicenseActivationStatus(bool $status): bool
    {
        if ($this->getLicenseActivationStatus() === $status) {
            return true;
        }

        return update_option('prli_activated', $status, false);
    }

    /**
     * Runs $work with $key standing in for the stored license key.
     *
     * GL builds its `Authorization: Basic domain:key` header from
     * `Credentials::getLicenseKey()`, which falls through to
     * {@see self::resolveLicenseKey()}. That is fine everywhere except
     * {@see \PrettyLinks\Licensing\LicenseManager::releaseSupersededKey()},
     * which has to talk about a key that is no longer the stored one — and
     * whether the licenses server authorises that call from the path segment or
     * from the header is not something this plugin can know. Signing the
     * request with the key being released makes it correct under either answer,
     * which beats waiting for one.
     *
     * A constant or environment override still wins — `getCredential()` checks
     * those first — but a key that comes from a constant cannot have been
     * superseded by this path in the first place.
     *
     * @param  string   $key  The key to sign with.
     * @param  callable $work The work to run.
     * @return mixed The callable's return value.
     */
    public static function withLicenseKey(string $key, callable $work)
    {
        $previous                 = self::$licenseKeyOverride;
        self::$licenseKeyOverride = $key;

        try {
            return $work();
        } finally {
            self::$licenseKeyOverride = $previous;
        }
    }

    /**
     * Resolves the stored license key from Pretty Links' option storage.
     *
     * @return string The license key, or an empty string when unset.
     */
    public function resolveLicenseKey(): string
    {
        if (self::$licenseKeyOverride !== null) {
            return self::$licenseKeyOverride;
        }

        $license = get_option('plp_mothership_license');
        // Current storage: array { license_key: … } written by storeLicenseKey().
        if (is_array($license) && !empty($license['license_key'])) {
            return (string) $license['license_key'];
        }
        // Back-compat: earlier v4 builds stored the key as a bare string under
        // the same option key, so tolerate that shape too.
        if (is_string($license) && $license !== '') {
            return $license;
        }
        $legacy = get_option('prli_license_key');
        return is_string($legacy) ? $legacy : '';
    }

    /**
     * Never background-install updates unless the site opts in.
     *
     * GL defaults to AUTOMATIC_UPDATE_MINOR, which makes shouldAutoUpdate()
     * return true for any same-major offer on a licensed site — a hard yes
     * that WordPress obeys regardless of the site's own auto-update setting.
     * That is a behaviour change, not a carry-over: v5's equivalent compared
     * `$item->Version`, which core never sets on an offer row, so the cron
     * path always answered false and nothing was ever installed unattended.
     * v8 reads the installed version off disk instead, so the same code now
     * answers true.
     *
     * Silently auto-installing a plugin that owns a site's redirects is not a
     * default anyone chose. Default to "none" and keep GL's own filter, so a
     * site that wants minor auto-updates is one add_filter away:
     *
     *     add_filter('pretty-link_automatic_updates', fn() => 'minor');
     *
     * "none" does NOT mean "never" here. GL's enum has no value for "defer to
     * the site", and a hard `false` overrides the Plugins-screen "Enable
     * auto-updates" toggle just as surely as GL's `minor` default overrides it
     * the other way — so the moment a customer activated a licence, their own
     * setting stopped mattering. {@see \PrettyLinks\Updates\AutoUpdatePolicy}
     * restores it: under this default the site's own choice stands, and only an
     * explicit `all` or `minor` from the filter above is treated as an
     * instruction.
     *
     * @return string One of the AUTOMATIC_UPDATE_* policies.
     */
    public function automaticUpdates(): string
    {
        /**
         * Filters the automatic update level for Pretty Links.
         *
         * @param string $level One of 'all', 'minor' or 'none'. Default 'none'.
         */
        return (string) apply_filters(
            "{$this->pluginId}_automatic_updates",
            self::AUTOMATIC_UPDATE_NONE
        );
    }

    /**
     * Persists the license key as a bare string.
     *
     * On develop the real write path (LicenseManager::setKey(), reached from
     * the REST route, the wizard and activateFromDefine()) stored a plain string;
     * the array shape existed only in a connector method develop itself
     * described as "a shape nothing writes". GL's Credentials calls this
     * method, which made the array shape the only one written — and that is a
     * one-way door. develop reads this option with a `(string)` cast, so a
     * rollback would turn every read into the literal 'Array', and
     * Wizard::hasExistingData() does the same cast on this branch. Since the
     * migration also used to delete `prli_license_info`, a rolled-back Lite
     * install had nothing left to recover from.
     *
     * {@see self::resolveLicenseKey()} still reads both shapes, so installs
     * already carrying the array keep working.
     *
     * @param  string $licenseKey The license key to store.
     * @return boolean Whether the option was updated.
     */
    public function storeLicenseKey(string $licenseKey): bool
    {
        return update_option('plp_mothership_license', $licenseKey, false);
    }

    /**
     * Resolves the email used by the email/token auth strategy.
     *
     * @return string The connected account email, or an empty string.
     */
    public function resolveEmail(): string
    {
        $email = get_option('prli_authenticator_account_email');
        return is_string($email) ? $email : '';
    }

    /**
     * Resolves the API token used by the email/token auth strategy.
     *
     * @return string The API token, or an empty string.
     */
    public function resolveApiToken(): string
    {
        $token = get_option('prli_authenticator_secret_token');
        return is_string($token) ? $token : '';
    }
}
