<?php

declare(strict_types=1);

namespace PrettyLinks\Onboarding;

defined('ABSPATH') || exit;

// phpcs:disable WordPress.Security.NonceVerification.Recommended
// phpcs:disable WordPress.Security.NonceVerification.Missing
// State-changing operations in this class protect with wp_verify_nonce /
// check_admin_referer (see handlePost(), which verifies NONCE_ACTION before
// dispatching to the applyXxx() helpers that read $_POST).
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
     * Nonce action for one wizard step.
     *
     * Per-step rather than one action for the whole wizard: a single shared
     * action let a nonce minted on step 1 validly POST to step 7.
     *
     * @param integer $step Step number.
     *
     * @return string
     */
    public static function nonceAction(int $step): string
    {
        return self::NONCE_ACTION . '-' . $step;
    }

    /**
     * Render the onboarding wizard page for the current step.
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

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Compared to the literal 'POST', never stored or emitted; unslashing or sanitizing a method name would be noise.
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
     * Process a wizard form submission: verify the nonce, apply the
     * step-specific changes, persist state, and redirect to the next step
     * (or complete the wizard on the final step).
     */
    private static function handlePost(): void
    {
        if (!isset($_POST['_prli_nonce']) || !is_string($_POST['_prli_nonce'])) {
            return;
        }
        $nonce = sanitize_text_field(wp_unslash((string) $_POST['_prli_nonce']));
        $step  = self::requestedStep();
        // Scoped to the step. Every step shared one nonce action, so a nonce
        // minted on step 1 validly POSTed to step 7 — enough to jump the wizard
        // and trip markComplete() without going through the steps in between.
        // Must agree with openForm()'s mint, which uses currentStep().
        if (!wp_verify_nonce($nonce, self::nonceAction($step))) {
            return;
        }

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
                    if (isset($result['error'])) {
                        // Activation failed — persist the error and stay on the
                        // license step (don't mark it complete or advance) so the
                        // user can correct the key and see why it failed.
                        update_option(self::OPTION_STATE, $state, false);
                        wp_safe_redirect(add_query_arg(
                            [
                                'page' => OnboardingPage::SLUG,
                                'step' => self::STEP_LICENSE,
                            ],
                            admin_url('admin.php')
                        ));
                        exit;
                    }
                }
                break;
            case self::STEP_FEATURES:
                if ($action === 'skip') {
                    // Skip processes nothing. applyFeatureSelections() is not
                    // just bookkeeping: it installs MonsterInsights when that
                    // box is ticked (and it defaults to ticked), and it
                    // rewrites the Pro queue from $_POST. Running it would
                    // re-attempt the very install that may have hung — and
                    // because Pro being installed means the Pro feature rows
                    // are not rendered at all, it would overwrite the queued
                    // features with an empty array, losing the user's picks
                    // with no UI anywhere to recover them. Leaving the step
                    // untouched is the point: everything stays queued for the
                    // Finish step's button and the Add-ons page.
                    //
                    // It must not rewrite the queue from $_POST either: Pro
                    // being installed means the Pro feature rows are not
                    // rendered, so collecting from the form would read an
                    // empty set and wipe the stored queue — the very loss this
                    // branch exists to avoid. The queue is written early in
                    // applyFeatureSelections(), before anything that can hang,
                    // so by the time Skip is on offer it is already on file.
                    //
                    // The marker deliberately stays set. It means "the last
                    // save of this step did not finish", and Skip does not
                    // make that untrue — it steps around it. Leaving it set is
                    // what keeps the escape hatch on offer if the user comes
                    // back to this step, which is still as slow as it was. A
                    // save that completes clears it.
                    break;
                }

                // Recorded before the slow work and cleared once the save
                // finishes. The step is only marked complete at the end of
                // this handler, so a save killed by a timeout or an activation
                // fatal leaves this set — which is how the step knows to offer
                // a way out next time.
                $state['features_attempted'] = true;
                update_option(self::OPTION_STATE, $state, false);

                $state['features'] = self::applyFeatureSelections();
                unset($state['features_attempted']);

                // With an active license (the normal path, License comes
                // first) install the queued add-ons now. Otherwise they stay
                // queued for the Finish step's resume button.
                if (ProState::isProInstalledAndActivated()) {
                    // Successes accumulate across drains in this sitting;
                    // failures describe only the latest one. Stepping Back to
                    // Features and continuing drains again, and without this
                    // the second pass either wiped what the first installed
                    // (an empty queue drains to all-empty arrays) or kept
                    // announcing a failure for an add-on since unticked —
                    // which drainQueue() had just cleared from
                    // META_ADDONS_UPGRADE_FAILED.
                    $outcome  = self::drainQueue();
                    $previous = is_array($state['drain_outcome'] ?? null) ? $state['drain_outcome'] : [];
                    foreach (['features', 'addons_installed'] as $kept) {
                        $outcome[$kept] = array_values(array_unique(array_merge(
                            (array) ($previous[$kept] ?? []),
                            $outcome[$kept]
                        )));
                    }
                    $state['drain_outcome'] = $outcome;
                }
                break;
            case self::STEP_LINK:
                if ($action === 'create') {
                    $url  = isset($_POST['prli_target_url']) ? esc_url_raw(wp_unslash((string) $_POST['prli_target_url'])) : '';
                    $name = isset($_POST['prli_link_name']) ? sanitize_text_field(wp_unslash((string) $_POST['prli_link_name'])) : '';
                    if ($url !== '') {
                        // Edit the link this step already made rather than
                        // making another. Going Back and resubmitting used to
                        // create a second link and orphan the first from the
                        // summary — `first_link_id` was never consulted.
                        $repo     = new LinksRepo();
                        $existing = (int) ($state['first_link_id'] ?? 0);
                        $fields   = [
                            'url'  => $url,
                            'name' => $name,
                        ];
                        // Only edit a link that is still live. `find()` does not
                        // filter `deleted_at`, so a first link the user trashed in
                        // another tab would otherwise be edited in place —
                        // resurrecting its values while leaving it in the trash,
                        // and leaving the wizard summary pointing at a trashed
                        // link. A fresh one is the right answer there.
                        $current  = $existing > 0 ? $repo->find($existing) : null;
                        $isLive   = is_array($current) && empty($current['deleted_at']);
                        $link     = $isLive
                            ? $repo->save($fields, $existing)
                            : $repo->save($fields);
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
                if ($action === 'drain') {
                    // Enables Pro features and installs add-ons, so it lives
                    // here behind the nonce rather than on the Finish render.
                    $state['drain_outcome'] = self::drainQueue();
                    update_option(self::OPTION_STATE, $state, false);
                    wp_safe_redirect(add_query_arg(
                        [
                            'page' => OnboardingPage::SLUG,
                            'step' => self::STEP_FINISH,
                        ],
                        admin_url('admin.php')
                    ));
                    exit;
                }
                // Otherwise the summary step just advances.
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
        // or no plugin supplies categories.
        $skipCategory = ($step === self::STEP_LINK)
            && (empty($state['first_link_id']) || !self::hasCategorySupport());
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
     * Restrict step-jumping via the URL. Redirects requests for steps the
     * user hasn't unlocked yet (or the Category step when no plugin supplies
     * categories) back to the furthest allowed step.
     */
    private static function clampStepAccess(): void
    {
        $requested = self::requestedStep();
        if ($requested === self::STEP_WELCOME) {
            return;
        }
        // No category support — bounce straight to Pay.
        if ($requested === self::STEP_CATEGORY && !self::hasCategorySupport()) {
            // Record Category as done first, or the clamp below sends the user
            // straight back: with step_completed at 3, maxAllowed is 4, so Pay
            // (5) is out of bounds and bounces to 4, which bounces to 5…
            // ERR_TOO_MANY_REDIRECTS. Reachable whenever category support is
            // removed after step 3 was completed. Mirrors what handlePost() already does when
            // it skips this step.
            //
            // Only when the Link step is actually behind them, though. Category
            // is unreachable before that, so bumping unconditionally would let a
            // hand-typed `?step=4` from step 0 unlock Pay and everything after
            // it. Without the bump the clamp below handles that request the way
            // it handles any other out-of-range step, and there is no loop to
            // break because the user was never entitled to Category anyway.
            $state = self::currentState();
            if ((int) ($state['step_completed'] ?? 0) >= self::STEP_LINK) {
                if ((int) $state['step_completed'] < self::STEP_CATEGORY) {
                    $state['step_completed'] = self::STEP_CATEGORY;
                    update_option(self::OPTION_STATE, $state, false);
                }
                wp_safe_redirect(add_query_arg(
                    [
                        'page' => OnboardingPage::SLUG,
                        'step' => self::STEP_PAY,
                    ],
                    admin_url('admin.php')
                ));
                exit;
            }
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
     * Mark onboarding as finished, writing both the v4 completion flag and
     * the legacy v3 flags so older consumers also treat it as complete.
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
     * Read the requested step from the `step` query var, clamped to the
     * valid range. Defaults to the welcome step when absent.
     *
     * @return integer Requested step number.
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
     * Read the persisted wizard state array from the options table.
     *
     * @return array<string, mixed>
     */
    private static function currentState(): array
    {
        $raw = get_option(self::OPTION_STATE, []);
        return is_array($raw) ? $raw : [];
    }

    /**
     * Resolve the step currently being rendered.
     *
     * @return integer Current step number.
     */
    private function currentStep(): int
    {
        // Deliberately the raw requested step, NOT a clamped one. `openForm()`
        // mints the nonce from this and `handlePost()` verifies it against
        // `requestedStep()`, so the two must resolve identically. Clamping here —
        // an easy conclusion to reach, given `clampStepAccess()` exists — would
        // make every POST whose requested step differs from the clamped one fail
        // nonce verification, and `handlePost()` returns silently, so the wizard
        // would appear to do nothing at all.
        return self::requestedStep();
    }

    /**
     * Enqueue onboarding styles. Registered on admin_enqueue_scripts so the
     * stylesheet lands in `<head>` — calling wp_enqueue_style() from inside
     * the page callback (after admin-header.php has already been printed)
     * is the classic cause of the flash of unstyled content readers see on
     * the wizard otherwise.
     *
     * @param string $hookSuffix Admin page hook suffix passed by
     *                           admin_enqueue_scripts; used as a fallback
     *                           when the current screen id isn't available.
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
        $basePath = PRLI_PATH;
        $baseUrl  = PRLI_URL;
        $rel      = 'assets/css/onboarding.css';
        if (is_file($basePath . $rel)) {
            wp_enqueue_style('prli-onboarding', $baseUrl . $rel, [], \PrettyLinks\Bootstrap::version());
        }

        // Guards the submit buttons that do slow work — the Features save
        // installs add-ons, so Continue can run for minutes. Progressive
        // enhancement: the form submits identically without it.
        $script = 'assets/js/onboarding.js';
        if (is_file($basePath . $script)) {
            wp_enqueue_script(
                'prli-onboarding',
                $baseUrl . $script,
                [],
                \PrettyLinks\Bootstrap::version(),
                true
            );
        }
    }

    /**
     * Render the wizard header (logo bar).
     */
    private function renderHeader(): void
    {
        $logo = esc_url(PRLI_URL . 'assets/images/logo-horizontal.svg');
        echo '<div class="prli-onboarding-header">';
        echo '<img src="' . esc_attr($logo) . '" alt="' . esc_attr__('Pretty Links', 'pretty-link') . '" class="prli-onboarding-logo" />';
        echo '</div>';
    }

    /**
     * Render the numbered step navigation, highlighting the current step and
     * linking the steps the user has already unlocked.
     *
     * @param integer $current The step currently being rendered.
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

        // Without category support, drop the step from the nav entirely so
        // users aren't shown a feature they can't use.
        if (!self::hasCategorySupport()) {
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
            // State for assistive tech too, not only the dot's colour.
            $currentAttr = $n === $current ? ' aria-current="step"' : '';
            if ($isClickable) {
                $url = admin_url('admin.php?page=' . OnboardingPage::SLUG . '&step=' . $n);
                echo '<li class="' . esc_attr($class) . '"' . $currentAttr . '><a href="' . esc_url($url) . '" class="prli-onboarding-step-link">';
            } else {
                echo '<li class="' . esc_attr($class) . '"' . $currentAttr . '>';
            }
            // A dot rather than the step number: the label names the step, and
            // a number would skip when a step is hidden (#975).
            echo '<span class="prli-step-dot" aria-hidden="true"></span><span class="prli-step-label">' . esc_html($label);
            if ($n < $current) {
                echo ' <span class="screen-reader-text">' . esc_html__('(completed)', 'pretty-link') . '</span>';
            }
            echo '</span>';
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
        // Category is hidden without category support — jump back over it.
        if ($prev === self::STEP_CATEGORY && !self::hasCategorySupport()) {
            $prev = self::STEP_LINK;
        }
        $prevUrl = ($prev === self::STEP_WELCOME)
            ? admin_url('admin.php?page=' . OnboardingPage::SLUG)
            : admin_url('admin.php?page=' . OnboardingPage::SLUG . '&step=' . $prev);
        echo '<a href="' . esc_url($prevUrl) . '" class="button button-link prli-onboarding-back">' . esc_html__('Back', 'pretty-link') . '</a> ';
    }

    /**
     * Render the welcome splash (pre-step), offering Start and Skip controls.
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
     * Render the License step (activate a key).
     *
     * @param array<string, mixed> $state Persisted wizard state.
     */
    private function renderLicense(array $state): void
    {
        $license = (new LicenseManager())->currentLicense();
        $last    = is_array($state['license'] ?? null) ? $state['license'] : [];
        require __DIR__ . '/views/license.php';
    }

    /**
     * Render the Features step (Lite toggles, Pro toggles, add-on installs).
     *
     * @param array<string, mixed> $state Persisted wizard state.
     */
    private function renderFeatures(array $state): void
    {
        $opts      = self::currentOptionSnapshot();
        $last      = is_array($state['features'] ?? null) ? $state['features'] : [];
        $miInstall = (string) ($last['mi_install'] ?? '');
        $miActive  = self::isMonsterInsightsActive();

        // Set at the top of a Features save and cleared when that save gets
        // through its slow work, so finding it still set means the previous
        // attempt died — a timeout, or an activation fatal. step_completed is
        // only bumped at the end of the handler, so the user is pinned on this
        // step with Back and Continue as the only controls, and Continue runs
        // the same failing work again. Offer a way past it.
        //
        // Keyed off the attempt rather than the queue, because a queue is not
        // evidence of being stuck: a user who ticked nothing has an empty one
        // and would have been offered no way out, while a user who ticked
        // something has a full one whether or not the save ever struggled.
        $canSkip = !empty($state['features_attempted']);

        require __DIR__ . '/views/features.php';
    }

    /**
     * Render the Pretty Link step (create the first link).
     *
     * @param array<string, mixed> $state Persisted wizard state.
     */
    private function renderFirstLink(array $state): void
    {
        $existingId = (int) ($state['first_link_id'] ?? 0);
        $link       = $existingId > 0 ? (new LinksRepo())->find($existingId) : null;
        require __DIR__ . '/views/first-link.php';
    }

    /**
     * Render the Category step (create or pick a category for the first link).
     *
     * @param array<string, mixed> $state Persisted wizard state.
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
     * @param array<string, mixed> $state Persisted wizard state (unused).
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
     * Render the Finish step: a summary of what was set up, draining any
     * queued Pro features/add-ons when a license is now active.
     *
     * @param array<string, mixed> $state Persisted wizard state.
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

        // Resume flow — if the user queued Pro features/add-ons earlier and has
        // since activated a license, the queue needs draining before the summary
        // reflects the settled state. Otherwise compute the tier-appropriate
        // upgrade CTA so the user can go buy the license.
        //
        // Draining is NOT done here. It enables Pro features, fires
        // `prli_onboarding_drain_addon` (installing plugins) and rewrites three
        // user-meta keys — and this method runs on a plain GET with no nonce, so
        // a refresh re-attempted every failed install with no backoff. Every
        // other state change in this wizard goes through the nonce-checked POST
        // handler; so does this one now. The view renders a button, and the
        // outcome of the last drain is read back from the state so the result
        // still shows after the redirect.
        $upgradeCta   = null;
        $queue        = self::currentQueue();
        $canDrain     = (!empty($queue['features']) || !empty($queue['addons']))
            && ProState::isProInstalledAndActivated();
        $drainOutcome = is_array($state['drain_outcome'] ?? null) ? $state['drain_outcome'] : null;
        if ($drainOutcome !== null) {
            // One-shot: clear it so a later visit doesn't re-announce a drain
            // that happened days ago.
            unset($state['drain_outcome']);
            update_option(self::OPTION_STATE, $state, false);
        }

        $addonsInstalledLabels = self::addonLabels((array) ($drainOutcome['addons_installed'] ?? []));
        $addonsFailedLabels    = self::addonLabels((array) ($drainOutcome['addons_failed'] ?? []));

        // Pro features the drain just switched on are otherwise reported only
        // by the success banner, and the banner is suppressed when an add-on
        // failed — so a resume where one add-on fails said nothing at all
        // about the features it had just enabled. Fold them into the
        // "Features enabled" row for this render. Render-time only:
        // $state['features']['enabled'] is not rewritten and drain_outcome is
        // one-shot, so a later Finish render shows only what the Features step
        // itself turned on.
        $enabledFeatureLabels = array_values(array_unique(array_merge(
            $enabledFeatureLabels,
            self::featureLabels((array) ($drainOutcome['features'] ?? []))
        )));

        if (!empty($queue['features']) || !empty($queue['addons'])) {
            $plan = ProUpsell::requiredPlanFor($queue['features'], $queue['addons']);

            // ProUpsell::requiredPlanFor() answers "what tier do these items
            // need", never "what tier does this site already have". Without
            // the second half,
            // a customer who has just activated a licence that covers
            // everything they picked is still shown "Upgrade to Pretty Links
            // Pro" with a checkout link, on the Finish step, immediately after
            // paying. Anything the current plan already entitles is not an
            // upgrade.
            if ($plan !== null) {
                $license     = new LicenseManager();
                $currentPlan = $license->planSlug();
                if (
                    $currentPlan !== ''
                    && PlanCatalog::tierRank($currentPlan) >= PlanCatalog::tierRank($plan)
                ) {
                    $plan = null;
                } elseif ($currentPlan === '' && $license->isActive()) {
                    // A licence is active but its plan is unreadable: the
                    // activation record carries a key and nothing else, which is
                    // exactly what a failed post-activation meta sync leaves
                    // behind (and what activate()'s honest message exists for).
                    // planSlug() answers '' for that, and '' is also what a free
                    // install answers — so the tier comparison above cannot tell
                    // a customer who just paid from one who never did. Silence is
                    // the only safe reading: never put a checkout link in front
                    // of someone holding an active licence.
                    $plan = null;
                }
            }

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
     * Human labels for Pro feature ids, falling back to the raw id when the
     * upsell catalog has no entry for it — a queue inherited from v3 can name
     * a feature this build does not know.
     *
     * @param string[] $featureIds Queued Pro feature ids.
     *
     * @return string[]
     */
    private static function featureLabels(array $featureIds): array
    {
        $catalog = ProUpsell::features();

        return array_map(
            static function (string $id) use ($catalog): string {
                $label = (string) ($catalog[$id]['label'] ?? '');
                return $label !== '' ? $label : $id;
            },
            array_values(array_map('strval', $featureIds))
        );
    }

    /**
     * Human labels for add-on slugs, falling back to the raw slug when the
     * catalog has no label for it.
     *
     * @param string[] $slugs Add-on slugs.
     *
     * @return string[]
     */
    private static function addonLabels(array $slugs): array
    {
        return array_map(
            static function (string $slug): string {
                $label = PlanCatalog::addonLabel($slug);
                return $label !== '' ? $label : $slug;
            },
            array_values($slugs)
        );
    }

    /**
     * Render the Complete step view.
     */
    private function renderComplete(): void
    {
        // Reaching this screen IS finishing onboarding. markComplete() used to
        // fire only from a POST at this step, but the step is reached by GET —
        // so closing the tab on "You're all set" left the resume notice nagging
        // and `activation_complete` unset forever.
        //
        // This is a write on a GET, which the wizard otherwise routes through
        // the nonce-checked POST handler. It's justified here and nowhere else:
        // `render()` has already checked the page capability, the write is
        // idempotent, it flips completion flags and nothing external, and there
        // is no other moment that means "the user finished".
        self::markComplete();

        require __DIR__ . '/views/complete.php';
    }

    /**
     * Open the wizard's POST form for the current step and emit the nonce.
     */
    private function openForm(): void
    {
        $url = admin_url('admin.php?page=' . OnboardingPage::SLUG . '&step=' . $this->currentStep());
        echo '<form method="post" action="' . esc_url($url) . '" class="prli-onboarding-form">';
        // Must agree with handlePost()'s `self::nonceAction(self::requestedStep())`.
        // currentStep() is an alias for requestedStep() and has to stay one — see
        // its docblock for what breaks if it starts clamping.
        wp_nonce_field(self::nonceAction($this->currentStep()), '_prli_nonce');
    }

    /**
     * Close the wizard's POST form.
     */
    private function closeForm(): void
    {
        echo '</form>';
    }

    /**
     * Render the step footer with the primary submit button and an optional
     * Back link to the previous step.
     *
     * @param string  $primaryLabel Label for the primary submit button.
     * @param boolean $showBack     Whether to render the Back link.
     * @param boolean $showSkip     Whether to render a Skip control that posts
     *                              `prli_action=skip`, letting the user past a
     *                              step whose save cannot complete.
     */
    private function renderFooter(string $primaryLabel, bool $showBack, bool $showSkip = false): void
    {
        echo '<div class="prli-onboarding-footer">';
        if ($showBack) {
            $prev    = max(self::STEP_LICENSE, $this->currentStep() - 1);
            $prevUrl = admin_url('admin.php?page=' . OnboardingPage::SLUG . '&step=' . $prev);
            echo '<a href="' . esc_url($prevUrl) . '" class="button button-link prli-onboarding-back">' . esc_html__('Back', 'pretty-link') . '</a>';
        }
        // The busy label is swapped in by assets/js/onboarding.js once the
        // form is submitted; the Features save can spend minutes installing
        // add-ons and said nothing while it did.
        // The primary comes first in tree order on purpose: the first submit
        // control is the form's default for implicit submission, and Enter
        // while a checkbox has focus submits. With Skip first, a keyboard user
        // pressing Enter would skip the step instead of continuing.
        printf(
            '<button type="submit" class="button button-primary" data-busy-label="%s">%s</button>',
            esc_attr__('Working…', 'pretty-link'),
            esc_html($primaryLabel)
        );
        if ($showSkip) {
            printf(
                '<button type="submit" name="prli_action" value="skip" class="button button-secondary">%s</button>',
                esc_html__('Skip for now', 'pretty-link')
            );
        }
        echo '</div>';
    }

    /**
     * Render one checkbox item in the Features step.
     *
     * @param string  $name        Form field name for the checkbox input.
     * @param string  $label       Human-readable feature name shown on the label.
     * @param string  $description Short supporting copy shown under the label.
     * @param string  $tooltip     Deeper "what this feature does and why it matters"
     *                             copy — surfaced via the (i) info bubble next to
     *                             the feature name.
     * @param boolean $checked     Whether the checkbox starts checked.
     * @param boolean $enabled     Whether the checkbox is interactive (false renders it disabled).
     * @param boolean $alreadyOn   Whether the feature is already active; renders a
     *                             checked, disabled checkbox instead of an input.
     * @param string  $badgeLabel  Optional badge text shown next to the feature name.
     * @param boolean $recommended Adds a green "Recommended" badge next to the
     *                             feature name.
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

        // Persisted before the MonsterInsights install, deliberately. That
        // install downloads and activates a plugin and can hang or fatal, and
        // nothing below it runs if it does — so writing the queue afterwards
        // meant a death there discarded the user's queued Pro picks entirely,
        // with nothing left for the Finish step to drain. The queue is cheap
        // and derived purely from $_POST, so there is no reason for it to wait
        // behind a network install.
        $queuedFeatures = ProUpsell::collectQueuedFeatures((array) $_POST);
        $queuedAddons   = ProUpsell::collectQueuedAddons((array) $_POST);

        // The Pro feature rows are only rendered while Pro is absent
        // (ProUpsell::renderOnboardingProFeatures() returns early once it is
        // installed), so with Pro present an empty collection means "the form
        // had no such fields", not "the user unticked everything". Passing
        // null leaves the stored feature queue alone rather than discarding
        // picks the user made back when they were still on Lite — which is
        // exactly the queue the Finish step's drain exists to consume.
        self::persistQueue(
            ProState::isProInstalled() ? null : $queuedFeatures,
            $queuedAddons
        );

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
     * Write the Pro-gated queue to user meta.
     *
     * Kept in user meta under the v3 key names for upgrade continuity, so the
     * wizard can drain it after the user returns from checkout with an active
     * licence.
     *
     * Each key is only written when there is something to write or something
     * already stored, so an unrelated save cannot create empty rows. Passing
     * null for either list leaves that key untouched — for when the form the
     * values came from could not express it, which is not the same as the
     * user clearing it.
     *
     * @param string[]|null $features Queued Pro feature ids, or null to leave them alone.
     * @param string[]|null $addons   Queued add-on slugs, or null to leave them alone.
     */
    private static function persistQueue(?array $features, ?array $addons): void
    {
        $userId = get_current_user_id();
        if ($userId <= 0) {
            return;
        }

        self::persistQueueKey($userId, ProUpsell::META_FEATURES_NOT_ENABLED, $features);
        self::persistQueueKey($userId, ProUpsell::META_ADDONS_NOT_INSTALLED, $addons);
    }

    /**
     * Write one queue key, or leave it alone.
     *
     * @param integer       $userId  Owner of the queue.
     * @param string        $metaKey User-meta key to write.
     * @param string[]|null $values  Values to store; null leaves the key untouched.
     */
    private static function persistQueueKey(int $userId, string $metaKey, ?array $values): void
    {
        if ($values === null) {
            return;
        }
        // Nothing to store and nothing stored: don't create an empty row on a
        // save that never had anything to do with this queue.
        if (empty($values) && get_user_meta($userId, $metaKey, true) === '') {
            return;
        }

        update_user_meta($userId, $metaKey, array_values($values));
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
            /**
             * Filter: prli_onboarding_drain_feature
             *
             * Turn on a feature the user queued during onboarding. The plugin
             * that owns the feature flips its own setting and returns true;
             * Lite only reports the outcome.
             *
             * @param bool   $enabled   True once a callback turned it on. Default false.
             * @param string $featureId The queued feature id.
             */
            if ((bool) apply_filters('prli_onboarding_drain_feature', false, $featureId)) {
                $outcome['features'][] = $featureId;
            }
        }

        // Resolve the user's current plan tier from the active license so
        // we only attempt installs the license actually entitles. Slugs
        // outside the entitlement set stay queued — the Finish-step CTA
        // keeps surfacing the required upgrade until they upgrade further
        // or clear the wizard.
        //
        // '' means "no readable plan", which covers both a free install and an
        // activation record whose meta sync failed. Skipping the whole queue on
        // '' was right for the first and wrong for the second: a paying customer
        // with a key-only record got every add-on they had picked silently
        // dropped. When a licence IS active, hand the slug to the installer and
        // let the licensed catalog — which is the server's answer about
        // entitlement, where PlanCatalog is only a local map — decide. A slug
        // the licence doesn't cover simply isn't in the catalog, and the
        // listener reports the failure so it stays queued.
        $license          = new LicenseManager();
        $currentPlan      = $license->planSlug();
        $entitlementKnown = $currentPlan !== '' || !$license->isActive();

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
            if ($entitlementKnown && ($currentPlan === '' || !PlanCatalog::planAllowsAddon($currentPlan, $slug))) {
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
     * Snapshot of all plugin options, used to seed the Features step toggles.
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
     * Set the onboarding link's category.
     *
     * @param integer $linkId     ID of the link to categorize.
     * @param integer $categoryId ID of the category term to attach.
     */
    private static function attachCategory(int $linkId, int $categoryId): void
    {
        (new LinksRepo())->setTerms($linkId, 'category', [$categoryId]);
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
        // Migrator runs on the next request's after_setup_theme
        // (Bootstrap::bootDeferred), so guard the existence query —
        // querying a missing table throws. Mirrors v3, which also checked
        // table_exists() before counting links.
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
     * @param  array<int, array<string, mixed>> $notices  Current active notices.
     * @param  string                           $screenId Current admin screen id.
     * @return array<int, array<string, mixed>>
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
            // Translators: %1$s opens link tag, %2$s closes link tag.
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
     * @param string $id ID of the dismissed notice.
     */
    public static function onNoticeDismissed(string $id): void
    {
        if ($id !== self::RESUME_NOTICE_ID) {
            return;
        }
        set_transient(self::NOTICE_DISMISS_TRANSIENT, 1, DAY_IN_SECONDS);
    }

    /**
     * Whether the MonsterInsights plugin is currently active.
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
     * @param  string $zipUrl              URL of the plugin zip to download and install.
     * @param  string $mainFile            Plugin basename (dir/file.php) used to activate it.
     * @param  string $dirSlug             Plugin directory slug under wp-content/plugins.
     * @param  string $installedFlagOption Option name to set when we installed the plugin; empty to skip.
     * @return string Either "installed" or "failed".
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
