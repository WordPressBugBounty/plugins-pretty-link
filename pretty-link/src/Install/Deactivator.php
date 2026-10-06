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
     * Every cron hook the plugin (or a library it wires up) can schedule.
     *
     * Leaving one behind is not harmless. The GroundLevel Resque hooks run on
     * *custom* intervals registered through `cron_schedules` by the Worker and
     * Cleaner services. Once the plugin is deactivated that filter is gone, the
     * schedule name no longer resolves, and WP-Cron logs
     * `Cron reschedule event error / invalid_schedule` on every tick — forever.
     * That is precisely the mess `Migrator::clearLegacyCronHooks()` exists to
     * clean up after v3.
     *
     * Library hook names are literals because they're derived at runtime from
     * provider parameters (`prli_` for IPN, `pretty-link` for Mothership) that
     * aren't reachable from here. Keep them in sync with the parameters set in
     * `Bootstrap::init()`.
     *
     * @var string[]
     */
    private const CRON_HOOKS = [
        // Lite.
        'prli_auto_trim_clicks',
        // GroundLevel Resque worker + cleanup. Custom `cron_schedules`
        // intervals — see the note above.
        'resque_run_jobs',
        'resque_cleanup_jobs',
        // GroundLevel In-Product Notifications. The library builds hook names
        // through `Util::prefixId()`, which inserts an `ipn` segment between the
        // prefix and the event name — so with prefix `prli_` the scheduled hooks
        // are `prli_ipn_remote_fetch` / `prli_ipn_clean`, NOT `prli_remote_fetch`
        // / `prli_clean`. Confirmed against `wp cron event list` on a live
        // install; the un-segmented names cleared nothing.
        'prli_ipn_remote_fetch',
        'prli_ipn_clean',
        // GroundLevel Mothership license check (plugin id `pretty-link`).
        'pretty-link_check_license_activation_status_event',
    ];

    /**
     * Clears scheduled cron events on deactivation.
     *
     * Pro's own events are cleared by Pro, off `prli_plugin_deactivating`.
     *
     * @return void
     */
    public static function onDeactivate(): void
    {
        foreach (self::CRON_HOOKS as $event) {
            wp_clear_scheduled_hook($event);
        }
    }

    /**
     * The cron hooks this class clears. Exposed so `Uninstaller` clears the
     * same set without a second copy of the list.
     *
     * @return string[]
     */
    public static function cronHooks(): array
    {
        return self::CRON_HOOKS;
    }
}
