<?php

/**
 * MonsterInsights cross-plugin compatibility shim.
 *
 * Pretty Links 4.0 dropped the v3 CPT (`post_type=pretty-link`) and the
 * v3 PHP class graph. MonsterInsights (Lite + Pro) integrates against
 * both. Without this shim the URL Builder → "Create Pretty Link" flow
 * silently no-ops, click tracking fatals on every redirect, and MI's
 * addons page reports Pretty Links as "Not Active" even when v4 is
 * running. The shim restores wire-level compatibility without putting
 * MI-specific code into v4 core.
 *
 * MI integration map (verified against MI 9.x source under
 * `wp-content/plugins/google-analytics-for-wordpress` and the matching
 * Premium plugin):
 *
 *  1. Detection. `class_exists('PrliBaseController')` is MI's sole
 *     signal that Pretty Links is *active* (see
 *     `includes/admin/routes.php`). The Vue URL Builder gates the
 *     "Create Pretty Link" navigation on `isAddonActive('pretty-link')`,
 *     so without the class the entire URL-Builder flow is dead.
 *     ➜ shim: declare an empty `PrliBaseController` class.
 *
 *  2. URL Builder navigation. Vue handler `copyToPrettyLinks` in
 *     `lite/assets/vue/js/settings.min.js` writes
 *     `localStorage.MonsterInsightsURL = JSON.stringify({value: url,
 *     expiry: now + 600})` and navigates to
 *     `wp-admin/post-new.php?post_type=pretty-link&monsterinsights_reference=url_builder`.
 *     v4 has no CPT, so that URL would `wp_die("Invalid post type")`.
 *     ➜ shim: on `admin_init`, detect the MI signature and 302 to
 *       `admin.php?page=pretty-link-add-new` preserving the marker.
 *
 *  3. URL Builder prefill. MI's own footer JS
 *     (`monsterinsights_tools_copy_url_to_prettylinks` in
 *     `includes/helpers.php`) reads localStorage, parses UTM params
 *     out of the URL to derive a post title, and pastes the values
 *     into v3's `textarea[name="prli_url"]` and
 *     `input[name="post_title"]`. Footer JS only fires on the v3 CPT
 *     URL, which we redirected away from — so MI's prefill never runs.
 *     ➜ shim: bridge JS (assets/js/monsterinsights-shim.js) reads the
 *       same localStorage key (with the same expiry handling and the
 *       same UTM-to-title formula) and feeds the values into v4's
 *       `window.prliAdmin.prefill` payload, which the React LinkForm
 *       reads on mount.
 *
 *  4. Click tracking. MI's `prli_before_redirect` callback uses
 *     `global $prli_link; $prli_link->get_one_by('url', $url)` and
 *     `PrliUtils::get_pretty_link_url($slug)`. v4 fires
 *     `prli_before_redirect` with the v3 signature already, but the
 *     class and global don't exist.
 *     ➜ shim: declare a `PrliUtils` class with `get_pretty_link_url`
 *       and populate `$prli_link` with a thin object exposing
 *       `get_one_by('url', $url)`. Both delegate to
 *       `Repositories\Links` so v4's filters/base URL still apply.
 *
 *  5. Addons-page settings link. MI's addons list links Pretty Links
 *     "Settings" to `edit.php?post_type=pretty-link`. v4 has no such
 *     screen.
 *     ➜ shim: when the request comes from a MonsterInsights referer,
 *       redirect to `admin.php?page=pretty-link-links`.
 *
 *  6. Onboarding-bypass. v3 used the `prli_onboard` option to redirect
 *     fresh installs to a welcome screen — MI cleared/restored it to
 *     bypass during the URL Builder flow. v4 replaces that with the
 *     `prli_redirect_to_onboarding` transient consumed by
 *     `Onboarding\FirstRunRedirect::maybeRedirect()` on `admin_init`/10.
 *     Without intervention, our redirect lands the user on the add-new
 *     URL → FirstRunRedirect intercepts → user ends up on the wizard,
 *     localStorage URL never read.
 *     ➜ shim: delete the transient inside our `admin_init`/1 handler
 *       before redirecting. The transient is one-shot anyway
 *       (FirstRunRedirect deletes it on consume), so eating it early
 *       has the same end state. Onboarding stays discoverable via the
 *       resume notice (`Wizard::injectResumeNotice`) since
 *       `prli_onboarding_done` is still false. MI's `prli_onboard`
 *       option get/set is a no-op against v4 (option doesn't exist).
 *
 * v4 plugin basename remains `pretty-link/pretty-link.php`, matching
 * MI's hardcoded `array_key_exists('pretty-link/pretty-link.php', ...)`
 * "installed" check, so no shim is required for installed-state.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

use PrettyLinks\Repositories\Links as PrliLinksRepo;

/**
 * Touchpoint #1 — Active-plugin detection class.
 *
 * MI checks `class_exists('PrliBaseController')` to decide whether
 * Pretty Links is active. The Vue URL Builder gates its "Create
 * Pretty Link" navigation on this. v4 does not extend or use this
 * class internally, so an empty marker is sufficient.
 */
