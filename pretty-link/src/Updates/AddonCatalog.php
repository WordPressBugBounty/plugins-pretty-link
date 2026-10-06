<?php

declare(strict_types=1);

namespace PrettyLinks\Updates;

defined('ABSPATH') || exit;

use PrettyLinks\Bootstrap;
use PrettyLinks\GroundLevel\Mothership\AbstractPluginConnection;
use PrettyLinks\Licensing\ProState;

/**
 * Cache-only reader for the licensed add-on catalog.
 *
 * Both callers here run on paths that must never block: the plugin-details
 * modal, and GL's product registration during boot. `AddonsManager::getAddons()`
 * is *prefer*-cache — it falls through to a paginated network fetch whenever the
 * transient is absent, which on an unlicensed install is always — so neither can
 * use it. This reads the transient and nothing else.
 */
class AddonCatalog
{
    /**
     * Suffix of the transient AddonsManager caches the catalog under, keyed by
     * pluginId. Mirrors its protected CACHE_KEY_ADDONS.
     *
     * @var string
     */
    private const CACHE_SUFFIX = '-mosh-addons';

    /**
     * The cached catalog, or an empty array when there isn't one.
     *
     * @return array<int, object>
     */
    public static function cached(): array
    {
        if (!ProState::isProInstalledAndActivated()) {
            return [];
        }

        try {
            $pluginId = Bootstrap::instance()->container()->get(AbstractPluginConnection::class)->pluginId;
            $products = get_transient($pluginId . self::CACHE_SUFFIX);
        } catch (\Throwable $e) {
            return [];
        }

        return is_array($products) ? $products : [];
    }
}
