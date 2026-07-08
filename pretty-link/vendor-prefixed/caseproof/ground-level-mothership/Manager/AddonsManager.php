<?php

declare(strict_types=1);

namespace PrettyLinks\GroundLevel\Mothership\Manager;

use PrettyLinks\GroundLevel\Mothership\AbstractPluginConnection;
use PrettyLinks\GroundLevel\Mothership\Api\Request;
use PrettyLinks\GroundLevel\Mothership\Api\Response;
use PrettyLinks\GroundLevel\Mothership\ExtensionType;
use PrettyLinks\GroundLevel\Support\Concerns\Hookable;
use PrettyLinks\GroundLevel\Support\Models\Hook;
use PrettyLinks\GroundLevel\Support\View;

/**
 * The AddonsManager class fetches the available add-ons and integrates with the WP extension installation API.
 */
class AddonsManager
{
    use Hookable;

    /**
     * Suffix for the cache key for the Products API response.
     *
     * @var string
     */
    protected const CACHE_KEY_PRODUCTS = '-mosh-products';

    /**
     * Suffix for the cache key for the update check.
     *
     * @var string
     */
    protected const CACHE_KEY_UPDATE_CHECK = '-mosh-addons-update-check';

    /**
     * The duration of the cache for the Products API response, default 60 minutes.
     *
     * @var integer
     */
    protected const CACHE_DURATION_MINUTES = 60;

    /**
     * The duration of the cache for the update check, default 30 minutes.
     *
     * @var integer
     */
    protected const UPDATE_CHECK_DURATION_MINUTES = 30;

    /**
     * The duration of the negative cache after a failed API request, default 5 minutes.
     *
     * @var integer
     */
    protected const ERROR_CACHE_DURATION_MINUTES = 5;

    /**
     * The plugin connection.
     *
     * @var AbstractPluginConnection
     */
    private AbstractPluginConnection $plugin;

    /**
     * The request instance.
     *
     * @var Request
     */
    private Request $request;

    /**
     * The view instance for rendering templates.
     *
     * @var View
     */
    private View $view;

    /**
     * The product object for the AJAX request.
     *
     * @var object|null
     */
    protected ?object $ajaxProduct = null;

    /**
     * Constructor.
     *
     * @param AbstractPluginConnection $plugin  The plugin connection.
     * @param Request                  $request The request instance.
     * @param View                     $view    The view instance for rendering templates.
     */
    public function __construct(AbstractPluginConnection $plugin, Request $request, View $view)
    {
        $this->plugin  = $plugin;
        $this->request = $request;
        $this->view    = $view;
    }

    /**
     * Configure WordPress hooks.
     *
     * @return array<Hook>
     */
    protected function configureHooks(): array
    {
        return [
            new Hook(
                Hook::TYPE_ACTION,
                'wp_ajax_mosh_addon_activate',
                [$this, 'ajaxAddonActivate']
            ),
            new Hook(
                Hook::TYPE_ACTION,
                'wp_ajax_mosh_addon_deactivate',
                [$this, 'ajaxAddonDeactivate']
            ),
            new Hook(
                Hook::TYPE_ACTION,
                'wp_ajax_mosh_addon_install',
                [$this, 'ajaxAddonInstall']
            ),
            new Hook(
                Hook::TYPE_FILTER,
                'site_transient_update_themes',
                [$this, 'addonsUpdateThemes']
            ),
            new Hook(
                Hook::TYPE_FILTER,
                'site_transient_update_plugins',
                [$this, 'addonsUpdatePlugins']
            ),
        ];
    }

    /**
     * Update the plugins transient with the available add-ons.
     *
     * @param  mixed $transient The update plugins transient.
     * @return mixed            The modified transient.
     */
    public function addonsUpdatePlugins($transient)
    {
        return $this->updateTransient($transient, ExtensionType::PLUGIN());
    }

    /**
     * Update the themes transient with the available add-ons.
     *
     * @param  mixed $transient The update themes transient.
     * @return mixed            The modified transient.
     */
    public function addonsUpdateThemes($transient)
    {
        return $this->updateTransient($transient, ExtensionType::THEME());
    }

