<?php

/**
 * Pretty Links uninstall script.
 *
 * Triggered by WordPress when the user explicitly deletes the plugin from
 * the Plugins screen. Never runs on deactivate.
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

require_once __DIR__ . '/vendor-prefixed/autoload.php';

\PrettyLinks\Install\Uninstaller::run();
