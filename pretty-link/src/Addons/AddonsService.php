<?php

declare(strict_types=1);

namespace PrettyLinks\Addons;

use PrettyLinks\Licensing\LicenseClient;
use PrettyLinks\Licensing\LicenseManager;

/**
 * Fetches the available add-ons from the Mothership and caches them.
 *
 * Mirrors the v3 `PrliUpdateController::addons()` protocol:
 *   GET {mothership}/versions/addons/{edition}/{license}?domain=&all=&edge=
 *
 * Cached in the `prli_addons` (or `prli_all_addons`) site transient for 12h,
 * matching v3's transient names so any external code still hits the same bag.
 */
class AddonsService
{
    public const TRANSIENT_ALL      = 'prli_all_addons';
    public const TRANSIENT_FILTERED = 'prli_addons';

    private LicenseClient $client;

    public function __construct(LicenseClient $client)
    {
        $this->client = $client;
    }

    /**
     * Return the add-on list as an array of objects (matching v3 return shape
     * when `$returnObject = true`).
     *
     * @return array<int|string, object>
     */
    public function getAddons(bool $force = false, bool $all = true): array
    {
        $transient = $all ? self::TRANSIENT_ALL : self::TRANSIENT_FILTERED;

        if ($force) {
            delete_site_transient($transient);
        }

        $cached = get_site_transient($transient);
        if (is_string($cached) && $cached !== '') {
            $decoded = json_decode($cached);
            return self::normalize($decoded);
        }

        $license = (string) get_option(LicenseManager::OPTION_LICENSE_KEY, '');
        $addons  = [];

        if ($license !== '') {
            $args = [
                'domain' => rawurlencode(LicenseClient::siteDomain()),
            ];
            if ($all) {
                $args['all'] = 'true';
            }
            if (defined('PRETTYLINK_EDGE') && constant('PRETTYLINK_EDGE')) {
                $args['edge'] = 'true';
            }

            $response = $this->client->addons($license, $args);
            if (is_array($response) && !isset($response['error'])) {
                $addons = $response;
            }
        }

        $json = (string) wp_json_encode($addons);
        set_site_transient($transient, $json, HOUR_IN_SECONDS * 12);

        return self::normalize(json_decode($json));
    }

    /**
     * @param  mixed $decoded
     * @return array<int|string, object>
     */
    private static function normalize($decoded): array
    {
        if (is_array($decoded)) {
            return $decoded;
        }
        if (is_object($decoded)) {
            return (array) $decoded;
        }
        return [];
    }
}
