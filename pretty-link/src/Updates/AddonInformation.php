<?php

declare(strict_types=1);

namespace PrettyLinks\Updates;

defined('ABSPATH') || exit;

use PrettyLinks\Bootstrap;
use PrettyLinks\GroundLevel\Mothership\Util;

/**
 * Serves the "View details" modal for licensed add-ons.
 *
 * Ground Level's LegacyUpdateService injects add-on update rows and gives each
 * one a `slug` of `dirname($mainFile)` — `pretty-link-splash-pages` and the
 * like. WordPress renders a "View details" link for any row with a slug, and
 * that link calls `plugins_api()`, which goes to WordPress.org. Our add-ons do
 * not exist there, so the modal comes back "An unexpected error occurred."
 *
 * develop had AddonUpdateChecker answering `plugins_api` for exactly these
 * slugs. This branch deletes it and registers nothing in its place.
 *
 * GL's UpdateService also answers `plugins_api`, but only for slugs passed to
 * its `plugin()` registrar — and its handler ignores the incoming result and
 * hits the licensing server before checking success, so wherever it is armed it
 * both blocks the modal on the network and can overwrite what this class
 * returned. Bootstrap therefore registers an add-on with GL only when the
 * installed plugin's `Update URI` header actually resolves to its own
 * directory, i.e. only when core would really call `update_plugins_{hostname}`
 * for it. Any add-on outside that narrow case is answered here, cache-only;
 * inside it, {@see self::guard()} keeps GL's failure stub from replacing this
 * answer.
 *
 * The licensed catalog already holds everything the modal needs, so this
 * answers from the cached product list — strictly cache-only, and only on a
 * licensed install, because it sits on the plugin-details modal's critical
 * path. Every package URL it hands back is validated against Mothership's
 * download-host allowlist first: core feeds `download_link` straight to
 * WP_Upgrader::install().
 */
class AddonInformation
{
    /**
     * Registers the handler.
     *
     * Priority 10 is fine: this only ever claims slugs that belong to us, and
     * returns the incoming result untouched for everything else. It is not a
     * defence against GL's own `plugins_api` handler — that one is added later
     * (during `init`) and at equal priority runs second — which is why
     * Bootstrap keeps GL unregistered for add-ons that don't need it.
     *
     * @return void
     */
    public function register(): void
    {
        add_filter('plugins_api', [$this, 'provide'], 10, 3);
    }

    /**
     * Covers the one case where GL does answer `plugins_api` for an add-on.
     *
     * Bootstrap registers an add-on with GL when core would really call
     * `update_plugins_{hostname}` for it, and that registration arms GL's
     * `plugins_api` handler too. It runs after this class (added during `init`,
     * equal priority), ignores the incoming result, and on a failed version
     * check returns nothing but `['slug' => …]` — so for exactly those add-ons,
     * the payload this class answered from cache is replaced by the blank modal
     * {@see PluginInformation} exists to prevent for the host plugin.
     *
     * The per-slug filter GL runs its payload through on the way out is the
     * only seam, so use it: re-answer from cache, or fall through to wp.org
     * rather than render an empty modal.
     *
     * @param  string $slug The slug registered with GL's UpdateService.
     * @return void
     */
    public function guard(string $slug): void
    {
        if ($slug === '') {
            return;
        }

        add_filter($slug . '_plugin_information', [$this, 'replaceEmptyPayload'], 5);
    }

    /**
     * Replaces GL's failure stub with this class's cached answer.
     *
     * @param  mixed $info The plugin information object GL assembled.
     * @return mixed The object when it carries real data, a cached answer when
     *               one exists, false otherwise.
     */
    public function replaceEmptyPayload($info)
    {
        if (!is_object($info)) {
            return $info;
        }

        // GL sets every one of these together, and only on a successful check.
        if (
            !empty($info->version)
            || !empty($info->download_link)
            || !empty($info->sections)
            || !empty($info->name)
        ) {
            return $info;
        }

        $slug = isset($info->slug) ? (string) $info->slug : '';
        if ($slug === '') {
            return false;
        }

        $cached = $this->provide(false, 'plugin_information', (object) ['slug' => $slug]);

        // No cached entry either: false makes GL's handler return false and
        // core carry on, which at least says something.
        return is_object($cached) ? $cached : false;
    }

