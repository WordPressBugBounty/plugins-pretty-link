<?php

declare(strict_types=1);

namespace PrettyLinks\Addons;

defined( 'ABSPATH' ) || exit;

use PrettyLinks\Bootstrap;
use PrettyLinks\GroundLevel\Mothership\Util;
use Plugin_Upgrader;

/**
 * Downloads a licensed add-on from a Mothership package URL, installs it, and
 * activates it. Shared by the Add-ons REST controller and the onboarding queue
 * drain so the validate → download → install → activate path lives in one place.
 *
 * Callers are responsible for permission/license gating; this only performs the
 * install and reports a structured result.
 */
class AddonInstaller
{
    /**
     * Install (and activate) an add-on plugin from its package URL.
     *
     * @param  string $packageUrl The add-on package (zip) URL.
     * @param  string $mainFile   Optional known plugin basename (e.g. `slug/slug.php`). Preferred over
     *                            the upgrader's guess, which can pick a bundled legacy file when a
     *                            package carries more than one plugin header. Trusted as given, with
     *                            no fallback to plugin_info() on purpose. Supplying it also changes
     *                            whether a download happens at all: when the plugin is already active
     *                            this is a no-op success, and when its directory already exists the
     *                            existing copy is activated instead (core refuses to install over it),
     *                            so a stale value surfaces as `activation_failed` rather than a
     *                            silent no-op.
     * @return array{success: bool, basename?: string, activated?: bool, code?: string, message?: string}
     */
    public function installFromUrl(string $packageUrl, string $mainFile = ''): array
    {
        $packageUrl = esc_url_raw($packageUrl);
        if ($packageUrl === '') {
            return [
                'success' => false,
                'code'    => 'bad_request',
                'message' => __('No download URL was provided for this add-on.', 'pretty-link'),
            ];
        }

        // Only install from an allowed host over HTTPS (the licenses server +
        // wordpress.org), matching ground-level-mothership's own guard.
        $util = Bootstrap::instance()->container()->get(Util::class);
        if (!$util->isAllowedDownloadUrl($packageUrl)) {
            return [
                'success' => false,
                'code'    => 'invalid_download_url',
                'message' => sprintf(
                    // translators: %s: the rejected download host.
                    __('The add-on download URL is not from an allowed host (%s).', 'pretty-link'),
                    (string) wp_parse_url($packageUrl, PHP_URL_HOST)
                ),
            ];
        }

        // Already on disk? Core's installer does not reuse an existing
        // directory — Plugin_Upgrader::install() leaves `clear_destination`
        // false and install_package() aborts with `folder_exists`. So an
        // add-on that was downloaded earlier and then deactivated could never
        // be installed again: every attempt reported a hard failure telling
        // the user to install it manually, which they cannot, because it is
        // already there. Only reachable with a known main file, which is what
        // tells us the directory belongs to this add-on. Mirrors the same
        // short-circuit in Wizard::installPluginFromUrl().
        $directory = $mainFile !== '' ? dirname($mainFile) : '';
        $onDisk    = $directory !== '' && $directory !== '.' && is_dir(WP_PLUGIN_DIR . '/' . $directory);
        if ($onDisk) {
            if (!function_exists('is_plugin_active')) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            // The directory check gates this: is_plugin_active() only reads the
            // active_plugins option, and core prunes stale entries in
            // validate_active_plugins(), which runs on wp-admin/plugins.php and
            // nowhere else. So an add-on whose files were deleted over SFTP
            // still reads as active, and answering "already installed" here
            // would report success for something that is simply gone instead of
            // reinstalling it.
            if (is_plugin_active($mainFile)) {
                return [
                    'success'   => true,
                    'basename'  => $mainFile,
                    'activated' => true,
                ];
            }

            $activated = activate_plugin($mainFile);
            if (is_wp_error($activated)) {
                // Unlike the install path below, nothing happened here — this
                // branch skips the download entirely, so a stale or wrong main
                // file would otherwise report a successful install of an add-on
                // that was never fetched and never will be. Say it failed so
                // the caller can act on it.
                return [
                    'success' => false,
                    'code'    => 'activation_failed',
                    'message' => $activated->get_error_message(),
                ];
            }

            return [
                'success'   => true,
                'basename'  => $mainFile,
                'activated' => true,
            ];
        }

        if (!function_exists('request_filesystem_credentials')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        // Buffered because request_filesystem_credentials() ECHOES the FTP/SSH
        // connection form before returning false whenever the filesystem
        // method is not `direct` and no credentials are stored. This runs
        // inside an admin_init POST (the onboarding Features save) and inside
        // REST — both of which then redirect or emit JSON, so that stray HTML
        // means "headers already sent" and a half-rendered form with no way
        // forward. We only want the answer, never the form: a site that
        // cannot give us credentials non-interactively is simply
        // `filesystem_unavailable`.
        ob_start();
        $creds = request_filesystem_credentials(admin_url('admin.php'), '', false, false, null);
        ob_end_clean();

        if ($creds === false || !WP_Filesystem($creds)) {
            return [
                'success' => false,
                'code'    => 'filesystem_unavailable',
                'message' => __('WordPress could not access the filesystem to install the add-on.', 'pretty-link'),
            ];
        }

        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

        // Skip the translations fetch so the installer doesn't stall on JSON
        // output, then restore the hook in finally — this runs inside a REST
        // request that keeps processing, so leaving it removed would suppress
        // language-pack upgrades for any later upgrader run in the same request.
        // has_action() returns the registered priority (WP core uses 20) or
        // false. Remove/restore at that exact priority rather than a hardcoded
        // number so this keeps working if core ever changes it.
        $langPackPriority = has_action(
            'upgrader_process_complete',
            ['Language_Pack_Upgrader', 'async_upgrade']
        );
        if (false !== $langPackPriority) {
            remove_action('upgrader_process_complete', ['Language_Pack_Upgrader', 'async_upgrade'], $langPackPriority);
        }

        try {
            $skin      = new AddonInstallSkin();
            $installer = new Plugin_Upgrader($skin);
            $installed = $installer->install($packageUrl);
            wp_cache_flush();

            // Surface the upgrader's own failure reason (e.g. "Download failed",
            // HTTP 403, unwritable dir). The skin collects errors rather than
            // emitting JSON, so prefer the WP_Error, then any collected messages.
            if (is_wp_error($installed)) {
                return [
                    'success' => false,
                    'code'    => 'install_failed',
                    'message' => $installed->get_error_message(),
                ];
            }

            $basename = $installed && $mainFile !== '' ? $mainFile : $installer->plugin_info();
            if (!$basename) {
                $skinErrors = $skin->getErrors();
                return [
                    'success' => false,
                    'code'    => 'install_failed',
                    'message' => $skinErrors !== []
                        ? implode(' ', $skinErrors)
                        : __('The add-on could not be installed — the package may be unavailable or invalid.', 'pretty-link'),
                ];
            }

            $activated = activate_plugin($basename);

            return [
                'success'   => true,
                'basename'  => (string) $basename,
                'activated' => !is_wp_error($activated),
            ];
        } finally {
            if (false !== $langPackPriority) {
                add_action('upgrader_process_complete', ['Language_Pack_Upgrader', 'async_upgrade'], $langPackPriority, 1);
            }
        }
    }
}
