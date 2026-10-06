<?php

declare(strict_types=1);

namespace PrettyLinks\Rest\Controllers;

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
            // `message` so @wordpress/api-fetch surfaces it as `err.message`
            // for the edition-mismatch notice's install CTA (NoticeStrip).
            return new WP_REST_Response(
                [
                    'code'    => 'install_failed',
                    'message' => (string) ($result['error']
                        ?? __('Could not install the licensed edition. Please try again.', 'pretty-link')),
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
            return new WP_REST_Response([
                'code'    => 'key_required',
                'message' => __('Enter your license key to activate.', 'pretty-link'),
            ], 400);
        }
        $response = $this->manager()->activate($key);
        if (isset($response['error'])) {
            // Surface the licenses-server message (LicenseManager::activate()
            // carries Response::getErrorMessage() through). Use the `message`
            // key so @wordpress/api-fetch exposes it as the rejected error's
            // `.message` for the React notice strip.
            return new WP_REST_Response([
                'code'    => 'activation_failed',
                'message' => (string) $response['error'],
            ], 400);
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
        // The facade always clears local state (even when the server refuses),
        // so this is always a success from the site's perspective; the message
        // tells the user if the activation may still be in use remotely.
        $response = $this->manager()->deactivate();
        return new WP_REST_Response([
            'success' => true,
            'result'  => $response,
            'license' => $this->manager()->currentLicense(),
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

        // Clear WP's update cache so the new release channel (stable vs edge)
        // is re-resolved by ground-level-mothership's UpdateService on the next
        // check, rather than waiting for the cache to expire.
        wp_clean_update_cache();

        // Retained for extensibility; add-ons may still listen for the toggle.
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
        return $this->container->get(LicenseManager::class);
    }
}
