<?php

declare(strict_types=1);

namespace PrettyLinks\Onboarding;

defined('ABSPATH') || exit;

// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
// phpcs:disable WordPress.Security.ValidatedSanitizedInput.MissingUnslash
// phpcs:disable WordPress.Security.NonceVerification.Recommended
// phpcs:disable WordPress.Security.NonceVerification.Missing
// $_SERVER values (REMOTE_ADDR, HTTP_USER_AGENT, REQUEST_URI, etc.) are read for
// click tracking / targeting / UI rendering, not form-submission input. State-changing
// operations in this class protect with wp_verify_nonce / check_admin_referer
// (see handlePost(), which verifies NONCE_ACTION before dispatching to the
// applyXxx() helpers that read $_POST).
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_key
// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_value
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter
// Custom plugin tables (prli_*): table names interpolated from $wpdb->prefix (trusted),
// user values bind through $wpdb->prepare(). No caching: these tables are the source
// of truth for click/redirect data and must read-through. "meta_key"/"meta_value" here
// refer to our own prli_link_metas table, not wp_postmeta.
use PrettyLinks\Admin\Page;
use PrettyLinks\Admin\Pages\Onboarding as OnboardingPage;
use PrettyLinks\Admin\Upsell\ProUpsell;
use PrettyLinks\Licensing\AuthClient;
use PrettyLinks\Licensing\LicenseManager;
use PrettyLinks\Licensing\PlanCatalog;
use PrettyLinks\Licensing\ProState;
use PrettyLinks\Options\Store as OptionsStore;
use PrettyLinks\Repositories\Links as LinksRepo;
use PrettyLinks\Stripe\Connect as StripeConnect;

/**
 * Pretty Links onboarding wizard. Step order mirrors 3.x
 * (PrliOnboardingController::route) plus a 4.0-only Pay Links step.
 *
 *   0 (pre-step) Welcome splash — no `step` in the URL
 *   1 License      (activate a key)
 *   2 Features     (lite toggles + pro toggles + addon installs)
 *   3 Pretty Link  (create the first link)
 *   4 Category     (create or pick; attach to the first link — Pro)
 *   5 Pay Links    (optional Stripe Connect; platform fee notice is filterable)
 *   6 Finish       (summary of what was set up)
 *   7 Complete     (done — redirect to dashboard)
 *
 * State persists in the `prli_onboarding` option (array). Completion writes:
 *   - `prli_onboarding_done`              (v4)
 *   - `prli_onboarding_complete = '1'`    (v3 consumers)
 *   - `prli_options.activation_complete`  (v3 upgrader check)
 */
class Wizard
{
    public const OPTION_STATE             = 'prli_onboarding';
    public const OPTION_DONE              = 'prli_onboarding_done';
    public const OPTION_LEGACY_COMPLETE   = 'prli_onboarding_complete';
    public const OPTION_LEGACY_ONBOARDED  = 'prli_onboarded';
    public const OPTION_INSTALLED_MI      = 'pretty_links_installed_monsterinsights';
    public const NOTICE_DISMISS_TRANSIENT = 'prli_dismiss_notice_continue_onboarding';
    public const NONCE_ACTION             = 'prli-onboarding';

    public const TOTAL_STEPS = 7;

    public const STEP_WELCOME  = 0;
    public const STEP_LICENSE  = 1;
    public const STEP_FEATURES = 2;
    public const STEP_LINK     = 3;
    public const STEP_CATEGORY = 4;
    public const STEP_PAY      = 5;
    public const STEP_FINISH   = 6;
    public const STEP_COMPLETE = 7;

    /**
     * Render the onboarding wizard for the current step.
     *
     * @return void
     */
    public function render(): void
    {
        if (!current_user_can(Page::capability())) {
            wp_die(esc_html__('You do not have permission to access this page.', 'pretty-link'));
        }

        $step  = $this->currentStep();
        $state = self::currentState();

        echo '<div class="wrap prli-onboarding-wrap">';
        $this->renderHeader();

        if ($step === self::STEP_WELCOME) {
            $this->renderWelcome();
            echo '</div>';
            return;
        }

        $this->renderStepNav($step);
        echo '<div class="prli-onboarding-step">';
        switch ($step) {
            case self::STEP_LICENSE:
                $this->renderLicense($state);
                break;
            case self::STEP_FEATURES:
                $this->renderFeatures($state);
                break;
            case self::STEP_LINK:
                $this->renderFirstLink($state);
                break;
            case self::STEP_CATEGORY:
                $this->renderCategory($state);
                break;
            case self::STEP_PAY:
                $this->renderPayLinks($state);
                break;
            case self::STEP_FINISH:
                $this->renderFinish($state);
                break;
            case self::STEP_COMPLETE:
                $this->renderComplete();
                break;
        }
        echo '</div>';
        echo '</div>';
    }

