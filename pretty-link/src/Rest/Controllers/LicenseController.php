<?php

declare(strict_types=1);

namespace PrettyLinks\Rest\Controllers;

use PrettyLinks\Licensing\AuthClient;
use PrettyLinks\Licensing\InstallLicensedEdition;
use PrettyLinks\Licensing\LicenseManager;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

class LicenseController extends BaseController
{
    /**
     * Registers the license REST routes.
     *
     * @return void
     */
    public function register(): void
    {
        register_rest_route($this->namespace(), '/license', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'index'],
                'permission_callback' => $this->permission(),
            ],
        ]);

        register_rest_route($this->namespace(), '/license/activate', [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'activate'],
                'permission_callback' => $this->permission(),
                'args'                => [
                    'key' => [
                        'type'     => 'string',
                        'required' => true,
                    ],
                ],
            ],
        ]);

        register_rest_route($this->namespace(), '/license/deactivate', [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'deactivate'],
                'permission_callback' => $this->permission(),
            ],
        ]);

        register_rest_route($this->namespace(), '/license/connect', [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'connect'],
                'permission_callback' => $this->permission(),
            ],
        ]);

        register_rest_route($this->namespace(), '/license/disconnect', [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'disconnect'],
                'permission_callback' => $this->permission(),
            ],
        ]);

        register_rest_route($this->namespace(), '/license/edge-updates', [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'edgeUpdates'],
                'permission_callback' => $this->permission(),
                'args'                => [
                    'edge' => [
                        'type'     => 'boolean',
                        'required' => true,
                    ],
                ],
            ],
        ]);

        register_rest_route($this->namespace(), '/licensing/install-edition', [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'installEdition'],
                'permission_callback' => [$this, 'canInstallPlugins'],
            ],
        ]);
    }

    /**
     * Fires the silent installer for the licensed Pretty Links edition.
     * Used by the edition-mismatch notice's CTA link in the React notice strip.
     */
    public function installEdition(): WP_REST_Response
    {
        $result = (new InstallLicensedEdition())->install();
        if (empty($result['success'])) {
            return new WP_REST_Response(
                [
                    'success' => false,
                    'error'   => $result['error'] ?? 'install_failed',
                ],
                500
            );
        }
        return new WP_REST_Response($result);
    }

    /**
     * Permission callback for installEdition — stricter than the default
     * `permissionCheck` because installing plugins requires `install_plugins`
     * capability, not just `manage_options`.
     */
    public function canInstallPlugins(): bool
    {
        return current_user_can('install_plugins');
    }

    /**
     * Returns the current license state.
     *
     * @return WP_REST_Response
     */
    public function index(): WP_REST_Response
    {
        return new WP_REST_Response($this->manager()->currentLicense());
    }

    /**
     * Activates a license key.
     *
     * @param WP_REST_Request $request The incoming REST request.
     *
     * @return WP_REST_Response
     */
    public function activate(WP_REST_Request $request): WP_REST_Response
    {
        $body = (array) $request->get_json_params();
        $key  = sanitize_text_field((string) ($body['key'] ?? ''));
        if ($key === '') {
            return new WP_REST_Response(['error' => 'key_required'], 400);
        }
        $response = $this->manager()->activate($key);
        if (isset($response['error'])) {
            return new WP_REST_Response($response, 400);
        }
        return new WP_REST_Response([
            'success' => true,
            'result'  => $response,
            'license' => $this->manager()->currentLicense(),
        ]);
    }

    /**
     * Deactivates the current license.
     *
     * @return WP_REST_Response
     */
    public function deactivate(): WP_REST_Response
    {
        $response = $this->manager()->deactivate();
        return new WP_REST_Response([
            'success' => true,
            'result'  => $response,
            'license' => $this->manager()->currentLicense(),
        ]);
    }

    /**
     * Returns the OAuth connect URL.
     *
     * @return WP_REST_Response
     */
    public function connect(): WP_REST_Response
    {
        $return  = admin_url('admin.php?page=pretty-link-options');
        $authUrl = $this->auth()->connectUrl($return);
        return new WP_REST_Response([
            'url' => esc_url_raw($authUrl),
        ]);
    }

    /**
     * Disconnects the current OAuth connection.
     *
     * @return WP_REST_Response
     */
    public function disconnect(): WP_REST_Response
    {
        $response = $this->auth()->disconnect();
        return new WP_REST_Response([
            'success' => !empty($response['disconnected']),
            'result'  => $response,
        ]);
    }

    /**
     * Toggles the edge (beta) update channel.
     *
     * @param WP_REST_Request $request The incoming REST request.
     *
     * @return WP_REST_Response
     */
    public function edgeUpdates(WP_REST_Request $request): WP_REST_Response
    {
        $body = (array) $request->get_json_params();
        $edge = !empty($body['edge']);
        update_option(LicenseManager::OPTION_EDGE_UPDATES, $edge);

        // Refresh the plugin-update transient so the new channel takes effect
        // on the Plugins page without waiting for the 12h cache to expire.
        do_action('prli_license_edge_updates_changed', $edge);

        return new WP_REST_Response([
            'success' => true,
            'edge'    => $edge,
            'license' => $this->manager()->currentLicense(),
        ]);
    }

    /**
     * Resolves the license manager from the container.
     *
     * @return LicenseManager
     */
    private function manager(): LicenseManager
    {
        return $this->container->has(LicenseManager::class)
            ? $this->container->get(LicenseManager::class)
            : new LicenseManager();
    }

    /**
     * Resolves the auth client from the container.
     *
     * @return AuthClient
     */
    private function auth(): AuthClient
    {
        return $this->container->has(AuthClient::class)
            ? $this->container->get(AuthClient::class)
            : new AuthClient();
    }
}
