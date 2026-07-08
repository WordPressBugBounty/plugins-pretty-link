<?php

declare(strict_types=1);

namespace PrettyLinks\Licensing;

use PrettyLinks\Admin\Notices;

/**
 * Facade over the license state stored in WP options/transients.
 *
 * Preserves every v3.x option and transient key name:
 *   plp_mothership_license   — the license key string (option)
 *   plp_edge_updates         — bleeding-edge release channel (option)
 *   prli_activated           — boolean active/inactive flag (option)
 *   prli_license_check_{key} — 24/72h throttle (site transient) + fail counter (option)
 *   prli_license_info        — 24h license payload (site transient)
 *   prli_update_info         — 12h plugin update info (site transient)
 *   prli_addons              — 12h addons listing (site transient)
 *   prli_all_addons          — 12h full addons listing (site transient)
 *
 * See inventory 09-lite-addons-updates.md §11 for the full back-compat list.
 */
class LicenseManager
{
    public const OPTION_LICENSE_KEY = 'plp_mothership_license';

    public const OPTION_ACTIVATED = 'prli_activated';

    public const OPTION_EDGE_UPDATES = 'plp_edge_updates';

    public const OPTION_ACTIVATION_OVERRIDE = 'prli_activation_override';

    public const TRANSIENT_LICENSE_INFO = 'prli_license_info';

    public const TRANSIENT_UPDATE_INFO = 'prli_update_info';

    public const TRANSIENT_ADDONS = 'prli_addons';

    public const TRANSIENT_ALL_ADDONS = 'prli_all_addons';

    public const CHECK_NORMAL_HOURS = 24;

    public const CHECK_FAILED_HOURS = 72;

    public const CHECK_FAIL_THRESHOLD = 3;

    private const NOTICE_DEFINE_ERROR = 'prli_license_define_error';

    /**
     * The mothership HTTP client.
     *
     * @var LicenseClient
     */
    private LicenseClient $client;

    /**
     * Set up the manager with an optional injected client.
     *
     * @param LicenseClient|null $client Optional client instance; a default is built when null.
     */
    public function __construct(?LicenseClient $client = null)
    {
        $this->client = $client ?? new LicenseClient(self::detectEdition());
    }

    /**
     * Current license snapshot for the admin UI.
     *
     * @return array{key: string, activated: bool, edge: bool, edition: string, info: array<string, mixed>|null}
     */
    public function currentLicense(): array
    {
        $key  = (string) get_option(self::OPTION_LICENSE_KEY, '');
        $info = get_site_transient(self::TRANSIENT_LICENSE_INFO);
        return [
            'key'       => $key,
            'activated' => $this->isActive(),
            'edge'      => (bool) get_option(self::OPTION_EDGE_UPDATES, false),
            'edition'   => $this->edition(),
            'info'      => is_array($info) ? $info : null,
        ];
    }

    /**
     * Determine whether the license is currently active.
     *
     * @return boolean True when a key is set and the activated flag is on.
     */
    public function isActive(): bool
    {
        $key = (string) get_option(self::OPTION_LICENSE_KEY, '');
        if ($key === '') {
            return false;
        }
        return (bool) get_option(self::OPTION_ACTIVATED, false);
    }

    /**
     * Returns a simple edition string: `free`, `plus`, or `pro`.
     *
     * Derived from the active license's product_slug when available, otherwise
     * from the legacy PRLI_EDITION constant if it's defined (3.x installs), else
     * `free`.
     */
    public function edition(): string
    {
        $info = get_site_transient(self::TRANSIENT_LICENSE_INFO);
        if (is_array($info) && !empty($info['product_slug'])) {
            return self::normalizeEdition((string) $info['product_slug']);
        }
        if (defined('PRLI_EDITION')) {
            return self::normalizeEdition((string) constant('PRLI_EDITION'));
        }
        return 'free';
    }

    /**
     * Persist the license key.
     *
     * @param string $key The license key to store.
     *
     * @return void
     */
    public function setKey(string $key): void
    {
        update_option(self::OPTION_LICENSE_KEY, $key);
        wp_cache_delete('alloptions', 'options');
    }

