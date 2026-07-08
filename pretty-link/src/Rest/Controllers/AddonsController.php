<?php

declare(strict_types=1);

namespace PrettyLinks\Rest\Controllers;

use PrettyLinks\Addons\AddonInstallSkin;
use PrettyLinks\Addons\AddonsService;
use PrettyLinks\Licensing\ProState;
use Plugin_Upgrader;
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
                ],
            ],
        ]);
    }

    /**
     * Returns the list of available add-ons.
     *
     * @param WP_REST_Request $request The incoming REST request.
     *
     * @return WP_REST_Response
     */
    public function index(WP_REST_Request $request): WP_REST_Response
    {
        $refresh = (bool) $request->get_param('refresh');
        $raw     = $this->service()->getAddons($refresh, true);

        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $addons = [];
        foreach ($raw as $slug => $info) {
            $info  = (object) $info;
            $extra = isset($info->extra_info) ? (object) $info->extra_info : null;
            if ($extra === null) {
                continue;
            }

            $directory   = isset($extra->directory) ? (string) $extra->directory : '';
            $mainFile    = isset($extra->main_file) ? (string) $extra->main_file : '';
            $installed   = $directory !== '' && is_dir(WP_PLUGIN_DIR . '/' . $directory);
            $active      = $mainFile !== '' && is_plugin_active($mainFile);
            $installable = !empty($info->installable);

            if ($installed && $active) {
                $status = 'active';
            } elseif ($installed && !$active) {
                $status = 'inactive';
            } elseif (!$installed && $installable) {
                $status = 'download';
            } else {
                $status = 'upgrade';
            }

            $addons[] = [
                'slug'        => (string) (is_int($slug) ? ($extra->directory ?? $info->product_name ?? '') : $slug),
                'name'        => isset($extra->list_title) ? (string) $extra->list_title : (string) ($info->product_name ?? ''),
                'description' => isset($extra->description) ? wp_kses_post((string) $extra->description) : '',
                'cover_image' => isset($extra->cover_image) ? (string) $extra->cover_image : '',
                'main_file'   => $mainFile,
                'directory'   => $directory,
                'url'         => isset($info->url) ? (string) $info->url : '',
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
        if ($plugin === '' || !current_user_can('activate_plugins')) {
            return new WP_REST_Response(['error' => 'forbidden'], 403);
        }

        if (!function_exists('activate_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $result = activate_plugins($plugin);
        if (is_wp_error($result)) {
            return new WP_REST_Response([
                'error'   => 'activation_failed',
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
        if ($plugin === '' || !current_user_can('deactivate_plugins')) {
            return new WP_REST_Response(['error' => 'forbidden'], 403);
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
     * Installs an add-on plugin from a package URL.
     *
     * @param WP_REST_Request $request The incoming REST request.
     *
     * @return WP_REST_Response
     */
    public function install(WP_REST_Request $request): WP_REST_Response
    {
        $packageUrl = esc_url_raw((string) $request->get_param('plugin'));
        if ($packageUrl === '') {
            return new WP_REST_Response(['error' => 'bad_request'], 400);
        }
        if (!current_user_can('install_plugins') || !current_user_can('activate_plugins')) {
            return new WP_REST_Response(['error' => 'forbidden'], 403);
        }
        // Add-ons are licensed downloads — reject the install unless the
        // current install has an active Pro license. Avoids attempting a
        // download with a stale/expired mothership package URL.
        if (!ProState::isProInstalledAndActivated()) {
            return new WP_REST_Response(['error' => 'license_required'], 403);
        }

        if (!function_exists('request_filesystem_credentials')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $url   = esc_url_raw(add_query_arg(['page' => 'pretty-link-addons'], admin_url('admin.php')));
        $creds = request_filesystem_credentials($url, '', false, false, null);
        if ($creds === false || !WP_Filesystem($creds)) {
            return new WP_REST_Response(['error' => 'filesystem_unavailable'], 500);
        }

        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        // Skip translations fetch so the installer doesn't stall on JSON output.
        remove_action('upgrader_process_complete', ['Language_Pack_Upgrader', 'async_upgrade'], 20);

        $installer = new Plugin_Upgrader(new AddonInstallSkin());
        $installer->install($packageUrl);
        wp_cache_flush();

        $basename = $installer->plugin_info();
        if (!$basename) {
            return new WP_REST_Response(['error' => 'install_failed'], 500);
        }

        $activated   = activate_plugin($basename);
        $didActivate = !is_wp_error($activated);

        return new WP_REST_Response([
            'success'   => true,
            'basename'  => $basename,
            'activated' => $didActivate,
            'status'    => $didActivate ? 'active' : 'inactive',
        ]);
    }

    /**
     * Resolves the add-ons service from the container.
     *
     * @return AddonsService
     */
    private function service(): AddonsService
    {
        return $this->container->get(AddonsService::class);
    }
}
