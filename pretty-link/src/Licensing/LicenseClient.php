<?php

declare(strict_types=1);

namespace PrettyLinks\Licensing;

/**
 * HTTP client for Caseproof Mothership license endpoints.
 *
 * Preserves the v3.x protocol (see inventory 09-lite-addons-updates.md §6).
 *
 * Endpoints:
 *   POST {domain}/license_keys/activate/{key}   body: { domain, product }
 *   POST {domain}/license_keys/deactivate/{key} body: { domain }
 *   GET  {domain}/license_keys/check/{key}      body: { domain }
 *   POST {domain}/versions/info/{key}           body: { domain, edge? }
 *
 * All calls enforce sslverify=true (fix for v3.x issue).
 * Returns structured arrays; throws \RuntimeException only on transport failure
 * (callers decide whether to surface network errors to the user).
 */
class LicenseClient
{
    public const DEFAULT_DOMAIN = 'https://mothership.caseproof.com';

    public const TIMEOUT = 10;

    /**
     * Resolved mothership base domain.
     *
     * @var string
     */
    private string $domain;

    /**
     * Plugin edition slug used in license requests.
     *
     * @var string
     */
    private string $edition;

    /**
     * Set up the client with the edition slug and optional domain.
     *
     * @param string      $edition The plugin edition slug.
     * @param string|null $domain  Optional mothership domain; resolved from config when null.
     */
    public function __construct(string $edition = 'pretty-link-lite', ?string $domain = null)
    {
        $this->edition = $edition;
        $this->domain  = $domain !== null ? rtrim($domain, '/') : self::resolveDomain();
    }

    /**
     * Activate a license key.
     *
     * @param string $key The license key to activate.
     *
     * @return array<string, mixed>
     */
    public function activate(string $key): array
    {
        $body = [
            'domain'  => rawurlencode(self::siteDomain()),
            'product' => $this->edition,
        ];
        return $this->post("/license_keys/activate/{$key}", $body);
    }

    /**
     * Deactivate a license key. Errors are swallowed so local state clears even
     * on invalid keys (matches v3.x behavior).
     *
     * @param string $key The license key to deactivate.
     *
     * @return array<string, mixed>
     */
    public function deactivate(string $key): array
    {
        $body = [
            'domain' => rawurlencode(self::siteDomain()),
        ];
        return $this->post("/license_keys/deactivate/{$key}", $body);
    }

    /**
     * Quick status check, local-throttled.
     *
     * @param string $key The license key to check.
     *
     * @return array<string, mixed>
     */
    public function check(string $key): array
    {
        $body = [
            'domain' => rawurlencode(self::siteDomain()),
        ];
        return $this->get("/license_keys/check/{$key}", $body);
    }

    /**
     * Force a remote check bypassing any local cache/throttle. Callers must
     * enforce throttling themselves (see LicenseManager).
     *
     * @param string $key The license key to check.
     *
     * @return array<string, mixed>
     */
    public function checkRemote(string $key): array
    {
        return $this->check($key);
    }

    /**
     * Fetch the license's bundled version info (main plugin package URL,
     * current version, expires, product_name, activation count, etc.).
     *
     * @param string               $key  The license key.
     * @param array<string, mixed> $args Extra args (e.g. ['edge' => 'true']).
     *
     * @return array<string, mixed>
     */
    public function versionInfo(string $key, array $args = []): array
    {
        $body = array_merge(
            ['domain' => rawurlencode(self::siteDomain())],
            $args
        );
        return $this->post("/versions/info/{$key}", $body);
    }

    /**
     * Licensed per-add-on version info. v3's `PrliAddonUpdates::queue_update()`
     * hit this as `GET /versions/info/{slug}/{key}` — note the reversed path
     * order and method vs the main-plugin `versionInfo()` (POST, key-only).
     *
     * @param string               $slug The add-on slug.
     * @param string               $key  The license key.
     * @param array<string, mixed> $args Extra query args.
     *
     * @return array<string, mixed>
     */
    public function addonVersionInfo(string $slug, string $key, array $args = []): array
    {
        $body = array_merge(
            ['domain' => rawurlencode(self::siteDomain())],
            $args
        );
        return $this->get("/versions/info/{$slug}/{$key}", $body);
    }