    /**
     * Early request handler (admin_init). Runs before admin-header.php writes
     * output, so wp_safe_redirect() / exit work for:
     *   - dismissing the resume notice
     *   - rebounding post-complete GETs
     *   - handling form submissions
     *   - clamping URL step-jumping (v3 validate_step)
     *   - stamping the first-entry marker (v3 prli_onboarded)
     */
    public static function handleRequest(): void
    {
        if (!is_admin() || wp_doing_ajax() || wp_doing_cron()) {
            return;
        }

        $page = isset($_GET['page']) ? sanitize_key((string) wp_unslash($_GET['page'])) : '';
        if ($page !== OnboardingPage::SLUG) {
            return;
        }

        if (!current_user_can(Page::capability())) {
            return;
        }

        $isPost = (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST');

        // Once complete, GET to the wizard rebounds to the dashboard.
        if (!$isPost && !self::shouldRun()) {
            wp_safe_redirect(admin_url('admin.php?page=' . Page::SLUG));
            exit;
        }

        if (!get_option(self::OPTION_LEGACY_ONBOARDED)) {
            update_option(self::OPTION_LEGACY_ONBOARDED, '1', false);
        }

        if ($isPost) {
            self::handlePost();
            return;
        }

        // Resume from checkout: when the user returns with ?resume=1 and
        // has an active Pro license, they can jump straight to the Finish
        // step so the queue drain renders. Bumps step_completed to allow
        // clampStepAccess through.
        if (!empty($_GET['resume']) && ProState::isProInstalledAndActivated()) {
            $state     = self::currentState();
            $completed = (int) ($state['step_completed'] ?? 0);
            if ($completed < self::STEP_FINISH - 1) {
                $state['step_completed'] = self::STEP_FINISH - 1;
                update_option(self::OPTION_STATE, $state, false);
            }
        }

        self::clampStepAccess();
    }

    /**
     * Handle a wizard form submission: verify the nonce, persist step state,
     * and redirect to the next step.
     *
     * @return void
     */
    private static function handlePost(): void
    {
        if (!isset($_POST['_prli_nonce']) || !is_string($_POST['_prli_nonce'])) {
            return;
        }
        $nonce = sanitize_text_field(wp_unslash((string) $_POST['_prli_nonce']));
        if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) {
            return;
        }

        $step   = self::requestedStep();
        $action = isset($_POST['prli_action']) ? sanitize_key(wp_unslash((string) $_POST['prli_action'])) : '';
        $state  = self::currentState();

        switch ($step) {
            case self::STEP_WELCOME:
                // POST from the welcome splash just advances to the license step.
                break;
            case self::STEP_LICENSE:
                $key = isset($_POST['prli_license_key']) ? sanitize_text_field(wp_unslash((string) $_POST['prli_license_key'])) : '';
                if ($action !== 'skip' && $key !== '') {
                    $result           = (new LicenseManager())->activate($key);
                    $state['license'] = [
                        'attempted' => true,
                        'activated' => empty($result['error']),
                        'message'   => isset($result['error']) ? (string) $result['error'] : '',
                    ];
                }
                break;
            case self::STEP_FEATURES:
                $state['features'] = self::applyFeatureSelections();
                break;
            case self::STEP_LINK:
                if ($action === 'create') {
                    $url  = isset($_POST['prli_target_url']) ? esc_url_raw(wp_unslash((string) $_POST['prli_target_url'])) : '';
                    $name = isset($_POST['prli_link_name']) ? sanitize_text_field(wp_unslash((string) $_POST['prli_link_name'])) : '';
                    if ($url !== '') {
                        $link = (new LinksRepo())->create([
                            'url'  => $url,
                            'name' => $name,
                        ]);
                        if (is_array($link) && !empty($link['id'])) {
                            $state['first_link_id'] = (int) $link['id'];
                        }
                    }
                }
                break;
            case self::STEP_CATEGORY:
                // Categories require a plugin that registers the
                // `prli_category_create` filter (Pretty Links Pro does).
                // On installs without that support the step is a no-op.
                if (!self::hasCategorySupport()) {
                    break;
                }
                $linkId = (int) ($state['first_link_id'] ?? 0);
                if ($action === 'create' && $linkId > 0) {
                    $name = isset($_POST['prli_category_name']) ? sanitize_text_field(wp_unslash((string) $_POST['prli_category_name'])) : '';
                    if ($name !== '') {
                        /**
                         * Filter: prli_category_create
                         *
                         * Pro listens and creates the category, returning
                         * its hydrated row. Free's no-op returns null so
                         * the onboarding step quietly skips category
                         * attachment when pro is absent.
                         *
                         * @param array<string, mixed>|null $default
                         * @param array{name: string} $data
                         */
                        $cat = apply_filters('prli_category_create', null, ['name' => $name]);
                        if (is_array($cat) && !empty($cat['id'])) {
                            self::attachCategory($linkId, (int) $cat['id']);
                            $state['category_id'] = (int) $cat['id'];
                        }
                    }
                } elseif ($action === 'pick' && $linkId > 0) {
                    $catId = isset($_POST['prli_category_id']) ? (int) $_POST['prli_category_id'] : 0;
                    if ($catId > 0) {
                        self::attachCategory($linkId, $catId);
                        $state['category_id'] = $catId;
                    }
                }
                break;
            case self::STEP_PAY:
                // Pay Links is informational + external OAuth — nothing to
                // persist here; Stripe connection state is owned by the
                // existing ConnectAjax endpoints.
                break;
            case self::STEP_FINISH:
                // Summary step — just advance.
                break;
        }

        $state['step_completed'] = max((int) ($state['step_completed'] ?? 0), $step);
        update_option(self::OPTION_STATE, $state, false);

        if ($step === self::STEP_COMPLETE) {
            self::markComplete();
            wp_safe_redirect(admin_url('admin.php?page=' . Page::SLUG));
            exit;
        }

        $next = min(self::TOTAL_STEPS, $step + 1);
        // Skip past the Category step when there's nothing to categorize:
        // either the Link step produced no link (user skipped or URL was empty),
        // or Pro isn't installed so the category feature is unavailable.
        $skipCategory = ($step === self::STEP_LINK)
            && (empty($state['first_link_id']) || !ProState::isProInstalled());
        if ($skipCategory) {
            $next = self::STEP_PAY;
            // Mark Category completed so Back/step-nav still behave sanely.
            $state['step_completed'] = max((int) ($state['step_completed'] ?? 0), self::STEP_CATEGORY);
            update_option(self::OPTION_STATE, $state, false);
        }
        wp_safe_redirect(add_query_arg(
            [
                'page' => OnboardingPage::SLUG,
                'step' => $next,
            ],
            admin_url('admin.php')
        ));
        exit;
    }

