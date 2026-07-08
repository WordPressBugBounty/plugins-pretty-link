<?php

declare(strict_types=1);

namespace PrettyLinks\Slug;

use PrettyLinks\Options\Store as OptionsStore;
use PrettyLinks\Redirect\ReservedSlugs;
use wpdb;

// Filter handle for the optional admin-side slug prefix. Plugins hook
// `prli_link_prefix_for_source` (with $source === 'admin') to supply one;
// Lite has no built-in prefix concept.

/**
 * Lowercase-alphanumeric slug generator. Honors `num_slug_chars` (default 4).
 *
 * Regenerates on collision with existing slugs or reserved words. Also used
 * by the Admin "generate slug" REST endpoint when the user clicks the
 * regenerate button in the Add/Edit Link form.
 */
class Generator
{
    private const ALPHABET = 'abcdefghijklmnopqrstuvwxyz0123456789';

    private const DEFAULT_LENGTH = 4;

    /**
     * Generates a unique, non-reserved slug for the given source.
     *
     * @param wpdb|null $db     Database access object, or null to use the global $wpdb.
     * @param string    $source One of 'admin' | 'user' | 'public'. Determines
     *                          which prefix gets baked into the slug — admin
     *                          uses the global `base_slug_prefix`; pro's
     *                          per-source filter resolves user/public prefixes.
     *
     * @return string Generated slug, optionally including a source prefix.
     */
    public static function generate(?wpdb $db = null, string $source = 'admin'): string
    {
        $db    = $db ?? $GLOBALS['wpdb'];
        $table = $db->prefix . 'prli_links';

        $store  = new OptionsStore();
        $length = max(2, (int) $store->get('num_slug_chars', self::DEFAULT_LENGTH));

        /**
         * Filter: prli_link_prefix_for_source
         *
         * Per-source URL prefix baked into the generated slug at creation
         * time. Any plugin (Pretty Links Pro, third-party extensions) can
         * hook this and return a prefix for `admin`, `user`, or `public`
         * sources — the stored slug is the full URL path, no runtime
         * prefix concept exists anywhere else. Lite ships no default.
         *
         * @param string $prefix Empty by default.
         * @param string $source One of 'admin' | 'user' | 'public'.
         */
        $prefix = (string) apply_filters('prli_link_prefix_for_source', '', $source);
        $prefix = trim($prefix, '/');

        $slug = '';
        for ($i = 0; $i < 20; $i++) {
            $candidate = self::random($length);
            $full      = $prefix !== '' ? $prefix . '/' . $candidate : $candidate;
            // Only user/public sources are gated against the reserved
            // list; admin-generated slugs can be anything.
            if (ReservedSlugs::isReserved($full, $source)) {
                continue;
            }
            $exists = $db->get_var(
                $db->prepare("SELECT id FROM {$table} WHERE slug = %s LIMIT 1", $full)
            );
            if (!$exists) {
                $slug = $full;
                break;
            }
        }

        if ($slug === '') {
            // Fallback: grow length rather than fail.
            $candidate = self::random($length + 2) . wp_rand(10, 99);
            $slug      = $prefix !== '' ? $prefix . '/' . $candidate : $candidate;
        }

        /**
         * Filter: prli-auto-generated-slug
         *
         * V3 compatibility hook. Fires on every auto-generated slug. Third
         * parties can override the final slug string. v3 passed an array of
         * already-generated slugs as arg 2 (used by batch imports); v4 emits
         * one slug at a time so an empty array is passed to preserve the
         * signature. `$length` matches v3's `num_chars` argument.
         *
         * @param string   $slug   Generated slug (may include prefix).
         * @param string[] $slugs  Empty in v4 (no batch generation).
         * @param int      $length Configured `num_slug_chars`.
         */
        return (string) apply_filters('prli-auto-generated-slug', $slug, [], $length);
    }

    /**
     * Builds a random slug string of the given length from the alphabet.
     *
     * @param integer $length Number of characters to generate.
     *
     * @return string Random slug fragment.
     */
    private static function random(int $length): string
    {
        $alphabet = self::ALPHABET;
        $max      = strlen($alphabet) - 1;
        $out      = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[wp_rand(0, $max)];
        }
        return $out;
    }
}
