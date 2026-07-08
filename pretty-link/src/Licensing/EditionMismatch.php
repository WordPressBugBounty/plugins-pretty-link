<?php

declare(strict_types=1);

namespace PrettyLinks\Licensing;

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
 * Preserved option / transient names:
 *   - `prli_license_info` site transient (read only)
 *   - `PRLI_EDITION` constant (read only)
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
     * @return array{installed: array{slug: string, name: string}, license: array{slug: string, name: string}}|null
     */
    public static function detect(): ?array
    {
        $info               = get_site_transient(LicenseManager::TRANSIENT_LICENSE_INFO);
        $licenseProductSlug = is_array($info) && !empty($info['product_slug'])
            ? (string) $info['product_slug']
            : '';

        if ($licenseProductSlug === '') {
            return null;
        }

        $installedSlug = defined('PRLI_EDITION') ? (string) constant('PRLI_EDITION') : '';
        if ($installedSlug === '' || $installedSlug === $licenseProductSlug) {
            return null;
        }

        if (!current_user_can('update_plugins')) {
            return null;
        }

        // Skip dev checkouts — matches v3's `@is_dir(PRLI_PATH . '/.git')`.
        if (defined('PRLI_PATH') && is_dir((string) constant('PRLI_PATH') . '/.git')) {
            return null;
        }

        $licenseName = is_array($info) && !empty($info['product_name'])
            ? (string) $info['product_name']
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
     * @param string $slug The edition slug to humanize.
     *
     * @return string The humanized display label.
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
     * @param array<int, array<string, mixed>> $notices  The current notices collection.
     * @param string                           $screenId The current admin screen identifier.
     *
     * @return array<int, array<string, mixed>> The notices collection, possibly with the mismatch notice appended.
     */
    public static function injectAdminNotice(array $notices, string $screenId): array
    {
        if (strpos($screenId, 'pretty-link') === false) {
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
                    // phpcs:ignore Squiz.Commenting.InlineComment.NotCapital,Squiz.Commenting.InlineComment.InvalidEndChar -- translators comment must remain verbatim.
                    // translators: %s: licensed edition name.
                    __('Install %s now.', 'pretty-link'),
                    $mismatch['license']['name']
                )
            )
        );

        $message = sprintf(
            '<strong>%1$s</strong> %2$s %3$s',
            esc_html__('Pretty Links edition mismatch.', 'pretty-link'),
            sprintf(
                // phpcs:ignore Squiz.Commenting.InlineComment.NotCapital,Squiz.Commenting.InlineComment.InvalidEndChar -- translators comment must remain verbatim.
                // translators: %1$s: installed edition name, %2$s: licensed edition name
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
     * Appends a warning line to the Pretty Links row on the Plugins update
     * screen when the installed edition doesn't match the license.
     * Registered for `in_plugin_update_message-pretty-link/pretty-link.php`.
     *
     * @param array<string, mixed> $pluginData The plugin data array passed by WordPress.
     * @param object               $response   The plugin update response object.
     *
     * @return void
     */
    public static function pluginUpdateRowMessage($pluginData, $response): void
    {
        $mismatch = self::detect();
        if ($mismatch === null) {
            return;
        }

        printf(
            '<br><strong>%s</strong>',
            sprintf(
                // phpcs:ignore Squiz.Commenting.InlineComment.NotCapital,Squiz.Commenting.InlineComment.InvalidEndChar -- translators comment must remain verbatim.
                // translators: %1$s: installed edition name, %2$s: licensed edition name
                esc_html__('Heads up: you have %1$s installed but your license is for %2$s. Install the correct edition to resume updates.', 'pretty-link'),
                esc_html($mismatch['installed']['name']),
                esc_html($mismatch['license']['name'])
            )
        );
    }
}
