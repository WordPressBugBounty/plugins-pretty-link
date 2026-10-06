<?php

declare(strict_types=1);

namespace PrettyLinks\Redirect;

use PrettyLinks\Helpers\LinkUrl;
use PrettyLinks\Options\Store as OptionsStore;
use wpdb;

/**
 * Pretty Links hot-path dispatcher. Fires on `init` at priority 1.
 *
 * Responsibilities:
 *  - Match the incoming REQUEST_URI against the configured slug pattern.
 *  - Resolve the link with one prepared SELECT, request-scoped static cache.
 *  - Emit redirect headers (no-cache, no-store) and Location.
 *  - Defer the click write via register_shutdown_function() + fastcgi_finish_request().
 *  - Atomic counter UPDATE for count-only tracking mode.
 *
 * See REWRITE-PLAN.md §5 and inventory/20-redirect-engine-deep-dive.md.
 */
class Engine
{
    /**
     * Characters permitted in a resolvable slug, as the body of a regex
     * character class (the part inside `[...]`). RFC 3986 path punctuation
     * (`-._~!$&'()*+,;=:@/` plus a literal space) plus Unicode letters and
     * numbers via `\p{L}\p{N}` (covers Latin/CJK/Cyrillic/Greek and more —
     * ASCII `A-Za-z0-9` are subsets of those properties) and combining marks
     * via `\p{M}` so NFD / Indic / Thai / Arabic grapheme clusters stay intact.
     *
     * This reproduces what Pretty Links 3.x accepted. v3 matched incoming
     * requests with `([^\?]*)` (see legacy PrliLink::is_pretty_link) — i.e.
     * any character except `?` — and stored slugs via sanitize_text_field(),
     * which preserves every printable character except tags and `%`-octets.
     * That INCLUDES a single space (`sanitize_text_field()` collapses runs of
     * whitespace and trims the ends, but keeps internal spaces), so v3 links
     * with spaces such as `O Neill Reactor 3/2 Neoprenanzug` were stored and
     * resolved verbatim — hence the literal space (`\x20`) below (#751). The
     * v4 rewrite had first narrowed this to `[A-Za-z0-9_\-/]` (any v3 link
     * containing `.`, `=`, `~`, `+`, etc. began returning a 404), and even
     * after that was widened the space was still missing. Non-Latin letters
     * were still stripped on save / rejected on resolve (#861) until `\p{L}`
     * / `\p{N}` landed.
     *
     * Only the true delimiters are excluded: `?` and `#` (query/fragment —
     * they can never appear in the path wp_parse_url() hands us) and `%`
     * (percent-encoding is decoded before matching; v3's sanitizer stripped
     * literal `%` too). Slugs are only ever used as prepared-statement lookup
     * keys, so this is an input allow-list, not an escaping boundary. Write-side
     * sanitisation goes through {@see canonicalizeSlug()}; the MU stub receives
     * this class via `%%PRLI_SLUG_CHAR_CLASS%%` at install time. Note
     * canonicalizeSlug() normalises whitespace to `-` (or a space when
     * `allow_slug_spaces` is on) before the strip, so a stored slug only
     * contains a space when that toggle is on or the row was migrated from
     * v3 — the space in the class keeps those legacy slugs resolvable.
     *
     * Every match/replace against this class MUST use the `/u` modifier —
     * without it `\p{L}`/`\p{N}` do not match non-ASCII. Prefer
     * {@see matchesSlugCharset()} / {@see stripToSlugCharset()} /
     * {@see canonicalizeSlug()} so the flag cannot be forgotten. The MU stub
     * receives the class via `%%PRLI_SLUG_CHAR_CLASS%%` at install time.
     *
     * @var string
     */
    public const SLUG_CHAR_CLASS = '\p{L}\p{N}\p{M}_\-/.~!$&\'()*+,;=:@\x20';

    /**
     * Whether `$slug` is non-empty and entirely within {@see SLUG_CHAR_CLASS}.
     *
     * Resolve-path check only — does not NFC-normalise. Canonicalisation
     * (including NFC) happens on write via {@see canonicalizeSlug()} so stored
     * form does not silently depend on whether `intl` appears later (#861).
     *
     * @param  string $slug Candidate slug (already decoded / trimmed by callers).
     * @return boolean
     */
    public static function matchesSlugCharset(string $slug): bool
    {
        return $slug !== '' && preg_match('#^[' . self::SLUG_CHAR_CLASS . ']+$#u', $slug) === 1;
    }

    /**
     * Strip every character outside {@see SLUG_CHAR_CLASS} from `$slug`.
     *
     * Returns `null` when the `/u` regex fails (typically malformed UTF-8) so
     * callers can surface `invalid_slug` instead of treating the failure as
     * "everything was stripped" and minting a random ASCII slug (#861).
     *
     * @param  string $slug Raw or partially-normalised slug.
     * @return string|null
     */
    public static function stripToSlugCharset(string $slug): ?string
    {
        return preg_replace('#[^' . self::SLUG_CHAR_CLASS . ']#u', '', $slug);
    }

    /**
     * Canonicalise a user-supplied slug for persistence / QR preview.
     *
     * Shared by Links save and QrPreview so both agree on whitespace,
     * charset strip, NFC (when intl is present), and trim. Returns `null` on
     * malformed UTF-8; `''` when the input is empty or strips to nothing.
     *
     * NFC is write-only: the resolve path matches the stored bytes as-is so
     * a host that later gains (or loses) `intl` cannot 404 existing links.
     *
     * @param  string  $slug        Raw user-supplied slug.
     * @param  boolean $allowSpaces When true, whitespace collapses to a space
     *                              (`allow_slug_spaces`); otherwise to `-`.
     * @return string|null
     */
    public static function canonicalizeSlug(string $slug, bool $allowSpaces = false): ?string
    {
        $slug = trim($slug);
        if ($slug === '') {
            return '';
        }
        $slug      = self::normalizeSlugUtf8($slug);
        $collapsed = preg_replace('/\s+/u', $allowSpaces ? ' ' : '-', $slug);
        if ($collapsed === null) {
            return null;
        }
        $stripped = self::stripToSlugCharset($collapsed);
        if ($stripped === null) {
            return null;
        }
        $slug = (string) preg_replace('#/+#', '/', $stripped);
        $slug = (string) preg_replace('/-+/', '-', $slug);
        return trim($slug, '-/ ');
    }

