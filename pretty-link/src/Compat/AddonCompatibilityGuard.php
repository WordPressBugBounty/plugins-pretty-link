<?php

declare(strict_types=1);

namespace PrettyLinks\Compat;

/**
 * Deactivates Pretty Links add-ons that ship a pre-4.0 build.
 *
 * The legacy (.org) add-on builds predate the 4.0 rewrite and bind to v3
 * internals — CPTs, namespaces, and helper classes that no longer exist. Left
 * active against a 4.0 core they fatal as soon as their deferred hooks fire.
 *
 * Detection runs on `plugins_loaded` at `PHP_INT_MIN` — the first callback of
 * the entire request, before any add-on's `plugins_loaded`/`init`/`admin_init`
 * callbacks have fired. At that point the add-ons have *registered* their hooks
 * (during the plugin-include phase) but none have run yet, and the add-ons do
 * nothing dangerous at include time — every removed-v3-API reference lives
 * inside a deferred hook callback. So in one pass we:
 *
 *   1. `deactivate_plugins()` the incompatible add-ons so they don't load on any
 *      future request, and
 *   2. strip their still-pending callbacks out of `$wp_filter` for the current
 *      request, by removing every registered hook whose callback is defined in a
 *      flagged add-on's directory. Because nothing they hooked has fired yet,
 *      this prevents the fatal on this request too — in every context (admin,
 *      front-end, AJAX, REST, cron), with no redirect.
 *
 * When anything is deactivated we persist the affected add-on names to an
 * option and surface a dismissible admin notice on every admin screen (not just
 * Pretty Links pages) telling the owner the add-on must be updated before it can
 * be reactivated.
 */
class AddonCompatibilityGuard
{
    /**
     * Stores the display names of add-ons deactivated by the guard so the
     * notice survives the next request (detection only fires while the add-on
     * is still active). Cleared on dismiss.
     */
    private const NOTICE_OPTION = 'prli_incompatible_addons_deactivated';

    /**
     * Query arg carrying the nonce-protected dismiss request.
     */
    private const DISMISS_ARG = 'prli_dismiss_addon_compat';

    /**
     * Nonce action for the dismiss link.
     */
    private const DISMISS_NONCE = 'prli_dismiss_addon_compat';

    /**
     * Lowest add-on version compatible with this core. v4 add-ons report
     * 4.0.0+; the legacy .org builds report 1.x/2.x, so version_compare cleanly
     * separates them.
     */
    private const MIN_VERSION = '4.0.0';

    /**
     * Capability gate for seeing and dismissing the notice.
     */
    private const CAPABILITY = 'activate_plugins';

    /**
     * Add-on plugin basenames we police. Only the three add-ons with legacy
     * (pre-4.0) builds in the wild are listed; the greenfield add-ons
     * (splash-pages, user-links, utms) are v4-only and have no incompatible
     * build to catch.
     *
     * @return string[]
     */
    private static function knownAddons(): array
    {
        return [
            'pretty-link-developer-tools/pretty-link-developer-tools.php',
            'pretty-link-product-displays/pretty-link-product-displays.php',
            'pretty-link-link-in-bio/pretty-link-link-in-bio.php',
        ];
    }

    /**
     * Detect and deactivate incompatible add-ons. Hooked on `plugins_loaded`
     * priority 1.
     */
    public static function enforce(): void
    {
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $incompatible = []; // Basename => display name.
        foreach (self::knownAddons() as $basename) {
            if (!is_plugin_active($basename)) {
                continue;
            }

            $file = WP_PLUGIN_DIR . '/' . $basename;
            if (!is_readable($file)) {
                continue;
            }

            $data    = get_file_data($file, [
                'Name'    => 'Name',
                'Version' => 'Version',
            ]);
            $version = isset($data['Version']) ? (string) $data['Version'] : '';

            // No version header → can't prove it's incompatible, leave it alone.
            if ($version === '' || version_compare($version, self::MIN_VERSION, '>=')) {
                continue;
            }

            $name                    = isset($data['Name']) && $data['Name'] !== ''
                ? (string) $data['Name']
                : $basename;
            $incompatible[$basename] = $name;
        }

        if (empty($incompatible)) {
            return;
        }

        // Silent: skip the add-on's own deactivation hooks, which may reference
        // the same removed v3 code we're protecting against.
        deactivate_plugins(array_keys($incompatible), true);

        update_option(self::NOTICE_OPTION, array_values($incompatible), false);

        // Note: deactivate_plugins() only stops future requests; it does not
        // unhook the callbacks the add-ons already registered this request.
        // Strip those now — none have fired yet at PHP_INT_MIN — so the stale
        // add-on can't fatal on this load.
        self::neutralizeCurrentRequest(array_keys($incompatible));
    }