    /**
     * Clamp URL step-jumping so users can only reach steps they've unlocked.
     *
     * @return void
     */
    private static function clampStepAccess(): void
    {
        $requested = self::requestedStep();
        if ($requested === self::STEP_WELCOME) {
            return;
        }
        // Category step is Pro-only — bounce Lite users straight to Pay.
        if ($requested === self::STEP_CATEGORY && !ProState::isProInstalled()) {
            wp_safe_redirect(add_query_arg(
                [
                    'page' => OnboardingPage::SLUG,
                    'step' => self::STEP_PAY,
                ],
                admin_url('admin.php')
            ));
            exit;
        }
        $state      = self::currentState();
        $completed  = (int) ($state['step_completed'] ?? 0);
        $maxAllowed = max(1, $completed + 1);
        if ($requested > $maxAllowed) {
            wp_safe_redirect(add_query_arg(
                [
                    'page' => OnboardingPage::SLUG,
                    'step' => $maxAllowed,
                ],
                admin_url('admin.php')
            ));
            exit;
        }
    }

    /**
     * Mark onboarding as complete, writing both v4 and v3 completion flags.
     *
     * @return void
     */
    private static function markComplete(): void
    {
        update_option(self::OPTION_DONE, true, false);
        update_option(self::OPTION_LEGACY_COMPLETE, '1', false);

        $options = get_option('prli_options');
        if (is_array($options)) {
            $options['activation_complete'] = true;
            update_option('prli_options', $options);
        }
    }

    /**
     * Resolve the requested step from the URL, clamped to the valid range.
     *
     * @return integer The requested step index.
     */
    private static function requestedStep(): int
    {
        if (!isset($_GET['step'])) {
            return self::STEP_WELCOME;
        }
        $step = (int) $_GET['step'];
        if ($step < 0) {
            return self::STEP_WELCOME;
        }
        if ($step > self::TOTAL_STEPS) {
            $step = self::TOTAL_STEPS;
        }
        return $step;
    }

    /**
     * Read the persisted onboarding state array.
     *
     * @return array<string, mixed>
     */
    private static function currentState(): array
    {
        $raw = get_option(self::OPTION_STATE, []);
        return is_array($raw) ? $raw : [];
    }

    /**
     * Get the step currently being rendered.
     *
     * @return integer The current step index.
     */
    private function currentStep(): int
    {
        return self::requestedStep();
    }

    /**
     * Enqueue onboarding styles. Registered on admin_enqueue_scripts so the
     * stylesheet lands in `<head>` — calling wp_enqueue_style() from inside
     * the page callback (after admin-header.php has already been printed)
     * is the classic cause of the flash of unstyled content readers see on
     * the wizard otherwise.
     *
     * @param string $hookSuffix The current admin page hook suffix.
     *
     * @return void
     */
    public static function enqueueAssets(string $hookSuffix = ''): void
    {
        $screen   = function_exists('get_current_screen') ? get_current_screen() : null;
        $screenId = $screen && is_string($screen->id) ? $screen->id : '';
        if (
            strpos($screenId, OnboardingPage::SLUG) === false
            && strpos((string) $hookSuffix, OnboardingPage::SLUG) === false
        ) {
            return;
        }
        $basePath = Page::getContainer()->get('BASE_PATH');
        $baseUrl  = Page::getContainer()->get('BASE_URL');
        $rel      = 'assets/css/onboarding.css';
        if (is_file($basePath . $rel)) {
            wp_enqueue_style('prli-onboarding', $baseUrl . $rel, [], \PrettyLinks\Bootstrap::version());
        }
    }

    /**
     * Render the wizard header with the Pretty Links logo.
     *
     * @return void
     */
    private function renderHeader(): void
    {
        $logo = esc_url(Page::getContainer()->get('BASE_URL') . 'assets/images/logo-horizontal.svg');
        echo '<div class="prli-onboarding-header">';
        echo '<img src="' . esc_attr($logo) . '" alt="' . esc_attr__('Pretty Links', 'pretty-link') . '" class="prli-onboarding-logo" />';
        echo '</div>';
    }

    /**
     * Render the step navigation bar.
     *
     * @param integer $current The current step index.
     *
     * @return void
     */
    private function renderStepNav(int $current): void
    {
        $labels = [
            self::STEP_LICENSE  => __('License', 'pretty-link'),
            self::STEP_FEATURES => __('Features', 'pretty-link'),
            self::STEP_LINK     => __('Pretty Link', 'pretty-link'),
            self::STEP_CATEGORY => __('Category', 'pretty-link'),
            self::STEP_PAY      => __('PrettyPay™', 'pretty-link'),
            self::STEP_FINISH   => __('Finish', 'pretty-link'),
            self::STEP_COMPLETE => __('Complete', 'pretty-link'),
        ];

        // Lite installs don't have category support — drop the step from
        // the nav entirely so users aren't shown a feature they can't use.
        if (!ProState::isProInstalled()) {
            unset($labels[self::STEP_CATEGORY]);
        }

        $state      = self::currentState();
        $completed  = (int) ($state['step_completed'] ?? 0);
        $maxAllowed = max(1, $completed + 1);

        echo '<ol class="prli-onboarding-steps">';
        foreach ($labels as $n => $label) {
            $class = 'prli-onboarding-step-item';
            if ($n === $current) {
                $class .= ' is-current';
            } elseif ($n < $current) {
                $class .= ' is-complete';
            }

            // Clickable if the step is reachable via clampStepAccess().
            $isClickable = ($n !== $current && $n <= $maxAllowed);
            if ($isClickable) {
                $url = admin_url('admin.php?page=' . OnboardingPage::SLUG . '&step=' . $n);
                echo '<li class="' . esc_attr($class) . '"><a href="' . esc_url($url) . '" class="prli-onboarding-step-link">';
            } else {
                echo '<li class="' . esc_attr($class) . '">';
            }
            echo '<span class="prli-step-num">' . esc_html((string) $n) . '</span><span class="prli-step-label">' . esc_html($label) . '</span>';
            echo $isClickable ? '</a></li>' : '</li>';
        }
        echo '</ol>';
    }

