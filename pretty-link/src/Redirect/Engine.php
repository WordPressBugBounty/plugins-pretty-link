<?php

declare(strict_types=1);

namespace PrettyLinks\Redirect;

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

        $requestUri  = isset($_SERVER['REQUEST_URI'])
            ? sanitize_text_field(wp_unslash((string) $_SERVER['REQUEST_URI']))
            : '';
        $specialSlug = $this->extractSpecialRouteSlug($requestUri);
        if ($specialSlug !== null) {
            $link = $this->resolve($specialSlug['slug']);
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

        $link = $this->resolve($slug);
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
        $incomingQuery = isset($_SERVER['QUERY_STRING']) ? sanitize_text_field(wp_unslash((string) $_SERVER['QUERY_STRING'])) : '';
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
        $path      = rawurldecode($path);
        $trim      = trim($path, '/');
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
        if ($slug === '' || !preg_match('#^[A-Za-z0-9_\-/]+$#', $slug)) {
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

        $slug = trim($path, '/');
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
        if (!preg_match('#^[A-Za-z0-9_\-/]+$#', $slug)) {
            return null;
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
            "SELECT * FROM {$table}
             WHERE slug = %s
               AND deleted_at IS NULL
             LIMIT 1",
            $slug
        );
        $row                       = $this->db->get_row($sql, ARRAY_A);
        $this->resolveCache[$slug] = is_array($row) ? $row : null;
        return $this->resolveCache[$slug];
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
        $db   = $this->db;
        $opts = $this->options;

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

        register_shutdown_function(static function () use ($db, $linkId, $url, $mode, $opts, $vuid, $firstClick): void {
            try {
                $writer = new ClickWriter($db, $opts);
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
        });
    }
}
