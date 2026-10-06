<?php

declare(strict_types=1);

namespace PrettyLinks\Addons;

use WP_Error;
use WP_Upgrader_Skin;

/**
 * WP_Upgrader skin for silent, programmatic add-on installations.
 *
 * Swallows all HTML/feedback output and *collects* errors instead of printing
 * them. The installer runs inside the REST controller
 * ({@see \PrettyLinks\Rest\Controllers\AddonsController::install()}), which
 * reads the upgrader's return value (and {@see self::getErrors()}) and emits a
 * proper REST error response. It must NOT call wp_send_json_error()/die() —
 * that would terminate the request mid-flight and bypass REST error handling.
 */
class AddonInstallSkin extends WP_Upgrader_Skin
{
    /**
     * Collected error messages.
     *
     * @var array<int, string>
     */
    public array $errors = [];

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
     * Collect errors rather than rendering or sending them. WP_Upgrader passes
     * either a WP_Error or a string here.
     *
     * @param string|WP_Error|array<mixed> $errors
     */
    public function error($errors)
    {
        if (is_wp_error($errors)) {
            foreach ($errors->get_error_messages() as $message) {
                $this->errors[] = (string) $message;
            }
            return;
        }
        if (is_string($errors) && $errors !== '') {
            $this->errors[] = $errors;
        }
    }

    /**
     * The collected error messages, if any.
     *
     * @return array<int, string>
     */
    public function getErrors(): array
    {
        return $this->errors;
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
