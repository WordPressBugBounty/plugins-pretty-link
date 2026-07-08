<?php

declare(strict_types=1);

namespace PrettyLinks\Install;

use PrettyLinks\Onboarding\FirstRunRedirect;
use PrettyLinks\Onboarding\Wizard;
use PrettyLinks\Options\Store;

/**
 * Runs when the plugin is activated. Idempotent.
 *
 * Heavy migration work lives in Database\Migrator::maybeRun() which runs synchronously
 * from Bootstrap::loaded() on every load — activation just primes defaults and marks
 * the plugin for a migration pass by clearing prli_migration_state.
 */
class Activator
{
    /**
     * Primes default options and marks the plugin for a migration pass.
     *
     * @return void
     */
    public static function onActivate(): void
    {
        $stored = get_option(Store::OPTION);
        if (!is_array($stored)) {
            add_option(Store::OPTION, Store::defaults(), '', 'yes');
        }

        if (!get_option('prli_activated_timestamp')) {
            add_option('prli_activated_timestamp', time(), '', 'no');
        }

        delete_option('prli_migration_state');

        // Fresh installs only: queue a one-shot redirect into the onboarding
        // wizard. shouldAutoLaunch() excludes established installs (existing
        // links or a carried-over license) so v3 upgrades aren't dropped into
        // the new-install flow.
        if (Wizard::shouldAutoLaunch()) {
            set_transient(FirstRunRedirect::TRANSIENT, 1, 30);
        }
    }
}
