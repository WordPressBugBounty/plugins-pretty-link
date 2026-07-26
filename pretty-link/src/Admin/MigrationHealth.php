<?php

declare(strict_types=1);

namespace PrettyLinks\Admin;

/**
 * Surfaces database-migration failures that used to be swallowed silently.
 *
 * Three surfaces:
 *  - an admin notice when a migration step is currently failing, with a
 *    one-click "Retry" and a link to the details;
 *  - a "Pretty Links — Migration" panel under Site Health → Info showing each
 *    migrator's version, pending state, and the actual DB error per step;
 *  - a retry handler (the "trigger it again" mechanism) that re-arms the failed
 *    steps so the next request re-runs them.
 *
 * Both migrators persist their state under a plain option — Lite in
 * `prli_migration_state`, Pro in `prlipro_migration_state`. This class reads
 * those by their literal keys so Lite carries no dependency on the Pro package
 * (same approach the deactivator uses for the Pro cron-hook names).
 */
class MigrationHealth
{
    /**
     * The admin-post action slug for the retry handler.
     */
    public const RETRY_ACTION = 'prli_retry_migration';

    /**
     * Migration-state option keys, Lite then Pro.
     *
     * @var list<string>
     */
    private const STATE_OPTIONS = ['prli_migration_state', 'prlipro_migration_state'];

    /**
     * Retry-backoff transients cleared on a manual retry so it fires at once.
     *
     * @var list<string>
     */
    private const BACKOFF_TRANSIENTS = ['prli_migration_retry_after', 'prlipro_migration_retry_after'];

    /**
     * Collect the current {step => error} failures across both migrators.
     *
     * @return array<string, string>
     */
    public static function failures(): array
    {
        $out = [];
        foreach (self::STATE_OPTIONS as $option) {
            $state = get_option($option);
            if (!is_array($state) || empty($state['failures']) || !is_array($state['failures'])) {
                continue;
            }
            // Key by "tier:step" so a step name shared by both migrators (e.g.
            // clear_legacy_cron_hooks) can't overwrite the other's failure.
            $tier = $option === 'prlipro_migration_state' ? 'pro' : 'lite';
            foreach ($state['failures'] as $step => $error) {
                $out[$tier . ':' . (string) $step] = (string) $error;
            }
        }
        return $out;
    }

    /**
     * Inject a live notice into Pretty Links' own notice queue when a migration
     * is currently failing. Hooked on the `prli_notices_active` filter so it
     * renders in the first-party React notice strip (styled, dismissible, and
     * not stripped by {@see Notices::suppressThirdParty()}) rather than as a
     * raw core admin notice. It's virtual — reflecting live failure state — so
     * it's never persisted to the `prli_notices` bag and vanishes on its own
     * once the failure clears.
     *
     * @param  array<int, array<string, mixed>> $notices  Notices for this screen.
     * @param  string                           $screenId Current admin screen id.
     * @return array<int, array<string, mixed>>
     */
    public static function injectNotice(array $notices, string $screenId): array
    {
        unset($screenId);
        if (!current_user_can(Page::capability())) {
            return $notices;
        }
        $failures = self::failures();
        if ($failures === []) {
            return $notices;
        }

        $retryUrl  = wp_nonce_url(
            admin_url('admin-post.php?action=' . self::RETRY_ACTION),
            self::RETRY_ACTION
        );
        $healthUrl = admin_url('site-health.php?tab=debug');

        // Keep the notice a plain-English heads-up + actions. The actual
        // database error lives in Site Health (below) so the notice stays
        // readable and non-alarming — that's the division of labour.
        // Deliberately generic: this surface reports ANY migration step failure
        // in either migrator, not just categories/tags — the specific step and
        // its error live in the Site Health panel.
        $message = '<strong>' . esc_html__('Pretty Links: a database migration didn\'t finish.', 'pretty-link') . '</strong> '
            . esc_html__('Some data may be incomplete until it does. It retries automatically — you can also retry now, or see the error details in Site Health.', 'pretty-link')
            . '<br>'
            . '<a href="' . esc_url($retryUrl) . '">' . esc_html__('Retry migration', 'pretty-link') . '</a>'
            . ' &middot; '
            . '<a href="' . esc_url($healthUrl) . '">' . esc_html__('View error details in Site Health', 'pretty-link') . '</a>';

        $notices[] = [
            'id'          => 'prli-migration-failure',
            'type'        => 'error',
            // Not dismissible: a data-integrity failure shouldn't be hideable
            // (matches EditionMismatch). It clears itself once resolved.
            'message'     => $message,
            'created'     => time(),
            'dismissible' => false,
        ];
        return $notices;
    }