    /**
     * Back link for a step. Rendered inside the custom-actions rows that
     * don't use renderFooter(). Points at the previous step (License goes
     * back to the welcome splash).
     */
    private function renderInlineBack(): void
    {
        $current = $this->currentStep();
        $prev    = max(self::STEP_WELCOME, $current - 1);
        // Category is hidden on Lite — jump back over it from Pay.
        if ($prev === self::STEP_CATEGORY && !ProState::isProInstalled()) {
            $prev = self::STEP_LINK;
        }
        $prevUrl = ($prev === self::STEP_WELCOME)
            ? admin_url('admin.php?page=' . OnboardingPage::SLUG)
            : admin_url('admin.php?page=' . OnboardingPage::SLUG . '&step=' . $prev);
        echo '<a href="' . esc_url($prevUrl) . '" class="button button-link prli-onboarding-back">' . esc_html__('Back', 'pretty-link') . '</a> ';
    }

    /**
     * Render the welcome splash step.
     *
     * @return void
     */
    private function renderWelcome(): void
    {
        $startUrl = admin_url('admin.php?page=' . OnboardingPage::SLUG . '&step=' . self::STEP_LICENSE);
        // Escape hatch so the welcome step matches the later steps, which
        // all offer a skip control. Leaves onboarding resumable via the
        // "continue onboarding" notice rather than marking it complete.
        $skipUrl = admin_url('admin.php?page=' . Page::SLUG);
        require __DIR__ . '/views/welcome.php';
    }

    /**
     * Render the License step.
     *
     * @param array<string, mixed> $state The current onboarding state.
     *
     * @return void
     */
    private function renderLicense(array $state): void
    {
        $license = (new LicenseManager())->currentLicense();
        $last    = is_array($state['license'] ?? null) ? $state['license'] : [];
        require __DIR__ . '/views/license.php';
    }

    /**
     * Render the Features step.
     *
     * @param array<string, mixed> $state The current onboarding state.
     *
     * @return void
     */
    private function renderFeatures(array $state): void
    {
        $opts      = self::currentOptionSnapshot();
        $last      = is_array($state['features'] ?? null) ? $state['features'] : [];
        $miInstall = (string) ($last['mi_install'] ?? '');
        $miActive  = self::isMonsterInsightsActive();
        require __DIR__ . '/views/features.php';
    }

    /**
     * Render the first-link step.
     *
     * @param array<string, mixed> $state The current onboarding state.
     *
     * @return void
     */
    private function renderFirstLink(array $state): void
    {
        $existingId = (int) ($state['first_link_id'] ?? 0);
        $link       = $existingId > 0 ? (new LinksRepo())->find($existingId) : null;
        require __DIR__ . '/views/first-link.php';
    }

    /**
     * Render the Category step.
     *
     * @param array<string, mixed> $state The current onboarding state.
     *
     * @return void
     */
    private function renderCategory(array $state): void
    {
        $hasSupport = self::hasCategorySupport();
        $linkId     = (int) ($state['first_link_id'] ?? 0);
        $categories = $hasSupport ? (array) apply_filters('prli_categories_all', []) : [];
        $currentCat = (int) ($state['category_id'] ?? 0);

        $currentCatRow = null;
        if ($currentCat > 0) {
            foreach ($categories as $c) {
                if ((int) $c['id'] === $currentCat) {
                    $currentCatRow = $c;
                    break;
                }
            }
        }

        require __DIR__ . '/views/category.php';
    }

    /**
     * Pay Links step — optional Stripe connection.
     *
     * @param array<string, mixed> $state The current onboarding state.
     *
     * @return void
     */
    private function renderPayLinks(array $state): void
    {
        unset($state);

        $isConnected = (StripeConnect::status() === 'connected');
        $isEnrolled  = ((string) get_option(AuthClient::OPTION_SITE_UUID, '') !== '');
        // When the site is already enrolled with the Caseproof auth service
        // we can hand off directly to the signed Stripe Connect URL. When not,
        // we build an auth-enrollment URL that chains into Stripe Connect
        // automatically on return (stripe_connect=true&method_id=…), so the
        // user presses one button and doesn't end up stranded mid-flow.
        $connectUrl  = $isEnrolled
            ? StripeConnect::connectUrl(StripeConnect::METHOD_ID, ['from' => 'onboarding'])
            : self::enrollAndConnectStripeUrl();
        $accountName = $isConnected ? StripeConnect::accountName() : '';

        require __DIR__ . '/views/paylinks.php';
    }

    /**
     * Build a one-click "enroll with Caseproof auth, then connect Stripe"
     * URL. Used by the Pay Links step when the site isn't already enrolled.
     * Mirrors the v3 chain (PrliAuthenticatorController + PrliStripeConnect).
     */
    private static function enrollAndConnectStripeUrl(): string
    {
        $returnUrl = add_query_arg(
            [
                'page'           => OnboardingPage::SLUG,
                'step'           => self::STEP_PAY,
                'stripe_connect' => 'true',
                'method_id'      => StripeConnect::METHOD_ID,
                'from'           => 'onboarding',
            ],
            admin_url('admin.php')
        );
        return (new AuthClient())->connectUrl($returnUrl);
    }

