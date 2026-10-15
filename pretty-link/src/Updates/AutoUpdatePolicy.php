<?php

declare(strict_types=1);

namespace PrettyLinks\Updates;

use PrettyLinks\GroundLevel\Mothership\AbstractPluginConnection;

defined('ABSPATH') || exit;

/**
 * Gives the site's own "Enable auto-updates" toggle the last word.
 *
 * Ground Level's UpdateService answers `auto_update_plugin` for every slug it
 * registers, and its policy enum has three values: `all` (always yes), `minor`
 * (yes for same-major offers) and `none` (always no). All three are hard
 * answers — {@see \PrettyLinks\GroundLevel\Mothership\UpdateService::shouldAutoUpdate()}
 * returns a boolean for each, never the incoming `$update`. There is no
 * "defer to the site" value, so whichever one a plugin picks, the moment a
 * licence is activated the Plugins-screen checkbox stops meaning anything:
 * GL's own default (`minor`) silently switches auto-updates ON, and `none`
 * silently switches them OFF.
 *
 * Lite has no licence, so GL returns `$update` untouched there and the toggle
 * works. The bug is that activating a licence changes the answer.
 *
 * So `none` — {@see \PrettyLinks\Licensing\MothershipConnector::automaticUpdates()}'s
 * default — is reinterpreted here as "we have no opinion": this filter runs
 * after GL's and hands back exactly the decision the chain had reached before
 * GL spoke, which on an unmodified site is the value core seeded from
 * `auto_update_plugins`. A site that deliberately filters
 * `pretty-link_automatic_updates` to `all` or `minor` still gets GL's hard
 * answer, because that is an opinion someone asked for.
 *
 * Only slugs registered with GL are restored — the host plugin and any add-on
 * Bootstrap arms — so this never speaks for another plugin's rows.
 *
 * Separately, GL's callback is typed `: ?bool` under strict_types but hands
 * back the incoming `$update` for every plugin that isn't ours. Core doesn't
 * require a boolean here, so one string ("1") from anywhere earlier in the
 * chain is an uncaught TypeError and a dead site. {@see self::coerce()} sits
 * directly in front of GL's callback and turns such values into the boolean
 * core would have read them as. Remove it once GL ships the fix
 * (caseproof/ground-level-php#517).
 */
class AutoUpdatePolicy
{
    /**
     * Runs before GL's callback, which sits at the default priority.
     *
     * @var integer
     */
    private const PRIORITY_CAPTURE = 1;

    /**
     * Runs after it.
     *
     * @var integer
     */
    private const PRIORITY_RESTORE = 11;

    /**
     * GL adds its `auto_update_plugin` callbacks from `init` at this priority.
     *
     * @var integer
     */
    private const GL_REGISTER_PRIORITY = 5;

    /**
     * The plugin connection, which owns the policy value.
     *
     * @var AbstractPluginConnection
     */
    private $connection;

    /**
     * Slugs registered with GL's UpdateService, keyed for lookup.
     *
     * @var array<string, true>
     */
    private $slugs = [];

    /**
     * The decision each offer carried into GL's callback, keyed by plugin file.
     *
     * @var array<string, boolean|null>
     */
    private $incoming = [];

    /**
     * Constructor.
     *
     * @param AbstractPluginConnection $connection The plugin connection.
     */
    public function __construct(AbstractPluginConnection $connection)
    {
        $this->connection = $connection;
    }

    /**
     * Declares a slug as one GL answers for.
     *
     * @param  string $slug The slug passed to UpdateService::plugin().
     * @return void
     */
    public function claim(string $slug): void
    {
        if ($slug !== '') {
            $this->slugs[$slug] = true;
        }
    }

    /**
     * Registers both halves of the capture/restore pair, and the coercion
     * that keeps GL's callback from fataling.
     *
     * @return void
     */
    public function register(): void
    {
        add_filter('auto_update_plugin', [$this, 'capture'], self::PRIORITY_CAPTURE, 2);
        add_filter('auto_update_plugin', [$this, 'restore'], self::PRIORITY_RESTORE, 2);
        add_action('init', [$this, 'registerCoercion'], self::GL_REGISTER_PRIORITY - 1);
    }

    /**
     * Adds the coercion at GL's priority just before GL adds its own callback.
     *
     * Callbacks at one priority run in the order they were added, so this runs
     * after everything plugins, mu-plugins and the theme hooked at the default
     * priority while loading, and right before GL's.
     *
     * @return void
     */
    public function registerCoercion(): void
    {
        add_filter('auto_update_plugin', [$this, 'coerce']);
    }

    /**
     * Turns a non-boolean decision into the boolean core would read it as.
     *
     * @param  mixed $update The incoming auto-update decision.
     * @return boolean|null The decision, as a boolean or null.
     */
    public function coerce($update)
    {
        if ($update === null || is_bool($update)) {
            return $update;
        }

        return (bool) $update;
    }

    /**
     * Remembers the decision an offer carried in.
     *
     * @param  boolean|null $update The incoming auto-update decision.
     * @param  mixed        $item   The update offer.
     * @return boolean|null The decision, untouched.
     */
    public function capture($update, $item)
    {
        $key = $this->offerKey($item);
        if ($key !== '') {
            $this->incoming[$key] = $update;
        }

        return $update;
    }

    /**
     * Hands back the captured decision for our own offers under the `none`
     * policy.
     *
     * @param  boolean|null $update The decision GL left behind.
     * @param  mixed        $item   The update offer.
     * @return boolean|null The site's own decision, or $update.
     */
    public function restore($update, $item)
    {
        $slug = is_object($item) && isset($item->slug) ? (string) $item->slug : '';
        if ($slug === '' || !isset($this->slugs[$slug])) {
            return $update;
        }

        if ($this->connection->automaticUpdates() !== AbstractPluginConnection::AUTOMATIC_UPDATE_NONE) {
            return $update;
        }

        $key = $this->offerKey($item);

        return array_key_exists($key, $this->incoming) ? $this->incoming[$key] : $update;
    }

    /**
     * Identifies an offer across the two passes.
     *
     * @param  mixed $item The update offer.
     * @return string The plugin file, or an empty string when the offer has none.
     */
    private function offerKey($item): string
    {
        if (!is_object($item)) {
            return '';
        }

        foreach (['plugin', 'id', 'slug'] as $property) {
            if (!empty($item->$property)) {
                return (string) $item->$property;
            }
        }

        return '';
    }
}