    /**
     * Attempt to activate a key. Updates local options on success. On failure,
     * returns the error payload from the remote.
     *
     * @param string $key The license key to activate.
     *
     * @return array<string, mixed>
     */
    public function activate(string $key): array
    {
        $beforeEdition = $this->edition();

        $response = $this->client->activate($key);
        if (isset($response['error'])) {
            return $response;
        }

        $this->setKey($key);
        update_option(self::OPTION_ACTIVATED, true);

        $throttleKey = 'prli_license_check_' . $key;
        delete_site_transient($throttleKey);
        delete_option($throttleKey);
        delete_site_transient(self::TRANSIENT_UPDATE_INFO);

        // Populate prli_license_info immediately so callers of currentLicense()
        // see product_name/expires_at on the first render after activation.
        $this->refreshLicenseInfo();

        do_action('prli_license_activated_before_queue_update');

        delete_site_transient(self::TRANSIENT_ADDONS);
        delete_site_transient(self::TRANSIENT_ALL_ADDONS);

        do_action('prli_license_activated', $response);

        $afterEdition = $this->edition();
        if ($beforeEdition !== $afterEdition) {
            /**
             * Action: prli_plugin_edition_changed
             *
             * Fires when the active edition transitions (e.g. free→pro on
             * license activation, or pro→free on deactivation). Add-ons
             * hook this to rebuild feature-gated state — clearing update
             * transients, refreshing license-tier caches, etc.
             *
             * @param string $after  New edition ('free', 'plus', 'pro').
             * @param string $before Previous edition.
             */
            do_action('prli_plugin_edition_changed', $afterEdition, $beforeEdition);
        }

        return $response;
    }

    /**
     * Deactivate the current license (remote + local).
     *
     * @return array<string, mixed>
     */
    public function deactivate(): array
    {
        $beforeEdition = $this->edition();

        $key    = (string) get_option(self::OPTION_LICENSE_KEY, '');
        $result = [
            'message' => __('License key deactivated', 'pretty-link'),
        ];
        if ($key !== '') {
            $response = $this->client->deactivate($key);
            if (!isset($response['error'])) {
                $result = $response;
            }
        }

        $this->setKey('');
        update_option(self::OPTION_ACTIVATED, false);

        $throttleKey = 'prli_license_check_' . $key;
        delete_site_transient($throttleKey);
        delete_option($throttleKey);

        delete_site_transient(self::TRANSIENT_LICENSE_INFO);
        delete_site_transient(self::TRANSIENT_UPDATE_INFO);
        delete_site_transient(self::TRANSIENT_ADDONS);
        delete_site_transient(self::TRANSIENT_ALL_ADDONS);

        do_action('prli_license_deactivated_before_queue_update');
        do_action('prli_license_deactivated', $result);

        $afterEdition = $this->edition();
        if ($beforeEdition !== $afterEdition) {
            do_action('prli_plugin_edition_changed', $afterEdition, $beforeEdition);
        }

        return $result;
    }

    /**
     * Activate the license key from the `PRETTYLINK_LICENSE_KEY` constant when
     * it's set in wp-config.php and differs from the stored key. Mirrors v3's
     * `PrliUpdateController::activate_from_define()`:
     *
     *  - Gated on the Pro entry file being on disk (matches v3 `is_installed()`).
     *  - Deactivates the previous key first when present.
     *  - Forces edge-updates off so the define remains the source of truth
     *    (pair with `define('PRETTYLINK_EDGE', true)` to opt in).
     *  - Activation errors surface via the React notice strip through
     *    `Notices::add()` so they live in the same queue as every other
     *    Pretty Links notice. A successful run clears any prior error.
     */
    public function activateFromDefine(): void
    {
        if (!defined('PRETTYLINK_LICENSE_KEY')) {
            return;
        }
        if (!ProState::isProInstalled()) {
            return;
        }

        $defined = (string) constant('PRETTYLINK_LICENSE_KEY');
        $current = (string) get_option(self::OPTION_LICENSE_KEY, '');
        if ($defined === $current) {
            return;
        }

        if ($current !== '') {
            $this->deactivate();
        }

        update_option(self::OPTION_EDGE_UPDATES, false);
        wp_cache_delete('alloptions', 'options');

        $response = $this->activate($defined);
        if (!isset($response['error'])) {
            Notices::remove(self::NOTICE_DEFINE_ERROR);
            return;
        }

        $error = (string) $response['error'];
        Notices::add(
            self::NOTICE_DEFINE_ERROR,
            'error',
            esc_html(sprintf(
                // phpcs:ignore Squiz.Commenting.InlineComment.NotCapital,Squiz.Commenting.InlineComment.InvalidEndChar -- translators comment must remain verbatim.
                // translators: %s: error message returned by the mothership.
                __('Error with PRETTYLINK_LICENSE_KEY: %s', 'pretty-link'),
                $error
            ))
        );
    }

