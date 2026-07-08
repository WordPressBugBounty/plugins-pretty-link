<?php

declare(strict_types=1);

namespace PrettyLinks\Admin;

use PrettyLinks\Support\HasStaticContainer;
use PrettyLinks\Support\StaticContainerAwareness;

/**
 * Single notice manager. Pretty Links historically stacked ~10 notices at once;
 * this replaces them with one coordinated queue persisted in a single option
 * bag (`prli_notices`). Dismissals write back to the same bag.
 */
class Notices implements StaticContainerAwareness
{
    use HasStaticContainer;

    private const OPTION = 'prli_notices';

    /**
     * Add or replace a notice in the persisted queue.
     *
     * @param  string $id      Unique notice identifier.
     * @param  string $type    Notice type (e.g. info, success, warning, error).
     * @param  string $message Notice body (allowed post HTML retained).
     * @return void
     */
    public static function add(string $id, string $type, string $message): void
    {
        $notices      = self::load();
        $notices[$id] = [
            'type'      => sanitize_text_field($type),
            'message'   => wp_kses_post($message),
            'dismissed' => false,
            'created'   => time(),
        ];
        update_option(self::OPTION, $notices, false);
    }

    /**
     * Mark a notice dismissed and broadcast the dismissal.
     *
     * @param  string $id Notice identifier to dismiss.
     * @return void
     */
    public static function dismiss(string $id): void
    {
        $notices = self::load();
        if (isset($notices[$id])) {
            $notices[$id]['dismissed'] = true;
            update_option(self::OPTION, $notices, false);
        }
        // Broadcast so code that injects virtual (non-persisted) notices via
        // the `prli_notices_active` filter can react to dismissals — e.g. the
        // onboarding wizard records a "dismissed for today" transient here.
        do_action('prli_notice_dismissed', $id);
    }

    /**
     * Permanently remove a notice from the persisted queue.
     *
     * @param  string $id Notice identifier to remove.
     * @return void
     */
    public static function remove(string $id): void
    {
        $notices = self::load();
        if (isset($notices[$id])) {
            unset($notices[$id]);
            update_option(self::OPTION, $notices, false);
        }
    }

    /**
     * Strip every `admin_notices` / `all_admin_notices` / `user_admin_notices`
     * / `network_admin_notices` callback registered by third parties on
     * Pretty Links screens.
     *
     * Pretty Links renders its own notice strip via the React shell
     * (see {@see self::activeForScreen()}). Letting other plugins pile
     * their banners on top competes for attention, blows out the layout,
     * and — in WordPress 6.7+ — is often considered a hostile UX pattern
     * inside a branded admin experience.
     *
     * Only the core `default_password_nag` and Pretty Links' own callbacks
     * (if any) are preserved. This hooks late on `in_admin_header` so every
     * other plugin has finished registering by then.
     */
    public static function suppressThirdParty(): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || !is_string($screen->id) || strpos($screen->id, 'pretty-link') === false) {
            return;
        }

        foreach (['admin_notices', 'all_admin_notices', 'user_admin_notices', 'network_admin_notices'] as $hook) {
            remove_all_actions($hook);
        }
    }

    /**
     * Active (non-dismissed) notices for the current screen, shaped for the
     * React admin bundle to render inside its page shell.
     *
     * We do NOT render via admin_notices anymore — WordPress relocates
     * .notice elements to just after the first h1/h2 at DOM-ready, which
     * races the React mount and lands notices in unpredictable positions
     * (sometimes at the top of the wrap, sometimes mashed against the page
     * header actions). By shipping the payload as bootstrap data instead,
     * the React shell puts them in exactly one spot on every render.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function activeForScreen(): array
    {
        $screen   = function_exists('get_current_screen') ? get_current_screen() : null;
        $screenId = $screen && is_string($screen->id) ? $screen->id : '';

        // Onboarding and What's New are focused flows — suppress everything.
        if (
            strpos($screenId, 'pretty-link-onboarding') !== false
            || strpos($screenId, 'pretty-link-whats-new') !== false
        ) {
            return [];
        }

        $out = [];
        foreach (self::load() as $id => $notice) {
            if (!empty($notice['dismissed'])) {
                continue;
            }
            $out[] = [
                'id'          => (string) $id,
                'type'        => (string) ($notice['type'] ?? 'info'),
                'message'     => (string) ($notice['message'] ?? ''),
                'created'     => (int) ($notice['created'] ?? 0),
                'dismissible' => true,
            ];
        }
        /**
         * Filter the notices delivered to the current screen. Use this to
         * inject ephemeral / virtual notices that shouldn't be persisted to
         * the `prli_notices` option (e.g. "resume onboarding"). Listeners
         * should respect the existing shape: each item is a
         * { id, type, message, created } array.
         *
         * @param array<int, array<string, mixed>> $out      Persisted notices for this screen.
         * @param string                           $screenId Current admin screen id.
         */
        return (array) apply_filters('prli_notices_active', $out, $screenId);
    }

    /**
     * Load the persisted notice bag from options.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function load(): array
    {
        $raw = get_option(self::OPTION, []);
        return is_array($raw) ? $raw : [];
    }
}
