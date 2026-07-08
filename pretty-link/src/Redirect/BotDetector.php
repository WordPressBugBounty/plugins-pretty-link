<?php

declare(strict_types=1);

namespace PrettyLinks\Redirect;

/**
 * Lightweight bot detection. Layered cheap signals that run on the redirect
 * hot path with no file I/O or network calls:
 *
 *  - DEFAULT_PATTERN: ~70 known crawler/scraper UAs incl. modern AI bots
 *    (GPTBot, ClaudeBot, PerplexityBot, etc.)
 *  - `prli_bot_regex` filter for site-owner overrides of the regex
 *  - `bot_patterns` option: extra UA substring patterns (one per line).
 *    v3 stored a boolean in `filter_robots`; that key is now restored to
 *    its boolean meaning and patterns live in the separate `bot_patterns` key.
 *  - Cloudflare Bot Management headers (CF-Bot-Score, CF-Verified-Bot)
 *  - Honeypot: empty `Accept` header (real browsers always send one)
 *  - Method check: link clicks are GET; HEAD/POST/etc on the redirect
 *    handler is a URL preview service or scanner probe
 *  - Platform mismatch: UA OS doesn't match Sec-CH-UA-Platform — strongest
 *    header-only check per Sicuranext + WICG analysis (no documented FPs,
 *    including mobile WebViews which keep platform consistency)
 *
 * No DNS, no DB writes, no network calls — bot detection runs on the
 * redirect hot path and must stay fast.
 */
class BotDetector
{
    private const DEFAULT_PATTERN =
        '~bot|crawl|spider|slurp|mediapartners|facebot|facebookexternalhit|'
        . 'googlebot|googleother|bingbot|duckduckbot|yandex|baiduspider|sogou|'
        . 'ahrefs|semrush|mj12bot|dotbot|rogerbot|applebot|applebot-extended|'
        . 'linkedinbot|twitterbot|discordbot|skypeuripreview|whatsapp|telegrambot|'
        . 'pingdom|uptimerobot|gtmetrix|headlesschrome|phantomjs|puppeteer|playwright|'
        . 'curl|wget|python-requests|node-fetch|axios|scrapy|'
        . 'gptbot|chatgpt-user|claudebot|claude-web|ccbot|anthropic-ai|perplexitybot|'
        . 'bytespider|amazonbot|meta-externalagent|cohere-ai|imagesiftbot|diffbot~i';

    /**
     * Whether the request looks like a bot, across all layered signals.
     *
     * @param  string                    $userAgent     The request User-Agent string.
     * @param  array<string, mixed>|null $serverHeaders Optional $_SERVER override; null means use $_SERVER directly.
     * @return boolean True when the request is classified as a bot.
     */
    public static function isBot(string $userAgent, ?array $serverHeaders = null): bool
    {
        if (!self::filterEnabled()) {
            return false;
        }

        if ($userAgent === '') {
            return true;
        }

        /**
         * Bot-matching regex.
         *
         * @var string $pattern
         */
        $pattern = apply_filters('prli_bot_regex', self::DEFAULT_PATTERN);
        if ($pattern !== '' && preg_match($pattern, $userAgent)) {
            return true;
        }

        $blocked = (string) (self::options()['bot_patterns'] ?? '');
        if ($blocked !== '') {
            foreach (array_map('trim', explode("\n", $blocked)) as $needle) {
                if (!self::isValidExtraPattern($needle)) {
                    continue;
                }
                if (stripos($userAgent, $needle) !== false) {
                    return true;
                }
            }
        }

        $headers = $serverHeaders ?? $_SERVER;

        if (
            apply_filters('prli_bot_cdn_check', true)
            && self::isCdnFlaggedBot($headers)
        ) {
            return true;
        }

        if (
            apply_filters('prli_bot_honeypot_check', true)
            && self::isHoneypotBot($headers)
        ) {
            return true;
        }

        // HTTP method check: real link clicks are always GET. HEAD = URL
        // preview / link checker; POST/PUT/etc = scanner or probe. Filterable
        // for sites that intentionally HEAD-monitor their own links.
        if (
            apply_filters('prli_bot_method_check', true)
            && self::isNonNavigationMethod($headers)
        ) {
            return true;
        }

        // Platform mismatch: UA claims one OS, Sec-CH-UA-Platform reports a
        // different one. No real browser does this — strong bot tell.
        // Per Sicuranext + WICG analysis, this is the safest header-based
        // mismatch check (no documented false positives, including WebViews).
        if (
            apply_filters('prli_bot_platform_check', true)
            && self::isPlatformMismatchBot($userAgent, $headers)
        ) {
            return true;
        }

        return false;
    }

    /**
     * Site-owner master switch. When false, every detection check returns
     * false — clicks count regardless of UA, headers, method, or platform.
     */
    private static function options(): array
    {
        static $opts = null;
        if ($opts === null) {
            $opts = (array) get_option('prli_options', []);
        }
        return $opts;
    }

