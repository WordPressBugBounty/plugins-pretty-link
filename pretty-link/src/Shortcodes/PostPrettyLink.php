<?php

declare(strict_types=1);

namespace PrettyLinks\Shortcodes;

use PrettyLinks\Repositories\Links as LinksRepository;

/**
 * `[post-pretty-link]` — renders the pretty link URL for the current post.
 *
 * V3 drop-in. Reads the `_pretty-link` post meta (populated by the Pro
 * auto-create feature on publish, or manually).
 *
 * Returns an empty string when:
 *   - there is no current post in scope;
 *   - the post isn't published;
 *   - the meta points at a link that no longer exists.
 *
 * Supported attributes (optional):
 *   link="url"   — return the URL only (default).
 *   link="anchor" — wrap in an <a> tag using the link name or the URL as text.
 *   text="foo"   — override the anchor text (implies link="anchor").
 */
class PostPrettyLink
{
    public const TAG = 'post-pretty-link';

    /**
     * Renders the `[post-pretty-link]` shortcode into a URL or anchor.
     *
     * @param array<string, mixed>|string $atts    Shortcode attributes.
     * @param mixed                       $content Enclosed shortcode content (unused).
     *
     * @return string Rendered URL or anchor HTML.
     */
    public static function render($atts, $content = null): string
    {
        unset($content);
        $atts = shortcode_atts(
            [
                'link' => 'url',
                'text' => '',
                'id'   => 0,
            ],
            is_array($atts) ? $atts : [],
            self::TAG
        );

        $postId = (int) $atts['id'];
        if ($postId <= 0) {
            $postId = (int) get_the_ID();
        }
        if ($postId <= 0) {
            return '';
        }
        $post = get_post($postId);
        if (!$post || $post->post_status !== 'publish') {
            return '';
        }

        $linkId = (int) get_post_meta($postId, '_pretty-link', true);
        if ($linkId <= 0) {
            return '';
        }

        $link = (new LinksRepository())->find($linkId);
        if (!is_array($link) || empty($link['pretty_url'])) {
            return '';
        }

        $url = (string) $link['pretty_url'];

        $wantAnchor = $atts['link'] === 'anchor' || $atts['text'] !== '';
        if (!$wantAnchor) {
            return esc_url($url);
        }

        $text = (string) $atts['text'];
        if ($text === '') {
            $text = !empty($link['name']) ? (string) $link['name'] : $url;
        }
        return '<a href="' . esc_url($url) . '" class="pretty-link pretty-link-post-link">' . esc_html($text) . '</a>';
    }
}
