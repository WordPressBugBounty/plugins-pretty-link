<?php

declare(strict_types=1);

namespace PrettyLinks\Install;

/**
 * Uninstall handler. Invoked by WordPress exactly once, when the user clicks
 * "Delete" on the Plugins screen after deactivating. NEVER runs on deactivate.
 *
 * Only clears scheduled crons. Link data, click history, settings, and all
 * database tables are intentionally preserved so a reinstall restores the
 * site to its previous state.
 */
class Uninstaller
{
    /**
     * Runs the uninstall routine, clearing scheduled crons only.
     *
     * @return void
     */
    public static function run(): void
    {
        if (!defined('WP_UNINSTALL_PLUGIN')) {
            return;
        }

        self::clearCron();
    }

    /**
     * Clears all Pretty Links scheduled cron events.
     *
     * @return void
     */
    private static function clearCron(): void
    {
        // Same set Deactivator clears, plus the bundled Pro's events, which Pro
        // normally clears off `prli_plugin_deactivating` but can't here:
        // uninstall.php runs with the plugin inactive and Pro not loaded.
        // Literal keys, kept in sync with pro/src/Health and pro/src/Expiration.
        $events = array_merge(Deactivator::cronHooks(), [
            'prli_link_health_check',
            'prli_link_health_check_now',
            'prli_link_health_watchdog',
            'prli_send_broken_link_emails',
            'prli_trash_expired_links',
        ]);
        foreach ($events as $event) {
            $timestamp = wp_next_scheduled($event);
            if ($timestamp) {
                wp_unschedule_event($timestamp, $event);
            }
            wp_clear_scheduled_hook($event);
        }
        // Link-health run transients (literal keys — Lite can't reference the
        // Pro HealthChecker constants; kept in sync with pro/src/Health).
        foreach (['prli_link_health_force_before', 'prli_link_health_running', 'prli_link_health_swept'] as $transient) {
            delete_transient($transient);
        }
    }
}