    /**
     * Local-throttled check. Returns true if a remote call was made. If the
     * throttle is still active, returns false and leaves state untouched.
     */
    public function maybeCheck(): bool
    {
        $beforeEdition = $this->edition();

        if ((bool) get_option(self::OPTION_ACTIVATION_OVERRIDE, false)) {
            update_option(self::OPTION_ACTIVATED, true);
            do_action('prli_license_activated', ['aov' => 1]);
            $this->fireEditionChangedIfDifferent($beforeEdition);
            return true;
        }

        $key = (string) get_option(self::OPTION_LICENSE_KEY, '');
        if ($key === '') {
            return false;
        }

        $throttleKey = 'prli_license_check_' . $key;
        if (get_site_transient($throttleKey)) {
            return false;
        }

        $failCount = (int) get_option($throttleKey, 0) + 1;
        update_option($throttleKey, $failCount);

        $ttlHours = $failCount > self::CHECK_FAIL_THRESHOLD
            ? self::CHECK_FAILED_HOURS
            : self::CHECK_NORMAL_HOURS;
        set_site_transient($throttleKey, true, $ttlHours * HOUR_IN_SECONDS);

        $response = $this->client->check($key);
        if (isset($response['error'])) {
            return true;
        }

        $expired = false;
        if (!empty($response['expires_at'])) {
            $timestamp = strtotime((string) $response['expires_at']);
            if ($timestamp && $timestamp < time()) {
                $expired = true;
                update_option(self::OPTION_ACTIVATED, false);
                do_action('prli_license_expired', $response);
            }
        }

        if (!$expired && isset($response['status'])) {
            if ($response['status'] === 'enabled') {
                update_option($throttleKey, 0);
                update_option(self::OPTION_ACTIVATED, true);
                do_action('prli_license_activated', $response);
            } elseif ($response['status'] === 'disabled') {
                update_option(self::OPTION_ACTIVATED, false);
                do_action('prli_license_invalidated', $response);
            }
        }

        $this->fireEditionChangedIfDifferent($beforeEdition);
        return true;
    }

    /**
     * Fire `prli_plugin_edition_changed` if the current edition differs
     * from the supplied previous value. Helper used by paths where the
     * edition may shift as a side-effect of a license status update.
     *
     * @param string $beforeEdition The edition value captured before the update.
     *
     * @return void
     */
    private function fireEditionChangedIfDifferent(string $beforeEdition): void
    {
        $afterEdition = $this->edition();
        if ($beforeEdition !== $afterEdition) {
            do_action('prli_plugin_edition_changed', $afterEdition, $beforeEdition);
        }
    }

    /**
     * Refresh the cached license info transient.
     *
     * @return array<string, mixed>|null
     */
    public function refreshLicenseInfo(): ?array
    {
        $key = (string) get_option(self::OPTION_LICENSE_KEY, '');
        if ($key === '') {
            return null;
        }
        $args = [];
        if ($this->edgeEnabled()) {
            $args['edge'] = 'true';
        }
        $info = $this->client->versionInfo($key, $args);
        if (isset($info['error'])) {
            return null;
        }
        set_site_transient(self::TRANSIENT_LICENSE_INFO, $info, 24 * HOUR_IN_SECONDS);
        return $info;
    }

    /**
     * Determine whether the bleeding-edge update channel is enabled.
     *
     * @return boolean True when edge updates are enabled via constant or option.
     */
    public function edgeEnabled(): bool
    {
        if (defined('PRETTYLINK_EDGE') && constant('PRETTYLINK_EDGE')) {
            return true;
        }
        return (bool) get_option(self::OPTION_EDGE_UPDATES, false);
    }

    /**
     * Canonical plan slug for the active license, or '' when unknown.
     *
     * Returns the raw mothership product_slug (e.g. `pretty-link-marketer`)
     * when it's recognized by {@see PlanCatalog}; '' for free / lite /
     * unrecognized installs. Use this — not edition() — when you need to
     * make tier-specific decisions (e.g. which add-ons the license entitles).
     */
    public function planSlug(): string
    {
        $info = get_site_transient(self::TRANSIENT_LICENSE_INFO);
        if (is_array($info) && !empty($info['product_slug'])) {
            $slug = strtolower((string) $info['product_slug']);
            if (PlanCatalog::isKnownPlan($slug)) {
                return $slug;
            }
        }
        return '';
    }

    /**
     * Map a product slug to our simple 3-tier edition string.
     *
     * @param string $slug The mothership product slug.
     *
     * @return string The normalized edition ('free', 'plus', or 'pro').
     */
    public static function normalizeEdition(string $slug): string
    {
        $slug = strtolower($slug);
        if ($slug === '' || strpos($slug, 'lite') !== false || strpos($slug, 'free') !== false) {
            return 'free';
        }
        if (strpos($slug, 'plus') !== false) {
            return 'plus';
        }
        // Pro-blogger, pro-developer, pro, executive, marketer, beginner → pro tier.
        return 'pro';
    }

    /**
     * Detect the installed edition slug from the PRLI_EDITION constant.
     *
     * @return string The edition slug, defaulting to 'pretty-link-lite'.
     */
    private static function detectEdition(): string
    {
        if (defined('PRLI_EDITION')) {
            return (string) constant('PRLI_EDITION');
        }
        return 'pretty-link-lite';
    }
}