    /**
     * Update the transient with the available add-ons.
     *
     * @param  mixed         $transient     The transient to update.
     * @param  ExtensionType $extensionType The extension type being updated.
     * @return mixed                        The modified transient.
     */
    protected function updateTransient($transient, ExtensionType $extensionType)
    {
        if (! $this->hasActiveLicense() || ! is_object($transient)) {
            return $transient;
        }

        if (! isset($transient->response) || ! is_array($transient->response)) {
            $transient->response = [];
        }

        $response = $this->getAddons(true);

        if ($response->isError()) {
            return $transient;
        }

        $products = $this->filterProductsByExtensionType($response->getData('products', []), $extensionType);
        return (! empty($products)) ? $this->injectUpdates($products, $transient, $extensionType) : $transient;
    }

    /**
     * Returns the modified update transient with add-on updates.
     *
     * @param  array         $products      The products to check.
     * @param  mixed         $transient     The transient to update.
     * @param  ExtensionType $extensionType The extension type to filter by.
     * @return mixed                        The modified transient.
     */
    protected function injectUpdates(array $products, $transient, ExtensionType $extensionType)
    {
        foreach ($products ?? [] as $product) {
            $mainFile      = $product->main_file ?? ''; // phpcs:ignore Squiz.NamingConventions.ValidVariableName.MemberNotCamelCaps -- API response.
            $versionLatest = $product->_embedded->{'version-latest'}->number ?? '';
            $urlLatest     = $product->_embedded->{'version-latest'}->url ?? '';
            $item          = null;
            // Plugins use the main file while themes use the directory name.
            $transientKey = $extensionType->equals(ExtensionType::THEME(), false) ? dirname($mainFile) : $mainFile;

            if (
                empty($mainFile) ||
                empty($versionLatest) ||
                empty($urlLatest) ||
                ! isset($transient->checked[$transientKey])
            ) {
                continue;
            }

            if ($extensionType->equals(ExtensionType::PLUGIN(), false)) {
                $item = (object) [
                    'id'           => $mainFile,
                    'slug'         => dirname($mainFile),
                    'plugin'       => $mainFile,
                    'new_version'  => $versionLatest,
                    'package'      => $urlLatest,
                    'url'          => '',
                    'tested'       => '',
                    'requires_php' => '',
                    'icons'        => [
                        '2x' => $product->image,
                        '1x' => $product->image,
                    ],
                ];
            } elseif ($extensionType->equals(ExtensionType::THEME(), false)) {
                $item = [
                    'theme'        => dirname($mainFile),
                    'new_version'  => $versionLatest,
                    'package'      => $urlLatest,
                    'url'          => '',
                    'requires'     => '',
                    'requires_php' => '',
                    'icons'        => [
                        '2x' => $product->image,
                        '1x' => $product->image,
                    ],
                ];
            }

            if (! is_null($item)) {
                if (version_compare($transient->checked[$transientKey], $versionLatest, '>=')) {
                    $transient->no_update[$transientKey] = $item; // phpcs:ignore Squiz.NamingConventions.ValidVariableName.MemberNotCamelCaps -- WordPress data structure.
                } else {
                    $transient->response[$transientKey] = $item;
                }
            }
        }

        return $transient;
    }

    /**
     * Get the add-ons from the API.
     *
     * @param  boolean $cached Whether to use the cached products or not.
     * @return Response The add-ons response.
     */
    public function getAddons(bool $cached = false): Response
    {
        if ($cached && $this->cachedResponseIsValid()) {
            $response = $this->getCachedApiResponse();
            if (!is_null($response)) {
                return $response;
            }
        }

        $args['_embed'] = 'version-latest';
        $response       = $this->request->get('products', $args);
        $cacheDuration  = $response->isError() ? self::ERROR_CACHE_DURATION_MINUTES : self::CACHE_DURATION_MINUTES;

        set_transient(
            $this->plugin->pluginId . self::CACHE_KEY_PRODUCTS,
            $response,
            $cacheDuration * MINUTE_IN_SECONDS
        );

        $this->markCacheRefreshed($cacheDuration);

        return $response;
    }

    /**
     * Get the cached API response.
     *
     * @return Response|null The cached API response, or null if unavailable.
     */
    protected function getCachedApiResponse(): ?Response
    {
        $cached = get_transient($this->plugin->pluginId . self::CACHE_KEY_PRODUCTS);

        return $cached instanceof Response ? $cached : null;
    }