    /**
     * Add a migration panel to Site Health → Info.
     *
     * @param  array<string, mixed> $info Existing debug information sections.
     * @return array<string, mixed>
     */
    public static function siteHealthInfo(array $info): array
    {
        $labels = [
            'prli_migration_state'    => __('Core (Lite)', 'pretty-link'),
            'prlipro_migration_state' => __('Pro', 'pretty-link'),
        ];
        $fields = [];

        foreach (self::STATE_OPTIONS as $option) {
            $state = get_option($option);
            $tier  = $labels[$option];
            if (!is_array($state)) {
                $fields[$option . '_version'] = [
                    'label' => sprintf('%s — %s', $tier, __('migration', 'pretty-link')),
                    'value' => __('not run yet', 'pretty-link'),
                ];
                continue;
            }

            $fields[$option . '_version'] = [
                'label' => sprintf('%s — %s', $tier, __('schema version', 'pretty-link')),
                'value' => (string) ($state['version'] ?? __('unknown', 'pretty-link')),
            ];

            $failures = (is_array($state['failures'] ?? null)) ? $state['failures'] : [];
            if ($failures === []) {
                // No error, but distinguish "done" from "still in progress"
                // (pending set = a step is queued/re-armed and hasn't settled)
                // so a pending state doesn't read as a clean OK.
                $fields[$option . '_status'] = [
                    'label' => sprintf('%s — %s', $tier, __('status', 'pretty-link')),
                    'value' => !empty($state['pending'])
                        ? __('In progress (runs on the next request)', 'pretty-link')
                        : __('OK', 'pretty-link'),
                ];
                continue;
            }
            foreach ($failures as $step => $error) {
                $fields[$option . '_fail_' . $step] = [
                    'label' => sprintf('%s — %s: %s', $tier, __('failed step', 'pretty-link'), (string) $step),
                    'value' => (string) $error,
                ];
            }
        }

        $info['pretty-links-migration'] = [
            'label'       => __('Pretty Links — Migration', 'pretty-link'),
            'description' => __('Status of the Pretty Links database migrations. A failed step shows the exact database error and retries automatically; use the Retry button in the admin notice to run it now. If the same error keeps recurring, the underlying data likely needs manual repair — send this error to support.', 'pretty-link'),
            'fields'      => $fields,
        ];
        return $info;
    }

    /**
     * Retry handler (admin-post): verify capability + nonce, re-arm failed
     * steps, then bounce back. The state mutation lives in
     * {@see self::rearmFailedSteps()} so it's unit-testable without the
     * redirect/exit.
     *
     * @return void
     */
    public static function handleRetry(): void
    {
        if (!current_user_can(Page::capability())) {
            wp_die(esc_html__('You are not allowed to do this.', 'pretty-link'), '', ['response' => 403]);
        }
        check_admin_referer(self::RETRY_ACTION);

        self::rearmFailedSteps();

        wp_safe_redirect(wp_get_referer() ?: admin_url());
        exit;
    }

    /**
     * Re-arm each recorded-failed step so the migrator re-runs it on the next
     * request: clear its done flag, mark the tier pending, and drop the backoff
     * so it fires promptly. Only tiers with a recorded failure are touched (no
     * unnecessary write / maybeRun), and `failures` is left for the migrator to
     * clear on a successful re-run — so state can't read OK before the retry.
     *
     * @return void
     */
    public static function rearmFailedSteps(): void
    {
        foreach (self::BACKOFF_TRANSIENTS as $transient) {
            delete_transient($transient);
        }

        foreach (self::STATE_OPTIONS as $option) {
            $state = get_option($option);
            if (!is_array($state)) {
                continue;
            }
            $rearm = array_keys((is_array($state['failures'] ?? null)) ? $state['failures'] : []);
            // Nothing failed for this tier — skip the write so we don't force an
            // unnecessary maybeRun() next load. A broken category/tag sync always
            // records a failure via the verify step, so that case is covered.
            if ($rearm === []) {
                continue;
            }
            foreach ($rearm as $step) {
                unset($state['steps'][$step]);
            }
            $state['pending'] = true;
            update_option($option, $state, false);
        }
    }
}
