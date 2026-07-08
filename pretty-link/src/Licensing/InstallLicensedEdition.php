<?php

declare(strict_types=1);

namespace PrettyLinks\Licensing;

defined('ABSPATH') || exit;

/**
 * Silent installer for the licensed Pretty Links edition.
 *
 * Reads the package URL from the `prli_license_info` transient (populated by
 * the mothership on license activation and updater refresh) and feeds it to
 * WordPress's `Plugin_Upgrader` via `Automatic_Upgrader_Skin` so no wp-admin
 * progress UI is rendered. Mirrors v3's `PrliUpdateController::install_plugin_silently()`.
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
        $info = get_site_transient(LicenseManager::TRANSIENT_LICENSE_INFO);
        if (!is_array($info) || empty($info['url'])) {
            return [
                'success' => false,
                'error'   => __('No licensed edition download URL is available. Try refreshing your license.', 'pretty-link'),
            ];
        }

        $url = (string) $info['url'];

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

        $skin     = new \Automatic_Upgrader_Skin();
        $upgrader = new \Plugin_Upgrader($skin);
        $result   = $upgrader->install($url, ['overwrite_package' => true]);

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

        /**
 * Fires after the licensed edition is installed.
*/
        do_action('prli_plugin_edition_changed', $basename, null);

        return [
            'success'   => true,
            'basename'  => $basename,
            'activated' => $activated,
        ];
    }
}