    /**
     * Render the Finish summary step.
     *
     * @param array<string, mixed> $state The current onboarding state.
     *
     * @return void
     */
    private function renderFinish(array $state): void
    {
        $linkId = (int) ($state['first_link_id'] ?? 0);
        $link   = $linkId > 0 ? (new LinksRepo())->find($linkId) : null;
        $link   = is_array($link) ? $link : null;

        $catId       = (int) ($state['category_id'] ?? 0);
        $categoryRow = null;
        if ($catId > 0) {
            foreach ((array) apply_filters('prli_categories_all', []) as $c) {
                if ((int) $c['id'] === $catId) {
                    $categoryRow = $c;
                    break;
                }
            }
        }

        $licenseActivated  = !empty((new LicenseManager())->currentLicense()['activated']);
        $stripeConnected   = (StripeConnect::status() === 'connected');
        $stripeAccountName = $stripeConnected ? StripeConnect::accountName() : '';

        $features             = is_array($state['features'] ?? null) ? $state['features'] : [];
        $enabledFeatureLabels = is_array($features['enabled'] ?? null) ? $features['enabled'] : [];

        // Resume flow — if the user queued Pro features/add-ons earlier
        // and has since activated a license, drain the queue before
        // rendering the finish screen so the summary reflects the
        // settled state. Otherwise compute the tier-appropriate upgrade
        // CTA so the user can go buy the license.
        $drainOutcome = null;
        $upgradeCta   = null;
        $queue        = self::currentQueue();
        $hasQueue     = !empty($queue['features']) || !empty($queue['addons']);

        if ($hasQueue && ProState::isProInstalledAndActivated()) {
            $drainOutcome = self::drainQueue();
            // Re-read queue so the view knows whether anything is still pending.
            $queue = self::currentQueue();
        }

        if (!empty($queue['features']) || !empty($queue['addons'])) {
            $plan = ProUpsell::requiredPlanFor($queue['features'], $queue['addons']);
            if ($plan !== null) {
                $returnUrl  = admin_url(add_query_arg(
                    [
                        'page'       => OnboardingPage::SLUG,
                        'step'       => self::STEP_FINISH,
                        'onboarding' => '1',
                        'resume'     => '1',
                    ],
                    'admin.php'
                ));
                $upgradeCta = ProUpsell::planCta($plan, $returnUrl);
            }
        }

        require __DIR__ . '/views/finish.php';
    }

    /**
     * Render the Complete step.
     *
     * @return void
     */
    private function renderComplete(): void
    {
        require __DIR__ . '/views/complete.php';
    }

    /**
     * Open the wizard form and emit the nonce field.
     *
     * @return void
     */
    private function openForm(): void
    {
        $url = admin_url('admin.php?page=' . OnboardingPage::SLUG . '&step=' . $this->currentStep());
        echo '<form method="post" action="' . esc_url($url) . '" class="prli-onboarding-form">';
        wp_nonce_field(self::NONCE_ACTION, '_prli_nonce');
    }

    /**
     * Close the wizard form.
     *
     * @return void
     */
    private function closeForm(): void
    {
        echo '</form>';
    }

    /**
     * Render the wizard footer with the submit button and optional Back link.
     *
     * @param string  $primaryLabel The label for the primary submit button.
     * @param boolean $showBack     Whether to render the Back link.
     *
     * @return void
     */
    private function renderFooter(string $primaryLabel, bool $showBack): void
    {
        echo '<div class="prli-onboarding-footer">';
        if ($showBack) {
            $prev    = max(self::STEP_LICENSE, $this->currentStep() - 1);
            $prevUrl = admin_url('admin.php?page=' . OnboardingPage::SLUG . '&step=' . $prev);
            echo '<a href="' . esc_url($prevUrl) . '" class="button button-link prli-onboarding-back">' . esc_html__('Back', 'pretty-link') . '</a>';
        }
        echo '<button type="submit" class="button button-primary">' . esc_html($primaryLabel) . '</button>';
        echo '</div>';
    }

    /**
     * Render one checkbox item in the Features step.
     *
     * @param string  $name        The checkbox input name attribute.
     * @param string  $label       The feature label text.
     * @param string  $description The feature description text.
     * @param string  $tooltip     Deeper "what this feature does and why it matters"
     *                             copy — surfaced via the (i) info bubble next to
     *                             the feature name.
     * @param boolean $checked     Whether the checkbox is checked.
     * @param boolean $enabled     Whether the checkbox is enabled (selectable).
     * @param boolean $alreadyOn   Whether the feature is already active (renders checked + disabled).
     * @param string  $badgeLabel  Optional badge label shown next to the feature name.
     * @param boolean $recommended Adds a green "Recommended" badge next to the
     *                             feature name.
     *
     * @return void
     */
    public static function renderFeatureItem(
        string $name,
        string $label,
        string $description,
        string $tooltip,
        bool $checked,
        bool $enabled,
        bool $alreadyOn = false,
        string $badgeLabel = '',
        bool $recommended = false
    ): void {
        $classes = 'prli-onboarding-feature';
        if (!$enabled && !$alreadyOn) {
            $classes .= ' is-disabled';
        }
        echo '<li class="' . esc_attr($classes) . '">';
        echo '<label>';
        if ($alreadyOn) {
            echo '<input type="checkbox" checked disabled />';
        } else {
            printf(
                '<input type="checkbox" name="%s" value="1"%s%s />',
                esc_attr($name),
                $checked ? ' checked' : '',
                $enabled ? '' : ' disabled'
            );
        }
        echo '<span class="prli-onboarding-feature-label">' . esc_html($label);
        if ($recommended) {
            echo ' <span class="prli-onboarding-feature-badge is-recommended">' . esc_html__('Recommended', 'pretty-link') . '</span>';
        }
        if ($badgeLabel !== '') {
            echo ' <span class="prli-onboarding-feature-badge">' . esc_html($badgeLabel) . '</span>';
        }
        if ($tooltip !== '') {
            echo ' <span class="prli-tooltip" tabindex="0" role="button" aria-label="' . esc_attr__('More information', 'pretty-link') . '">';
            echo '<span class="prli-tooltip__icon" aria-hidden="true">i</span>';
            echo '<span class="prli-tooltip__bubble" role="tooltip">' . esc_html($tooltip) . '</span>';
            echo '</span>';
        }
        echo '</span>';
        echo '<span class="prli-onboarding-feature-desc">' . esc_html($description) . '</span>';
        echo '</label>';
        echo '</li>';
    }