    /**
     * Remove every still-pending hook callback that belongs to a deactivated
     * add-on so its deferred callbacks never fire on the current request.
     *
     * Safe because we run at `plugins_loaded:PHP_INT_MIN`, before any add-on
     * callback has fired: the only thing in `$wp_filter` from these add-ons is
     * what they registered at include time, and none of it has run yet. Removing
     * it prevents the fatal their `init`/`plugins_loaded`/etc. callbacks would
     * otherwise cause when they touch removed v3 APIs.
     *
     * @param string[] $basenames Plugin basenames of the deactivated add-ons.
     */
    private static function neutralizeCurrentRequest(array $basenames): void
    {
        $dirs = [];
        foreach ($basenames as $basename) {
            $dir    = WP_PLUGIN_DIR . '/' . dirname($basename);
            $real   = realpath($dir);
            $dirs[] = wp_normalize_path(rtrim($real !== false ? $real : $dir, '/')) . '/';
        }

        global $wp_filter;
        if (empty($wp_filter) || ! is_array($wp_filter)) {
            return;
        }

        // Collect first, remove after: remove_filter() mutates the WP_Hook
        // callbacks array we'd otherwise be iterating.
        $removals = [];
        foreach ($wp_filter as $hookName => $hook) {
            if (! ($hook instanceof \WP_Hook)) {
                continue;
            }
            foreach ($hook->callbacks as $priority => $callbacks) {
                foreach ($callbacks as $registered) {
                    $callback = $registered['function'] ?? null;
                    if ($callback === null) {
                        continue;
                    }
                    $file = self::callbackFile($callback);
                    if ($file !== null && self::fileInDirs($file, $dirs)) {
                        $removals[] = [$hookName, $callback, $priority];
                    }
                }
            }
        }

        foreach ($removals as $removal) {
            remove_filter($removal[0], $removal[1], $removal[2]);
        }
    }

    /**
     * Resolve the file a hook callback is defined in, or null if it can't be
     * determined. Reflection reads metadata only — it never invokes the
     * callback, so it cannot trigger the very fatal we're preventing.
     *
     * @param  mixed $callback A registered hook callback.
     * @return string|null
     */
    private static function callbackFile($callback): ?string
    {
        try {
            if ($callback instanceof \Closure) {
                $ref = new \ReflectionFunction($callback);
            } elseif (is_array($callback) && count($callback) === 2) {
                $ref = new \ReflectionMethod($callback[0], $callback[1]);
            } elseif (is_string($callback) && strpos($callback, '::') !== false) {
                [$class, $method] = explode('::', $callback, 2);
                $ref              = new \ReflectionMethod($class, $method);
            } elseif (is_string($callback)) {
                $ref = new \ReflectionFunction($callback);
            } elseif (is_object($callback)) {
                $ref = new \ReflectionMethod($callback, '__invoke');
            } else {
                return null;
            }
        } catch (\Throwable $e) {
            return null;
        }

        $file = $ref->getFileName();
        return $file !== false ? $file : null;
    }

    /**
     * Whether a file path lives inside any of the given normalized directories.
     *
     * @param  string   $file Absolute file path.
     * @param  string[] $dirs Normalized directory prefixes (each trailing-slashed).
     * @return boolean
     */
    private static function fileInDirs(string $file, array $dirs): bool
    {
        $real = realpath($file);
        $file = wp_normalize_path($real !== false ? $real : $file);
        foreach ($dirs as $dir) {
            if (strpos($file, $dir) === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Handle the nonce-protected dismiss link. Hooked on `admin_init`.
     */
    public static function maybeDismiss(): void
    {
        if (!isset($_GET[self::DISMISS_ARG]) || !current_user_can(self::CAPABILITY)) {
            return;
        }

        $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';
        if (!wp_verify_nonce($nonce, self::DISMISS_NONCE)) {
            return;
        }

        delete_option(self::NOTICE_OPTION);
        wp_safe_redirect(remove_query_arg([self::DISMISS_ARG, '_wpnonce']));
        exit;
    }

    /**
     * Register the notice renderer. Hooked on `in_admin_header` priority 2 so it
     * runs after {@see \PrettyLinks\Admin\Notices::suppressThirdParty()}
     * (priority 1) clears the admin_notices queue on Pretty Links screens —
     * guaranteeing the notice shows on every admin page, including ours.
     */
    public static function registerNotice(): void
    {
        if (!current_user_can(self::CAPABILITY) || empty(self::deactivatedNames())) {
            return;
        }

        add_action('admin_notices', [self::class, 'renderNotice']);
    }

    /**
     * Render the deactivation notice.
     */
    public static function renderNotice(): void
    {
        $names = self::deactivatedNames();
        if (empty($names)) {
            return;
        }

        $intro = _n(
            'The following Pretty Links add-on was deactivated because it is not compatible with Pretty Links 4.0. It must be updated before it can be reactivated:',
            'The following Pretty Links add-ons were deactivated because they are not compatible with Pretty Links 4.0. They must be updated before they can be reactivated:',
            count($names),
            'pretty-link'
        );

        $dismissUrl = wp_nonce_url(add_query_arg(self::DISMISS_ARG, '1'), self::DISMISS_NONCE);

        echo '<div class="notice notice-warning">';
        echo '<p>' . esc_html($intro) . '</p>';
        echo '<ul class="ul-disc">';
        foreach ($names as $name) {
            echo '<li>' . esc_html((string) $name) . '</li>';
        }
        echo '</ul>';
        echo '<p><a href="' . esc_url($dismissUrl) . '">'
            . esc_html__('Dismiss this notice', 'pretty-link')
            . '</a></p>';
        echo '</div>';
    }

    /**
     * Display names of add-ons currently flagged as deactivated.
     *
     * @return string[]
     */
    private static function deactivatedNames(): array
    {
        $names = get_option(self::NOTICE_OPTION, []);
        return is_array($names) ? $names : [];
    }
}