    /**
     * Clears the cache.
     *
     * @return void
     */
    public function clearCache(): void
    {
        delete_transient($this->plugin->pluginId . self::CACHE_KEY_PRODUCTS);
        delete_transient($this->plugin->pluginId . self::CACHE_KEY_UPDATE_CHECK);
    }

    /**
     * Check if the cached API response is valid.
     *
     * @return boolean
     */
    protected function cachedResponseIsValid(): bool
    {
        $updateCheckTransient = get_transient($this->plugin->pluginId . self::CACHE_KEY_UPDATE_CHECK);
        return (false !== $updateCheckTransient);
    }

    /**
     * Mark the cached API response valid for a designated period.
     *
     * @param  integer $minutes Optionally set the duration of the cache, default 30 minutes.
     * @return boolean          True if the transient was set, false otherwise.
     */
    protected function markCacheRefreshed(int $minutes = 30): bool
    {
        return set_transient(
            $this->plugin->pluginId . self::CACHE_KEY_UPDATE_CHECK,
            null,
            $minutes * MINUTE_IN_SECONDS
        );
    }

    /**
     * Check if the license for the product exists and is active.
     *
     * @return boolean
     */
    protected function hasActiveLicense(): bool
    {
        return $this->plugin->getLicenseKey() && $this->plugin->getLicenseActivationStatus();
    }

    /**
     * Filter products by extension type.
     *
     * @param  array         $products      The products to filter.
     * @param  ExtensionType $extensionType The extension type to filter by.
     * @return array
     */
    protected function filterProductsByExtensionType(array $products, ExtensionType $extensionType): array
    {
        return array_values(
            array_filter($products, function ($product) use ($extensionType) {
                return ($product->extension_type ?? false) === $extensionType->getValue(); // phpcs:ignore Squiz.NamingConventions.ValidVariableName.MemberNotCamelCaps -- API response
            })
        );
    }

    /**
     * Get a product by slug.
     *
     * @param  string $slug The slug of the product to get.
     * @return object|null The product, or null if not found.
     */
    protected function getProductBySlug(string $slug): ?object
    {
        $apiResponse = $this->getAddons(true);
        $result      = null;

        foreach ($apiResponse->getData('products', []) as $product) {
            if (! empty($product->slug) && $product->slug === $slug) {
                $result = $product;
                break;
            }
        }

        return $result;
    }

    /**
     * Setup an AJAX request. All requests setup the same way: validate the nonce
     * and slug, get a product using the slug, and set the instance property.
     *
     * @return void
     */
    protected function setupAjaxRequest(): void
    {
        if (! check_ajax_referer('mosh_addons', false, false)) {
            wp_send_json_error(
                new \WP_Error(
                    'security_check_failed',
                    esc_html__('Security check failed.', 'pretty-link')
                )
            );
        }

        if (empty($_POST['slug']) || ! is_string($_POST['slug'])) {
            wp_send_json_error(
                new \WP_Error(
                    'bad_request',
                    esc_html__('Bad request.', 'pretty-link')
                )
            );
        }

        $slug              = sanitize_text_field(wp_unslash($_POST['slug'] ?? ''));
        $this->ajaxProduct = $this->getProductBySlug($slug);

        if (! $this->ajaxProduct) {
            wp_send_json_error(
                new \WP_Error(
                    'addon_not_found',
                    esc_html__('Add-on not found.', 'pretty-link')
                )
            );
        }
    }

