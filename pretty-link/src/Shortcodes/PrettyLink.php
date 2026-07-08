<?php

declare(strict_types=1);

namespace PrettyLinks\Shortcodes;

use PrettyLinks\Repositories\Links as LinksRepo;

/**
 * `[prettylink]` shortcode — renders an anchor to a pretty URL.
 *
 * Supported attributes:
 *   id     — prli_links.id
 *   slug   — prli_links.slug
 *   text   — link text (defaults to the link's name, or the URL)
 *   class  — extra CSS classes
 *   target — anchor target (`_blank` etc.)
 *
 * Shortcode tag name (`prettylink`) is new in 4.0 (3.x never registered it).
 * Keep it stable — do NOT rename — for forward compatibility.
 */
class PrettyLink
{
    public const TAG = 'prettylink';

    /**
     * Renders the `[prettylink]` shortcode into an anchor element.
     *
     * @param mixed $atts    Shortcode attributes (array or empty string).
     * @param mixed $content Enclosed shortcode content (unused).
     *
     * @return string Rendered anchor HTML, or an empty string when unresolved.
     */
    public static function render($atts, $content = null): string
    {
        unset($content);
        $atts = shortcode_atts(
            [
                'id'     => 0,
                'slug'   => '',
                'text'   => '',
                'class'  => '',
                'target' => '',
                // The classic TinyMCE inserter emits `source="tinymce"`
                // so its anchors render with `pretty-link-tinymce` instead
                // of the default `pretty-link-shortcode`. Any other value
                // is ignored.
                'source' => '',
            ],
            is_array($atts) ? $atts : [],
            self::TAG
        );

        $repo = new LinksRepo();
        $link = null;
        if ((int) $atts['id'] > 0) {
            $link = $repo->find((int) $atts['id']);
        } elseif ($atts['slug'] !== '') {
            $link = $repo->findBySlug((string) $atts['slug']);
        }

        if (!is_array($link) || empty($link['pretty_url'])) {
            return '';
        }

        $text = $atts['text'] !== ''
            ? (string) $atts['text']
            : (string) ($link['name'] !== '' ? $link['name'] : $link['pretty_url']);

        // Universal `pretty-link` marker + surface-specific second class
        // so themes can target every inserted pretty link with one
        // selector (`a.pretty-link`) while still distinguishing the
        // editor, shortcode, TinyMCE, keyword, and URL surfaces.
        $surface      = strtolower((string) $atts['source']) === 'tinymce'
            ? 'pretty-link-tinymce'
            : 'pretty-link-shortcode';
        $defaultClass = 'pretty-link ' . $surface;

        $attrs      = [
            'href' => esc_url((string) $link['pretty_url']),
        ];
        $classParts = [$defaultClass];
        if ($atts['class'] !== '') {
            $classParts[] = (string) $atts['class'];
        }
        $attrs['class'] = esc_attr(implode(' ', $classParts));
        if ($atts['target'] !== '') {
            $attrs['target'] = esc_attr((string) $atts['target']);
            if (strtolower((string) $atts['target']) === '_blank') {
                $attrs['rel'] = 'noopener';
            }
        }
        if (!empty($link['nofollow']) || !empty($link['sponsored'])) {
            $rel = [];
            if (!empty($link['nofollow'])) {
                $rel[] = 'nofollow';
            }
            if (!empty($link['sponsored'])) {
                $rel[] = 'sponsored';
            }
            if (isset($attrs['rel'])) {
                $rel[] = $attrs['rel'];
            }
            $attrs['rel'] = implode(' ', array_unique($rel));
        }

        $html = '<a';
        foreach ($attrs as $name => $value) {
            $html .= ' ' . $name . '="' . $value . '"';
        }
        $html .= '>' . esc_html($text) . '</a>';

        return $html;
    }
}