    /**
     * NFC-normalise a slug when the intl Normalizer is available.
     *
     * Used only on the write path ({@see canonicalizeSlug()}). Falls back to
     * the input unchanged when intl is absent or normalisation fails.
     *
     * @param  string $slug Decoded slug candidate.
     * @return string
     */
    private static function normalizeSlugUtf8(string $slug): string
    {
        if ($slug === '' || !class_exists(\Normalizer::class)) {
            return $slug;
        }
        $normalized = \Normalizer::normalize($slug, \Normalizer::FORM_C);
        return is_string($normalized) ? $normalized : $slug;
    }

    /**
     * WordPress database handle.
     *
     * @var wpdb
     */
    private wpdb $db;

    /**
     * Plugin options store.
     *
     * @var OptionsStore
     */
    private OptionsStore $options;

    /**
     * Request-scoped cache of resolved link rows, keyed by slug.
     *
     * @var array<string, array<string, mixed>|null>
     */
    private array $resolveCache = [];

    /**
     * Decoded, trimmed request path for this dispatch, exactly as it arrived —
     * before any home-path or `index.php/` stripping. Set by both slug
     * extractors, and read by languagePrefixedSlug() so it never has to infer
     * what was removed. Null until an extractor has run.
     *
     * @var string|null
     */
    private ?string $requestPath = null;

    /**
     * Decoded, trimmed path component of `home_url()` for this dispatch.
     *
     * Memoised because every caller pays for it twice otherwise, and under WPML
     * a `home_url()` call is not cheap: its filter builds a
     * `WPML_Home_Url_Filter_Context` whose `should_not_filter()` walks an
     * unbounded `debug_backtrace()`. Scoped to one request, where the value
     * cannot change.
     *
     * @var string|null
     */
    private ?string $filteredHomePath = null;

    /**
     * Idempotent click-write closure for the current dispatch, or null when no
     * write was scheduled. Runs at most once (guarded by {@see self::$clickWritten}).
     *
     * @var callable|null
     */
    private $pendingClickWrite = null;

    /**
     * Whether the scheduled click write has already run this request.
     *
     * @var boolean
     */
    private bool $clickWritten = false;

    /**
     * Constructor.
     *
     * @param wpdb         $db      WordPress database handle.
     * @param OptionsStore $options Plugin options store.
     */
    public function __construct(wpdb $db, OptionsStore $options)
    {
        $this->db      = $db;
        $this->options = $options;
    }