    /**
     * Activate an add-on.
     *
     * @return void
     */
    public function ajaxAddonActivate(): void
    {
        $this->setupAjaxRequest();
        $extensionType  = $this->ajaxProduct->extension_type ?? false; // phpcs:ignore Squiz.NamingConventions.ValidVariableName.MemberNotCamelCaps -- API response
        $hasPermissions = false;

        if (ExtensionType::PLUGIN === $extensionType) {
            $hasPermissions = current_user_can('activate_plugins');
        } elseif (ExtensionType::THEME === $extensionType) {
            $hasPermissions = current_user_can('switch_themes');
        } else {
            wp_send_json_error(
                new \WP_Error(
                    'invalid_addon_type',
                    esc_html__('Invalid add-on type.', 'pretty-link')
                )
            );
        }

        if (! $hasPermissions) {
            wp_send_json_error(
                new \WP_Error(
                    'insufficient_permissions',
                    esc_html__(
                        "Sorry, you don't have the necessary permission to perform this action.",
                        'pretty-link'
                    )
                )
            );
        }

        $mainFile  = $this->ajaxProduct->main_file ?? false; // phpcs:ignore Squiz.NamingConventions.ValidVariableName.MemberNotCamelCaps -- API response.
        $activated = false;

        if ($mainFile) {
            if (ExtensionType::PLUGIN === $extensionType) {
                $activated = activate_plugin($mainFile);
                $activated = (is_null($activated)) ? true : $activated;
            } else {
                switch_theme(dirname($mainFile));
                $activated = true;
            }
        }

        if ($activated && ! is_wp_error($activated)) {
            $successMsg = (ExtensionType::PLUGIN === $extensionType) ?
                esc_html__('Plugin activated.', 'pretty-link') :
                esc_html__('Theme activated.', 'pretty-link');
            wp_send_json_success($successMsg);
        } else {
            wp_send_json_error(
                new \WP_Error(
                    'activation_failed',
                    esc_html__('The add-on could not be activated.', 'pretty-link')
                )
            );
        }
    }

    /**
     * Deactivate an add-on.
     *
     * @return void
     */
    public function ajaxAddonDeactivate(): void
    {
        $this->setupAjaxRequest();
        $extensionType  = $this->ajaxProduct->extension_type ?? false; // phpcs:ignore Squiz.NamingConventions.ValidVariableName.MemberNotCamelCaps -- API response
        $hasPermissions = false;

        if (ExtensionType::PLUGIN === $extensionType) {
            $hasPermissions = current_user_can('deactivate_plugins');
        } elseif (ExtensionType::THEME === $extensionType) {
            wp_send_json_error(
                new \WP_Error(
                    'invalid_addon_type',
                    esc_html__(
                        'Themes cannot be deactivated. Activate a new theme instead.',
                        'pretty-link'
                    )
                )
            );
        } else {
            wp_send_json_error(
                new \WP_Error(
                    'invalid_addon_type',
                    esc_html__('Invalid add-on type.', 'pretty-link')
                )
            );
        }

        if (! $hasPermissions) {
            wp_send_json_error(
                new \WP_Error(
                    'insufficient_permissions',
                    esc_html__(
                        "Sorry, you don't have permission to deactivate addons.",
                        'pretty-link'
                    )
                )
            );
        }

        $mainFile = $this->ajaxProduct->main_file ?? false; // phpcs:ignore Squiz.NamingConventions.ValidVariableName.MemberNotCamelCaps -- API response.

        if (! $mainFile) {
            wp_send_json_error(
                new \WP_Error(
                    'deactivation_failed',
                    esc_html__('The add-on could not be deactivated.', 'pretty-link')
                )
            );
        }

        deactivate_plugins($mainFile);

        $successMsg = (ExtensionType::PLUGIN === $extensionType) ?
            esc_html__('Plugin deactivated.', 'pretty-link') :
            esc_html__('Add-on deactivated.', 'pretty-link');

        wp_send_json_success($successMsg);
    }

