<?php

declare(strict_types=1);

namespace PrettyLinks\GroundLevel\Mothership\Manager;

use PrettyLinks\GroundLevel\Mothership\AbstractPluginConnection;
use PrettyLinks\GroundLevel\Mothership\Api\Request\LicenseActivations;
use PrettyLinks\GroundLevel\Mothership\Api\Request\Licenses;
use PrettyLinks\GroundLevel\Mothership\Api\Request\Products;
use PrettyLinks\GroundLevel\Mothership\Api\Response;
use PrettyLinks\GroundLevel\Mothership\Credentials;
use PrettyLinks\GroundLevel\Mothership\Transients\ActivationTransient;
use PrettyLinks\GroundLevel\Support\AdminNotices;
use PrettyLinks\GroundLevel\Support\Concerns\Hookable;
use PrettyLinks\GroundLevel\Support\Models\Hook;
use PrettyLinks\GroundLevel\Support\Result;
use PrettyLinks\GroundLevel\Support\View;

/**
 * Manages license activation, deactivation, validation, and plugin updates via the Mothership API.
 */
class LicenseManager
{
    use Hookable;

    /**
     * The plugin connection.
     *
     * @var AbstractPluginConnection
     */
    private AbstractPluginConnection $plugin;

    /**
     * The addons manager instance.
     *
     * @var AddonsManager
     */
    private AddonsManager $addonsManager;

    /**
     * The credentials instance.
     *
     * @var Credentials
     */
    private Credentials $credentials;

    /**
     * The license activations API.
     *
     * @var LicenseActivations
     */
    private LicenseActivations $licenseActivations;

    /**
     * The products API.
     *
     * @var Products
     */
    private Products $products;

    /**
     * The licenses API.
     *
     * @var Licenses
     */
    private Licenses $licenses;

    /**
     * The activation transient.
     *
     * @var ActivationTransient
     */
    private ActivationTransient $activationTransient;

    /**
     * The admin notices service.
     *
     * @var AdminNotices
     */
    private AdminNotices $adminNotices;

    /**
     * The view instance for rendering templates.
     *
     * @var View
     */
    private View $view;