    /**
     * Hot-path entry point. Matches the request URI to a link, emits redirect
     * headers, resolves the target via filters, defers the click write, and
     * issues the redirect (or hands off to a Pro handler) before exiting.
     *
     * @return void
     */
    public function dispatch(): void
    {
        if (!$this->isDispatchableRequest()) {
            return;
        }

        // Raw, never sanitized — see rawServerString().
        $requestUri  = self::rawServerString('REQUEST_URI');
        $specialSlug = $this->extractSpecialRouteSlug($requestUri);
        if ($specialSlug !== null) {
            $link = $this->resolveWithAltDomainFallback($specialSlug['slug']);
            if ($link !== null) {
                /**
                 * Filter: prli_handle_special_route
                 *
                 * Allows Pro subsystems (QR codes, Pretty Bar, etc.) to handle
                 * special routes like `/{slug}/gen_qr_png` before the redirect
                 * path runs. Handlers should call exit; if they handle the
                 * request. Returning true indicates the handler took over.
                 *
                 * @param bool                 $handled     Whether handled already.
                 * @param string               $route       Special route name (gen_qr_png, gen_qr_svg, etc.).
                 * @param array<string, mixed> $link        Link row.
                 * @param string               $requestUri  Raw REQUEST_URI.
                 */
                $handled = apply_filters(
                    'prli_handle_special_route',
                    false,
                    $specialSlug['route'],
                    $link,
                    $requestUri
                );
                if ($handled === true) {
                    return;
                }
            }
        }

        $slug = $this->extractSlug($requestUri);
        if ($slug === null) {
            return;
        }

        $link = $this->resolveWithAltDomainFallback($slug);
        if ($link === null) {
            return;
        }

        $this->emitHeaders($link);

        $type = (string) ($link['redirect_type'] ?? '302');

        /**
         * Filter: prli_resolved_redirect_type
         *
         * Gives subsystems a chance to downgrade a link's configured redirect
         * type before the engine acts on it. Honors the link as-configured by
         * default; used for narrow, documented overrides — e.g. Pro coerces
         * `prettybar` to `302` when the Pretty Bar feature is disabled
         * site-wide, so the link still resolves without rendering the bar.
         * Lite has no hooks on this filter and never downgrades Pro types
         * itself — when Pro is absent those types reach the cloaked-redirect
         * action with no listener and fall through to `wp_redirect()` at the
         * 302 status forced below.
         *
         * @param string               $type Configured redirect_type value.
         * @param array<string, mixed> $link Link row.
         */
        $type = (string) apply_filters('prli_resolved_redirect_type', $type, $link);

        // 307 stays in the allow list so v3-era links with it set keep working,
        // but unknown/invalid values fall back to 302 (the new default).
        $status = in_array($type, ['301', '302', '307'], true) ? (int) $type : 302;
        $target = (string) ($link['url'] ?? '');

        /**
         * Filter: prli_target_url
         *
         * Allows Pro subsystems (rotation, targeting, expiration) to swap the
         * redirect target URL. Matches v3's signature: receives and returns an
         * associative array with at minimum `url`, `link_id`, `redirect_type`
         * keys. The full link row is merged in so v4 callbacks can access any
         * column. Returning an empty `url` key aborts the redirect silently.
         *
         * @param array<string, mixed> $data Merged link row + v3-compat keys:
         *   `url`           — resolved target URL (may differ from link row after prior filters),
         *   `link_id`       — link ID (v3 compat alias for `id`),
         *   `redirect_type` — redirect type string.
         */
        $filterData = array_merge($link, [
            'url'           => $target,
            'link_id'       => (int) ($link['id'] ?? 0),
            'redirect_type' => (string) ($link['redirect_type'] ?? ''),
        ]);
        $filtered   = apply_filters('prli_target_url', $filterData);
        $target     = is_array($filtered)
            ? (string) ($filtered['url'] ?? '')
            : (string) $filtered;

        // Pixel and pay (gateway) links have no target URL by design — their
        // response is emitted later by a `prli_handle_redirect_type` handler
        // (Pro's PixelRenderer, Stripe's CheckoutRedirect) and the click is
        // still recorded below. Mirrors v3's PrliUtils empty-URL exemption and
        // the same `pixel` / pay-link carve-out in Repositories\Links::create().
        // Every other type genuinely needs a destination, so a blank target
        // (e.g. a misconfigured 301, or one a prior filter cleared) still aborts.
        $isPayLink    = $type === 'prettypay_link_stripe' || (int) ($link['prettypay_link'] ?? 0) === 1;
        $isTargetless = $type === 'pixel' || $isPayLink;
        if ($target === '' && !$isTargetless) {
            return;
        }

        // Build the forwarded param string separately (not yet merged into
        // $target) so that prli_before_redirect receives $target and
        // $paramString as distinct by-reference args — matching v3's signature.
        // Raw, never sanitized — see rawServerString().
        $incomingQuery = self::rawServerString('QUERY_STRING');
        $paramString   = self::buildForwardedParamString($target, $link, $incomingQuery);

        /**
         * Action: prli_before_redirect
         *
         * Fires before the redirect is issued. Matches v3's signature exactly:
         * $target (base URL) and $paramString (forwarded query string) are both
         * passed by reference so hooks can mutate them. After the hook,
         * $paramString is merged into $target preserving any URL fragment,
         * then the site-wide tracking param is appended to the fully assembled
         * URL so duplicate-key detection runs against the complete query string.
         *
         * @param string $target      Base outbound URL (by reference).
         * @param string $paramString Forwarded query string with leading separator (by reference).
         * @param array  $_GET        Incoming GET parameters.
         */
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Redirect request is not a form submission; $_GET passed to v3-compatible hook for inspection only.
        do_action_ref_array('prli_before_redirect', [&$target, &$paramString, $_GET]);

        $target = self::appendParamString($target, $paramString);

        // A link whose target points back at its own pretty URL redirects for
        // ever — the browser follows, the engine matches the same slug again
        // (trailing slashes are trimmed, so `/docs` and `/docs/` are one link)
        // and the loop only ends when the browser gives up. Fall through to
        // WordPress instead, exactly as an unmatched slug does, so the site
        // can still serve a real page at that path. No click is recorded: the
        // redirect never happened. See issue #983.
        //
        // Checked here, after `prli_target_url`, `prli_before_redirect` and
        // param forwarding have all had their say, because any of them can
        // rewrite the target — and before the cloaked/pixel handlers below,
        // which exit on their own and would otherwise skip the guard.
        if (LinkUrl::isSelfReferential($target, (string) ($link['slug'] ?? ''))) {
            return;
        }

        $this->scheduleClickWrite((int) $link['id'], $link, $target);

        /**
         * Action: prli_issue_cloaked_redirect
         *
         * Fires for non-HTTP redirect types (cloak, prettybar, metarefresh,
         * javascript, etc.) to allow Pro subsystems to render and exit.
         * Matches v3's signature exactly. Handlers must call exit; themselves.
         *
         * @param string               $redirectType Link redirect_type value.
         * @param array<string, mixed> $link         Link row.
         * @param string               $target       Resolved outbound URL (with forwarded params).
         * @param string               $paramString  Forwarded query/param string for this redirect (v3 arg 4).
         * @param int                  $status       Fallback HTTP status (always 302 for cloaked types) (v4 additive arg 5).
         */
        do_action('prli_issue_cloaked_redirect', $type, $link, $target, $paramString, $status);

        /**
         * Filter: prli_handle_redirect_type
         *
         * Catchall for non-HTTP redirect_type values (e.g. `prettypay_link_stripe`,
         * `pixel`, future gateway types). Handler should emit its own response
         * and call exit; if it takes over. Return true to short-circuit the
         * default `wp_redirect` path.
         *
         * @param bool                 $handled Whether handled already.
         * @param array<string, mixed> $link    Link row.
         * @param string               $target  Resolved target URL.
         * @param int                  $status  Resolved HTTP status.
         * @param string               $type    Raw redirect_type value.
         */
        $typeHandled = apply_filters('prli_handle_redirect_type', false, $link, $target, $status, $type);
        if ($typeHandled === true) {
            return;
        }

        // A targetless type (pixel/pay) only reaches this point when no handler
        // claimed it — e.g. Pro was deactivated after the link was created.
        // There is no URL to redirect to, so abort cleanly. Key on
        // $isTargetless, not $target: param forwarding may have turned the
        // empty target into a bare query string (e.g. "?utm=abc"), which would
        // otherwise slip past an empty-string check and emit a relative
        // `Location:` redirect instead of falling through to a 404.
        if ($isTargetless || $target === '') {
            return;
        }

        $scheme = (string) wp_parse_url($target, PHP_URL_SCHEME);
        if (function_exists('wp_redirect') && in_array($scheme, ['http', 'https'], true)) {
            // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Pretty Links redirects to user-configured external destinations by design.
            wp_redirect($target, $status, 'Pretty Links');
        } else {
            // Non-http(s) schemes (tel:, sms:, mailto:, etc.) force 302
            // regardless of the configured 301/307 — permanent caching
            // is inappropriate for these, and wp_redirect would mangle them.
            // Strip CRLF at the emit boundary as defense-in-depth: storage
            // already strips it, but the by-reference prli_target_url /
            // prli_before_redirect hooks run after validation and could
            // reintroduce it. wp_redirect() sanitizes the http(s) path; this
            // raw header() is the only emit site without that safety net.
            header('Location: ' . str_replace(["\r", "\n"], '', $target), true, 302);
        }

        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        }

