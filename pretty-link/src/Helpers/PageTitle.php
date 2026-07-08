<?php

declare(strict_types=1);

namespace PrettyLinks\Helpers;

/**
 * Fetch the <title> from a remote URL for use as a link's name.
 *
 * Ports v3's `PrliUtils::get_page_title()` surface so the admin
 * "Add/Edit Link" form, the CSV importer, the REST API, and Pro's
 * public link form all auto-populate the name when the user leaves it
 * blank (matching v3 Pretty Links behavior).
 *
 * Fetch is bounded by a 5s timeout; on any failure (timeout, non-200,
 * missing/empty `<title>`) an empty string is returned and callers
 * fall back to their own default (usually the slug).
 */
class PageTitle
{
    private const TIMEOUT_SECONDS = 5;
    private const MAX_LENGTH      = 150;

    /**
     * Fetches the remote page title for a URL, or an empty string on failure.
     *
     * @param string $url URL to fetch the title from.
     *
     * @return string Extracted page title, or an empty string on failure.
     */
    public static function fetch(string $url): string
    {
        if (!function_exists('wp_http_validate_url') || !wp_http_validate_url($url)) {
            return '';
        }

        $response = wp_safe_remote_get(
            $url,
            [
                'timeout'     => self::TIMEOUT_SECONDS,
                'redirection' => 3,
                // Verify TLS: the fetched <title> is stored as the link name,
                // so an unverified connection would let a MITM inject it. A
                // cert failure just returns '' and the caller falls back to the
                // slug, so enabling this can't break link creation.
                'sslverify'   => true,
                'user-agent'  => sprintf(
                    'PrettyLinks/%s; %s',
                    \PrettyLinks\Bootstrap::version(),
                    home_url('/')
                ),
                'headers'     => ['Accept' => 'text/html,application/xhtml+xml'],
            ]
        );
        if (is_wp_error($response)) {
            return '';
        }
        if ((int) wp_remote_retrieve_response_code($response) !== 200) {
            return '';
        }
        $body = (string) wp_remote_retrieve_body($response);
        if ($body === '') {
            return '';
        }
        if (!preg_match('~<title[^>]*>(.*?)</title>~is', $body, $m)) {
            return '';
        }
        $title = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Normalize wrapped-in-source titles to a single line.
        $title = trim((string) preg_replace('~\s+~u', ' ', $title));
        if ($title === '') {
            return '';
        }
        if (function_exists('mb_substr')) {
            return mb_substr($title, 0, self::MAX_LENGTH);
        }
        return substr($title, 0, self::MAX_LENGTH);
    }
}
