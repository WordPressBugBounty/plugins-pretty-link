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
        foreach (['prli_link_health_check', 'prli_link_health_check_now', 'prli_send_broken_link_emails', 'prli_auto_trim_clicks'] as $event) {
            wp_clear_scheduled_hook($event);
        }
        // Drop the link-health run transients too, so a queued force pass can't
        // resume after reactivation. Literal keys (Lite can't reference the Pro
        // HealthChecker constants), kept in sync with pro/src/Health.
        foreach (['prli_link_health_force_before', 'prli_link_health_running', 'prli_link_health_swept'] as $transient) {
            delete_transient($transient);
        }
    }
}
