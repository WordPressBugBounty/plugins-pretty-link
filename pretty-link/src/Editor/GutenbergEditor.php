<?php

declare(strict_types=1);

namespace PrettyLinks\Editor;

defined('ABSPATH') || exit;

/**
 * Gutenberg rich-text format type — registers the Pretty Link toolbar button
 * on every block editor page so authors can insert Pretty Links inline.
 *
 * Enqueued via enqueue_block_editor_assets so it runs on any post type,
 * not just Pretty Links admin screens.
 */
class GutenbergEditor
{
    /**
     * Plugin base URL with a trailing slash.
     *
     * @var string
     */
    private string $baseUrl;

    /**
     * Plugin base filesystem path with a trailing slash.
     *
     * @var string
     */
    private string $basePath;

    /**
     * Constructor.
     *
     * @param string $baseUrl  Plugin base URL.
     * @param string $basePath Plugin base filesystem path.
     */
    public function __construct(string $baseUrl, string $basePath)
    {
        $this->baseUrl  = rtrim($baseUrl, '/') . '/';
        $this->basePath = rtrim($basePath, '/') . '/';
    }

    /**
     * Builds the editor settings passed to the block-editor script.
     *
     * @return array{nofollow: bool, sponsored: bool} Default link attribute toggles.
     */
    private function editorData(): array
    {
        $opts = get_option('prli_options');
        return [
            'nofollow'  => !empty($opts['link_nofollow']),
            'sponsored' => !empty($opts['link_sponsored']),
        ];
    }

    /**
     * Hooks the block-editor asset enqueue.
     *
     * @return void
     */
    public function register(): void
    {
        add_action('enqueue_block_editor_assets', [$this, 'enqueue']);
    }

    /**
     * Enqueues the Pretty Link block-editor script and styles.
     *
     * @return void
     */
    public function enqueue(): void
    {
        $assetFile = $this->basePath . 'assets/js/build/editor.asset.php';
        if (!is_file($assetFile)) {
            return;
        }

        $asset = require $assetFile;
        $deps  = is_array($asset) && isset($asset['dependencies']) ? (array) $asset['dependencies'] : [
            'wp-rich-text',
            'wp-block-editor',
            'wp-components',
            'wp-element',
            'wp-api-fetch',
            'wp-i18n',
        ];
        $ver   = is_array($asset) && isset($asset['version']) ? (string) $asset['version'] : \PrettyLinks\Bootstrap::version();

        $jsRel  = 'assets/js/build/editor.js';
        $cssRel = 'assets/css/build/editor.css';

        if (is_file($this->basePath . $jsRel)) {
            wp_enqueue_script(
                'prli-gutenberg-editor',
                $this->baseUrl . $jsRel,
                $deps,
                $ver,
                true
            );
            wp_set_script_translations('prli-gutenberg-editor', 'pretty-link');
            wp_localize_script('prli-gutenberg-editor', 'prliEditor', $this->editorData());
        }

        if (is_file($this->basePath . $cssRel)) {
            wp_enqueue_style(
                'prli-gutenberg-editor',
                $this->baseUrl . $cssRel,
                ['wp-components'],
                $ver
            );
        }
    }
}