if (!class_exists('PrliBaseController', false)) {
    class PrliBaseController // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound, PSR1.Classes.ClassDeclaration.MissingNamespace -- v3/MonsterInsights compat: this global class name is the detection contract and must stay non-namespaced.
    {
    }
}

/**
 * Touchpoint #4a — `PrliUtils::get_pretty_link_url()` static surface.
 *
 * Resolves through the v4 Links repo so any `prli_pretty_link_base`
 * filtering still applies. Returns '' for unknown / soft-deleted slugs
 * rather than throwing — MI's caller string-concatenates the result
 * into a tracking event and an empty value is safer than a fatal.
 */
if (!class_exists('PrliUtils', false)) {
    /**
     * Minimal v3 `PrliUtils` shim, scoped to the surface MonsterInsights
     * touches. Expand only when a *new* confirmed MI integration point
     * needs it — speculative members violate the v4 cleanup rules.
     */
    class PrliUtils // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound, PSR1.Classes.ClassDeclaration.MissingNamespace, PSR1.Classes.ClassDeclaration.MultipleClasses -- v3/MonsterInsights compat: this global class name is the integration contract and must stay non-namespaced in this shared shim file.
    {
        /**
         * Resolve a slug to its fully-qualified pretty URL.
         *
         * @param  string $slug Link slug to resolve.
         * @return string The pretty URL, or '' for unknown/soft-deleted slugs.
         */
        public static function get_pretty_link_url(string $slug): string // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- v3/MonsterInsights compat: MI calls this exact snake_case method name.
        {
            $link = (new PrliLinksRepo())->findBySlug($slug);
            if (!is_array($link) || !empty($link['deleted_at'])) {
                return '';
            }
            return (string) ($link['pretty_url'] ?? '');
        }
    }
}

/**
 * Touchpoint #4b — `global $prli_link` lookup stub.
 *
 * MI calls `$prli_link->get_one_by('url', $url)` and reads `->slug` and
 * `->name`. Populate the global on `init` (before any redirect could
 * fire `prli_before_redirect`) with an anonymous object exposing only
 * the column MI queries.
 */
add_action('init', static function (): void {
    global $prli_link;
    if (isset($prli_link) && is_object($prli_link) && method_exists($prli_link, 'get_one_by')) {
        return;
    }
    $prli_link = new class {
        /**
         * Match v3's PrliLink::get_one_by() narrowly: MI only passes
         * `'url'`. Anything else returns null rather than pretending
         * to support the full v3 signature.
         *
         * @param  string $column Column to match on; only 'url' is supported.
         * @param  mixed  $value  Value to match against the column.
         * @return object|null
         */
        public function get_one_by(string $column, $value) // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- v3/MonsterInsights compat: MI calls this exact snake_case method name.
        {
            if ($column !== 'url') {
                return null;
            }
            $row = (new PrliLinksRepo())->findByUrl((string) $value);
            if (!is_array($row) || !empty($row['deleted_at'])) {
                return null;
            }
            return (object) $row;
        }
    };
}, 1);

