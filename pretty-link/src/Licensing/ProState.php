<?php

declare(strict_types=1);

namespace PrettyLinks\Licensing;

/**
 * Single source of truth for "is Pro available?" checks across Lite and Pro.
 *
 * Two questions answered here — everything else is a derived query on the
 * LicenseManager:
 *
 *   isProInstalled()              — Is Pro code present on disk?
 *   isProInstalledAndActivated()  — Pro present AND a valid/non-expired
 *                                   license is active (delegates the
 *                                   license question to LicenseManager).
 *
 * Pro features should fully function whenever `isProInstalled()` is true,
 * regardless of license state. `isProInstalledAndActivated()` is reserved
 * for paths that hit the mothership or spend money on the user's behalf
 * (update pings, add-on installs, the Stripe platform-fee bypass, the
 * "Get More with Pro" upsell menu).
 *
 * Build-time slug `PRLI_EDITION` is not a Pro-state check — it's a
 * diagnostic label baked into the MU-plugin stub and redirect-log
 * metadata. Do not consult it from here.
 */
class ProState
{
    /**
     * Test-only override for `isProInstalled()`. Production code never touches
     * this — it stays null, the real filesystem check runs. The Ground Level
     * `WritesProperties` trait pokes this via reflection so Lite-path tests
     * can run in dev environments where `pro/` is on disk.
     *
     * @var boolean|null
     */
    private static ?bool $installedOverride = null;

    /**
     * Determine whether the Pro plugin files are present on disk.
     *
     * @return boolean True when the Pro directory exists (or is forced via the test override).
     */
    public static function isProInstalled(): bool
    {
        if (self::$installedOverride !== null) {
            return self::$installedOverride;
        }
        if (!defined('PRLI_PATH')) {
            return false;
        }
        return is_dir(rtrim((string) constant('PRLI_PATH'), '/\\') . '/pro');
    }

    /**
     * Determine whether Pro is installed and holds an active license.
     *
     * @return boolean True when Pro is present on disk and its license is active.
     */
    public static function isProInstalledAndActivated(): bool
    {
        if (!self::isProInstalled()) {
            return false;
        }
        try {
            return (new LicenseManager())->isActive();
        } catch (\Throwable $e) {
            return false;
        }
    }
}