    /**
     * Fetch plugin_information for a given addon slug.
     *
     * @param string               $slug The add-on slug.
     * @param string               $key  The license key.
     * @param array<string, mixed> $args Extra query args.
     *
     * @return array<string, mixed>
     */
    public function pluginInformation(string $slug, string $key, array $args = []): array
    {
        $body = array_merge(
            ['domain' => rawurlencode(self::siteDomain())],
            $args
        );
        return $this->get("/versions/plugin_information/{$slug}/{$key}", $body);
    }

    /**
     * Fetch the anonymous latest-version info for fallback.
     *
     * @param string               $slug The add-on slug.
     * @param array<string, mixed> $args Extra query args.
     *
     * @return array<string, mixed>
     */
    public function latestVersion(string $slug, array $args = []): array
    {
        return $this->get("/versions/latest/{$slug}", $args);
    }

    /**
     * Fetch the add-ons list for the current edition + license. Matches v3:
     *   GET {domain}/versions/addons/{edition}/{key}?domain=&all=&edge=
     *
     * @param string               $key  The license key.
     * @param array<string, mixed> $args Extra query args (e.g. ['all' => 'true']).
     *
     * @return array<int|string, mixed>
     */
    public function addons(string $key, array $args = []): array
    {
        $body = array_merge(
            ['domain' => rawurlencode(self::siteDomain())],
            $args
        );
        return $this->get("/versions/addons/{$this->edition}/{$key}", $body);
    }

    /**
     * Send a POST request to the mothership.
     *
     * @param string               $path The request path appended to the domain.
     * @param array<string, mixed> $body The request body parameters.
     *
     * @return array<string, mixed>
     */
    private function post(string $path, array $body): array
    {
        return $this->request('POST', $path, $body);
    }

    /**
     * Send a GET request to the mothership.
     *
     * @param string               $path The request path appended to the domain.
     * @param array<string, mixed> $body The request body parameters.
     *
     * @return array<string, mixed>
     */
    private function get(string $path, array $body): array
    {
        return $this->request('GET', $path, $body);
    }

    /**
     * Perform an HTTP request to the mothership and decode the JSON response.
     *
     * @param string               $method The HTTP method (GET or POST).
     * @param string               $path   The request path appended to the domain.
     * @param array<string, mixed> $body   The request body parameters.
     *
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $body): array
    {
        $response = wp_remote_request(
            $this->domain . $path,
            [
                'method'    => $method,
                'body'      => $body,
                'timeout'   => self::TIMEOUT,
                'sslverify' => true,
            ]
        );

        if (is_wp_error($response)) {
            return [
                'error' => $response->get_error_message(),
            ];
        }

        $raw     = (string) wp_remote_retrieve_body($response);
        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            return ['error' => 'invalid_response'];
        }

        return $decoded;
    }

    /**
     * The site's canonical domain (scheme stripped, trailing slash removed).
     * Matches v3.x `PrliUtils::site_domain()` behavior.
     */
    public static function siteDomain(): string
    {
        $url   = (string) get_option('siteurl');
        $parts = wp_parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return (string) wp_parse_url(home_url(), PHP_URL_HOST);
        }
        $host = (string) $parts['host'];
        if (!empty($parts['path'])) {
            $host .= rtrim((string) $parts['path'], '/');
        }
        return $host;
    }

    /**
     * Resolve the mothership domain from config, forcing HTTPS, falling back to the default.
     *
     * @return string The resolved mothership base domain.
     */
    private static function resolveDomain(): string
    {
        if (defined('PRLI_MOTHERSHIP_DOMAIN')) {
            $constant = (string) constant('PRLI_MOTHERSHIP_DOMAIN');
            if ($constant !== '') {
                // V3.x default was HTTP; we force HTTPS in v4.x.
                if (strpos($constant, 'http://') === 0) {
                    $constant = 'https://' . substr($constant, 7);
                } elseif (strpos($constant, 'https://') !== 0) {
                    $constant = 'https://' . $constant;
                }
                return rtrim($constant, '/');
            }
        }
        return self::DEFAULT_DOMAIN;
    }
}
