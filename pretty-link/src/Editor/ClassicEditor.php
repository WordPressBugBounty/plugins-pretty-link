<?php

declare(strict_types=1);

namespace PrettyLinks\Editor;

defined('ABSPATH') || exit;

/**
 * Classic TinyMCE integration — adds a toolbar button that opens a
 * Block-editor-style Pretty Link inserter (typeahead search, toggles for
 * new-window / nofollow / sponsored, inline "create new"). Emits raw
 * `<a>` HTML so the anchor attributes match exactly what the Block editor
 * produces.
 *
 * Only hooked on classic-editor pages (post edit screens).
 */
class ClassicEditor
{
    /**
     * Plugin base URL with a trailing slash.
     *
     * @var string
     */
    private string $baseUrl;

    /**
     * Constructor.
     *
     * @param string $baseUrl Plugin base URL.
     */
    public function __construct(string $baseUrl)
    {
        $this->baseUrl = rtrim($baseUrl, '/') . '/';
    }

    /**
     * Hooks the TinyMCE plugin, button, and bridge script.
     *
     * @return void
     */
    public function register(): void
    {
        add_filter('mce_external_plugins', [$this, 'registerPlugin']);
        add_filter('mce_buttons', [$this, 'registerButton']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueBridge']);
    }

    /**
     * Registers the TinyMCE plugin file on classic-editor screens.
     *
     * @param  array<string, string> $plugins Registered external TinyMCE plugins.
     * @return array<string, string> The filtered plugins map.
     */
    public function registerPlugin(array $plugins): array
    {
        if (!self::isClassicEditor()) {
            return $plugins;
        }
        $plugins['prettylink'] = $this->baseUrl . 'assets/js/classic-tinymce.js';
        return $plugins;
    }

    /**
     * Adds the Pretty Link button to the TinyMCE toolbar.
     *
     * @param  array<int, string> $buttons Registered TinyMCE toolbar buttons.
     * @return array<int, string> The filtered buttons list.
     */
    public function registerButton(array $buttons): array
    {
        if (!self::isClassicEditor()) {
            return $buttons;
        }
        $buttons[] = 'prettylink';
        return $buttons;
    }

    /**
     * Ships the companion script that primes `wp.apiFetch` with a REST nonce
     * and exposes `window.prliClassicInserter` for the TinyMCE plugin. The
     * plugin file itself is loaded by TinyMCE's loader without dependency
     * resolution, so this bridge must run before the post editor mounts.
     */
    public function enqueueBridge(): void
    {
        if (!self::isClassicEditor()) {
            return;
        }

        wp_enqueue_script('wp-api-fetch');
        wp_enqueue_script('wp-i18n');
        wp_enqueue_style(
            'prli-classic-tinymce',
            $this->baseUrl . 'assets/css/classic-tinymce.css',
            [],
            \PrettyLinks\Bootstrap::version()
        );

        $data = [
            'restRoot'  => esc_url_raw(trailingslashit(rest_url())),
            'restNonce' => wp_create_nonce('wp_rest'),
            'i18n'      => [
                'buttonTitle'       => __('Insert Pretty Link', 'pretty-link'),
                'searchPlaceholder' => __('Search Pretty Links…', 'pretty-link'),
                'apply'             => __('Apply', 'pretty-link'),
                'update'            => __('Update', 'pretty-link'),
                'cancel'            => __('Cancel', 'pretty-link'),
                'openInNewTab'      => __('Open in New Tab', 'pretty-link'),
                'nofollow'          => __('Nofollow', 'pretty-link'),
                'sponsored'         => __('Sponsored', 'pretty-link'),
                'createNew'         => __('Create New Pretty Link', 'pretty-link'),
                'targetUrl'         => __('Target URL', 'pretty-link'),
                'slug'              => __('Slug', 'pretty-link'),
                'createInsert'      => __('Create & Insert', 'pretty-link'),
                'creating'          => __('Creating…', 'pretty-link'),
                'createError'       => __('Could not create link. The slug may already be in use.', 'pretty-link'),
                'noResults'         => __('No matches.', 'pretty-link'),
                'title'             => __('Insert Pretty Link', 'pretty-link'),
                'editTitle'         => __('Edit Pretty Link', 'pretty-link'),
                'removeLink'        => __('Remove link', 'pretty-link'),
                'linkText'          => __('Link text', 'pretty-link'),
                'linkTextHelp'      => __('Leave empty to use the link name.', 'pretty-link'),
            ],
        ];

        $bridge = 'window.prliClassicInserter = ' . wp_json_encode($data) . ';'
            . 'if (window.wp && window.wp.apiFetch) {'
            . 'window.wp.apiFetch.use(window.wp.apiFetch.createNonceMiddleware(window.prliClassicInserter.restNonce));'
            . 'window.wp.apiFetch.use(window.wp.apiFetch.createRootURLMiddleware(window.prliClassicInserter.restRoot));'
            . '}';
        wp_add_inline_script('wp-api-fetch', $bridge, 'after');
    }

    /**
     * Determines whether the current screen is a classic post-edit screen.
     *
     * @return boolean True on post.php / post-new.php post-editing screens.
     */
    private static function isClassicEditor(): bool
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen && isset($screen->base) && $screen->base === 'post') {
            return true;
        }
        return !empty($GLOBALS['pagenow']) && in_array($GLOBALS['pagenow'], ['post.php', 'post-new.php'], true);
    }
}