/**
 * Touchpoint #2 — URL Builder redirect.
 *
 * `post-new.php?post_type=pretty-link&monsterinsights_reference=url_builder`
 * → `admin.php?page=pretty-link-add-new&monsterinsights_reference=url_builder`.
 * The marker is preserved so the bridge JS knows to read localStorage.
 */
add_action('admin_init', static function (): void {
    global $pagenow;
    if ($pagenow !== 'post-new.php') {
        return;
    }
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- MonsterInsights deep-link navigation, not form processing; no nonce exists to verify.
    if (!isset($_GET['post_type']) || $_GET['post_type'] !== 'pretty-link') {
        return;
    }
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- MonsterInsights deep-link navigation, not form processing; no nonce exists to verify.
    if (!isset($_GET['monsterinsights_reference'])) {
        return;
    }
    // Eat v4's first-run onboarding transient so FirstRunRedirect (admin_init/10)
    // doesn't divert the user to the wizard on the redirect target. See touchpoint
    // #6 in the file header for rationale.
    delete_transient('prli_redirect_to_onboarding');

    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- MonsterInsights deep-link navigation, not form processing; no nonce exists to verify.
    $reference = sanitize_key((string) wp_unslash($_GET['monsterinsights_reference']));
    $target    = add_query_arg(
        [
            'page'                      => 'pretty-link-add-new',
            'monsterinsights_reference' => $reference,
        ],
        admin_url('admin.php')
    );
    wp_safe_redirect($target);
    exit;
}, 1);

/**
 * Touchpoint #5 — Addons-page "Settings" link redirect.
 *
 * MI's addons table links Pretty Links to `edit.php?post_type=pretty-link`.
 * Scope the redirect to requests with a MonsterInsights referer so we
 * don't intercept for other contexts.
 */
add_action('admin_init', static function (): void {
    global $pagenow;
    if ($pagenow !== 'edit.php') {
        return;
    }
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- MonsterInsights deep-link navigation, not form processing; no nonce exists to verify.
    if (!isset($_GET['post_type']) || $_GET['post_type'] !== 'pretty-link') {
        return;
    }
    $referer = wp_get_referer();
    if (!is_string($referer) || strpos($referer, 'monsterinsights') === false) {
        return;
    }
    wp_safe_redirect(admin_url('admin.php?page=pretty-link-links'));
    exit;
}, 1);

/**
 * Touchpoint #3 — URL Builder prefill bridge.
 *
 * The URL MI wants prefilled lives in `localStorage.MonsterInsightsURL`,
 * unreachable server-side. The React bootstrap snapshots
 * `window.prliAdmin` at module-eval time (`src-js/shared/bootstrap.js`),
 * so the mutation has to land before the `prli-shared` script tag
 * executes. `wp_add_inline_script(..., 'before')` prints between the
 * localize data and the script tag — exactly the slot we need. JS
 * source lives in `assets/js/monsterinsights-shim.js`, loaded via
 * file_get_contents so PHP holds no JS logic.
 */
add_action('admin_enqueue_scripts', static function (string $hookSuffix): void {
    if (strpos($hookSuffix, 'pretty-link-add-new') === false) {
        return;
    }
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- MonsterInsights deep-link navigation, not form processing; no nonce exists to verify.
    if (!isset($_GET['monsterinsights_reference'])) {
        return;
    }
    $absPath = PRLI_PATH . 'assets/js/monsterinsights-shim.js';
    if (!is_file($absPath)) {
        return;
    }
    $js = (string) file_get_contents($absPath);
    if ($js === '') {
        return;
    }
    wp_add_inline_script('prli-shared', $js, 'before');
}, 11);
