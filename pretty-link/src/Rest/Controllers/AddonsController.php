<?php

declare(strict_types=1);

namespace PrettyLinks\Rest\Controllers;

use PrettyLinks\Addons\AddonInstaller;
use PrettyLinks\GroundLevel\Mothership\Manager\AddonsManager;
use PrettyLinks\Licensing\ProState;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * REST surface for the Add-ons page. Backs the React Add-ons UI for
 * listing, activating, deactivating, and installing add-ons.
 */
class AddonsController extends BaseController
{
    /**
     * Registers the add-ons REST routes.
     *
     * @return void
     */
    public function register(): void
    {
        register_rest_route($this->namespace(), '/addons', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'index'],
                'permission_callback' => $this->permission(),
                'args'                => [
                    'refresh' => [
                        'type'    => 'boolean',
                        'default' => false,
                    ],
                ],
            ],
        ]);

        register_rest_route($this->namespace(), '/addons/activate', [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'activate'],
                'permission_callback' => $this->permission(),
                'args'                => [
                    'plugin' => [
                        'type'     => 'string',
                        'required' => true,
                    ],
                ],
            ],
        ]);

        register_rest_route($this->namespace(), '/addons/deactivate', [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'deactivate'],
                'permission_callback' => $this->permission(),
                'args'                => [
                    'plugin' => [
                        'type'     => 'string',
                        'required' => true,
                    ],
                ],
            ],
        ]);

        register_rest_route($this->namespace(), '/addons/install', [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'install'],
                'permission_callback' => $this->permission(),
                'args'                => [
                    'plugin' => [
                        'type'     => 'string',
                        'required' => true,
                    ],
                    'main_file' => [
                        'type'     => 'string',
                        'required' => false,
                    ],
                ],
            ],
        ]);
    }

    /**
     * Lists the available add-ons with their installed/active status.
     *
     * @param WP_REST_Request $request The incoming REST request.
     *
     * @return WP_REST_Response
     */
    public function index(WP_REST_Request $request): WP_REST_Response
    {
        $refresh = (bool) $request->get_param('refresh');
        // Cached list by default; a refresh request forces a fresh fetch from
        // the licenses server (getAddons(false)).
        $products = $this->addonsManager()->getAddons(!$refresh);

        if ($refresh) {
            // "Refresh Add-ons" should also force a fresh Plugins/Updates-screen
            // check, not just refresh this catalog list. Deleting WP's own
            // transient makes it regenerate on the next admin page load —
            // re-running LegacyUpdateService's `site_transient_update_plugins`
            // pass, which is what injects add-on rows, plus the
            // `update_plugins_{slug}` filter for the minority of add-ons that
            // ship their own `Update URI` header. Without this the Plugins
            // screen could keep advertising an update the site already has,
            // with no admin-facing way to force a recheck short of toggling the
            // license.
            delete_site_transient('update_plugins');

            /**
             * Fires after the add-on catalog has been re-fetched from the
             * licensing server.
             *
             * On develop this fired from AddonsService::refresh(); that class
             * is gone and nothing replaced the action, so add-ons that cleared
             * their own caches on it stopped being told. This is the
             * equivalent moment.
             *
             * @param array<int, object> $products The freshly fetched add-ons.
             */
            do_action('prli_addons_refreshed', $products);
        }

        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $addons = [];
        foreach ($products as $product) {
            $mainFile = isset($product->main_file) ? (string) $product->main_file : '';

            // Without a main file there is nothing to install, activate or
            // deactivate: the card would sit on "Download" forever and its
            // Activate button would POST `plugin: ''`. GL's own products view
            // filters these out for the same reason.
            if ($mainFile === '') {
                continue;
            }

            // A main file at the plugins-directory root makes dirname() answer
            // '.', and is_dir(WP_PLUGIN_DIR . '/.') is always true — which
            // reported every such add-on as installed-but-inactive and offered
            // an Activate button for something that was never downloaded.
            $directory = dirname($mainFile);
            if ($directory === '.' || $directory === '' || $directory === DIRECTORY_SEPARATOR) {
                $directory = '';
            }

            // Add-ons the current license doesn't entitle come back typed
            // `upgrade-addon` (no downloadable version) and surface as upsells.
            $isUpgrade = (($product->type ?? '') === 'upgrade-addon');
            $installed = $directory !== '' && is_dir(WP_PLUGIN_DIR . '/' . $directory);
            $active    = $installed && is_plugin_active($mainFile);

            // Installed state wins over the upgrade upsell: an add-on kept from a
            // higher tier (now typed `upgrade-addon` after a downgrade) must still
            // expose its Activate/Deactivate control. Only surface `upgrade` when
            // the add-on isn't on disk.
            if ($installed && $active) {
                $status = 'active';
            } elseif ($installed) {
                $status = 'inactive';
            } elseif ($isUpgrade) {
                $status = 'upgrade';
            } else {
                $status = 'download';
            }

            $addons[] = [
                'slug'        => (string) ($product->slug ?? ''),
                'name'        => (string) ($product->name ?? ''),
                'description' => isset($product->description) ? wp_kses_post((string) $product->description) : '',
                'cover_image' => (string) ($product->image ?? ''),
                'main_file'   => $mainFile,
                'directory'   => $directory,
                'url'         => isset($product->version->url) ? (string) $product->version->url : '',
                'status'      => $status,
            ];
        }

        return new WP_REST_Response([
            'addons' => $addons,
        ]);
    }

    /**
     * Activates an installed add-on plugin.
     *
     * @param WP_REST_Request $request The incoming REST request.
     *
     * @return WP_REST_Response
     */
    public function activate(WP_REST_Request $request): WP_REST_Response
    {
        $plugin = sanitize_text_field((string) $request->get_param('plugin'));
        if ($plugin === '') {
            return new WP_REST_Response([
                'code'    => 'bad_request',
                'message' => __('No add-on was specified to activate.', 'pretty-link'),
            ], 400);
        }
        if (!current_user_can('activate_plugins')) {
            return new WP_REST_Response([
                'code'    => 'forbidden',
                'message' => __('You do not have permission to activate this add-on.', 'pretty-link'),
            ], 403);
        }

        if (!function_exists('activate_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $result = activate_plugins($plugin);
        if (is_wp_error($result)) {
            return new WP_REST_Response([
                'code'    => 'activation_failed',
                'message' => $result->get_error_message(),
            ], 400);
        }

        return new WP_REST_Response([
            'success' => true,
            'status'  => 'active',
        ]);
    }

    /**
     * Deactivates an active add-on plugin.
     *
     * @param WP_REST_Request $request The incoming REST request.
     *
     * @return WP_REST_Response
     */
    public function deactivate(WP_REST_Request $request): WP_REST_Response
    {
        $plugin = sanitize_text_field((string) $request->get_param('plugin'));
        if ($plugin === '') {
            return new WP_REST_Response([
                'code'    => 'bad_request',
                'message' => __('No add-on was specified to deactivate.', 'pretty-link'),
            ], 400);
        }
        if (!current_user_can('deactivate_plugins')) {
            return new WP_REST_Response([
                'code'    => 'forbidden',
                'message' => __('You do not have permission to deactivate this add-on.', 'pretty-link'),
            ], 403);
        }

        if (!function_exists('deactivate_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        deactivate_plugins($plugin);

        return new WP_REST_Response([
            'success' => true,
            'status'  => 'inactive',
        ]);
    }

    /**
     * Downloads and installs a licensed add-on from its package URL.
     *
     * @param WP_REST_Request $request The incoming REST request.
     *
     * @return WP_REST_Response
     */
    public function install(WP_REST_Request $request): WP_REST_Response
    {
        $packageUrl = esc_url_raw((string) $request->get_param('plugin'));
        if ($packageUrl === '') {
            return new WP_REST_Response([
                'code'    => 'bad_request',
                'message' => __('No download URL was provided for this add-on.', 'pretty-link'),
            ], 400);
        }
        if (!current_user_can('install_plugins') || !current_user_can('activate_plugins')) {
            return new WP_REST_Response([
                'code'    => 'forbidden',
                'message' => __('You do not have permission to install plugins on this site.', 'pretty-link'),
            ], 403);
        }
        // Add-ons are licensed downloads — reject the install unless the
        // current install has an active Pro license. Avoids attempting a
        // download with a stale/expired mothership package URL.
        if (!ProState::isProInstalledAndActivated()) {
            return new WP_REST_Response([
                'code'    => 'license_required',
                'message' => __('An active Pro license is required to install add-ons.', 'pretty-link'),
            ], 403);
        }

        $result = (new AddonInstaller())->installFromUrl($packageUrl, sanitize_text_field((string) $request->get_param('main_file')));
        if (empty($result['success'])) {
            $code   = (string) ($result['code'] ?? 'install_failed');
            $status = in_array($code, ['bad_request', 'invalid_download_url'], true) ? 400 : 500;
            return new WP_REST_Response([
                'code'    => $code,
                'message' => (string) ($result['message'] ?? __('The add-on could not be installed.', 'pretty-link')),
            ], $status);
        }

        return new WP_REST_Response([
            'success'   => true,
            'basename'  => (string) ($result['basename'] ?? ''),
            'activated' => !empty($result['activated']),
            'status'    => !empty($result['activated']) ? 'active' : 'inactive',
        ]);
    }

    /**
     * Resolves the add-ons manager from the container.
     *
     * @return AddonsManager
     */
    private function addonsManager(): AddonsManager
    {
        return $this->container->get(AddonsManager::class);
    }
}
