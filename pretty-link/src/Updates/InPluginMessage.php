<?php

declare(strict_types=1);

namespace PrettyLinks\Updates;

defined('ABSPATH') || exit;

/**
 * Appends a major-version upgrade notice above the plugin's Update button on
 * the Plugins screen.
 *
 * Hook: `in_plugin_update_message-pretty-link/pretty-link.php`.
 *
 * Only renders if the pending update is a major-version bump (e.g. 4.x → 5.x).
 * The message is kept short and non-dismissable by design — WordPress shows
 * it inline and the user dismisses it by running the update.
 */
class InPluginMessage
{
    public const PLUGIN_SLUG = 'pretty-link/pretty-link.php';

    /**
     * Currently installed plugin version.
     *
     * @var string
     */
    private string $currentVersion;

    /**
     * Stores the currently installed plugin version.
     *
     * @param string $currentVersion Currently installed plugin version.
     */
    public function __construct(string $currentVersion)
    {
        $this->currentVersion = $currentVersion;
    }

    /**
     * Registers the in-plugin update message hook.
     *
     * @return void
     */
    public function register(): void
    {
        add_action(
            'in_plugin_update_message-' . self::PLUGIN_SLUG,
            [$this, 'render'],
            10,
            2
        );
    }

    /**
     * Renders the major-version upgrade notice on the Plugins screen.
     *
     * @param array<string, mixed>|mixed $pluginData Plugin row data (unused).
     * @param object|mixed               $response   Update response object.
     *
     * @return void
     */
    public function render($pluginData, $response): void
    {
        unset($pluginData);

        $newVersion = is_object($response) && isset($response->new_version)
            ? (string) $response->new_version
            : '';
        if ($newVersion === '') {
            return;
        }

        $currentMajor = (int) explode('.', $this->currentVersion, 2)[0];
        $newMajor     = (int) explode('.', $newVersion, 2)[0];
        if ($newMajor <= $currentMajor) {
            return;
        }

        echo '<br /><strong>' . esc_html__('Heads up:', 'pretty-link') . '</strong> ';
        printf(
            // phpcs:ignore Squiz.Commenting.InlineComment.NotCapital, Squiz.Commenting.InlineComment.InvalidEndChar -- WordPress i18n translator comment, must remain verbatim.
            // translators: %s: new major version, e.g. 5.0
            esc_html__('This is a major update (%s). Back up your database before upgrading. See the changelog for breaking changes.', 'pretty-link'),
            esc_html($newVersion)
        );
    }
}
