<?php

declare(strict_types=1);

namespace PrettyLinks\Updates;

defined('ABSPATH') || exit;

/**
 * Keeps the "View details" modal from coming back blank.
 *
 * Ground Level's mothership package answers `plugins_api` for our slug and fills the
 * payload — name, version, last_updated, download_link, sections — only when
 * its version check succeeds. It returns the object either way. Core treats
 * ANY non-false return from `plugins_api` as authoritative and stops there, so
 * whenever the licensing server is unreachable, the license is inactive, or
 * the product lookup fails, the user gets a modal containing nothing but a
 * slug. v5 returned the untouched `$result` on error, so WordPress fell
 * through to wp.org and showed the real listing.
 *
 * GL runs its payload through the `{slug}_plugin_information` filter before
 * returning it, which is enough to restore that: returning false from the
 * filter makes GL's own callback return false, and core carries on to wp.org.
 *
 * This lives in Lite deliberately. The `plugins_api` registration is not gated
 * by the `Update URI` header, so it is live in the free build too — where
 * nothing else hooks that filter, and the blank modal is all a user would ever
 * see.
 */
class PluginInformation
{
    /**
     * Registers the guard.
     *
     * Priority 5 so it runs before Pro's UpdateChecker enrichment at 10. When
     * this bails out to false, that enrichment sees a non-object and passes it
     * straight through.
     *
     * @return void
     */
    public function register(): void
    {
        add_filter('pretty-link_plugin_information', [$this, 'discardEmptyPayload'], 5);
    }

    /**
     * Falls back to WordPress.org when GL's lookup produced nothing usable.
     *
     * @param  mixed $info The plugin information object GL assembled.
     * @return mixed The object when it carries real data, false otherwise.
     */
    public function discardEmptyPayload($info)
    {
        if (!is_object($info)) {
            return $info;
        }

        // GL sets every one of these together, and only on a successful check.
        // A payload with none of them is the failure shape — nothing but the
        // slug it was seeded with.
        if (
            empty($info->version)
            && empty($info->download_link)
            && empty($info->sections)
            && empty($info->name)
        ) {
            return false;
        }

        return $info;
    }
}