    /**
     * Persist Features-step selections. Returns a structured record for the
     * wizard state (labels of enabled features + install outcomes for addons).
     *
     * @return array<string, mixed>
     */
    private static function applyFeatureSelections(): array
    {
        $store = new OptionsStore();

        // Lite toggles.
        $store->set('link_track_me', !empty($_POST['prli_feature_link_track_me']));
        $store->set('link_nofollow', !empty($_POST['prli_feature_link_nofollow']));
        $store->set('link_sponsored', !empty($_POST['prli_feature_link_sponsored']));

        $enabled = [];
        if (!empty($_POST['prli_feature_link_track_me'])) {
            $enabled[] = __('Link Tracking', 'pretty-link');
        }
        if (!empty($_POST['prli_feature_link_nofollow'])) {
            $enabled[] = __('No Follow', 'pretty-link');
        }
        if (!empty($_POST['prli_feature_link_sponsored'])) {
            $enabled[] = __('Sponsored', 'pretty-link');
        }

        /**
         * Filter: prli_onboarding_feature_labels
         *
         * Fires during the onboarding wizard's Features-step save. Plugins
         * hooking this read their own fields out of `$_POST`, persist any
         * plugin-specific options, and return a list of human-readable
         * labels for the features they enabled — those labels get appended
         * to the wizard's enabled-features summary.
         *
         * @param string[]             $labels Default empty list.
         * @param array<string, mixed> $post   Reference copy of `$_POST`.
         */
        $extraLabels = (array) apply_filters('prli_onboarding_feature_labels', [], (array) $_POST);
        foreach ($extraLabels as $label) {
            if (is_string($label) && $label !== '') {
                $enabled[] = $label;
            }
        }

        $record = [
            'enabled' => $enabled,
        ];

        if (!empty($_POST['prli_addon_monsterinsights']) && !self::isMonsterInsightsActive()) {
            $record['mi_install'] = self::installMonsterInsights();
        }
        /**
         * Filter: prli_onboarding_feature_save_record
         *
         * Fires after Lite has persisted its own feature selections and
         * built the base record. Plugins read their own fields out of
         * `$_POST` (second argument), run any install/provisioning work,
         * and return merged record state — e.g. the Pro add-on installer
         * appends `pd_install` here.
         *
         * @param array<string, mixed> $record Current record.
         * @param array<string, mixed> $post   Reference copy of `$_POST`.
         */
        $record = (array) apply_filters('prli_onboarding_feature_save_record', $record, (array) $_POST);

        // Queue any Pro-gated features/add-ons the user checked. We persist
        // in user meta (matches v3 key names for upgrade continuity) so the
        // wizard can drain the queue after the user returns from checkout
        // with an active license.
        $userId = get_current_user_id();
        if ($userId > 0) {
            $queuedFeatures = ProUpsell::collectQueuedFeatures((array) $_POST);
            $queuedAddons   = ProUpsell::collectQueuedAddons((array) $_POST);

            if (!empty($queuedFeatures) || get_user_meta($userId, ProUpsell::META_FEATURES_NOT_ENABLED, true) !== '') {
                update_user_meta($userId, ProUpsell::META_FEATURES_NOT_ENABLED, array_values($queuedFeatures));
            }
            if (!empty($queuedAddons) || get_user_meta($userId, ProUpsell::META_ADDONS_NOT_INSTALLED, true) !== '') {
                update_user_meta($userId, ProUpsell::META_ADDONS_NOT_INSTALLED, array_values($queuedAddons));
            }

            if (!empty($queuedFeatures)) {
                $record['queued_features'] = $queuedFeatures;
            }
            if (!empty($queuedAddons)) {
                $record['queued_addons'] = $queuedAddons;
            }
        }

        return $record;
    }

    /**
     * Return the Pro-feature / add-on queue for the current user. Shape:
     * `['features' => string[], 'addons' => string[]]`.
     *
     * @return array{features: string[], addons: string[]}
     */
    public static function currentQueue(): array
    {
        $userId = get_current_user_id();
        if ($userId <= 0) {
            return [
                'features' => [],
                'addons'   => [],
            ];
        }
        $features = get_user_meta($userId, ProUpsell::META_FEATURES_NOT_ENABLED, true);
        $addons   = get_user_meta($userId, ProUpsell::META_ADDONS_NOT_INSTALLED, true);
        return [
            'features' => is_array($features) ? array_values(array_filter(array_map('strval', $features))) : [],
            'addons'   => is_array($addons)   ? array_values(array_filter(array_map('strval', $addons)))   : [],
        ];
    }

    /**
     * Clear the onboarding queue for the current user (called after a
     * successful resume run drains it).
     */
    public static function clearQueue(): void
    {
        $userId = get_current_user_id();
        if ($userId <= 0) {
            return;
        }
        delete_user_meta($userId, ProUpsell::META_FEATURES_NOT_ENABLED);
        delete_user_meta($userId, ProUpsell::META_ADDONS_NOT_INSTALLED);
    }