    /**
     * Install an add-on.
     *
     * @return void
     */
    public function ajaxAddonInstall(): void
    {
        $this->setupAjaxRequest();
        $extensionType  = $this->ajaxProduct->extension_type ?? false; // phpcs:ignore Squiz.NamingConventions.ValidVariableName.MemberNotCamelCaps -- API response
        $hasPermissions = false;

        if (ExtensionType::PLUGIN === $extensionType) {
            $hasPermissions = current_user_can('install_plugins') && current_user_can('activate_plugins');
        } elseif (ExtensionType::THEME === $extensionType) {
            $hasPermissions = current_user_can('install_themes') && current_user_can('switch_themes');
        } else {
            wp_send_json_error(
                new \WP_Error(
                    'invalid_addon_type',
                    esc_html__('Invalid add-on type.', 'pretty-link')
                )
            );
        }

        if (! $hasPermissions) {
            wp_send_json_error(
                new \WP_Error(
                    'insufficient_permissions',
                    esc_html__(
                        "Sorry, you don't have permission to install addons.",
                        'pretty-link'
                    )
                )
            );
        }

        set_current_screen();
        $creds = request_filesystem_credentials(admin_url('admin.php'), '', false, false, null);

        if (false === $creds || ! WP_Filesystem($creds)) {
            wp_send_json_error(
                new \WP_Error(
                    'insufficient_permissions',
                    esc_html__(
                        "Sorry, you don't have permission to install addons.",
                        'pretty-link'
                    )
                )
            );
        }

        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        remove_action('upgrader_process_complete', ['Language_Pack_Upgrader', 'async_upgrade'], 20);


        if (ExtensionType::PLUGIN === $extensionType) {
            require_once(ABSPATH . 'wp-admin/includes/plugin.php');
            $installer = new \Plugin_Upgrader(new AddonInstallSkin());
        } else {
            require_once(ABSPATH . 'wp-admin/includes/theme.php');
            $installer = new \Theme_Upgrader(new AddonInstallSkin());
        }

        $addonUrl = $this->ajaxProduct->_embedded->{'version-latest'}->url ?? '';

        if (! filter_var($addonUrl, FILTER_VALIDATE_URL)) {
            wp_send_json_error(
                new \WP_Error(
                    'invalid_addon_url',
                    esc_html__('Invalid add-on URL.', 'pretty-link')
                )
            );
        }

        $installed = $installer->install($addonUrl);

        if (! $installed || is_wp_error($installed)) {
            wp_send_json_error(
                new \WP_Error(
                    'addon_install_failed',
                    esc_html__('The add-on was not installed successfully.', 'pretty-link')
                )
            );
        }

        wp_cache_flush();
        $activated = false;

        if (ExtensionType::PLUGIN === $extensionType) {
            $baseName  = $installer->plugin_info();
            $activated = ($baseName) ? is_null(activate_plugin($baseName)) : $activated;
        } else {
            $themeInfo = $installer->theme_info();
            if ($themeInfo instanceof \WP_Theme) {
                $baseName = $themeInfo->get_stylesheet();
                if ($baseName) {
                    switch_theme($baseName);
                    $activated = get_stylesheet() === $baseName;
                }
            }
        }

        if ($activated) {
            wp_send_json_success(
                [
                    'message'   => $extensionType === ExtensionType::PLUGIN
                                    ? esc_html__('Plugin installed and activated.', 'pretty-link')
                                    : esc_html__('Theme installed and activated.', 'pretty-link'),
                    'activated' => true,
                ]
            );
        } else {
            wp_send_json_success(
                [
                    'message'   => $extensionType === ExtensionType::PLUGIN
                                    ? esc_html__('Plugin installed.', 'pretty-link')
                                    : esc_html__('Theme installed.', 'pretty-link'),
                    'activated' => false,
                ]
            );
        }
    }

    /**
     * Generates and returns the HTML for the add-ons.
     *
     * @return string The HTML for the add-ons.
     */
    public function generateAddonsHtml(): string
    {
        if (! $this->plugin->getLicenseKey()) {
            return '<div class="notice notice-error is-dismissible"><p>' . esc_html__(
                'Please enter your license key to access add-ons.',
                'pretty-link'
            ) . '</p></div>';
        }

        // Refresh the add-ons if the button is clicked.
        if (
            isset($_POST['submit-button-mosh-refresh-addon'])
            && wp_verify_nonce(
                sanitize_text_field(wp_unslash($_POST['grdlvl_mosh_refresh_addons_nonce'] ?? '')),
                'grdlvl_mosh_refresh_addons'
            )
        ) {
            delete_transient($this->plugin->pluginId . self::CACHE_KEY_PRODUCTS);
        }

        $addons = $this->getAddons(true);
        if ($addons->isError()) {
            return sprintf(
                '<div class=""><p>%s <b>%s</b></p></div>',
                esc_html__('There was an issue connecting with the API.', 'pretty-link'),
                esc_html($addons->getMessage())
            );
        }

        $this->enqueueAssets();
        $products = $this->prepareProductsForDisplay($addons->getData('products', []));

        return $this->view->render('products.php', ['products' => $products]);
    }

