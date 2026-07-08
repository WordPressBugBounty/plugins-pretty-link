<?php

declare(strict_types=1);

namespace PrettyLinks\Admin;

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_key
// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_value
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter
// Custom plugin tables (prli_*): table names interpolated from $wpdb->prefix (trusted),
// user values bind through $wpdb->prepare(). No caching: these tables are the source
// of truth for click/redirect data and must read-through. "meta_key"/"meta_value" here
// refer to our own prli_link_metas table, not wp_postmeta.
use PrettyLinks\Licensing\ProState;

/**
 * Review-request eligibility and dismissal.
 *
 * Eligibility is emitted as `reviewAsk: bool` in the JS bootstrap payload
 * (see Assets::bootstrapData). The React component handles rendering,
 * the multi-step flow, and cookie management — PHP is the eligibility gate.
 *
 * Dismiss state lives in per-user meta so each admin independently controls
 * whether they see the notice. The JS also sets a client-side cookie on any
 * dismiss action as a caching safety net: if a cached DB read returns stale
 * data, the cookie prevents re-display on that device for the appropriate
 * window before the cache warms.
 *
 * Dismiss types (via REST POST /review-ask/dismiss):
 *   remove → permanent: usermeta prli_review_prompt_removed = 1, 30-day cookie
 *   delay  → 30-day snooze: usermeta prli_review_prompt_delay = {delayed_until: timestamp}, 30-day cookie
 */
class ReviewNotice
{
    public const META_REMOVED  = 'prli_review_prompt_removed';
    public const META_DELAY    = 'prli_review_prompt_delay';
    public const DELAY_SECONDS = 30 * DAY_IN_SECONDS;

    /**
     * Whether the current user should be shown the review ask.
     *
     * @return boolean
     */
    public static function isEligible(): bool
    {
        // Pro users never see the review ask.
        if (ProState::isProInstalled()) {
            return false;
        }

        if (!current_user_can(Page::capability())) {
            return false;
        }

        $userId = get_current_user_id();
        if (!$userId) {
            return false;
        }

        // Permanently dismissed by this user.
        if (get_user_meta($userId, self::META_REMOVED, true)) {
            return false;
        }

        // Currently in a snooze window.
        $delay = get_user_meta($userId, self::META_DELAY, true);
        if (is_array($delay) && isset($delay['delayed_until']) && (int) $delay['delayed_until'] > time()) {
            return false;
        }

        // At least one click must exist. EXISTS with LIMIT 1 is the cheapest
        // way to confirm the plugin has been used without scanning the table.
        global $wpdb;
        return $wpdb->get_var("SELECT 1 FROM {$wpdb->prefix}prli_clicks LIMIT 1") !== null;
    }

    /**
     * Permanently dismiss or snooze for the current user.
     *
     * @param 'remove'|'delay' $type Dismissal mode: permanent removal or 30-day snooze.
     */
    public static function dismiss(string $type): void
    {
        $userId = get_current_user_id();
        if (!$userId) {
            return;
        }

        if ($type === 'remove') {
            update_user_meta($userId, self::META_REMOVED, 1);
        } else {
            update_user_meta($userId, self::META_DELAY, [
                'delayed_until' => time() + self::DELAY_SECONDS,
            ]);
        }
    }
}