    /**
     * Constructor.
     *
     * @param AbstractPluginConnection $plugin              The plugin connection.
     * @param AddonsManager            $addonsManager       The addons manager instance.
     * @param Credentials              $credentials         The credentials instance.
     * @param LicenseActivations       $licenseActivations  The license activations API.
     * @param Products                 $products            The products API.
     * @param Licenses                 $licenses            The licenses API.
     * @param ActivationTransient      $activationTransient The activation transient.
     * @param AdminNotices             $adminNotices        The admin notices service.
     * @param View                     $view                The view instance for rendering templates.
     */
    public function __construct(
        AbstractPluginConnection $plugin,
        AddonsManager $addonsManager,
        Credentials $credentials,
        LicenseActivations $licenseActivations,
        Products $products,
        Licenses $licenses,
        ActivationTransient $activationTransient,
        AdminNotices $adminNotices,
        View $view
    ) {
        $this->plugin              = $plugin;
        $this->addonsManager       = $addonsManager;
        $this->credentials         = $credentials;
        $this->licenseActivations  = $licenseActivations;
        $this->products            = $products;
        $this->licenses            = $licenses;
        $this->activationTransient = $activationTransient;
        $this->adminNotices        = $adminNotices;
        $this->view                = $view;

        // Add WP Cron event to check the license status every 12 hours.
        $cronName = $this->plugin->pluginId . '_check_license_activation_status_event';
        if (!wp_next_scheduled($cronName)) {
            wp_schedule_event(time(), 'twicedaily', $cronName);
        }
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
                $this->plugin->pluginId . '_check_license_activation_status_event',
                [$this, 'checkLicenseActivationStatus']
            ),
            new Hook(
                Hook::TYPE_ACTION,
                $this->plugin->pluginId . '_active_license_invalidated',
                [$this,  'onLicenseRevoked'],
            ),
            new Hook(
                Hook::TYPE_ACTION,
                $this->plugin->pluginId . '_active_license_expired',
                [$this,  'onLicenseRevoked'],
            ),
            new Hook(
                Hook::TYPE_FILTER,
                'update_plugins_' . $this->plugin->pluginId,
                [$this, 'updatePlugin'],
                10,
                2
            ),
            new Hook(
                Hook::TYPE_FILTER,
                'plugins_api',
                [$this, 'pluginInformation'],
                10,
                3
            ),
            new Hook(
                Hook::TYPE_FILTER,
                'auto_update_plugin',
                [$this, 'autoUpdatePlugin'],
                10,
                2
            ),
            new Hook(
                Hook::TYPE_FILTER,
                'upgrader_pre_install',
                [$this, 'preventUpdatesInDevelopment'],
                10,
                2
            ),
        ];
    }

    /**
     * Checks the Mothership server for plugin updates.
     *
     * Important: the Update URI header must be set in the plugin's main file for this to be triggered.
     * The header value should match the {@see AbstractPluginConnection::$pluginId}.
     * For example, if $pluginId is set to 'ground-level-dev', the plugin header should be:
     * Update URI: ground-level-dev
     *
     * Free plugins/editions should omit the Update URI header, and this method will never be hooked,
     * allowing updates to be handled via the WordPress.org repository as normal.
     *
     * The $update array shape when an update is available:
     *   - id?:            string
     *   - slug:           string
     *   - version:        string
     *   - url:            string
     *   - package?:       string
     *   - tested?:        string
     *   - requires_php?:  string
     *   - autoupdate?:    bool
     *   - icons?:         string[]
     *   - banners?:       string[]
     *   - banners_rtl?:   string[]
     *   - translations?:  array
     *
     * @param false|array $update     Plugin update data with the latest details. False if no update is available.
     * @param array       $pluginData Plugin headers.
     *
     * @return array|false The update array or false if no update is available.
     */
    public function updatePlugin($update, $pluginData)
    {
        $versionCheck = $this->products->getVersionCheck(
            $this->plugin->productId,
            [
                'prerelease' => $this->plugin->allowPrereleaseVersions(),
                '_embed'     => 'version',
            ]
        );
        if ($versionCheck->isError()) {
            return $update;
        }

        /**
         * If the embed isn't returned that could be because the user is not authenticated,
         * the license is expired or disabled, etc... In these scenarios
         * we'll still return the version number but there won't be anything to download.
         */
        $versionLatest = $versionCheck->getEmbed('version');
        return [
            'slug'    => $this->plugin->pluginId,
            'version' => $versionCheck->getData('number', ''),
            'package' => $versionLatest->url ?? '',
            'url'     => $pluginData['PluginURI'] ?? '',
        ];
    }

    /**
     * Provides plugin information for the WordPress plugin details modal.
     *
     * The $args object properties:
     *   - slug?:              string
     *   - per_page?:          int
     *   - page?:              int
     *   - number?:            int
     *   - search?:            string
     *   - tag?:               string
     *   - author?:            string
     *   - user?:              string
     *   - browse?:            string
     *   - locale?:            string
     *   - installed_plugins?: string
     *   - is_ssl?:            bool
     *   - fields?:            array {
     *       short_description, description, sections, tested, requires, requires_php,
     *       rating, ratings, downloaded, downloadlink, last_updated, added, tags,
     *       compatibility, homepage, versions, donate_link, reviews, banners, icons,
     *       active_installs, contributors
     *   }
     *
     * @see https://developer.wordpress.org/reference/hooks/plugins_api/
     *
     * @param false|object $result The result object. Default false.
     * @param string       $action The type of information being requested from the Plugin Installation API.
     *                             One of 'query_plugins', 'plugin_information', or 'hot_tags'.
     * @param object       $args   Plugin API arguments.
     *
     * @return false|object The plugin information object or the original $result.
     */
    public function pluginInformation($result, string $action, $args)
    {
        if ($action !== 'plugin_information') {
            return $result;
        }

        if (!isset($args->slug) || $args->slug !== $this->plugin->pluginId) {
            return $result;
        }

        $product = $this->products->get($this->plugin->productId, ['_embed' => 'version-latest']);

        if ($product->isError()) {
            return $result;
        }

        $latestVersion = $product->getEmbed('version-latest');
        if (is_null($latestVersion)) {
            return $result;
        }

        $pluginInfo = (object) [
            'name'          => $product->getData('name', ''),
            'slug'          => $args->slug,
            'version'       => $latestVersion->number ?? '',
            // phpcs:ignore Squiz.NamingConventions.ValidVariableName.MemberNotCamelCaps
            'last_updated'  => $latestVersion->created_at ?? '',
            'download_link' => $latestVersion->url ?? '',
            'sections'      => [
                'description' => $product->getData('description', ''),
            ],
        ];

        /**
         * Filters the plugin information displayed in the WordPress plugin details modal.
         *
         * Consuming plugins can use this filter to provide additional details
         * such as author, homepage, banners, and changelog that are not available
         * from the Mothership API.
         *
         * @param object $pluginInfo The plugin information object.
         * @param object $product    The product data from the Mothership API.
         */
        return apply_filters(
            $this->plugin->pluginId . '_plugin_information',
            $pluginInfo,
            $product
        );
    }

    /**
     * Prevents plugin updates in development environments by checking for a .git directory.
     *
     * This hooks into WordPress's upgrader_pre_install filter and blocks the update if the
     * plugin directory contains a .git directory. This covers both manual "Update Now" clicks
     * from the plugins page and programmatic calls via {@see installPluginSilently()}.
     *
     * @param boolean|\WP_Error $abort Whether to abort the update. A WP_Error will abort.
     * @param array             $extra Extra arguments passed by the upgrader, including 'plugin'.
     *
     * @return boolean|\WP_Error The original $abort value, or a WP_Error to block the update.
     */
    public function preventUpdatesInDevelopment($abort, $extra)
    {
        if (!isset($extra['plugin']) || $extra['plugin'] !== $this->plugin->pluginFile) {
            return $abort;
        }

        $pluginDir = WP_PLUGIN_DIR . '/' . dirname($this->plugin->pluginFile);

        if (@is_dir($pluginDir . '/.git')) {
            return new \WP_Error(
                'dev_environment',
                __(
                    'Plugin update is prevented in development environment to avoid accidental overwrites.',
                    'pretty-link'
                )
            );
        }

        return $abort;
    }

    /**
     * Determines whether the plugin should be auto-updated during WordPress background updates.
     *
     * @param boolean|null $update Whether to auto-update. Null if not yet decided.
     * @param object       $item   The plugin update object containing plugin, new_version, etc.
     *
     * @return boolean|null Whether to auto-update the plugin.
     */
    public function autoUpdatePlugin($update, $item): ?bool
    {
        if (!isset($item->plugin) || $item->plugin !== $this->plugin->pluginFile) {
            return $update;
        }

        if (!$this->plugin->getLicenseActivationStatus()) {
            return $update;
        }

        $policy = $this->plugin->automaticUpdates();

        if (AbstractPluginConnection::AUTOMATIC_UPDATE_NONE === $policy) {
            return false;
        }

        if (AbstractPluginConnection::AUTOMATIC_UPDATE_ALL === $policy) {
            return true;
        }

        // {@see GroundLevel\Mothership\AbstractPluginConnection::AUTOMATIC_UPDATE_MINOR} policy: allow minor
        // and patch updates, block major version bumps.
        // phpcs:ignore Squiz.NamingConventions.ValidVariableName.MemberNotCamelCaps
        $currentMajor = (int) explode('.', $item->Version ?? '0')[0];
        // phpcs:ignore Squiz.NamingConventions.ValidVariableName.MemberNotCamelCaps
        $newMajor = (int) explode('.', $item->new_version ?? '0')[0];

        return $newMajor === $currentMajor;
    }

    /**
     * Cleans up after a license is revoked (invalidated or expired).
     */
    public function onLicenseRevoked(): void
    {
        // Update license status.
        $this->plugin->updateLicenseActivationStatus(false);

        // Notify users.
        $this->notifyLicenseRevoked();

        // Clean up.
        $this->addonsManager->clearCache();
        $this->activationTransient->delete();
    }

    /**
     * Sends license revocation notifications via admin notice and email.
     */
    private function notifyLicenseRevoked(): void
    {
        $isExpired   = $this->plugin->pluginId . '_active_license_expired' === current_action();
        $productName = $this->activationTransient->productName ?: $this->plugin->productId;
        $licenseKey  = $this->activationTransient->licenseKey ?: '';
        $accountUrl  = $this->plugin->getAccountUrl();

        if ($isExpired) {
            $error = sprintf(
                // Translators: %1$s license key, %2$s product name.
                __(
                    'The license key %1$s has expired. Please renew your license to continue using %2$s.',
                    'pretty-link'
                ),
                '<code>' . esc_html($licenseKey) . '</code>',
                $productName
            );
        } else {
            $error = __(
                // phpcs:ignore Generic.Files.LineLength.TooLong
                "This issue could have been caused by a change in the license's status or the site may have been disabled from your account dashboard.",
                'pretty-link'
            );
        }

        $this->notifyLicenseRevokedNotice($error, $productName, $accountUrl);
        $this->notifyLicenseRevokedEmail($error, $productName, $accountUrl);
    }

    /**
     * Displays an admin notice for a revoked or expired license.
     *
     * @param string $error       The error detail message.
     * @param string $productName The product name.
     * @param string $accountUrl  The account dashboard URL.
     */
    private function notifyLicenseRevokedNotice(
        string $error,
        string $productName,
        string $accountUrl
    ): void {
        $message = sprintf(
            // Translators: %s product name.
            __('During a recent %s license status check an error was encountered.', 'pretty-link'),
            $productName
        );

        $actions = [];
        if (!empty($accountUrl)) {
            $actions[] = [
                'label' => __('Visit Account Dashboard', 'pretty-link'),
                'url'   => $accountUrl,
            ];
        }

        $notice = implode(' ', [$message, $error]);
        $this->adminNotices->notice('license_revoked', $notice, AdminNotices::WARNING, $actions);
    }

    /**
     * Sends an email notification for a revoked or expired license.
     *
     * @param string $error       The error detail message.
     * @param string $productName The product name.
     * @param string $accountUrl  The account dashboard URL.
     */
    private function notifyLicenseRevokedEmail(
        string $error,
        string $productName,
        string $accountUrl
    ): void {
        $siteName = get_bloginfo('name');
        $siteUrl  = home_url();

        $subject = sprintf(
            // Translators: %1$s site name, %2$s product name.
            __('[WARNING] Error with your %1$s license for %2$s', 'pretty-link'),
            $siteName,
            $productName
        );

        $body = [
            sprintf(
                // Translators: %1$s product name, %2$s site name, %3$s site URL.
                __('A recent %1$s license status check on %2$s (%3$s) has encountered an error.', 'pretty-link'),
                $productName,
                $siteName,
                $siteUrl
            ),
            wp_strip_all_tags($error),
        ];

        if (!empty($accountUrl)) {
            // Translators: %s account dashboard URL.
            $body[] = sprintf(__('Visit Account Dashboard: %s', 'pretty-link'), $accountUrl);
        }

        wp_mail(get_option('admin_email'), $subject, implode("\n\n", $body));
    }

    /**
     * Checks and validates the license activation with the license server.
     *
     * Triggers actions if the active license is expired or invalidated.
     *
     * @return boolean false if the license activation could not be verified, true otherwise.
     */
    public function checkLicenseActivationStatus(): bool
    {
        if (!$this->plugin->getLicenseActivationStatus()) {
            return false;
        }

        $licenseKey       = $this->credentials->getLicenseKey();
        $activationDomain = $this->credentials->getActivationDomain();

        if (empty($licenseKey) || empty($activationDomain)) {
            return false;
        }

        $activation = $this->licenseActivations->retrieveLicenseActivation($licenseKey, $activationDomain);

        if ($activation->isError() && in_array($activation->statusCode, [401, 403, 404], true)) {
            do_action_deprecated(
                $this->plugin->pluginId . '_license_status_changed',
                [false, $activation],
                '4.0.0',
                'Use the {$pluginId}_active_license_invalidated or {$pluginId}_active_license_expired actions instead.'
            );

            if ('license-expired' === $activation->getErrorCode()) {
                /**
                 * Fires when an active license is detected as expired during a status check.
                 *
                 * @param Response $activation The response from the license activation retrieval API call.
                 */
                do_action($this->plugin->pluginId . '_active_license_expired', $activation);
            } else {
                /**
                 * Fires when an active license is detected as invalid during a status check.
                 *
                 * @param Response $activation The response from the license activation retrieval API call.
                 */
                do_action($this->plugin->pluginId . '_active_license_invalidated', $activation);
            }

            return false;
        }

        $this->syncActivationTransient();

        return true;
    }

    /**
     * Handles admin actions and notices.
     */
    public function controller(): void
    {
        $licenseButton = $this->plugin->pluginId . '_license_button';

        if (!isset($_POST[$licenseButton])) {
            return;
        }

        $action = sanitize_text_field(wp_unslash($_POST[$licenseButton]));

        if (!in_array($action, ['activate', 'deactivate', 'check_status'], true)) {
            return;
        }

        if ('activate' === $action) {
            $result = $this->handleActivation();
        } elseif ('deactivate' === $action) {
            $result = $this->handleDeactivation();
        } elseif ('check_status' === $action) {
            $result = $this->handleCheckStatus();
        }

        $this->renderNotice($result);
    }

    /**
     * Handles license activation with validation.
     *
     * @return Result
     */
    private function handleActivation(): Result
    {
        if (!current_user_can('manage_options')) {
            return Result::failure(__('Insufficient permissions', 'pretty-link'));
        }

        $nonce = sanitize_text_field(wp_unslash($_POST['_wpnonce'] ?? ''));

        if (empty($nonce) || !wp_verify_nonce($nonce, 'mothership_activate_license')) {
            return Result::failure(__('Invalid nonce', 'pretty-link'));
        }

        if (empty($_POST['license_key']) || empty($_POST['activation_domain'])) {
            return Result::failure(__('License key and domain are required', 'pretty-link'));
        }

        $licenseKey = sanitize_text_field(wp_unslash($_POST['license_key']));
        $domain     = sanitize_text_field(wp_unslash($_POST['activation_domain']));

        return $this->activateLicense($licenseKey, $domain);
    }

    /**
     * Handles license deactivation with validation.
     *
     * @return Result
     */
    private function handleDeactivation(): Result
    {
        if (!current_user_can('manage_options')) {
            return Result::failure(__('Insufficient permissions', 'pretty-link'));
        }

        $nonce = sanitize_text_field(wp_unslash($_POST['_wpnonce'] ?? ''));

        if (empty($nonce) || !wp_verify_nonce($nonce, 'mothership_deactivate_license')) {
            return Result::failure(__('Invalid nonce', 'pretty-link'));
        }

        if (empty($_POST['license_key']) || empty($_POST['activation_domain'])) {
            return Result::failure(__('License key and domain are required', 'pretty-link'));
        }

        $licenseKey = sanitize_text_field(wp_unslash($_POST['license_key']));
        $domain     = sanitize_text_field(wp_unslash($_POST['activation_domain']));

        return $this->deactivateLicense($licenseKey, $domain);
    }

    /**
     * Handles the on-demand license status check with validation.
     *
     * @return Result
     */
    private function handleCheckStatus(): Result
    {
        if (!current_user_can('manage_options')) {
            return Result::failure(__('Insufficient permissions', 'pretty-link'));
        }

        $nonce = sanitize_text_field(wp_unslash($_POST['_wpnonce'] ?? ''));

        if (empty($nonce) || !wp_verify_nonce($nonce, 'mothership_check_license_status')) {
            return Result::failure(__('Invalid nonce', 'pretty-link'));
        }

        if ($this->checkLicenseActivationStatus()) {
            return Result::success(__('License status verified successfully', 'pretty-link'));
        }

        return Result::failure(__('License could not be verified', 'pretty-link'));
    }

    /**
     * Renders an admin notice based on the result.
     *
     * @param Result $result The result to render.
     */
    private function renderNotice(Result $result): void
    {
        $message = $result->isSuccess()
            ? ($result->getMessage() ?: __('Operation completed successfully', 'pretty-link'))
            : ($result->getMessage() ?: __('An error occurred during the operation', 'pretty-link'));

        $type = $result->isSuccess() ? AdminNotices::SUCCESS : AdminNotices::ERROR;

        $this->adminNotices->flash('license_action_result', $message, $type);
    }

    /**
     * Returns the appropriate license form based on the current activation status.
     *
     * @return string The HTML for the form.
     */
    public function generateLicenseActivationForm(): string
    {
        if ($this->plugin->getLicenseActivationStatus()) {
            return $this->generateDisconnectForm();
        } else {
            return $this->generateActivationForm();
        }
    }

    /**
     * Generates the HTML for the activation form.
     *
     * @return string The HTML for the form.
     */
    public function generateActivationForm(): string
    {
        $licenseIsStored = $this->credentials->isCredentialSetInEnvironmentOrConstants(
            Credentials::LICENSE_KEY_BASENAME
        );

        return $this->view->render('license-form.php', [
            'pluginId'         => $this->plugin->pluginId,
            'licenseKey'       => $this->credentials->getLicenseKey(),
            'activationDomain' => $this->credentials->getActivationDomain(),
            'action'           => 'activate',
            'licenseIsStored'  => $licenseIsStored,
        ]);
    }

    /**
     * Generates the HTML for the disconnect form.
     *
     * @return string The HTML for the form.
     */
    public function generateDisconnectForm(): string
    {
        return $this->view->render('license-form.php', [
            'pluginId'         => $this->plugin->pluginId,
            'licenseKey'       => $this->credentials->getLicenseKey(),
            'activationDomain' => $this->credentials->getActivationDomain(),
            'action'           => 'deactivate',
            'licenseIsStored'  => false,
        ]);
    }

    /**
     * Generates the HTML for the on-demand license status check form.
     *
     * @return string The HTML for the form.
     */
    public function generateCheckStatusForm(): string
    {
        return $this->view->render('check-status-form.php', [
            'pluginId' => $this->plugin->pluginId,
        ]);
    }

    /**
     * Activates the license.
     *
     * @param  string  $licenseKey            The license key.
     * @param  string  $domain                The domain.
     * @param  boolean $installCorrectEdition Whether to check and install the correct product edition after activation.
     * @return Result The result indicating success or failure.
     */
    public function activateLicense(string $licenseKey, string $domain, bool $installCorrectEdition = false): Result
    {
        $activation = $this->licenseActivations->activate($this->plugin->productId, $licenseKey, $domain);

        if ($activation->isError()) {
            return Result::failure($activation->getErrorMessage());
        }

        $this->plugin->updateLicenseKey($licenseKey);
        $this->plugin->updateLicenseActivationStatus(true);
        $this->syncActivationTransient();

        /**
         * Fires after a license is successfully activated.
         *
         * @param Response $activation The response from the license activation API call.
         * @param string   $licenseKey The license key that was activated.
         * @param string   $domain     The domain the license was activated for.
         */
        do_action($this->plugin->pluginId . '_license_activated', $activation, $licenseKey, $domain);

        if ($installCorrectEdition) {
            $result = $this->maybeInstallCorrectEdition();
            if (false !== $result) {
                return $result;
            }
        }

        return Result::success(__('License activated successfully', 'pretty-link'));
    }

    /**
     * Deactivates the license.
     *
     * @param  string $licenseKey The license key.
     * @param  string $domain     The domain.
     * @return Result The result indicating success or failure.
     */
    public function deactivateLicense(string $licenseKey, string $domain): Result
    {
        $response = $this->licenseActivations->deactivate($licenseKey, $domain);

        if ($response->isError()) {
            return Result::failure($response->getErrorMessage());
        }

        $this->plugin->updateLicenseKey('');
        $this->plugin->updateLicenseActivationStatus(false);
        $this->addonsManager->clearCache();
        $this->activationTransient->delete();

        /**
         * Fires after a license is successfully deactivated.
         *
         * @param Response $response   The response from the license deactivation API call.
         * @param string   $licenseKey The license key that was deactivated.
         * @param string   $domain     The domain the license was deactivated for.
         */
        do_action($this->plugin->pluginId . '_license_deactivated', $response, $licenseKey, $domain);

        return Result::success(__('License deactivated successfully', 'pretty-link'));
    }

    /**
     * Retrieves the latest version for a product.
     *
     * This method is a replacement for {@see GroundLevel\Mothership\Api\Request\Products::getVersionLatest()}
     * which currently does not support filtering by type.
     *
     * @todo Use order_by=number in the query args once
     * {@see https://github.com/caseproof/licenses.caseproof.com/issues/589} is resolved.
     *
     * @param  string $slug The product slug.
     * @return \PrettyLinks\GroundLevel\Mothership\Api\Response The response from the API.
     */
    private function getVersionLatest(string $slug): Response
    {
        $args = [
            'order'    => 'desc',
            'order_by' => 'created_at',
            'per_page' => 1,
        ];

        if (!$this->plugin->allowPrereleaseVersions()) {
            $args['type'] = 'release';
        }

        $versions = $this->products->getVersions($slug, $args);

        if ($versions->isError()) {
            return $versions;
        }

        $versionList = $versions->getData('versions');
        if (empty($versionList)) {
            return new Response((object) ['message' => 'Not found'], 404);
        }

        // We are only interested in the first result (latest version).
        return new Response($versionList[0]);
    }

    /**
     * Fetches activation details from the API and saves them to {@see GroundLevel\Mothership\Transients\ActivationTransient}.
     */
    public function syncActivationTransient(): void
    {
        $licenseKey = $this->credentials->getLicenseKey();

        if (empty($licenseKey)) {
            return;
        }

        $response = $this->licenseActivations->retrieveLicenseActivationsMeta($licenseKey, [
            '_embed' => 'license,license.product',
        ]);

        if ($response->isError()) {
            return;
        }

        $licenseMeta = $response->data;
        if (is_null($licenseMeta)) {
            return;
        }

        $license = $response->getEmbed('license');
        if (is_null($license)) {
            return;
        }

        $product = $response->getEmbed('license.product');
        if (is_null($product)) {
            return;
        }

        // License activation counts.
        $this->activationTransient->prodActivationsAllowed = (int) ($licenseMeta->prod->allowed ?? 0);
        $this->activationTransient->prodActivationsUsed    = (int) ($licenseMeta->prod->used ?? 0);
        $this->activationTransient->prodActivationsFree    = (int) ($licenseMeta->prod->free ?? 0);
        $this->activationTransient->testActivationsAllowed = (int) ($licenseMeta->test->allowed ?? 0);
        $this->activationTransient->testActivationsUsed    = (int) ($licenseMeta->test->used ?? 0);
        $this->activationTransient->testActivationsFree    = (int) ($licenseMeta->test->free ?? 0);

        // Product data.
        $this->activationTransient->productSlug = $product->slug;
        $this->activationTransient->productName = $product->name ?? '';

        // License data.
        $this->activationTransient->licenseKey       = $licenseKey;
        $this->activationTransient->licenseStatus    = $license->status ?? '';
        $this->activationTransient->licenseExpiresAt = $license->expires_at ?? ''; // phpcs:ignore Squiz.NamingConventions.ValidVariableName.MemberNotCamelCaps

        if (!empty($product->slug)) {
            $version = $this->getVersionLatest($product->slug);

            if (!$version->isError()) {
                // Version data.
                $this->activationTransient->downloadUrl   = $version->getData('url', '');
                $this->activationTransient->versionNumber = $version->getData('number', '');
            }
        }

        $this->activationTransient->save();
    }

    /**
     * Retrieves the product and its sibling editions, keyed by slug.
     *
     * @param  string $slug The product slug.
     * @return array<string, object> Products keyed by slug.
     */
    public function getEditions(string $slug): array
    {
        // This could be reduced to one API request if the relations endpoint supports
        // embedding "self".
        $product  = $this->products->get($slug);
        $siblings = $this->products->getRelations($slug, ['type' => 'sibling']);

        if ($product->isError() || $siblings->isError()) {
            return [];
        }

        $productData     = $product->data;
        $siblingProducts = $siblings->getData('products', []);
        if (null === $productData) {
            return [];
        }

        $editions                     = [];
        $editions[$productData->slug] = $productData;
        foreach ($siblingProducts as $sibling) {
            $editions[$sibling->slug] = $sibling;
        }

        return $editions;
    }

    /**
     * Checks whether the license entitles a higher-priority edition than the one currently installed.
     *
     * @return array{installed: object, license: object}|false false if the installed edition is correct, otherwise an array of both editions.
     */
    public function isIncorrectEditionInstalled()
    {
        $editions         = $this->getEditions($this->plugin->productId);
        $installedEdition = $editions[$this->plugin->productId] ?? null;
        $licenseEdition   = $editions[$this->activationTransient->productSlug] ?? null;

        // Not enough data to compare.
        if (is_null($installedEdition) || is_null($licenseEdition)) {
            return false;
        }

        // Same edition.
        if ($installedEdition->slug === $licenseEdition->slug) {
            return false;
        }

        if ($licenseEdition->priority > $installedEdition->priority) {
            return [
                'installed' => $installedEdition,
                'license'   => $licenseEdition,
            ];
        }

        return false;
    }

    /**
     * Silently installs a plugin from a download URL.
     *
     * @param  string $downloadUrl The URL to download the plugin from.
     * @return Result The result indicating success or failure.
     */
    public function installPluginSilently(string $downloadUrl): Result
    {
        if (!current_user_can('update_plugins')) {
            return Result::failure(__('Insufficient permissions to install plugin', 'pretty-link'));
        }

        if (!filter_var($downloadUrl, FILTER_VALIDATE_URL) || !str_starts_with($downloadUrl, 'https://')) {
            return Result::failure(__('Invalid download URL', 'pretty-link'));
        }

        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

        $skin     = new \Automatic_Upgrader_Skin();
        $upgrader = new \Plugin_Upgrader($skin);

        $result = $upgrader->install($downloadUrl, ['overwrite_package' => true]);

        if (is_wp_error($result)) {
            return Result::failure($result->get_error_message());
        }

        if (false === $result) {
            return Result::failure(__('Plugin installation failed', 'pretty-link'));
        }

        return Result::success(__('Plugin installed successfully', 'pretty-link'));
    }

    /**
     * Checks if the edition associated with the activated license is different from the currently installed edition,
     * and if so, attempts to silently install the correct edition based on the license.
     *
     * @return Result|false The installation result, or false if no edition change was needed.
     */
    protected function maybeInstallCorrectEdition()
    {
        $editions = $this->isIncorrectEditionInstalled();

        if (false === $editions) {
            return false;
        }

        $downloadUrl = $this->activationTransient->downloadUrl;

        if (empty($downloadUrl)) {
            return false;
        }

        $result = $this->installPluginSilently($downloadUrl);

        if ($result->isSuccess()) {
            /**
             * Fires when the currently installed edition is changed.
             *
             * @param array $editions {
             *     @type  object $installed The previously installed edition.
             *     @type  object $license   The edition associated with the activated license.
             * }
             */
            do_action(
                $this->plugin->pluginId . '_edition_changed',
                $editions
            );

            return Result::success(
                __('License activated and plugin updated successfully', 'pretty-link'),
                $editions
            );
        }

        return false;
    }
}