    /**
     * Site-owner master switch for bot filtering, with a v3 upgrade path.
     *
     * @return boolean True when bot filtering is enabled.
     */
    public static function filterEnabled(): bool
    {
        $opts = self::options();
        if (!is_array($opts)) {
            return true;
        }
        if (array_key_exists('enable_bot_filter', $opts)) {
            return (bool) $opts['enable_bot_filter'];
        }
        // V3 upgrade path: honor filter_robots boolean when enable_bot_filter
        // hasn't been written yet (user hasn't saved settings in v4).
        if (array_key_exists('filter_robots', $opts)) {
            return (bool) $opts['filter_robots'];
        }
        return true;
    }

    /**
     * Whether CDN (Cloudflare) headers flag the request as a bot.
     *
     * @param  array<string, mixed> $headers Request server headers.
     * @return boolean True when a CDN signal indicates a bot.
     */
    private static function isCdnFlaggedBot(array $headers): bool
    {
        // Cloudflare Bot Management: 1-99 score, lower = more bot-like.
        // Header is absent if site isn't on a CF plan with Bot Management.
        $score = $headers['HTTP_CF_BOT_SCORE'] ?? null;
        if ($score !== null && is_numeric($score) && (int) $score < 30) {
            return true;
        }

        // Cloudflare verified bots (Googlebot, Bingbot, etc.). They aren't
        // human visitors so we drop them from analytics regardless.
        $verified = $headers['HTTP_CF_VERIFIED_BOT'] ?? null;
        if (
            $verified !== null
            && filter_var($verified, FILTER_VALIDATE_BOOLEAN)
        ) {
            return true;
        }

        return false;
    }

    /**
     * Whether the request trips the empty-Accept-header honeypot.
     *
     * @param  array<string, mixed> $headers Request server headers.
     * @return boolean True when the Accept header is absent.
     */
    private static function isHoneypotBot(array $headers): bool
    {
        // Real browsers ALWAYS send an Accept header with their navigation
        // request. curl/wget/python-requests etc. don't, unless told to.
        $accept = isset($headers['HTTP_ACCEPT']) ? (string) $headers['HTTP_ACCEPT'] : '';
        if ($accept === '') {
            return true;
        }
        return false;
    }

    /**
     * Whether the request uses a non-navigation HTTP method (not GET).
     *
     * @param  array<string, mixed> $headers Request server headers.
     * @return boolean True when the method is anything other than GET.
     */
    private static function isNonNavigationMethod(array $headers): bool
    {
        $method = isset($headers['REQUEST_METHOD'])
            ? strtoupper((string) $headers['REQUEST_METHOD'])
            : '';
        return $method !== '' && $method !== 'GET';
    }

    /**
     * Whether the UA-implied OS contradicts the Sec-CH-UA-Platform header.
     *
     * @param  string               $userAgent The request User-Agent string.
     * @param  array<string, mixed> $headers   Request server headers.
     * @return boolean True when the platforms disagree.
     */
    private static function isPlatformMismatchBot(string $userAgent, array $headers): bool
    {
        $raw = isset($headers['HTTP_SEC_CH_UA_PLATFORM'])
            ? (string) $headers['HTTP_SEC_CH_UA_PLATFORM']
            : '';
        if ($raw === '') {
            return false;
        }

        // Spec value is e.g. `"Windows"` (quoted). Some server stacks deliver
        // it backslash-escaped as `\"Windows\"`, so strip backslashes too —
        // otherwise real browsers get misclassified as bots when the quotes
        // don't come through cleanly.
        $chPlatform = trim($raw, "\\\"' \t");
        if ($chPlatform === '' || strcasecmp($chPlatform, 'Unknown') === 0) {
            return false;
        }

        $uaPlatform = self::detectUaPlatform($userAgent);
        if ($uaPlatform === '') {
            return false;
        }

        return strcasecmp($chPlatform, $uaPlatform) !== 0;
    }

    /**
     * Classify the OS implied by a User-Agent string. Order matters —
     * Android UAs contain "Linux" too, so check Android first.
     *
     * @param  string $ua The request User-Agent string.
     * @return string Canonical platform label, or '' when undetermined.
     */
    private static function detectUaPlatform(string $ua): string
    {
        if (stripos($ua, 'Android') !== false) {
            return 'Android';
        }
        if (
            stripos($ua, 'iPhone') !== false
            || stripos($ua, 'iPad') !== false
            || stripos($ua, 'iPod') !== false
        ) {
            return 'iOS';
        }
        if (stripos($ua, 'Macintosh') !== false || stripos($ua, 'Mac OS X') !== false) {
            return 'macOS';
        }
        if (stripos($ua, 'Windows') !== false) {
            return 'Windows';
        }
        if (stripos($ua, 'CrOS') !== false) {
            return 'Chrome OS';
        }
        if (stripos($ua, 'Linux') !== false) {
            return 'Linux';
        }
        return '';
    }

    /**
     * Filter out entries that can't be real bot-UA patterns and would
     * otherwise produce broad false-positives:
     *
     *  - Short substrings (< 3 chars) are too generic and cause false positives.
     *  - Pure-numeric entries are version numbers or stale boolean values that
     *    should never be substring-matched against a UA string.
     *
     * @param  string $needle A single admin-configured bot-UA substring.
     * @return boolean True when the entry is usable as a substring pattern.
     */
    private static function isValidExtraPattern(string $needle): bool
    {
        if ($needle === '' || strlen($needle) < 3) {
            return false;
        }
        if (ctype_digit($needle)) {
            return false;
        }
        return true;
    }
}
