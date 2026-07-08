<?php

declare(strict_types=1);

namespace PrettyLinks\Install;

/**
 * Runs when the plugin is deactivated. Idempotent.
 *
 * Clears scheduled cron events. Persistent data (tables, options) is left
 * intact — that's Uninstaller's job.
 */
class Deactivator
{
    /**
     * Clears scheduled cron events on deactivation.
     *
     * @return void
     */
    public static function onDeactivate(): void
    {
        foreach (['prli_link_health_check', 'prli_send_broken_link_emails', 'prli_auto_trim_clicks'] as $event) {
            wp_clear_scheduled_hook($event);
        }
    }
}
