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
        foreach (['prli_link_health_check', 'prli_send_broken_link_emails', 'prli_auto_trim_clicks'] as $event) {
            $timestamp = wp_next_scheduled($event);
            if ($timestamp) {
                wp_unschedule_event($timestamp, $event);
            }
            wp_clear_scheduled_hook($event);
        }
    }
}
