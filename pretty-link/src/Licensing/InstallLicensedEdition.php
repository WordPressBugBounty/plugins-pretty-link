<?php

declare(strict_types=1);

namespace PrettyLinks\Licensing;

defined('ABSPATH') || exit;

use PrettyLinks\Bootstrap;
use PrettyLinks\GroundLevel\Mothership\Transients\ActivationTransient;
use PrettyLinks\GroundLevel\Mothership\Util;

/**
 * Silent installer for the licensed Pretty Links edition.
 *
 * Reads the package URL from ground-level-mothership's {@see ActivationTransient}
 * (populated on license activation / status sync) and feeds it to WordPress's
 * `Plugin_Upgrader` via `Automatic_Upgrader_Skin` so no wp-admin progress UI is
 * rendered. Mirrors v3's `PrliUpdateController::install_plugin_silently()`.
 *
 * After a successful install, the active plugin list is refreshed so WordPress
 * picks up the new edition's main file in the current request — matching how
 * wp-admin's own "Install & Activate" flow behaves.
 */
class InstallLicensedEdition
{
    /**
     * Install the licensed edition. Returns an array with a `success` flag
     * and, on success, the installed `basename` + whether activation occurred.
     * Returns `['success' => false, 'error' => '<message>']` on any failure
     * so callers can surface a human-readable error without unwrapping WP_Error.
     *
     * @return array{success: bool, basename?: string, activated?: bool, error?: string}
     */
    public function install(): array
    {
        $activation = Bootstrap::instance()->container()->get(ActivationTransient::class);
        $url        = $activation->downloadUrl;
        if ($url === '') {
            return [
                'success' => false,
                'error'   => __('No licensed edition download URL is available. Try refreshing your license.', 'pretty-link'),
            ];
        }

        // Validate against the download-host allowlist (HTTPS + known hosts),
        // same guard AddonInstaller and GL's own install paths apply, so a
        // poisoned transient / tampered API response can't redirect the
        // install to an untrusted source.
        $util = Bootstrap::instance()->container()->get(Util::class);
        if (!$util->isAllowedDownloadUrl($url)) {
            return [
                'success' => false,
                'error'   => __('The licensed edition download URL is not from an allowed host.', 'pretty-link'),
            ];
        }

        if (!function_exists('request_filesystem_credentials')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        if (!class_exists('Plugin_Upgrader')) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        }
        if (!class_exists('Automatic_Upgrader_Skin')) {
            require_once ABSPATH . 'wp-admin/includes/class-automatic-upgrader-skin.php';
        }
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        // `overwrite_package` sets `clear_destination`, so core deletes the LIVE
        // plugin directory before copying the replacement in. Core's
        // backup/restore is gated on `hook_extra['temp_backup']`, which
        // `upgrade()` passes and `install()` does not — so a failure between the
        // delete and the copy leaves no Pretty Links on disk, every short link
        // 404s, and there is nothing to roll back to.
        //
        // `install()` hardcodes its own `hook_extra`, so the value cannot be
        // passed as an argument; `upgrader_package_options` is the supported way
        // in. With it set, core moves the current directory aside first, restores
        // it if the install fails, and deletes the backup on success.
        $slug   = self::backupSlug();
        $backup = $slug === '' ? null : static function ($options) use ($slug) {
            return self::withTempBackup($options, $slug);
        };

        $skin     = new \Automatic_Upgrader_Skin();
        $upgrader = new \Plugin_Upgrader($skin);
        if ($backup !== null) {
            add_filter('upgrader_package_options', $backup);
        }
        try {
            $result = $upgrader->install($url, ['overwrite_package' => true]);
        } finally {
            if ($backup !== null) {
                remove_filter('upgrader_package_options', $backup);
            }
        }

        if (is_wp_error($result)) {
            return [
                'success' => false,
                'error'   => (string) $result->get_error_message(),
            ];
        }
        if ($result === false) {
            $messages = $skin->get_upgrade_messages();
            $last     = is_array($messages) && $messages ? (string) end($messages) : '';
            return [
                'success' => false,
                'error'   => $last !== '' ? $last : __('The installer failed with no error message.', 'pretty-link'),
            ];
        }

        // Locate the installed main file so the caller can activate + fire
        // post-install hooks.
        $basename = (string) $upgrader->plugin_info();
        if ($basename === '') {
            return [
                'success' => false,
                'error'   => __('Installed successfully, but the plugin file could not be located.', 'pretty-link'),
            ];
        }

        $activated = false;
        if (!is_plugin_active($basename)) {
            $activateResult = activate_plugin($basename);
            $activated      = !is_wp_error($activateResult);
        } else {
            $activated = true;
        }

        self::fireEditionChanged($activation->productSlug, (string) PRLI_EDITION);

        return [
            'success'   => true,
            'basename'  => $basename,
            'activated' => $activated,
        ];
    }