    /**
     * Drain the queue: enable queued features + install queued add-ons.
     * Called on Resume when the license is active. Returns the outcome
     * so the view can surface what succeeded or failed.
     *
     * @return array{features: string[], addons_installed: string[], addons_failed: string[]}
     */
    public static function drainQueue(): array
    {
        $outcome = [
            'features'         => [],
            'addons_installed' => [],
            'addons_failed'    => [],
        ];

        if (!ProState::isProInstalledAndActivated()) {
            return $outcome;
        }

        $queue = self::currentQueue();
        foreach ($queue['features'] as $featureId) {
            if (ProUpsell::enableQueuedFeature($featureId)) {
                $outcome['features'][] = $featureId;
            }
        }

        // Resolve the user's current plan tier from the active license so
        // we only attempt installs the license actually entitles. Slugs
        // outside the entitlement set stay queued — the Finish-step CTA
        // keeps surfacing the required upgrade until they upgrade further
        // or clear the wizard.
        $currentPlan = (new LicenseManager())->planSlug();

        /**
         * Filter: prli_onboarding_drain_addon
         *
         * Pro listens to perform the actual add-on install using its
         * licensed add-on installer. Return true on success, false on
         * failure — anything else leaves the slug queued for a future
         * retry. Lite ships no install path on its own, so absent Pro
         * the queue stays intact.
         *
         * @param bool|null $installed Default null = unknown; subscriber returns bool.
         * @param string    $slug      Add-on slug.
         */
        $installedFailed = [];
        foreach ($queue['addons'] as $slug) {
            if ($currentPlan === '' || !PlanCatalog::planAllowsAddon($currentPlan, $slug)) {
                continue;
            }
            $result = apply_filters('prli_onboarding_drain_addon', null, $slug);
            if ($result === true) {
                $outcome['addons_installed'][] = $slug;
            } elseif ($result === false) {
                $installedFailed[]          = $slug;
                $outcome['addons_failed'][] = $slug;
            }
        }

        // Rewrite the queue with only the still-pending items so a
        // partial-drain leaves a sensible state for the next visit.
        $userId = get_current_user_id();
        if ($userId > 0) {
            $remaining = array_values(array_diff($queue['addons'], $outcome['addons_installed']));
            if (empty($remaining)) {
                delete_user_meta($userId, ProUpsell::META_ADDONS_NOT_INSTALLED);
            } else {
                update_user_meta($userId, ProUpsell::META_ADDONS_NOT_INSTALLED, $remaining);
            }

            // Feature toggles are idempotent — we clear the feature queue
            // unconditionally once we ran through it.
            delete_user_meta($userId, ProUpsell::META_FEATURES_NOT_ENABLED);

            if (!empty($installedFailed)) {
                update_user_meta($userId, ProUpsell::META_ADDONS_UPGRADE_FAILED, $installedFailed);
            } else {
                delete_user_meta($userId, ProUpsell::META_ADDONS_UPGRADE_FAILED);
            }
        }

        return $outcome;
    }

    /**
     * Snapshot the current plugin options.
     *
     * @return array<string, mixed>
     */
    private static function currentOptionSnapshot(): array
    {
        return (new OptionsStore())->all();
    }

    /**
     * Whether a plugin on this install supplies the category hooks the
     * onboarding wizard's Category step relies on. Pretty Links Pro
     * registers them; third-party plugins can too.
     */
    private static function hasCategorySupport(): bool
    {
        return has_filter('prli_category_create') || has_filter('prli_categories_all');
    }

