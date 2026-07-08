<?php

declare(strict_types=1);

namespace PrettyLinks\Addons;

use WP_Upgrader_Skin;

/**
 * WP_Upgrader skin for on-the-fly add-on installations via AJAX.
 *
 * Ports v3's `PrliAddonInstallSkin` verbatim: swallow HTML output, JSON-encode
 * errors so the AJAX handler can surface them to the client.
 */
class AddonInstallSkin extends WP_Upgrader_Skin
{
    /**
     * @param object $upgrader
     */
    public function set_upgrader(&$upgrader)
    {
        if (is_object($upgrader)) {
            $this->upgrader =& $upgrader;
        }
    }

    public function header()
    {
    }

    public function footer()
    {
    }

    /**
     * @param array<mixed> $errors
     */
    public function error($errors)
    {
        if (!empty($errors)) {
            wp_send_json_error($errors);
        }
    }

    /**
     * @param string $string
     * @param mixed  ...$args
     */
    public function feedback($string, ...$args)
    {
    }

    /**
     * @param string $type
     */
    public function decrement_update_count($type)
    {
    }
}