    /**
     * Answers `plugins_api` for one of our add-on slugs.
     *
     * @param  mixed  $result The result from earlier filters. False by default.
     * @param  string $action The plugins_api action being performed.
     * @param  mixed  $args   The plugins_api request arguments.
     * @return mixed
     */
    public function provide($result, $action, $args) // phpcs:ignore Squiz.Commenting.FunctionComment.ScalarTypeHintMissing -- WP filter signature is fixed; $action stays untyped.
    {
        if ($action !== 'plugin_information') {
            return $result;
        }

        $slug = is_object($args) && isset($args->slug) ? (string) $args->slug : '';
        if ($slug === '') {
            return $result;
        }

        $product = $this->findAddon($slug);
        if ($product === null) {
            return $result;
        }

        // Whatever goes in `download_link` is handed straight to
        // WP_Upgrader::install() by core — wp-admin/update.php's
        // action=install-plugin and the two ajax install paths all do
        // `$upgrader->install($api->download_link)` — and the modal's install
        // button targets exactly those endpoints. So a poisoned add-on
        // transient or a tampered API body would otherwise install an
        // arbitrary-host ZIP as a plugin on one admin click. GL puts this same
        // value through sanitizeDownloadUrl() everywhere else it touches it
        // (LegacyUpdateService, the mosh_addon_install ajax path); this was the
        // one path that skipped the fence.
        $downloadUrl = $this->sanitizeDownloadUrl(
            isset($product->version->url) ? (string) $product->version->url : ''
        );

        if ($downloadUrl === '') {
            // Nothing installable and nothing trustworthy to show: let core
            // fall through rather than render a modal whose button is armed
            // with a URL we just rejected.
            return $result;
        }

        $info = [
            'name'          => (string) ($product->name ?? ''),
            'slug'          => $slug,
            'version'       => isset($product->version->number) ? (string) $product->version->number : '',
            'author'        => '<a href="https://prettylinks.com">Pretty Links</a>',
            'homepage'      => 'https://prettylinks.com',
            'download_link' => $downloadUrl,
            'sections'      => [
                'description' => isset($product->description) ? wp_kses_post((string) $product->description) : '',
            ],
        ];

        $image = (string) ($product->image ?? '');
        if ($image !== '') {
            $info['icons'] = [
                '1x' => $image,
                '2x' => $image,
            ];
        }

        return (object) $info;
    }

    /**
     * Finds a licensed add-on by the slug WordPress asked about.
     *
     * The update row's slug is `dirname($mainFile)`, which is not always the
     * product slug, so both are matched.
     *
     * @param  string $slug The slug from the plugins_api request.
     * @return object|null The matching product, or null when it isn't ours.
     */
    private function findAddon(string $slug): ?object
    {
        foreach ($this->products() as $product) {
            if ((string) ($product->slug ?? '') === $slug) {
                return $product;
            }

            $mainFile = (string) ($product->main_file ?? '');
            if ($mainFile !== '' && dirname($mainFile) === $slug) {
                return $product;
            }
        }

        return null;
    }

    /**
     * Validates a package URL against Mothership's download-host allowlist.
     *
     * @param  string $url The candidate package URL.
     * @return string The URL when allowed, an empty string otherwise.
     */
    protected function sanitizeDownloadUrl(string $url): string
    {
        if ($url === '') {
            return '';
        }

        try {
            return Bootstrap::instance()->container()->get(Util::class)->sanitizeDownloadUrl($url);
        } catch (\Throwable $e) {
            // Fail closed: an unvalidatable URL is not an installable one.
            return '';
        }
    }

    /**
     * The cached licensed add-on catalog. Cache-only, and never on Lite.
     *
     * Protected so tests can supply a catalog without reaching the network.
     *
     * @return array<int, object>
     */
    protected function products(): array
    {
        return AddonCatalog::cached();
    }
}
