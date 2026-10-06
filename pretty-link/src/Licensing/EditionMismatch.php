<?php

declare(strict_types=1);

namespace PrettyLinks\Licensing;

use PrettyLinks\Bootstrap;
use PrettyLinks\GroundLevel\Mothership\Transients\ActivationTransient;
use PrettyLinks\Updates\AddonCatalog;

/**
 * Detects "wrong edition installed for this license" and surfaces it to the
 * user. Ports v3's `PrliUtils::is_incorrect_edition_installed()` +
 * `PrliUpdateController::check_incorrect_edition()`.
 *
 * Triggers in two places that match v3 behavior:
 *   1. An admin notice on Pretty Links screens (in lieu of v3's activate-page
 *      banner).
 *   2. A message appended to the Pretty Links plugin update row via
 *      `in_plugin_update_message-pretty-link/pretty-link.php`.
 *
 * The licensed edition is read from ground-level-mothership's
 * {@see ActivationTransient}; the installed edition from the `PRLI_EDITION`
 * build constant.
 */
class EditionMismatch
{
    /**
     * Detect an edition mismatch. Returns an array with `installed` and
     * `license` edition data when the installed edition differs from the
     * licensed edition. Returns null when there's nothing to warn about.
     *
     * Any slug difference counts — covers both "wrong Pro edition installed"
     * (e.g. Pro-Blogger with a Pro-Developer license) and "Lite installed with
     * a Pro license". The licensed `product_name` comes from the mothership
     * response; the installed name is humanized from PRLI_EDITION.
     *
     * Deliberately capability-free. The `update_plugins` check used to live in
     * here, which meant callers couldn't tell "the editions match" from "there
     * is no current user" — and one caller uses this as a CORRECTNESS guard,
     * not for display: {@see self::blankMismatchedPackages()}. Under cron there
     * is no user, so the guard evaporated and the licensed-but-wrong-edition
     * package URL was cached for 12 hours. The display callers below own the
     * capability check.
     *
     * @return array{installed: array{slug: string, name: string}, license: array{slug: string, name: string}}|null
     */
    public static function detect(): ?array
    {
        $activation         = Bootstrap::instance()->container()->get(ActivationTransient::class);
        $licenseProductSlug = $activation->productSlug;

        if ($licenseProductSlug === '') {
            return null;
        }

        $installedSlug = defined('PRLI_EDITION') ? (string) constant('PRLI_EDITION') : '';
        if ($installedSlug === '' || $installedSlug === $licenseProductSlug) {
            return null;
        }

        // Skip dev checkouts — matches v3's `@is_dir(PRLI_PATH . '/.git')`.
        if (defined('PRLI_PATH') && is_dir((string) constant('PRLI_PATH') . '/.git')) {
            return null;
        }

        $licenseName = $activation->productName !== ''
            ? $activation->productName
            : self::humanize($licenseProductSlug);

        return [
            'installed' => [
                'slug' => $installedSlug,
                'name' => self::humanize($installedSlug),
            ],
            'license'   => [
                'slug' => $licenseProductSlug,
                'name' => $licenseName,
            ],
        ];
    }

    /**
     * Convert a slug like "pretty-link-pro-developer" into a display string
     * like "Pretty Link Pro Developer". Used for the installed-edition label
     * (no mothership name for it) and as a fallback when the mothership
     * response lacks `product_name`.
     *
     * @param  string $slug The edition slug to humanize, e.g. "pretty-link-pro-developer".
     * @return string The humanized display string, e.g. "Pretty Link Pro Developer".
     */
    private static function humanize(string $slug): string
    {
        return ucwords(str_replace('-', ' ', $slug));
    }

    public const NOTICE_ID = 'prli_edition_mismatch';

    /**
     * Inject the edition-mismatch warning into the React notice strip via the
     * shared `prli_notices_active` filter. Non-dismissible — the notice only
     * goes away once the admin installs the correct edition.
     *
     * @param  array<int, array<string, mixed>> $notices  The existing notices passed through the filter.
     * @param  string                           $screenId The current admin screen ID; the notice is only injected on Pretty Links screens.
     * @return array<int, array<string, mixed>>           The notices array, with the mismatch warning appended when applicable.
     */
    public static function injectAdminNotice(array $notices, string $screenId): array
    {
        if (strpos($screenId, 'pretty-link') === false) {
            return $notices;
        }
        // Display-side capability gate — the CTA installs a plugin.
        if (!current_user_can('update_plugins')) {
            return $notices;
        }

        $mismatch = self::detect();
        if ($mismatch === null) {
            return $notices;
        }

        $installCta = sprintf(
            '<a href="#" class="prli-install-license-edition" data-edition="%s">%s</a>',
            esc_attr($mismatch['license']['slug']),
            esc_html(
                sprintf(
                    // Translators: %s: licensed edition name.
                    __('Install %s now.', 'pretty-link'),
                    $mismatch['license']['name']
                )
            )
        );

        $message = sprintf(
            '<strong>%1$s</strong> %2$s %3$s',
            esc_html__('Pretty Links edition mismatch.', 'pretty-link'),
            sprintf(
                // Translators: %1$s: installed edition name, %2$s: licensed edition name.
                esc_html__('You have %1$s installed, but your license is for %2$s.', 'pretty-link'),
                '<em>' . esc_html($mismatch['installed']['name']) . '</em>',
                '<em>' . esc_html($mismatch['license']['name']) . '</em>'
            ),
            $installCta
        );

        $notices[] = [
            'id'          => self::NOTICE_ID,
            'type'        => 'warning',
            'message'     => wp_kses_post($message),
            'created'     => time(),
            'dismissible' => false,
        ];
        return $notices;
    }