    /**
     * Prepare the addons for display. Remove parent products and any addons that may be missing
     * required data, and add data for installation status, text, and icon class.
     *
     * @param  array $products The products to prepare. Each product is a StdClass object.
     * @return array           The prepared products.
     */
    protected function prepareProductsForDisplay(array $products): array
    {
        $products = array_values(
            array_filter($products, function ($product) {
                $isAddon          = ! empty($product->type) && 'addon' === $product->type;
                $hasMainFile      = ! empty($product->main_file); // phpcs:ignore Squiz.NamingConventions.ValidVariableName.MemberNotCamelCaps -- API response.
                $hasExtensionType = ! empty($product->extension_type); // phpcs:ignore Squiz.NamingConventions.ValidVariableName.MemberNotCamelCaps -- API response
                return $isAddon && $hasMainFile && $hasExtensionType;
            })
        );

        $pluginUpdates = get_site_transient('update_plugins');
        $themeUpdates  = get_site_transient('update_themes');

        foreach ($products as $product) {
            $mainFile      = $product->main_file ?? false; // phpcs:ignore Squiz.NamingConventions.ValidVariableName.MemberNotCamelCaps -- API response.
            $extensionType = $product->extension_type ?? false; // phpcs:ignore Squiz.NamingConventions.ValidVariableName.MemberNotCamelCaps -- API response
            if (ExtensionType::PLUGIN === $extensionType) {
                $installed = is_dir(WP_PLUGIN_DIR . '/' . dirname($mainFile));
                $active    = is_plugin_active($mainFile);
                // TODO: Add JS for update handling, set this using isset($pluginUpdates->response[$mainFile]).
                $product->updateAvailable = false;
            } else {
                $theme     = wp_get_theme(dirname($mainFile));
                $installed = $theme->exists();
                $active    = get_stylesheet() === dirname($mainFile);
                // TODO: Add JS for update handling, set this using isset($themeUpdates->response[$mainFile]).
                $product->updateAvailable = false;
            }
            if ($installed && $active) {
                $product->status      = 'active';
                $product->statusLabel = esc_html__('Active', 'pretty-link');

                if (ExtensionType::PLUGIN === $extensionType) {
                    $product->iconClass   = 'dashicons dashicons-no-alt';
                    $product->buttonLabel = esc_html__('Deactivate', 'pretty-link');
                } else {
                    $product->iconClass   = 'dashicons dashicons-admin-appearance';
                    $product->buttonLabel = esc_html__('Switch Themes', 'pretty-link');
                }
            } elseif (! $installed) {
                $product->status      = 'not-installed';
                $product->iconClass   = 'dashicons dashicons-download';
                $product->statusLabel = esc_html__('Not Installed', 'pretty-link');
                $product->buttonLabel = esc_html__('Install Add-on', 'pretty-link');
            } else {
                $product->status      = 'inactive';
                $product->iconClass   = 'dashicons dashicons-yes-alt';
                $product->statusLabel = esc_html__('Inactive', 'pretty-link');
                $product->buttonLabel = esc_html__('Activate', 'pretty-link');
            }
        }

        return $products;
    }

    /**
     * Enqueues the assets for the add-ons display.
     *
     * @return void
     */
    public function enqueueAssets(): void
    {
        wp_enqueue_style('dashicons');
        wp_enqueue_script('mosh-addons-js', plugin_dir_url(__FILE__) . '../assets/addons.js', [], null, true);
        wp_enqueue_style('mosh-addons-css', plugin_dir_url(__FILE__) . '../assets/addons.css');
        wp_localize_script('mosh-addons-js', 'MoshAddons', [
            'ajax_url'              => admin_url('admin-ajax.php'),
            'themes_url'            => admin_url('themes.php'),
            'nonce'                 => wp_create_nonce('mosh_addons'),
            'active'                => esc_html__('Active', 'pretty-link'),
            'inactive'              => esc_html__('Inactive', 'pretty-link'),
            'activate'              => esc_html__('Activate', 'pretty-link'),
            'deactivate'            => esc_html__('Deactivate', 'pretty-link'),
            'switch_themes'         => esc_html__('Switch Themes', 'pretty-link'),
            'processing'            => esc_html__('Processing...', 'pretty-link'),
            'install_failed'        => esc_html__(
                'Could not install theme. Please download and install manually.',
                'pretty-link'
            ),
            'plugin_install_failed' => esc_html__(
                'Could not install plugin. Please download and install manually.',
                'pretty-link'
            ),
        ]);
    }
}
