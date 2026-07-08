<?php

declare(strict_types=1);

namespace PrettyLinks\Rest\Controllers;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Per-user UI preference storage.
 *
 * The Links list "Screen Options"-style column picker persists each user's
 * visible-column set here. Values round-trip as JSON in a single user meta
 * row per preference key. Meta keys are namespaced `prli_pref_<key>` to
 * avoid collision with other plugins' usermeta.
 *
 * Only logged-in users with the plugin capability may read/write their own
 * preferences; there's no per-user introspection of other users' settings.
 */
class PreferencesController extends BaseController
{
    /**
     * Meta key prefix. Stored as `prli_pref_<key>` in `wp_usermeta`.
     */
    private const META_PREFIX = 'prli_pref_';

    /**
     * Hard cap on preference key length. Matches WP's usermeta key column
     * budget with room to spare; also limits URL length on GET/PUT.
     */
    private const MAX_KEY_LEN = 64;

    /**
     * Register the preferences routes under `/wp-json/pretty-links/v1`.
     */
    public function register(): void
    {
        register_rest_route($this->namespace(), '/preferences/(?P<key>[A-Za-z0-9_\-]+)', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'show'],
                'permission_callback' => $this->permission(),
            ],
            [
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => [$this, 'update'],
                'permission_callback' => $this->permission(),
            ],
        ]);
    }

    /**
     * GET handler — return the JSON-decoded preference value, or 404 when
     * the user has never saved this key.
     *
     * @param WP_REST_Request $request The incoming REST request.
     *
     * @return WP_REST_Response
     */
    public function show(WP_REST_Request $request): WP_REST_Response
    {
        $userId = get_current_user_id();
        if ($userId <= 0) {
            return new WP_REST_Response(['error' => 'not_logged_in'], 401);
        }
        $key = $this->sanitizeKey((string) $request['key']);
        if ($key === '') {
            return new WP_REST_Response(['error' => 'invalid_key'], 400);
        }
        $metaKey = self::META_PREFIX . $key;
        $exists  = metadata_exists('user', $userId, $metaKey);
        if (!$exists) {
            return new WP_REST_Response(['error' => 'not_found'], 404);
        }
        $raw     = (string) get_user_meta($userId, $metaKey, true);
        $decoded = json_decode($raw, true);
        return new WP_REST_Response(['value' => $decoded]);
    }

    /**
     * PUT/POST handler — replace the preference value. Body must be JSON
     * with a `value` member; the value is sanitized per-key (see
     * `sanitizeValue()`) before being JSON-encoded into user meta.
     *
     * @param WP_REST_Request $request The incoming REST request.
     *
     * @return WP_REST_Response
     */
    public function update(WP_REST_Request $request): WP_REST_Response
    {
        $userId = get_current_user_id();
        if ($userId <= 0) {
            return new WP_REST_Response(['error' => 'not_logged_in'], 401);
        }
        $key = $this->sanitizeKey((string) $request['key']);
        if ($key === '') {
            return new WP_REST_Response(['error' => 'invalid_key'], 400);
        }
        $body = (array) $request->get_json_params();
        if (!array_key_exists('value', $body)) {
            return new WP_REST_Response(['error' => 'value_required'], 400);
        }
        $value   = $this->sanitizeValue($key, $body['value']);
        $metaKey = self::META_PREFIX . $key;
        $encoded = (string) wp_json_encode($value);
        update_user_meta($userId, $metaKey, wp_slash($encoded));
        return new WP_REST_Response(['value' => $value]);
    }

    /**
     * Strict key sanitization: lowercase alphanumerics, underscores, and
     * dashes only; max 64 chars. Returns '' on invalid input so the
     * caller can 400 the request.
     *
     * @param string $key The raw preference key.
     *
     * @return string
     */
    private function sanitizeKey(string $key): string
    {
        $key = strtolower(trim($key));
        if ($key === '' || strlen($key) > self::MAX_KEY_LEN) {
            return '';
        }
        if (!preg_match('/^[a-z0-9_\-]+$/', $key)) {
            return '';
        }
        return $key;
    }

    /**
     * Keys we know about get typed sanitization. Unknown keys fall through
     * as a best-effort JSON-safe recursive scalar/array copy so forward-
     * compat UI code can add preferences without also touching this file.
     *
     * @param  string $key   Sanitized preference key.
     * @param  mixed  $value Raw value from the request body.
     * @return mixed         Sanitized value ready for JSON encoding.
     */
    private function sanitizeValue(string $key, $value)
    {
        if ($key === 'links_visible_columns') {
            if (!is_array($value)) {
                return [];
            }
            $out = [];
            foreach ($value as $col) {
                $col = sanitize_key((string) $col);
                if ($col !== '' && !in_array($col, $out, true)) {
                    $out[] = $col;
                }
            }
            return $out;
        }
        if ($key === 'links_per_page') {
            $allowed = [10, 20, 50, 100];
            $int     = (int) $value;
            return in_array($int, $allowed, true) ? $int : 20;
        }
        if ($key === 'clicks_per_page') {
            $allowed = [10, 25, 50, 100];
            $int     = (int) $value;
            return in_array($int, $allowed, true) ? $int : 25;
        }
        return $this->jsonSafe($value);
    }

    /**
     * Recursive scalar/array sanitizer for unknown preference keys.
     *
     * @param  mixed $value Arbitrary JSON-decoded value.
     * @return mixed        Sanitized copy, JSON-safe.
     */
    private function jsonSafe($value)
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[is_int($k) ? $k : sanitize_key((string) $k)] = $this->jsonSafe($v);
            }
            return $out;
        }
        if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
            return $value;
        }
        return sanitize_text_field((string) $value);
    }
}