        // With synchronous tracking on, record the click now — after the
        // response is flushed (so no added visitor latency where a finish
        // function exists) but before the shutdown queue, so a foreign
        // shutdown `exit` can't abort it. Idempotent: the shutdown fallback
        // registered in scheduleClickWrite() then no-ops.
        if ((bool) $this->options->get('synchronous_click_tracking', false)) {
            $this->flushClickWrite();
        }

        exit;
    }

    /**
     * Guard clauses for the hot path: only plain front-end GET requests with
     * a usable REQUEST_URI reach slug resolution. Admin, WP-CLI, cron, REST,
     * AJAX, and XML-RPC contexts are never redirect requests.
     */
    private function isDispatchableRequest(): bool
    {
        if (is_admin() || (defined('WP_CLI') && WP_CLI) || (defined('DOING_CRON') && DOING_CRON)) {
            return false;
        }
        if (
            (defined('REST_REQUEST') && REST_REQUEST)
            || (defined('DOING_AJAX') && DOING_AJAX)
            || (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST)
        ) {
            return false;
        }
        if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'GET') {
            return false;
        }
        if (!isset($_SERVER['REQUEST_URI']) || !is_string($_SERVER['REQUEST_URI'])) {
            return false;
        }
        return true;
    }

    /**
     * Read a `$_SERVER` string unslashed but deliberately NOT sanitized.
     *
     * Both call sites need the value byte for byte. `sanitize_text_field()`
     * deletes every `%NN` octet — core's `_sanitize_text_fields()` loops
     * `preg_match('/%[a-f0-9]{2}/i')` + `str_replace` until none match — which
     * would drop the `%20` a browser sends for a slug containing a space
     * (#751) and silently corrupt any percent-encoded value being forwarded to
     * the destination (#818). It also collapses whitespace runs and trims.
     *
     * Not sanitizing here is safe because validation and escaping happen per
     * context downstream rather than up front. The request path is
     * rawurldecode()d and matched against the SLUG_CHAR_CLASS allow-list, then
     * looked up with a prepared statement — that pair is the security
     * boundary. The forwarded query string is never decoded; it is escaped at
     * every emit boundary instead: `wp_redirect()` runs
     * `wp_sanitize_redirect()` (which strips encoded CRLF outright), the raw
     * `header('Location: ...')` path strips CRLF itself, and the cloaked types
     * render the target through `esc_url()`/`esc_url_raw()`/`wp_json_encode()`.
     *
     * `wp_unslash()` is required: `wp_magic_quotes()` slashes all of
     * `$_SERVER` in wp-settings.php before `init`, where dispatch() runs. The
     * turbo dispatcher correctly omits it — mu-plugins load earlier still.
     *
     * Kept as one helper on purpose. The rationale was written at the
     * REQUEST_URI site in #751 and never carried to the QUERY_STRING site 110
     * lines below, so a later sweep for Plugin Check warnings "fixed" the
     * unprotected one and shipped #818. One annotated site cannot drift.
     *
     * @param string $key Key to read from `$_SERVER`.
     *
     * @return string Empty string when the key is absent.
     */
    private static function rawServerString(string $key): string
    {
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read verbatim by design (#751, #818); sanitizing destroys percent-encoding. Validated and escaped per context downstream — see the docblock above.
        return isset($_SERVER[$key]) ? (string) wp_unslash((string) $_SERVER[$key]) : '';
    }

    /**
     * Detects special post-slug routes like `/{slug}/gen_qr_png?download=<nonce>`.
     *
     * Slug can contain slashes (e.g. `go/abcd`) because the prefix is baked
     * into the stored slug — no runtime prefix concept exists here. The
     * special-route suffix is always the last path segment.
     *
     * @param string $requestUri Raw REQUEST_URI to inspect.
     *
     * @return array{slug: string, route: string}|null
     */
    private function extractSpecialRouteSlug(string $requestUri): ?array
    {
        $path = (string) wp_parse_url($requestUri, PHP_URL_PATH);
        if ($path === '' || $path === '/') {
            return null;
        }
        $path              = rawurldecode($path);
        $this->requestPath = trim($path, '/');
        $trim              = $this->stripHomePath(trim($path, '/'));
        // Strip an `index.php/` prefix (almost-pretty permalinks) for parity
        // with extractSlug(), so `/index.php/{slug}/gen_qr_png` — and its
        // subdirectory form — resolves the special route too.
        if (strpos($trim, 'index.php/') === 0) {
            $trim = substr($trim, strlen('index.php/'));
        }
        $lastSlash = strrpos($trim, '/');
        if ($lastSlash === false) {
            return null;
        }

        $route = substr($trim, $lastSlash + 1);
        $slug  = substr($trim, 0, $lastSlash);

        $allowedRoutes = ['gen_qr_png', 'gen_qr_svg'];
        if (!in_array($route, $allowedRoutes, true)) {
            return null;
        }

        // Drop the route segment from the recorded request path: what follows is
        // resolved as a SLUG, so languagePrefixedSlug() must not hand back a
        // candidate with `/gen_qr_png` still glued to the end.
        $arrivedSlash = strrpos((string) $this->requestPath, '/');
        if ($arrivedSlash !== false) {
            $this->requestPath = substr((string) $this->requestPath, 0, $arrivedSlash);
        }
        if (!self::matchesSlugCharset($slug)) {
            return null;
        }
        return [
            'slug'  => $slug,
            'route' => $route,
        ];
    }

    /**
     * Emit no-cache, signature, and robots headers for the resolved link.
     *
     * @param array<string, mixed> $link Resolved link row.
     */
    private function emitHeaders(array $link): void
    {
        if (headers_sent()) {
            return;
        }
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0', true);
        header('Pragma: no-cache', true);
        header('Expires: 0', true);
        // V3 parity: signature header with edition + version. Format
        // mirrors v3's `{$prli_edition} <version> http://prettylink.com`
        // where edition is the title-cased PRLI_EDITION slug
        // (e.g. `pretty-link-lite` → `Pretty Link Lite`). Version is
        // parsed from the plugin file's `Version:` header at request
        // time so bumping the version in pretty-link.php is the single
        // source of truth — no separate PRLI_VERSION constant to drift.
        $edition      = defined('PRLI_EDITION') ? (string) PRLI_EDITION : 'pretty-link-lite';
        $editionLabel = ucwords(str_replace('-', ' ', $edition));
        $headers      = function_exists('get_file_data') && defined('PRLI_FILE')
            ? get_file_data(PRLI_FILE, ['Version' => 'Version'])
            : [];
        $version      = (string) ($headers['Version'] ?? '');
        header(
            'X-Redirect-Powered-By: ' . $editionLabel . ' ' . $version
                . ' http://prettylink.com',
            true
        );

        $robots = [];
        if (!empty($link['nofollow'])) {
            $robots[] = 'noindex';
            $robots[] = 'nofollow';
        }
        if (!empty($link['sponsored'])) {
            $robots[] = 'sponsored';
        }
        if ($robots !== []) {
            header('X-Robots-Tag: ' . implode(', ', $robots), true);
        }
    }

    /**
     * Resolve the slug from an incoming request path. The stored slug is the
     * full path (prefix baked in at link creation time) — no runtime prefix
     * stripping or rewriting. A configured `base_slug_prefix` is purely a
     * prefill hint on the new-link form, not a resolution-time concept.
     *
     * @param string $requestUri Raw REQUEST_URI to extract the slug from.
     *
     * @return string|null
     */
    private function extractSlug(string $requestUri): ?string
    {
        $path = (string) wp_parse_url($requestUri, PHP_URL_PATH);
        if ($path === '' || $path === '/') {
            return null;
        }
        $path = rawurldecode($path);
        // Kept for languagePrefixedSlug(), which must work from the path as it
        // ARRIVED rather than reconstructing it. See that method.
        $this->requestPath = trim($path, '/');

        $slug = $this->stripHomePath(trim($path, '/'));
        if ($slug === '') {
            return null;
        }

        // Almost-pretty permalinks (`/index.php/%postname%/`) route every
        // request through an `/index.php/` prefix at the web-server layer.
        // Strip it so the slug matches the bare form stored in the DB.
        // `index.php` can't be a legitimate user slug, so unconditional
        // stripping is safe.
        if (strpos($slug, 'index.php/') === 0) {
            $slug = substr($slug, strlen('index.php/'));
        }
        if ($slug === '') {
            return null;
        }

        // Slugs can contain slashes (e.g. `go/abcd`), so `/` is valid here.
        if (!self::matchesSlugCharset($slug)) {
            return null;
        }

        return $slug;
    }

    /**
     * Strip the WordPress subdirectory base path from an already-trimmed
     * request path. On a site installed at `example.com/blog`, `home_url()`
     * carries a `/blog` path segment that WP's own request parser removes
     * before matching; the engine bypasses that parser, so it must remove the
     * same segment itself or every slug lookup on a subdirectory install
     * fails. Only the single leading occurrence is removed, so a legitimate
     * slug that repeats the base segment (e.g. `blog/blog`) still resolves.
     * Collapses to a no-op on root installs, where the base path is empty.
     *
     * @param string $slug Trimmed request path (no surrounding slashes).
     *
     * @return string
     */
    private function stripHomePath(string $slug): string
    {
        $homePath = $this->filteredHomePath();
        if ($homePath === '') {
            return $slug;
        }
        if ($slug === $homePath) {
            return '';
        }
        $prefix = $homePath . '/';
        if (strpos($slug, $prefix) === 0) {
            return substr($slug, strlen($prefix));
        }
        return $slug;
    }

    /**
     * Resolve a link row by slug with a request-scoped static cache. Returns
     * the matching non-deleted row, or null when no link matches.
     *
     * @param string $slug Slug to look up.
     *
     * @return array<string, mixed>|null
     */
    private function resolve(string $slug): ?array
    {
        if (array_key_exists($slug, $this->resolveCache)) {
            return $this->resolveCache[$slug];
        }

        $table                     = $this->db->prefix . 'prli_links';
        $sql                       = $this->db->prepare(
            // Oldest live row wins; no UNIQUE on `slug`, so duplicates remain
            // possible — see Links::slugTaken() for why (#852).
            "SELECT * FROM {$table}
             WHERE slug = %s
               AND deleted_at IS NULL
             ORDER BY id ASC
             LIMIT 1",
            $slug
        );
        $row                       = $this->db->get_row($sql, ARRAY_A);
        $this->resolveCache[$slug] = is_array($row) ? $row : null;
        return $this->resolveCache[$slug];
    }

    /**
     * Resolve a slug verbatim, then — only on a miss — retry with the
     * alternate-domain path prefix stripped.
     *
     * The pretty-link base (`prli_pretty_link_base`, honored by
     * `Helpers\LinkUrl::build()` when rendering public URLs) can carry a path
     * segment: Pro's "Alternate Domain" setting may point at
     * `https://example.com/go/`, and some v3 sites used that path as a
     * de-facto base-slug prefix. v3 stripped it at redirect time because a
     * single `$prli_blogurl` drove both URL building and request matching; v4
     * split those, so the path is prepended when rendering but not removed
     * when resolving, and the public URL 404s. Restoring the symmetry here
     * keeps a stored slug like `go/abcd` winning over any inference. See issue
     * #752 for that half.
     *
     * Three tiers, in order:
     *   1. A language-prefixed candidate, when something has decorated
     *      `home_url()` for this request — see languagePrefixedSlug() (#850).
     *      Absent on virtually every site, and skipped entirely on alternate
     *      domains.
     *   2. The slug verbatim, as the extractors produced it — today's lookup.
     *   3. The alternate-domain path stripped (#752).
     *
     * @param string $slug Verbatim slug extracted from the request.
     *
     * @return array<string, mixed>|null
     */
    private function resolveWithAltDomainFallback(string $slug): ?array
    {
        // A multilingual plugin may have eaten a real part of this slug before
        // we ever saw it — try to get it back first. See
        // languagePrefixedSlug() for the whole story; on the overwhelming
        // majority of sites this returns null and nothing below changes.
        $prefixed = $this->languagePrefixedSlug($slug);
        if ($prefixed !== null) {
            $link = $this->resolve($prefixed);
            if ($link !== null) {
                return $link;
            }
        }

        $link = $this->resolve($slug);
        if ($link !== null) {
            return $link;
        }
        $stripped = $this->stripAltDomainPath($slug);
        if ($stripped !== null && $stripped !== $slug) {
            return $this->resolve($stripped);
        }
        return $link;
    }

    /**
     * The slug this request would have had if nobody had rewritten
     * `home_url()`, or null when that can't apply.
     *
     * Invariants, for anyone editing this — the rest of the docblock is why:
     *   - Build from the path as it ARRIVED (`$this->requestPath`); never
     *     rebuild it from the stripped slug.
     *   - Remove the RAW home path, never the filtered one.
     *   - Only act when the filtered path EXTENDS the raw one.
     *   - Any alternate base opts the site out entirely.
     *
     * ## The bug (#850)
     *
     * `extractSlug()` removes the site's install directory from the request
     * path before matching, because stored slugs never include it: on
     * `example.com/blog`, `/blog/go/abc` has to find the slug `go/abc`. It
     * learns that directory from `home_url()`.
     *
     * WPML filters `home_url()` (at priority -10, in
     * `wpml-url-filters.class.php`) so it carries the ACTIVE LANGUAGE as a
     * directory — `https://example.com/nl` on a Dutch request. The stripper
     * cannot tell that apart from a real install directory, so on
     * `/nl/go/example` it removes `nl/` and looks up `go/example`.
     *
     * For a site that deliberately created `nl/go/example` as its own link with
     * a Dutch destination, that link becomes unreachable: every language
     * resolves to the one base slug. Reported by a Pro customer whose Dutch and
     * German links all served the English target.
     *
     * ## Why compare two sources instead of trusting one
     *
     * The install directory is knowable from the stored `home` option, which
     * WPML does not rewrite in this context — its `pre_option_home` callback is
     * backtrace-gated to calls originating in theme files
     * (`sitepress.class.php:3425`), so a plugin's `get_option('home')` is
     * untouched. When the two sources disagree, something is decorating the
     * filtered one for this request, and the leading segment is suspect.
     *
     * Neither source is authoritative — `option_home` is filterable too, core
     * itself uses it for `WP_HOME` — so the disagreement is used only to decide
     * whether to TRY an extra lookup. The candidate still has to match a real
     * row to change anything, and when it doesn't, resolution proceeds exactly
     * as before. That is what makes this additive: a site with no
     * language-prefixed links behaves identically to today, including still
     * serving the base slug for `/nl/go/example`.
     *
     * ## Why the raw home path, rather than the path verbatim
     *
     * Stripping nothing at all works on a root install, where the raw path is
     * empty — but not on a subdirectory install, where the request is
     * `/blog/nl/go/example`. Verbatim gives `blog/nl/go/example`, which can
     * never match a stored slug, so the fix would silently do nothing there.
     * Removing the RAW path gives `nl/go/example` on both.
     *
     * ## Scope
     *
     * Directory-based language negotiation only. WPML's other two modes put the
     * language in the host (`nl.example.com`) or a query parameter
     * (`?lang=nl`), leaving the path identical to the default language — there
     * is no prefix to recover and nothing here fires.
     *
     * Alternate domain (Pro) is deliberately excluded, see below.
     *
     * @param string $slug Slug as `extractSlug()` produced it.
     *
     * @return string|null Language-prefixed candidate, or null when not applicable.
     */
    private function languagePrefixedSlug(string $slug): ?string
    {
        // Resolved ONCE and reused below. Under WPML every `home_url()` call runs
        // `WPML_Home_Url_Filter_Context::should_not_filter()`, which walks an
        // unbounded `debug_backtrace()` — so on exactly the sites this fix
        // targets, each extra call is a real cost on the redirect path.
        $homeUrl      = (string) home_url();
        $filteredPath = $this->filteredHomePath();
        // No install directory reported means nothing was stripped, so there is
        // nothing to put back. Also the cheap early exit for the root installs
        // that most sites are.
        if ($filteredPath === '') {
            return null;
        }

        // Alternate domain: left entirely alone. Pretty URLs on those sites are
        // built from `prli_pretty_link_base`, NOT `home_url()`, so WPML never
        // decorates them and `/nl/go/slug` is not a URL such a site emits.
        // Recovering a prefix that cannot occur would only add lookups, and
        // stacking a language directory on an alt-domain path
        // (`/nl/klik/slug`) leaves two prefixes with no way to attribute which
        // segment belongs to which.
        //
        // ANY rewrite of the base opts out, compared whole rather than by path:
        // a pathless alternate host (`https://short.example.com`) has no path to
        // notice, and a path that happens to equal the decorated one would be
        // missed too. On a site with no alternate domain the filter returns
        // `home_url()` untouched, so the two are identical and this is a no-op.
        $altBase = (string) apply_filters('prli_pretty_link_base', $homeUrl);
        // Trailing slashes only, deliberately: `LinkUrl::build()` rtrims this
        // same base, so a base that differs by nothing but a slash is not an
        // alternate domain and must not opt the site out.
        if (rtrim($altBase, '/') !== rtrim($homeUrl, '/')) {
            return null;
        }

        // This doubles as the supported escape hatch: a site that wants prefix
        // recovery off can filter `prli_pretty_link_base` to any base differing
        // from `home_url()` by more than a trailing slash.
        //
        // The flip side, deliberately not normalised away: a filter that only
        // canonicalises — forcing https, dropping `www` — also opts the site
        // out, silently. Scheme and host are left in the comparison because we
        // cannot tell that apart from a deliberate alternate host, and opting
        // out is the safe direction; the cost is that such a site keeps the
        // pre-#850 behaviour.
        //
        // WPML leaves `option_home` alone on this path, but other plugins may
        // filter it — and if both sources end up agreeing, recovery is skipped
        // and the original bug persists for that site. Not universal coverage.
        $rawPath = $this->homePath((string) get_option('home'));
        if ($rawPath === $filteredPath) {
            // Sources agree: the leading segment really is the install
            // directory. This is every non-multilingual site, and every
            // default-language request on a multilingual one.
            return null;
        }

        // Only when the decorated path EXTENDS the real one — `blog` becoming
        // `blog/nl`, which is the shape a language directory produces. Anything
        // else is an unrelated rewrite (raw `blog`, filtered `portal`) whose
        // segments we have no business reinterpreting.
        if ($rawPath !== '' && strpos($filteredPath . '/', $rawPath . '/') !== 0) {
            return null;
        }

        // Derived from the path as it ARRIVED, never rebuilt from the stripped
        // slug. Re-prepending `$filteredPath` would assume `extractSlug()` had
        // removed it, and it only does so when the request actually starts with
        // it — `stripHomePath()` returns the path untouched otherwise. On a
        // request that carries no language segment while `home_url()` is
        // decorated, that assumption invents a prefix which was never there and
        // hands the resulting row priority over the real match.
        if ($this->requestPath === null) {
            return null;
        }
        $prefixed = self::removeLeadingSegment($this->requestPath, $rawPath);

        // Parity with the extractors: `index.php/` is a web-server artifact of
        // almost-pretty permalinks, never part of a stored slug. It sits AFTER
        // the language segment here (`nl/index.php/go/abc`), because the
        // extractors strip the home path first and this candidate keeps the
        // segment they removed — so match the first segment-aligned occurrence
        // rather than only a leading one. `index.php` cannot be a legitimate
        // slug segment, so this is unambiguous.
        $prefixed = (string) preg_replace('#(^|/)index\.php/#', '$1', $prefixed, 1);

        // Nothing recovered (the request had no extra segment) or identical to
        // what we are already about to try.
        return ($prefixed === '' || $prefixed === $slug) ? null : $prefixed;
    }

    /**
     * Decoded, trimmed path component of a URL, for comparing home paths.
     *
     * Decoded because the callers compare against an already-decoded request
     * path: a home path of `/my%20site` has to match `my site`.
     *
     * @param string $url URL to take the path from.
     *
     * @return string
     */
    private function homePath(string $url): string
    {
        return trim(rawurldecode((string) wp_parse_url($url, PHP_URL_PATH)), '/');
    }

    /**
     * `home_url()`'s path for this dispatch, resolved once.
     *
     * Decoded to match the callers, which have already decoded the request
     * path: a home path of `/my%20site` must compare against `my site`.
     *
     * @return string
     */
    private function filteredHomePath(): string
    {
        if ($this->filteredHomePath === null) {
            $this->filteredHomePath = $this->homePath((string) home_url());
        }
        return $this->filteredHomePath;
    }

    /**
     * Remove a leading path prefix, or return '' when the path IS that prefix.
     *
     * The prefix may be multi-segment (`blog`, or `blog/nl` if reused that way).
     * Only the first occurrence goes, so a slug repeating it (`blog/blog`) still
     * resolves.
     *
     * @param string $slug    Trimmed request path.
     * @param string $segment Segment to remove, without surrounding slashes.
     *
     * @return string
     */
    private static function removeLeadingSegment(string $slug, string $segment): string
    {
        if ($segment === '') {
            return $slug;
        }
        if ($slug === $segment) {
            return '';
        }
        $prefix = $segment . '/';
        if (strpos($slug, $prefix) === 0) {
            return substr($slug, strlen($prefix));
        }
        return $slug;
    }

    /**
     * Strip the alternate-domain path prefix from a slug, or null when there
     * is nothing to strip.
     *
     * The home-subdirectory path is already removed by `stripHomePath()`
     * before slugs reach resolution, so the alt-domain path is reduced to the
     * segment(s) beyond the home path too (e.g. home `/blog`, alt-domain
     * `/blog/go` → strip a leading `go/`). A no-op when the pretty-link base
     * has no path, or its path is just the home path (the common case — no
     * alt domain, or an alt domain at its own root).
     *
     * @param string $slug Slug whose verbatim lookup missed.
     *
     * @return string|null Stripped slug, or null when the prefix is absent.
     */
    private function stripAltDomainPath(string $slug): ?string
    {
        $base     = (string) apply_filters('prli_pretty_link_base', home_url());
        $altPath  = trim(rawurldecode((string) wp_parse_url($base, PHP_URL_PATH)), '/');
        $homePath = trim(rawurldecode((string) wp_parse_url((string) home_url(), PHP_URL_PATH)), '/');
        if ($altPath === '' || $altPath === $homePath) {
            return null;
        }
        // Reduce to the alt-domain path beyond the already-stripped home path.
        if ($homePath !== '' && strpos($altPath . '/', $homePath . '/') === 0) {
            $altPath = trim(substr($altPath, strlen($homePath)), '/');
        }
        if ($altPath === '') {
            return null;
        }
        $needle = $altPath . '/';
        if (strncmp($slug, $needle, strlen($needle)) !== 0) {
            return null;
        }
        $stripped = substr($slug, strlen($needle));
        return $stripped !== '' ? $stripped : null;
    }

    /**
     * Per-link param forwarding. Pure function — takes the outbound target,
     * the hydrated link row (for its `param_forwarding` flag), and the
     * incoming request's raw query string. Returns the target with the
     * forwarded params appended when the feature is on, or unchanged when
     * off.
     *
     * @param string               $target        Outbound target URL.
     * @param array<string, mixed> $link          Hydrated link row.
     * @param string               $incomingQuery Raw incoming query string.
     *
     * @return string
     */
    public static function forwardIncomingParams(string $target, array $link, string $incomingQuery): string
    {
        $paramString = self::buildForwardedParamString($target, $link, $incomingQuery);
        return self::appendParamString($target, $paramString);
    }

    /**
     * Append a forwarded param string to the target URL, preserving any
     * fragment so the params land before the `#` (matching v3 behaviour).
     * Returns $target unchanged when $paramString is empty.
     *
     * @param string $target      Outbound URL, possibly with a fragment.
     * @param string $paramString Leading-separator query string to append.
     */
    private static function appendParamString(string $target, string $paramString): string
    {
        if ($paramString === '') {
            return $target;
        }
        $hashPos = strpos($target, '#');
        if ($hashPos === false) {
            return $target . $paramString;
        }
        return substr($target, 0, $hashPos) . $paramString . substr($target, $hashPos);
    }

    /**
     * Build the forwarded query string for a link without merging it into the
     * target URL. Returns the param string with its leading separator (`?` or
     * `&`), or an empty string when forwarding is off or there is nothing to
     * forward. Used by the dispatch flow to keep $target and $paramString
     * separate for the prli_before_redirect hook (v3 signature compat).
     *
     * @param string               $target        Outbound target URL.
     * @param array<string, mixed> $link          Hydrated link row.
     * @param string               $incomingQuery Raw incoming query string.
     *
     * @return string
     */
    public static function buildForwardedParamString(string $target, array $link, string $incomingQuery): string
    {
        $mode = (string) ($link['param_forwarding'] ?? 'off');
        if ($mode === '' || $mode === 'off' || $mode === '0') {
            return '';
        }
        if ($incomingQuery === '') {
            return '';
        }

        $existingQuery = (string) wp_parse_url($target, PHP_URL_QUERY);
        $separator     = ($existingQuery !== '') ? '&' : '?';
        $paramString   = $separator . $incomingQuery;

        // V3 decoded %5B/%5D back to [ and ] so array params landed readable
        // on the target. Some upstream handlers depend on this exact shape.
        $paramString = str_ireplace(['%5B', '%5D'], ['[', ']'], $paramString);

        /**
         * Filter: prli_redirect_params
         *
         * Modify the forwarded query string before appending it to the
         * target URL. Preserved from v3 for third-party compat. The value
         * is passed WITH its leading separator (`?` or `&`). Return an
         * empty string to suppress forwarding.
         *
         * @param string               $paramString   Leading-separator query string.
         * @param array<string, mixed> $link          Hydrated link row.
         * @param string               $incomingQuery Raw $_SERVER['QUERY_STRING'].
         */
        return (string) apply_filters('prli_redirect_params', $paramString, $link, $incomingQuery);
    }

    /**
     * Defer the click write to shutdown when tracking is enabled for the link.
     * Resolves visitor/first-click/dedup cookies before the response flushes,
     * then registers a shutdown handler that records the click per the
     * configured tracking mode.
     *
     * @param integer              $linkId Link ID being tracked.
     * @param array<string, mixed> $link   Resolved link row.
     * @param string               $url    Resolved outbound URL recorded with the click.
     *
     * @return void
     */
    private function scheduleClickWrite(int $linkId, array $link, string $url): void
    {
        if ((int) apply_filters('prli_track_link', $link['track_me'] ?? 0, $linkId) === 0) {
            return;
        }
        if (defined('REST_REQUEST') && REST_REQUEST) {
            return;
        }

        // Three tracking modes matching v3 values:
        // extended → prli_clicks row with parsed UA (browser/device/os),
        // plus prli_links.clicks/uniques fast-read bumps
        // normal   → prli_clicks row, raw fields only (no UA parsing),
        // plus prli_links.clicks/uniques fast-read bumps — default
        // count    → static-clicks / static-uniques meta bumps in
        // prli_link_metas only; no prli_clicks row, no bump
        // on prli_links.clicks/uniques.
        $mode = (string) $this->options->get('extended_tracking', 'normal');

        // Resolve and set vuid / first-click / dedup cookies before the
        // response is flushed — setcookie() inside a shutdown function
        // after fastcgi_finish_request() silently drops the Set-Cookie
        // header. first_click drives `uniques` (meta in count mode,
        // prli_links column in normal/extended); vuid is only consumed
        // by normal/extended rows but the cookie is still set in count
        // mode so a later mode switch has one ready.
        $vuid       = ClickWriter::resolveVisitorId();
        $firstClick = ClickWriter::resolveFirstClick($linkId);

        if (ClickWriter::resolveDedup($linkId)) {
            return;
        }

        // Idempotent write, guarded so it runs at most once regardless of how
        // many code paths invoke it (inline flush + shutdown fallback).
        $this->pendingClickWrite = function () use ($linkId, $url, $mode, $vuid, $firstClick): void {
            if ($this->clickWritten) {
                return;
            }
            $this->clickWritten = true;
            try {
                $writer = new ClickWriter($this->db, $this->options);
                if ($mode === 'extended') {
                    $writer->writeExtended($linkId, $url, $vuid, $firstClick);
                } elseif ($mode === 'count') {
                    $writer->writeCount($linkId, $firstClick);
                } else {
                    $writer->writeNormal($linkId, $url, $vuid, $firstClick);
                }
            } catch (\Throwable $e) {
                // Swallow — a failed click write must never break the redirect response.
                // Always log: this runs post-response so a silent failure is invisible
                // to the user. Previous gating on WP_DEBUG hid a broken vendor prefix
                // that stopped click recording entirely.
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Post-response click-write failure must be visible; a previous WP_DEBUG gate hid a broken build.
                error_log(sprintf(
                    '[PrettyLinks] click write failed: %s in %s:%d',
                    $e->getMessage(),
                    $e->getFile(),
                    $e->getLine()
                ));
            }
        };

        // Default path: defer to shutdown (post-response, zero visitor latency).
        // When `synchronous_click_tracking` is on, dispatch() also calls the
        // write inline right after the response is flushed — see flushClickWrite().
        // Registering the shutdown fallback in both modes is harmless: the
        // idempotency guard means it no-ops if the inline write already ran, and
        // it still covers redirect-type handlers that exit before the inline point.
        register_shutdown_function($this->pendingClickWrite);
    }

    /**
     * Run the scheduled click write inline (idempotent). Called by dispatch()
     * after the response is flushed when synchronous tracking is enabled, so the
     * click lands before PHP's shutdown queue — where a foreign shutdown `exit`
     * could otherwise abort the deferred write.
     *
     * @return void
     */
    private function flushClickWrite(): void
    {
        if ($this->pendingClickWrite !== null) {
            ($this->pendingClickWrite)();
        }
    }
}