    /**
     * Fire `prli_plugin_edition_changed` with the same edition slugs
     * {@see LicenseManager} passes: the licensed edition just installed, and
     * the edition this request is still running (`PRLI_EDITION`). Fires on
     * every successful install, even when both normalize to the same tier
     * (e.g. Pro Blogger replaced by Pro Developer), because the installed
     * product still changed.
     *
     * A blank product slug falls back to `PRLI_EDITION`, the same way
     * {@see LicenseManager::edition()} resolves it, so an install whose
     * transient lacks a slug never reports a downgrade to `free`.
     *
     * @param string $licensedSlug Product slug of the edition just installed.
     * @param string $running      `PRLI_EDITION` of the edition this request runs.
     */
    private static function fireEditionChanged(string $licensedSlug, string $running): void
    {
        /**
         * Action: prli_plugin_edition_changed
         *
         * @param string $after  Installed edition ('free', 'plus', 'pro').
         * @param string $before Previously installed edition.
         */
        do_action(
            'prli_plugin_edition_changed',
            LicenseManager::normalizeEdition($licensedSlug !== '' ? $licensedSlug : $running),
            LicenseManager::normalizeEdition($running)
        );
    }

    /**
     * Add the `temp_backup` entry to a set of upgrader package options.
     *
     * Separate from the closure that registers it so the shape can be tested
     * without running a real install.
     *
     * @param mixed  $options Package options as core passes them.
     * @param string $slug    Plugin directory to back up.
     *
     * @return mixed The options, with `hook_extra['temp_backup']` set when the
     *               shape allows it.
     */
    public static function withTempBackup($options, string $slug)
    {
        if (
            $slug === ''
            || !is_array($options)
            || !isset($options['hook_extra'])
            || !is_array($options['hook_extra'])
        ) {
            return $options;
        }
        $options['hook_extra']['temp_backup'] = [
            'slug' => $slug,
            'src'  => WP_PLUGIN_DIR,
            'dir'  => 'plugins',
        ];
        return $options;
    }

    /**
     * Directory for core to back up, or '' when no backup can be arranged.
     *
     * `temp_backup` is honoured from WordPress 6.3 and the plugin requires 6.5, so
     * there is no version gate here — that was only needed while 6.0 was
     * supported, where the key was silently ignored and declaring it would have
     * implied a rollback that did not exist.
     *
     * Still returns '' for an install outside `WP_PLUGIN_DIR` (a symlink, or a
     * test checkout): core resolves `src`/`slug` literally and returns `WP_Error`
     * for a directory it cannot find, which would abort the install outright.
     *
     * @return string
     */
    private static function backupSlug(): string
    {
        if (!defined('PRLI_PATH')) {
            return '';
        }
        $dir = basename(rtrim((string) constant('PRLI_PATH'), '/\\'));
        if ($dir === '' || $dir === '.' || !is_dir(WP_PLUGIN_DIR . '/' . $dir)) {
            return '';
        }
        return $dir;
    }
}
