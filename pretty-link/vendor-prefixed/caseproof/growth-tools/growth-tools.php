<?php

/**
 * Plugin Name: Caseproof Growth Tools
 * Plugin URI:  https://github.com/caseproof/growth-tools
 * Description: A collection of tools to help grow your business.
 * Version:     1.6.0
 * Author:      Caseproof
 * Author URI:  https://github.com/caseproof
 * Text Domain: cspf-growth-tools
 */

declare(strict_types=1);

// Include autoloader.
require_once 'vendor/autoload.php';
require_once 'bootstrap.php';

/*
 * If running as a standalone plugin, load a default configuration and start the plugin.
 */
if (CASEPROOF_GT_STANDALONE) {
    // Start the plugin.
    \PrettyLinks\Caseproof\GrowthTools\instance(
        [
            'file'       => __FILE__,
            'instanceId' => defined('CASEPROOF_GT_STANDALONE_INSTANCE_ID') ? CASEPROOF_GT_STANDALONE_INSTANCE_ID : '',
        ]
    );
}
