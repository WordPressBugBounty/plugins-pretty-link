<?php

declare(strict_types=1);

namespace PrettyLinks\Options;

/**
 * Reads and writes the Lite option blob `prli_options`.
 *
 * Preserves every 3.x field name — see inventory 05-lite-options.md and
 * the back-compat checklist in REWRITE-PLAN.md Appendix A.
 *
 * Owns only Lite's own options. Extensions that need separate option
 * storage are expected to manage their own blob.
 */
class Store
{
    public const OPTION = 'prli_options';

    /**
     * In-memory cache of the merged option blob.
     *
     * @var array<string, mixed>|null
     */
    private ?array $cache = null;

    /**
     * Lite option defaults. Keys preserved from v3.x.
     *
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'link_redirect_type'           => '302',
            'link_nofollow'                => true,
            'link_sponsored'               => false,
            'link_track_me'                => true,
            'auto_trim_clicks'             => false,
            'auto_trim_window'             => '90d',
            'extended_tracking'            => 'normal',
            'enable_bot_filter'            => true,
            'filter_robots'                => false,
            'bot_patterns'                 => '',
            'prli_exclude_ips'             => '',
            'whitelist_ips'                => '',
            'anonymize_ips'                => false,
            'activation_complete'          => false,
            // V3 slug generation options.
            'num_slug_chars'               => 4,
            // Site-specific extra patterns the slug-validation pass blocks
            // on top of the WP-core defaults baked into ReservedSlugs.
            // Newline- or comma-separated; shell-style wildcards (`*`)
            // supported. Always honored for `'user'`/`'public'` sources;
            // honored for `'admin'` too when the bypass flag below is off.
            'reserved_slug_patterns'       => '',
            // Whether admin-source link creation bypasses the reserved
            // patterns. Default true (matches v3 + pre-extraction v4
            // behavior — admins are trusted). Site owners can flip it off
            // to enforce the pattern list site-wide.
            'reserved_slugs_admins_bypass' => true,
            // Bookmarklet-redirect verification token (v3 shape: inside
            // prli_options). Set on first use by Tools\Bookmarklet.
            'bookmarklet_auth'             => '',
            // PrettyPay lite-option defaults (v3 parity — REST layer
            // already reads/writes but Store had no defaults).
            'prettypay_thank_you_page_id'  => 0,
            'prettypay_default_currency'   => 'USD',
            // Master switch for the PrettyPay feature. Default on so sites
            // upgrading from v3 keep their existing pay links visible. When
            // toggled off, the admin UI (menu entry, admin-bar shortcut,
            // PrettyPay section cues) is hidden; existing pay links continue
            // to resolve at the engine level.
            'prettypay_enabled'            => true,
        ];
    }

    /**
     * Returns the full option blob merged over the defaults.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        if ($this->cache === null) {
            $stored = get_option(self::OPTION, []);
            if (!is_array($stored)) {
                $stored = [];
            }
            $this->cache = array_merge(self::defaults(), $stored);
        }
        return $this->cache;
    }

    /**
     * Returns a single option value, or the default when absent.
     *
     * @param string $key     Option key to read.
     * @param mixed  $default Value returned when the key is absent.
     *
     * @return mixed Stored option value or the default.
     */
    public function get(string $key, $default = null)
    {
        $all = $this->all();
        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    /**
     * Stores a single option value and persists the blob.
     *
     * @param string $key   Option key to write.
     * @param mixed  $value Value to store.
     *
     * @return void
     */
    public function set(string $key, $value): void
    {
        $all       = $this->all();
        $all[$key] = $value;
        update_option(self::OPTION, $all);
        $this->cache = $all;
    }

    /**
     * Merges the given values into the option blob and persists it.
     *
     * @param array<string, mixed> $values Values to merge over the current blob.
     *
     * @return void
     */
    public function merge(array $values): void
    {
        $all = array_merge($this->all(), $values);
        update_option(self::OPTION, $all);
        $this->cache = $all;
    }

    /**
     * Clears the in-memory cache so the next read reloads from storage.
     *
     * @return void
     */
    public function forget(): void
    {
        $this->cache = null;
    }
}