    /**
     * Attach a category term to a link in the prli_link_terms table.
     *
     * @param integer $linkId     The link ID to attach the category to.
     * @param integer $categoryId The category term ID to attach.
     *
     * @return void
     */
    private static function attachCategory(int $linkId, int $categoryId): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'prli_link_terms';
        $wpdb->replace(
            $table,
            [
                'link_id'  => $linkId,
                'term_id'  => $categoryId,
                'taxonomy' => 'category',
            ],
            ['%d', '%d', '%s']
        );
    }

    /**
     * Determine whether the onboarding wizard should still be shown.
     *
     * Pure completion-flag check: false once onboarding is finished. Used
     * by the in-wizard request handler (rebound + resume) and the resume
     * notice — paths where a license/links are expected (e.g. a user who
     * bought a license mid-flow and returned via ?resume=1). The
     * existing-data guard for *auto-launch* lives in
     * {@see ::shouldAutoLaunch()}, not here.
     */
    public static function shouldRun(): bool
    {
        if ((bool) get_option(self::OPTION_DONE, false)) {
            return false;
        }
        $options = get_option('prli_options');
        if (is_array($options) && !empty($options['activation_complete'])) {
            return false;
        }
        if ((string) get_option(self::OPTION_LEGACY_COMPLETE, '') === '1') {
            return false;
        }
        return true;
    }

    /**
     * Whether the wizard should AUTO-LAUNCH on activation / first admin
     * load. Stricter than {@see ::shouldRun()}: an established install is
     * never auto-launched into the new-install flow.
     *
     * V3 gated onboarding the same way — PrliOnboardingController::activated_plugin()
     * bailed out when the links table already had rows. The v4 rewrite
     * dropped that guard, so long-time customers upgrading from v3 (who
     * never finished, or predate, v3 onboarding and thus carry no
     * completion flag) got dropped into the wizard. Restoring it here —
     * rather than in shouldRun() — keeps the legitimate resume-after-
     * checkout flow (which has a license by design) working.
     */
    public static function shouldAutoLaunch(): bool
    {
        return self::shouldRun() && !self::hasExistingData();
    }

    /**
     * Whether the site already holds Pretty Links data, marking it as an
     * existing install rather than a fresh one. A saved license key (the
     * `plp_mothership_license` option carries over unchanged from v3) or
     * any existing link both qualify.
     */
    private static function hasExistingData(): bool
    {
        if ((string) get_option(LicenseManager::OPTION_LICENSE_KEY, '') !== '') {
            return true;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'prli_links';

        // This runs in the activation path (Activator → shouldAutoLaunch).
        // On a fresh install the links table isn't created until the
        // Migrator runs on the next request's plugins_loaded, so guard the
        // existence query — querying a missing table throws. Mirrors v3,
        // which also checked table_exists() before counting links.
        $found = $wpdb->get_var(
            $wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))
        );
        if ($found !== $table) {
            return false;
        }

        // Lightweight existence check — no COUNT over the whole table and no
        // row hydration, just "is there at least one link?". Mirrors v3's
        // `$prli_link->get_count() > 0` guard.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted $wpdb->prefix.
        return (bool) $wpdb->get_var("SELECT 1 FROM {$table} LIMIT 1");
    }

    /**
     * Apply the ShareASale affiliate id filter only when MI was installed
     * through our onboarding.
     */
    public static function registerShareASaleFilter(): void
    {
        if (!get_option(self::OPTION_INSTALLED_MI)) {
            return;
        }
        add_filter('monsterinsights_shareasale_id', static function () {
            return '409876';
        });
    }

    public const RESUME_NOTICE_ID = 'prli_onboarding_resume';

    /**
     * Inject a virtual "resume onboarding" notice into the Pretty Links
     * notice strip when the user has started but not finished onboarding.
     * Not persisted to `prli_notices` — rendered per-request through the
     * `prli_notices_active` filter so the day-long dismiss transient
     * controls visibility (the React strip's dismiss fires
     * `prli_notice_dismissed` which we park the transient on).
     *
     * @param array<int, array<string, mixed>> $notices  The current notices collection.
     * @param string                           $screenId The current admin screen identifier.
     *
     * @return array<int, array<string, mixed>> The notices collection, possibly with the resume notice appended.
     */
    public static function injectResumeNotice(array $notices, string $screenId): array
    {
        if (!current_user_can(Page::capability())) {
            return $notices;
        }
        if (strpos($screenId, 'pretty-link') === false) {
            return $notices;
        }
        if (strpos($screenId, OnboardingPage::SLUG) !== false) {
            return $notices;
        }
        if (!get_option(self::OPTION_LEGACY_ONBOARDED)) {
            return $notices;
        }
        if (!self::shouldRun()) {
            return $notices;
        }
        if (get_transient(self::NOTICE_DISMISS_TRANSIENT)) {
            return $notices;
        }

        $resumeUrl = esc_url(admin_url('admin.php?page=' . OnboardingPage::SLUG));
        $message   = sprintf(
            // phpcs:ignore Squiz.Commenting.InlineComment.NotCapital,Squiz.Commenting.InlineComment.InvalidEndChar -- translators comment must remain verbatim.
            // translators: %1$s opens link tag, %2$s closes link tag
            __('Welcome back! It looks like you didn\'t finish the Pretty Links setup. %1$sPick up where you left off%2$s.', 'pretty-link'),
            '<a href="' . $resumeUrl . '">',
            '</a>'
        );

        $notices[] = [
            'id'      => self::RESUME_NOTICE_ID,
            'type'    => 'info',
            'message' => wp_kses_post($message),
            'created' => time(),
        ];
        return $notices;
    }

    /**
     * React strip dismissal lands in Notices::dismiss() which fires the
     * `prli_notice_dismissed` action. Our resume notice isn't stored in the
     * persistent bag, so instead we park a day-long transient that the
     * injector checks on the next render.
     *
     * @param string $id The dismissed notice identifier.
     *
     * @return void
     */
    public static function onNoticeDismissed(string $id): void
    {
        if ($id !== self::RESUME_NOTICE_ID) {
            return;
        }
        set_transient(self::NOTICE_DISMISS_TRANSIENT, 1, DAY_IN_SECONDS);
    }

    /**
     * Determine whether the MonsterInsights plugin is active.
     *
     * @return boolean True when MonsterInsights is active.
     */
    private static function isMonsterInsightsActive(): bool
    {
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        return is_plugin_active('google-analytics-for-wordpress/googleanalytics.php');
    }

    /**
     * Install + activate the free MonsterInsights plugin. Returns "installed"
     * or "failed".
     */
    public static function installMonsterInsights(): string
    {
        $result = self::installPluginFromUrl(
            'https://downloads.wordpress.org/plugin/google-analytics-for-wordpress.latest-stable.zip',
            'google-analytics-for-wordpress/googleanalytics.php',
            'google-analytics-for-wordpress',
            self::OPTION_INSTALLED_MI
        );

        if ($result === 'installed') {
            delete_transient('_monsterinsights_activation_redirect');
            update_option('monsterinsights_skip_wizard', true, false);
        }

        return $result;
    }

    /**
     * Shared install-from-zip path. Installs, activates, and (optionally)
     * stamps a "we installed this" option so scoped filters can key off of it.
     *
     * @param string $zipUrl              The plugin zip download URL.
     * @param string $mainFile            The plugin main file (relative to the plugins dir).
     * @param string $dirSlug             The plugin directory slug.
     * @param string $installedFlagOption Option key to stamp on success, or '' to skip.
     *
     * @return string Either 'installed' or 'failed'.
     */
    private static function installPluginFromUrl(string $zipUrl, string $mainFile, string $dirSlug, string $installedFlagOption): string
    {
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        if (is_plugin_active($mainFile)) {
            if ($installedFlagOption !== '') {
                update_option($installedFlagOption, true, false);
            }
            return 'installed';
        }

        if (is_dir(WP_PLUGIN_DIR . '/' . $dirSlug)) {
            $activated = activate_plugin($mainFile);
            if (is_wp_error($activated)) {
                return 'failed';
            }
            if ($installedFlagOption !== '') {
                update_option($installedFlagOption, true, false);
            }
            return 'installed';
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/misc.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

        if (!\WP_Filesystem()) {
            return 'failed';
        }

        remove_action('upgrader_process_complete', ['Language_Pack_Upgrader', 'async_upgrade'], 20);

        $upgrader  = new \Plugin_Upgrader(new \Automatic_Upgrader_Skin());
        $installed = $upgrader->install($zipUrl);

        if (is_wp_error($installed) || $installed !== true) {
            return 'failed';
        }

        wp_cache_flush();

        $activated = activate_plugin($mainFile);
        if (is_wp_error($activated)) {
            return 'failed';
        }

        if ($installedFlagOption !== '') {
            update_option($installedFlagOption, true, false);
        }
        return 'installed';
    }
}
