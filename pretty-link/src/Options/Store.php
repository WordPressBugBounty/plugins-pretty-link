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
            // Menu placement: when true the Links list becomes the first
            // item under the Pretty Links parent, which also makes the
            // parent itself open the list. The Dashboard keeps working but
            // moves to `admin.php?page=pretty-link-dashboard`, and the links
            // list stays registered at its own slug so existing deep links
            // survive. Off by default - this changes where a long-standing
            // menu click lands, so it is opt-in.
            'links_first_menu_item'        => false,
            'auto_trim_clicks'             => false,
            'auto_trim_window'             => '90d',
            'extended_tracking'            => 'normal',
            // Record the click synchronously during the redirect instead of
            // deferring it to a shutdown function. Off by default: the deferred
            // write runs after the response is flushed, so it adds no visitor
            // latency. Turn it on only as a compatibility escape hatch — another
            // plugin that calls exit() in an earlier-registered shutdown
            // function (e.g. some security/logging plugins) aborts PHP's
            // shutdown queue and silently drops our deferred write. See
            // Engine::scheduleClickWrite(). Does NOT affect the standalone
            // (mu-plugin) redirect path — disable that separately if needed.
            'synchronous_click_tracking'   => false,
            'enable_bot_filter'            => true,
            'filter_robots'                => false,
            'bot_patterns'                 => '',
            'prli_exclude_ips'             => '',
            'whitelist_ips'                => '',
            'anonymize_ips'                => false,
            'activation_complete'          => false,
            // V3 slug generation options.
            'num_slug_chars'               => 4,
            // Preserve literal spaces in slugs instead of normalising them to
            // dashes on save — v3 parity (#751). Off by default; the migrator
            // flips it on for sites that already have space-containing slugs,
            // and the Options UI only surfaces the toggle for those sites.
            'allow_slug_spaces'            => false,
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
            // A v3 site that hasn't saved v4 Options has no enable_bot_filter
            // yet, and BotDetector (and the turbo drop-in) honor v3's
            // filter_robots then. Report that same effective value (#899).
            // Keep the `(bool)` cast identical to those two copies; the
            // drop-in loads before the autoloader, so it can't call Store.
            if (!array_key_exists('enable_bot_filter', $stored) && array_key_exists('filter_robots', $stored)) {
                $this->cache['enable_bot_filter'] = (bool) $stored['filter_robots'];
            }
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
        $this->persist([$key => $value]);
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
        $this->persist($values);
    }

    /**
     * Write the given keys into the stored blob, leaving untouched keys absent.
     *
     * Deliberately merges over what is actually STORED, not over
     * `all()` — which is the defaults merged with the stored blob. Writing that
     * back turned every default into an explicitly-stored value on the first
     * write of any single key, and several things read the raw option and treat
     * "key absent" as meaningful:
     *
     *  - `BotDetector::filterEnabled()` falls back to v3's `filter_robots`
     *    only while `enable_bot_filter` is absent (and `all()` reports that
     *    same value). Baking the default in killed that upgrade path on the
     *    first option write.
     *  - Pro's `migrateNumSlugChars()` copies the v3 Pro value across only
     *    while the Lite blob has no `num_slug_chars`. The Lite migrator's
     *    `initSlugSpaceCompat()` writes at `after_setup_theme:1`, before Pro
     *    boots, so the default 4 was already baked in by the time the
     *    migration looked — and a v3 site's configured slug length was lost.
     *
     * Reads are unaffected: the cache is cleared, so the next `all()` layers
     * the defaults on top again.
     *
     * @param array<string, mixed> $values Values to write.
     *
     * @return void
     */
    private function persist(array $values): void
    {
        $stored = get_option(self::OPTION, []);
        if (!is_array($stored)) {
            $stored = [];
        }
        $stored = array_merge($stored, $values);
        update_option(self::OPTION, $stored);
        $this->cache = null;
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