    /**
     * Surface this warning on every licensed add-on's own plugin-update row.
     *
     * Automatic updates cannot work while the installed edition doesn't match
     * the licence, and the add-on's row is the user's breadcrumb to fixing it —
     * the main plugin's row alone is easy to miss in a long plugin list.
     * develop registered this per add-on from AddonUpdateChecker; that class is
     * gone and nothing replaced the registration.
     *
     * Runs on admin_init so the catalog transient is warm and the work stays
     * off the front end. The Plugins screen renders well after.
     *
     * @return integer The number of add-on rows registered.
     */
    public static function registerAddonRows(): int
    {
        $registered = 0;

        foreach (AddonCatalog::cached() as $product) {
            $mainFile = (string) ($product->main_file ?? '');

            if ($mainFile === '') {
                continue;
            }

            add_action('in_plugin_update_message-' . $mainFile, [self::class, 'pluginUpdateRowMessage'], 10, 2);
            $registered++;
        }

        return $registered;
    }

    /**
     * Blank the package URL on every Pretty Links row while the installed
     * edition doesn't match the licence.
     *
     * Ported from v3's `PrliUpdateController::check_incorrect_edition()`;
     * develop carried it in `UpdateChecker` and `AddonUpdateChecker`. Both
     * classes are gone, and detect()'s docblock went on promising a guard that
     * nothing implemented — so a licensed-but-wrong-edition install could take
     * the licensed product's ZIP through a single "Update now" click and swap
     * its own edition underneath the site. The licensed edition install has a
     * deliberate route: the mismatch notice's CTA, gated on `install_plugins`,
     * which calls {@see InstallLicensedEdition}.
     *
     * An empty `package` makes core render the row without an update link, and
     * `pluginUpdateRowMessage()` prints the reason right beside it. The offered
     * version stays, so the user still sees that an update exists.
     *
     * Filters the read side (`site_transient_update_plugins`) rather than the
     * write side: GL's LegacyUpdateService injects add-on rows on read too, so
     * the write side never sees them.
     *
     * @param  mixed $transient The update_plugins transient.
     * @return mixed The transient, with our package URLs blanked on a mismatch.
     */
    public static function blankMismatchedPackages($transient)
    {
        if (!is_object($transient) || empty($transient->response) || !is_array($transient->response)) {
            return $transient;
        }

        if (self::detect() === null) {
            return $transient;
        }

        return self::stripPackages($transient);
    }

    /**
     * The mechanical half of {@see self::blankMismatchedPackages()}: blank the
     * package URL on every row this plugin owns, unconditionally.
     *
     * Split out so the transient surgery can be exercised on its own. detect()
     * short-circuits on a `.git` directory in PRLI_PATH, which every checkout
     * the suite runs in has, so a test that went through the filter entry point
     * could only ever assert the no-op branch.
     *
     * @param  object $transient The update_plugins transient.
     * @return object The transient, with our package URLs blanked.
     */
    public static function stripPackages(object $transient): object
    {
        foreach (self::ownedPluginFiles() as $file) {
            if (!isset($transient->response[$file])) {
                continue;
            }

            $row = $transient->response[$file];
            if (is_object($row)) {
                $row->package = '';
            } elseif (is_array($row)) {
                $row['package']             = '';
                $transient->response[$file] = $row;
            }
        }

        return $transient;
    }

    /**
     * The plugin files this guard owns: the host plugin and every licensed
     * add-on in the cached catalog.
     *
     * @return array<int, string>
     */
    private static function ownedPluginFiles(): array
    {
        $files = defined('PRLI_FILE') ? [plugin_basename((string) constant('PRLI_FILE'))] : [];

        foreach (AddonCatalog::cached() as $product) {
            $mainFile = (string) ($product->main_file ?? '');
            if ($mainFile !== '') {
                $files[] = $mainFile;
            }
        }

        return $files;
    }

    /**
     * Appends a warning line to the Pretty Links row on the Plugins update
     * screen when the installed edition doesn't match the license.
     * Registered for `in_plugin_update_message-pretty-link/pretty-link.php`.
     *
     * @param array<string, mixed> $pluginData The plugin metadata array passed by the WordPress hook (unused).
     * @param object               $response   The update response object passed by the WordPress hook (unused).
     */
    public static function pluginUpdateRowMessage($pluginData, $response): void
    {
        // Display-side capability gate. Redundant on this screen in practice,
        // kept explicit so detect() stays purely a state question.
        if (!current_user_can('update_plugins')) {
            return;
        }

        $mismatch = self::detect();
        if ($mismatch === null) {
            return;
        }

        printf(
            '<br><strong>%s</strong>',
            sprintf(
                // Translators: %1$s: installed edition name, %2$s: licensed edition name.
                esc_html__('Heads up: you have %1$s installed but your license is for %2$s. Install the correct edition to resume updates.', 'pretty-link'),
                esc_html($mismatch['installed']['name']),
                esc_html($mismatch['license']['name'])
            )
        );
    }
}
