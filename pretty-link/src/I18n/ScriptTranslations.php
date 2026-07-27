<?php

declare(strict_types=1);

namespace PrettyLinks\I18n;

/**
 * Bridges Loco Translate / WordPress.org JavaScript translation files to the
 * plugin's built script bundles.
 *
 * `@wordpress/i18n` strings live in the React source (`src-js/...`), so every
 * translation tool emits one JSON file per SOURCE file, named after a hash of
 * that source path (e.g. `pretty-link-fr_FR-<md5(src-js/.../Header.js)>.json`).
 * At runtime, however, WordPress enqueues the compiled bundles
 * (`assets/js/build/<bundle>.js`) and looks for a single JSON named after a
 * hash of the BUILT path — which no tool produces. The result: PHP strings
 * translate but the React UI stays in English.
 *
 * Rather than force the build/POT pipeline to rewrite references (which would
 * only fix the WP.org/CLI path and never Loco), this collects every
 * per-source JSON for the domain + locale and merges them into one locale-data
 * payload, returned via `pre_load_script_translations` for any of our handles.
 * Over-inclusion is harmless — extra msgids in a bundle's locale data are
 * simply unused.
 */
class ScriptTranslations
{
    /**
     * The Lite text domain.
     */
    private const DOMAIN = 'pretty-link';

    /**
     * Merged locale-data payloads, keyed by "domain|locale".
     *
     * @var array<string, string|null>
     */
    private static $cache = [];

    /**
     * `pre_load_script_translations` callback for the Lite domain.
     *
     * @param  string|false|null $pre    Short-circuit value (null to defer to core).
     * @param  string|false      $file   Path core computed (unused — we key on the handle's domain).
     * @param  string            $handle Script handle.
     * @param  string            $domain Text domain.
     * @return string|false|null JSON-encoded locale data, or the untouched $pre.
     */
    public static function merge($pre, $file, string $handle, string $domain)
    {
        if ($pre !== null || $domain !== self::DOMAIN) {
            return $pre;
        }

        return self::localeData(self::DOMAIN);
    }

    /**
     * Build the merged locale-data JSON for a domain in the current locale.
     *
     * Domain-agnostic so Pro can reuse it for `pretty-link-pro` without Lite
     * ever naming the Pro domain.
     *
     * @param  string $domain Text domain to gather JSON files for.
     * @return string|null JSON payload, or null when no translations exist.
     */
    public static function localeData(string $domain)
    {
        $locale   = determine_locale();
        $cacheKey = $domain . '|' . $locale;

        if (array_key_exists($cacheKey, self::$cache)) {
            return self::$cache[$cacheKey];
        }

        $dirs = [
            WP_LANG_DIR . '/plugins',
            // Loco Translate's "Custom" save location.
            WP_LANG_DIR . '/loco/plugins',
        ];
        if (defined('PRLI_FILE')) {
            $dirs[] = plugin_dir_path(PRLI_FILE) . 'languages';
        }

        $messages = [];
        $header   = null;

        foreach ($dirs as $dir) {
            $files = glob($dir . '/' . $domain . '-' . $locale . '-*.json');

            foreach (($files ?: []) as $path) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local translation JSON, not a remote URL.
                $raw = file_get_contents($path);
                if ($raw === false) {
                    continue;
                }

                $data = json_decode($raw, true);
                if (!is_array($data) || empty($data['locale_data']['messages'])) {
                    continue;
                }

                $block = $data['locale_data']['messages'];
                if ($header === null && isset($block[''])) {
                    $header = $block[''];
                }
                unset($block['']);

                // Same msgid always carries the same translation across bundles,
                // so first-writer-wins is safe.
                $messages += $block;
            }
        }

        if (empty($messages)) {
            self::$cache[$cacheKey] = null;
            return null;
        }

        $messages[''] = $header !== null
            ? $header
            : [
                'domain'       => 'messages',
                'plural-forms' => 'nplurals=2; plural=(n != 1);',
            ];

        self::$cache[$cacheKey] = wp_json_encode(
            [
                'domain'      => 'messages',
                'locale_data' => ['messages' => $messages],
            ]
        );

        return self::$cache[$cacheKey];
    }
}
