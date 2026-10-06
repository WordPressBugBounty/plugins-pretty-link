<?php

declare(strict_types=1);

namespace PrettyLinks\Licensing;

use PrettyLinks\Admin\Notices;
use PrettyLinks\Bootstrap;
use PrettyLinks\GroundLevel\Mothership\AbstractPluginConnection;
use PrettyLinks\GroundLevel\Support\Contracts\Arrayable;
use PrettyLinks\GroundLevel\Mothership\Api\Request\LicenseActivations;
use PrettyLinks\GroundLevel\Mothership\Credentials;
use PrettyLinks\GroundLevel\Mothership\Manager\AddonsManager;
use PrettyLinks\GroundLevel\Mothership\Manager\LicenseManager as MothershipLicenseManager;
use PrettyLinks\GroundLevel\Mothership\Transients\ActivationTransient;

/**
 * Thin Pretty Links facade over ground-level-mothership's licensing services.
 *
 * Activation, deactivation, status checks, edition installs, and the 12-hour
 * status cron are all owned by {@see MothershipLicenseManager}. This class
 * adapts that surface to the shape Pretty Links already consumes (REST
 * controllers, the onboarding wizard, ProState, the React options screen) and
 * bridges Mothership's `pretty-link_*` lifecycle events to the historical
 * `prli_*` hooks add-ons depend on (see {@see Bootstrap}).
 *
 * Credentials live in {@see Credentials} (env/constant/option-resolved via
 * {@see MothershipConnector}); activation metadata lives in
 * {@see ActivationTransient}. The option/transient constants below are kept for
 * back-compat with readers that haven't migrated (edge channel, legacy caches).
 */
class LicenseManager
{
    /**
     * Legacy option/transient keys. The license key + activation flag are now
     * owned by Credentials / the connector; these remain for the edge-update
     * channel and the one-time migration off the v3 cache.
     */
    public const OPTION_LICENSE_KEY = 'plp_mothership_license';

    public const OPTION_ACTIVATED = 'prli_activated';

    public const OPTION_EDGE_UPDATES = 'plp_edge_updates';

    public const OPTION_ACTIVATION_OVERRIDE = 'prli_activation_override';

    /**
     * The release channel ('stable' or 'edge') as of the last admin request,
     * so a change can be noticed and WP's update cache dropped.
     *
     * @var string
     */
    public const OPTION_CHANNEL_SEEN = 'prli_release_channel_seen';

    /**
     * Whether this process has already logged the retired-constant warning.
     * Keeps a cron or WP-CLI run to one line rather than one per call.
     *
     * @var boolean
     */
    private static $retiredHostConstantLogged = false;

    /**
     * Legacy v3/early-v4 license cache. Read once by migrateLegacyLicenseInfo()
     * to seed the GL ActivationTransient, then left alone so a rollback to
     * develop still has something to read.
     */
    public const TRANSIENT_LICENSE_INFO = 'prli_license_info';

    /**
     * Marks a recent attempt to refill the activation transient, so an
     * unreachable licenses server costs one request per window rather than one
     * per admin page load. See maybeRefreshLicenseInfo().
     *
     * Network-scoped, unlike {@see self::TRANSIENT_RECHECK_ATTEMPT}. That
     * sibling guards per-site state (`prli_activated`), but this one guards
     * refreshLicenseInfo(), which writes the activation record — and that
     * record is a *site* transient shared by the whole network
     * (`ActivationTransient` passes `$isSite = true`). A per-site marker would
     * let every subsite queue its own request for one shared record.
     *
     * @var string
     */
    public const TRANSIENT_LICENSE_INFO_ATTEMPT = 'prli_license_info_attempt';

    /**
     * Seconds between attempts to refill the activation metadata after a failure.
     *
     * @var integer
     */
    private const LICENSE_INFO_RETRY = 900;

    private const NOTICE_DEFINE_ERROR = 'prli_license_define_error';

    /**
     * Notice id for a wp-config still pinning the retired v3 mothership
     * constant.
     *
     * @var string
     */
    private const NOTICE_RETIRED_HOST_CONSTANT = 'prli_license_retired_host_constant';

    /**
     * Paces PRETTYLINK_LICENSE_KEY activation attempts. See activateFromDefine().
     *
     * Per-site, like {@see self::TRANSIENT_RECHECK_ATTEMPT} and
     * {@see self::TRANSIENT_VERIFY_ATTEMPT} and for the same reason: every
     * piece of state activateFromDefine() writes is per-site — the key
     * (`plp_mothership_license`), the activation flag (`prli_activated`) and
     * the error notice. PRETTYLINK_LICENSE_KEY is defined once in wp-config for
     * the whole network, so with a network marker the first subsite to try and
     * fail locked out every sibling for fifteen minutes, and a subsite that
     * never got its own attempt in could stay unlicensed indefinitely. Each
     * subsite also needs its own activation on the server, so there is nothing
     * shared here for one request to satisfy on another's behalf.
     *
     * @var string
     */
    public const TRANSIENT_DEFINE_ATTEMPT = 'prli_license_define_attempt';

    /**
     * Seconds between PRETTYLINK_LICENSE_KEY activation attempts.
     *
     * @var integer
     */
    private const DEFINE_RETRY = 900;

    /**
     * Marker throttling the activation-recovery re-check.
     *
     * A per-site transient, not a network one: the state it guards
     * (`prli_activated`, `plp_mothership_license`) is per-site, so a
     * network-scoped marker would let one subsite's attempt suppress every
     * other subsite's — leaving a lapsed licence on site B unable to self-heal
     * because site A tried fifteen minutes ago.
     *
     * @var string
     */
    public const TRANSIENT_RECHECK_ATTEMPT = 'prli_license_recheck_attempt';

    /**
     * Marker throttling the per-request activation verification.
     *
     * Per-site for the same reason as {@see self::TRANSIENT_RECHECK_ATTEMPT}:
     * with a network marker, a revoked licence on one subsite would go
     * unverified for a full day because a sibling checked first.
     *
     * @var string
     */
    public const TRANSIENT_VERIFY_ATTEMPT = 'prli_license_verify_attempt';

    /**
     * Seconds between per-request activation verifications. develop used the
     * same 24h cadence; GL's cron is twice-daily, so on a site whose cron works
     * this costs nothing anyone notices, and on a site whose cron doesn't it is
     * the only thing that notices at all.
     *
     * @var integer
     */
    private const VERIFY_RETRY = 86400;

    /**
     * Seconds between activation-recovery attempts. Matches the other
     * admin_init throttles: one blocking request per window, not per page load.
     *
     * @var integer
     */
    private const RECHECK_RETRY = 900;

    /**
     * Current license snapshot for the admin UI / REST.
     *
     * @return array{key: string, activated: bool, edge: bool, edition: string, info: array<string, mixed>|null}
     */
    public function currentLicense(): array
    {
        return [
            'key'       => self::maskKey($this->credentials()->getLicenseKey()),
            'activated' => $this->isActive(),
            'edge'      => $this->edgeEnabled(),
            'edition'   => $this->edition(),
            'info'      => $this->licenseInfo(),
        ];
    }

    /**
     * The tail of the license key, which is all any consumer actually uses.
     *
     * The currentLicense() payload resolves the key through env var, constant,
     * option and the legacy `prli_license_key`, then puts it on the wire — so a
     * key supplied in wp-config, and a stale v3 key the admin may have
     * forgotten about, both travel over REST to any user with
     * `manage_options`, subsite admins on multisite included.
     *
     * Nothing needs it. The only consumer is LicenseSection.js, which renders
     * `'••••••••-••••-••••-••••-' + license.key.slice(-12)`, and the wizard's
     * view never reads the field at all. Sending only the tail removes the
     * secret from the wire entirely and renders identically — `slice(-12)` of a
     * twelve-character string is that same string, so already-built bundles
     * keep working untouched.
     *
     * @param  string $key The full license key.
     * @return string The last twelve characters.
     */
    private static function maskKey(string $key): string
    {
        // Real keys are 36-character UUIDs, so this always drops the majority
        // of the value. A key of twelve characters or fewer is a dev fixture,
        // not a licence.
        return substr($key, -12);
    }

    /**
     * License metadata in the shape the React options screen expects
     * (product_name + expires_at), sourced from the activation transient.
     *
     * @return array<string, mixed>|null Null when no license has been activated.
     */
    private function licenseInfo(): ?array
    {
        $activation = $this->activation();
        if ($activation->licenseKey === '' && $activation->productSlug === '') {
            return null;
        }
        return [
            'product_name'        => $activation->productName,
            'product_slug'        => $activation->productSlug,
            'expires_at'          => $activation->licenseExpiresAt,
            'status'              => $activation->licenseStatus,
            'activations_used'    => $activation->prodActivationsUsed,
            'activations_allowed' => $activation->prodActivationsAllowed,
        ];
    }

    /**
     * Whether the activation record actually carries license details.
     *
     * A record can hold a key and nothing else: on a failed meta sync after a
     * key rotation the transient is reset to the new key with the product
     * slug, name, status and expiry blanked. The product slug is what marks a
     * record as filled in, so a key-only record reads as "still needs
     * refreshing" rather than "complete".
     *
     * @return boolean
     */
    private function hasLicenseDetails(): bool
    {
        return $this->activation()->productSlug !== '';
    }

    /**
     * Whether the license is active: a key on file and the activation flag set.
     *
     * @return boolean
     */
    public function isActive(): bool
    {
        // Both must hold: a key on file and the activation flag set. Mothership
        // keeps these consistent (the flag only flips on a successful activate,
        // and resets when the key is overwritten), but the key guard preserves
        // Pretty Links' historical "no key ⇒ inactive" semantics defensively.
        if ($this->credentials()->getLicenseKey() === '') {
            return false;
        }
        return $this->connection()->getLicenseActivationStatus();
    }

    /**
     * Simple edition string: `free`, `plus`, or `pro`. Derived from the active
     * license's product slug, falling back to the build's PRLI_EDITION.
     */
    public function edition(): string
    {
        $slug = $this->activation()->productSlug;
        if ($slug !== '') {
            return self::normalizeEdition($slug);
        }
        if (defined('PRLI_EDITION')) {
            return self::normalizeEdition((string) constant('PRLI_EDITION'));
        }
        return 'free';
    }

    /**
     * Canonical plan slug for the active license, or '' when unknown/free.
     * Use this — not edition() — for tier-specific add-on entitlement checks.
     */
    public function planSlug(): string
    {
        $slug = strtolower($this->activation()->productSlug);
        if ($slug !== '' && PlanCatalog::isKnownPlan($slug)) {
            return $slug;
        }
        return '';
    }

    /**
     * Persist the license key (delegates to Credentials, which honors any
     * env/constant override).
     *
     * @param  string $key The license key.
     * @return void
     */
    public function setKey(string $key): void
    {
        $this->credentials()->setLicenseKey($key);
    }

    /**
     * Activate a key against the licenses server. On success, Mothership stores
     * the key, flips the activation flag, syncs the activation transient, and
     * fires `pretty-link_license_activated` (bridged to `prli_license_activated`).
     * Returns the v3-compatible array shape the REST layer + wizard expect.
     *
     * @param  string $key The license key to activate.
     * @return array<string, mixed>
     */
    public function activate(string $key): array
    {
        $before = $this->edition();

        // Pass installCorrectEdition=false: don't download/overwrite the
        // plugin's own files inline during this request. When the activated license is
        // for a different edition, EditionMismatch surfaces a notice whose CTA
        // hits /licensing/install-edition (gated on `install_plugins`), keeping
        // the heavy, capability-sensitive install out of the activate call.
        $result = $this->mothership()->activateLicense($key, $this->credentials()->getDomain(), false);

        if ($result->isFailure()) {
            return ['error' => self::activationErrorMessage((string) $result->getMessage())];
        }

        $this->fireEditionChangedIfDifferent($before);

        // GL returns Result::success even when the follow-up meta sync failed,
        // so "License activated." can appear next to a blank License/Expires
        // panel: isActive() is true, but licenseInfo() has nothing to show.
        // Say so rather than claiming a clean result.
        if (!$this->hasLicenseDetails()) {
            return [
                'message' => __(
                    // phpcs:ignore Generic.Files.LineLength.TooLong
                    'License activated, but its details could not be loaded from the licensing server. They will appear on the next successful check.',
                    'pretty-link'
                ),
            ];
        }

        return ['message' => $result->getMessage()];
    }

    /**
     * Normalize a GL lifecycle payload to the array shape add-ons expect.
     *
     * 3.x and develop both passed arrays to the `prli_license_*` actions. GL
     * passes an `Api\Response`, which implements `Arrayable` and nothing else —
     * no `ArrayAccess`, no parent — so a 3.x-era listener written as
     * `function ($activation) { … $activation['status'] … }` dies with
     * "Cannot use object of type Response as array". That is strictly worse
     * than the zero-argument version it replaced, where `null['status']` was
     * only a warning.
     *
     * `Response::toArray()` returns the payload in the shape those listeners
     * were written against.
     *
     * @param  mixed $payload The GL payload, or anything else a caller passes.
     * @return array<string, mixed>
     */
    public static function actionPayload($payload): array
    {
        if ($payload instanceof Arrayable) {
            return $payload->toArray();
        }

        if (is_array($payload)) {
            return $payload;
        }

        return null === $payload ? [] : (array) $payload;
    }

    /**
     * Turn GL's activation failure into something an admin can act on.
     *
     * Two shapes arrive here and neither is fit to render.
     *
     * An empty string, whenever the error body is not JSON — a Cloudflare 403,
     * a 429, a 502. Response::getMessage() has nothing to read, so
     * getErrorMessage() returns ''. That is worse than it sounds: the wizard
     * writes `activated => empty($result['error'])`, and empty('') is true, so
     * a failed activation records itself as a success. views/license.php then
     * gates its error block on `empty($last['activated'])` and renders
     * nothing, while its `?? 'Activation failed…'` default cannot fire because
     * '' is not null. The user gets the same page back, an empty key field and
     * no explanation, on every retry.
     *
     * Or a message beginning "WP_Error : 1. " — GL's handleWpError() prefixes
     * a PHP class name and an enumeration index to WordPress's own text, so a
     * timeout that used to read "cURL error 28: Operation timed out" now reads
     * "WP_Error : 1. cURL error 28: Operation timed out". On develop no admin
     * could reach that string, because user-facing activation went through
     * LicenseClient and GL's only live path (the status cron) discarded the
     * message. This branch routes activation through GL, so it surfaces on the
     * wizard and the React licence panel both.
     *
     * Normalising here rather than in the three consumers keeps the REST route,
     * the wizard and the panel consistent for free.
     *
     * @param  string $message The raw message from Mothership.
     * @return string A non-empty, human-readable message.
     */
    private static function activationErrorMessage(string $message): string
    {
        $message = (string) preg_replace('/^\s*WP_Error\s*:\s*/i', '', $message);
        $message = (string) preg_replace('/^\s*\d+\.\s*/', '', $message);
        $message = trim($message);

        if ($message === '') {
            return __(
                'Activation failed. Double-check the key and try again, or contact support if it keeps failing.',
                'pretty-link'
            );
        }

        return $message;
    }

    /**
     * Deactivate the current license. Always clears local state — even when the
     * server refuses or is unreachable — so the site can be re-activated
     * elsewhere (v3 parity). ground-level-mothership only clears on success or
     * an expired key, so we force the clear for any other failure.
     *
     * @return array<string, mixed>
     */
    public function deactivate(): array
    {
        $before = $this->edition();

        // GL treats an expired licence as a successful deactivate — it clears
        // local state and returns success — while the activation is still held
        // on the server. Its 4th action argument says which happened, and
        // nothing read it, so the panel showed a clean green result for a seat
        // the customer is still paying for. Capture it.
        $freed   = true;
        $capture = static function ($response = null, $key = '', $domain = '', $wasFreed = true) use (&$freed): void {
            $freed = (bool) $wasFreed;
        };
        add_action('pretty-link_license_deactivated', $capture, 1, 4);

        try {
            $result = $this->mothership()->deactivateLicense(
                $this->credentials()->getLicenseKey(),
                $this->credentials()->getDomain()
            );
        } finally {
            remove_action('pretty-link_license_deactivated', $capture, 1);
        }

        // Shed any lingering v3 `prli_license_key` so the UI doesn't resurrect
        // it via resolveLicenseKey()'s legacy fallback after deactivation.
        delete_option('prli_license_key');

        if ($result->isFailure()) {
            // Since ground-level-mothership 9.1.2 this is no longer the
            // "server refused" path. GL now clears local state BEFORE it calls
            // the API and reports an API rejection as a success carrying an
            // explanatory message, so the compensation that used to live here
            // — re-clearing the key, status, transient and add-on cache, and
            // firing the prli_* actions by hand — is all done by GL and the
            // bridge in Bootstrap::init() instead.
            //
            // The only way back here now is the activation-status write itself
            // failing, and GL returns before tearing anything down in that
            // case. Nothing to compensate for, and claiming the site was
            // deactivated would be a lie, so report it as it is.
            $this->fireEditionChangedIfDifferent($before);

            return [
                'message' => $result->getMessage(),
                'remote'  => false,
            ];
        }

        $this->fireEditionChangedIfDifferent($before);

        return [
            'message' => $result->getMessage(),
            'remote'  => $freed,
        ];
    }

    /**
     * Runs on admin load. Applies the dev/QA activation override, and failing
     * that gives a lapsed activation a chance to recover — see
     * {@see self::maybeRecoverActivation()}, which is throttled so an
     * unreachable server costs one request per window rather than one per page
     * load. Routine revocation stays with Mothership's twice-daily cron
     * (`pretty-link_check_license_activation_status_event`).
     *
     * @return boolean True when the activation flag was turned on.
     */
    public function maybeCheck(): bool
    {
        // Dev/QA override: force the activation flag on without a real license.
        if ((bool) get_option(self::OPTION_ACTIVATION_OVERRIDE, false)) {
            $this->connection()->setLicenseActivationStatus(true);
            return true;
        }

        if ($this->isActive()) {
            $this->maybeVerifyActivation();
            return false;
        }

        return $this->maybeRecoverActivation();
    }

    /**
     * Warn when wp-config still pins the retired v3 licensing-host constant.
     *
     * Version 3 pointed the stack at a different licensing host with
     * `PRLI_MOTHERSHIP_DOMAIN`. The v8 stack reads
     * `PRLI_MOTHERSHIP_API_BASE_URL` instead (Mothership\Util::getApiBaseUrl()),
     * and nothing bridges the two — so a staging wp-config that still defines
     * the old name is silently ignored and the site talks to the PRODUCTION
     * licensing server. From staging that means burning real activation seats,
     * or a status check deactivating a customer's live licence.
     *
     * Deliberately a notice rather than a translation. The old constant is a
     * bare domain for the old API, whose path shape the new licences server
     * does not share, so mapping it across would just point the stack at a
     * host that cannot answer — no safer than production and harder to
     * diagnose. Making the silence loud is the fix; the operator picks the
     * right value.
     *
     * `v3-TO-V4-BACKCOMPAT-AUDIT.md` gated retiring this constant on "no wild
     * config still defines it", which nothing had checked.
     *
     * Guarded to admin, cron and WP-CLI requests. The not-defined path calls
     * `Notices::remove()`, which reads the non-autoloaded `prli_notices`
     * option — an uncached SELECT. Hooked on `init` priority 1, that lands on
     * the short-link redirect hot path (registered before, and running at
     * equal priority ahead of, `RedirectEngine::dispatch`). Nothing on a
     * front-end request can consume this signal anyway: the notice renders
     * only in wp-admin and the log line only matters where cron/CLI runs.
     * admin-ajax is excluded as well: `is_admin()` is true there, but a
     * heartbeat tick renders no notices. Every context that can create the
     * notice can still clear it, so nothing gets stranded.
     *
     * @return void
     */
    public function warnOnRetiredHostConstant(): void
    {
        if (!((is_admin() && !wp_doing_ajax()) || wp_doing_cron() || (defined('WP_CLI') && WP_CLI))) {
            return;
        }

        if (!$this->retiredHostConstantDefined()) {
            Notices::remove(self::NOTICE_RETIRED_HOST_CONSTANT);
            return;
        }

        // A site that also sets the current constant has already been migrated;
        // the stale one is inert there.
        if ($this->currentHostConstantDefined()) {
            Notices::remove(self::NOTICE_RETIRED_HOST_CONSTANT);
            return;
        }

        // The admin_init hook never fires under cron or WP-CLI, and the notice only
        // renders on a Pretty Links screen — so a staging box that only ever
        // runs scheduled work would go on talking to production licensing in
        // complete silence. Log it once per process as well, wherever we are.
        if (!self::$retiredHostConstantLogged) {
            self::$retiredHostConstantLogged = true;
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- The only signal a cron/CLI-only environment ever gets that its licensing host override is being ignored.
            error_log(
                '[PrettyLinks] wp-config.php defines PRLI_MOTHERSHIP_DOMAIN, which is no longer read. '
                . 'Licensing is using the production server. '
                . 'Replace it with PRLI_MOTHERSHIP_API_BASE_URL (a full URL ending in /api/v1/).'
            );
        }

        Notices::addOnce(
            self::NOTICE_RETIRED_HOST_CONSTANT,
            'error',
            esc_html__(
                // phpcs:ignore Generic.Files.LineLength.TooLong
                'Your wp-config.php defines PRLI_MOTHERSHIP_DOMAIN, which Pretty Links no longer reads. Licensing is talking to the production server. Replace it with PRLI_MOTHERSHIP_API_BASE_URL (a full URL ending in /api/v1/).',
                'pretty-link'
            )
        );
    }

    /**
     * Whether the retired v3 licensing-host constant is defined.
     *
     * Isolated as a seam, like {@see self::definedLicenseKey()}, so
     * {@see self::warnOnRetiredHostConstant()} can be exercised without
     * define()ing a process-global constant that would leak into every later
     * test in the run.
     *
     * @return boolean
     */
    protected function retiredHostConstantDefined(): bool
    {
        return defined('PRLI_MOTHERSHIP_DOMAIN');
    }

    /**
     * Whether the current licensing-host constant is defined.
     *
     * @return boolean
     */
    protected function currentHostConstantDefined(): bool
    {
        return defined('PRLI_MOTHERSHIP_API_BASE_URL');
    }

    /**
     * Drop WP's update cache when the release channel changes.
     *
     * The stable/edge choice decides which package GL offers, but nothing
     * invalidates `update_plugins` when it moves. The REST toggle clears the
     * cache itself, so the option path is covered; the `PRETTYLINK_EDGE`
     * constant never did, and this branch added a third way in — the
     * `pretty-link_allow_prerelease_versions` filter — which does not either.
     * A site that switches channel by any of those can go on being offered the
     * other channel's package until the cache expires, up to twelve hours.
     *
     * Comparing the effective answer rather than any one input covers all
     * three at once, including the filter, whose value this code never sees
     * directly.
     *
     * The first observation on an existing install only records the channel:
     * without that, every site would drop its update cache once on upgrade for
     * no reason.
     *
     * @return void
     */
    public function maybeSyncReleaseChannel(): void
    {
        // Both sides stay per-site: the option that records the channel and the
        // option/constant/filter that decide it. `update_plugins` itself is
        // genuinely network-scoped on multisite — plugins are installed once
        // for the whole network, so there is only one cache to invalidate, and
        // a channel change on any site has to invalidate it.
        $current = $this->connection()->allowPrereleaseVersions() ? 'edge' : 'stable';
        $seen    = (string) get_option(self::OPTION_CHANNEL_SEEN, '');

        if ($seen === $current) {
            return;
        }

        update_option(self::OPTION_CHANNEL_SEEN, $current, false);

        if ($seen === '') {
            return;
        }

        delete_site_transient('update_plugins');
    }

    /**
     * Take GL's paid-license nag off the Plugins screen in the free build.
     *
     * GL's license manager hooks `in_plugin_update_message-{pluginFile}` and
     * prints "Please activate your license to download this update. Visit your
     * account dashboard" for any update row with an empty package. On Lite —
     * the WordPress.org build — that is paid-license chrome upselling from an
     * update row, and v5 had neither the hook nor the method. Pro keeps it,
     * where the message is accurate and actionable.
     *
     * Runs on admin_init because MothershipServiceProvider::boot() registers
     * GL's hooks after the bootstrap line that schedules this, and admin_init
     * is still well ahead of the Plugins screen rendering its rows.
     *
     * @return boolean True when the nag was removed.
     */
    public function removeLiteUpdateNag(): bool
    {
        if (ProState::isProInstalled()) {
            return false;
        }

        return remove_action(
            'in_plugin_update_message-' . plugin_basename(PRLI_FILE),
            [$this->mothership(), 'appendLicenseUpdateMessage'],
            10
        );
    }

    /**
     * Backstop for expiry and revocation on the request path.
     *
     * Server-side status verification became cron-only on this branch
     * (`pretty-link_check_license_activation_status_event`, twice daily).
     * WordPress cron is not a scheduler — it needs traffic to fire, and
     * DISABLE_WP_CRON with a missing or broken system cron switches it off
     * entirely. On either kind of site an expired or revoked license goes on
     * reporting itself active indefinitely: Pro features stay unlocked, the
     * update row keeps advertising licensed packages, and
     * {@see \PrettyLinks\Stripe\Fee::hasActiveLicense()} keeps waiving the
     * platform fee on every new checkout session. develop verified on the
     * request path; this restores that as a backstop.
     *
     * Delegates to GL's own checker, so the invalidated/expired actions, the
     * `prli_*` bridges and the transient re-sync all behave exactly as they do
     * from the cron. Throttled to a day, and only ever runs while the license
     * is active — turning the flag off is the only outcome it can produce.
     *
     * @return void
     */
    private function maybeVerifyActivation(): void
    {
        if (get_transient(self::TRANSIENT_VERIFY_ATTEMPT)) {
            return;
        }
        set_transient(self::TRANSIENT_VERIFY_ATTEMPT, time(), self::VERIFY_RETRY);

        $before = $this->edition();

        $this->mothership()->checkLicenseActivationStatus();

        $this->fireEditionChangedIfDifferent($before);
    }

    /**
     * Turn the activation flag back on when the server still recognises the
     * key on file.
     *
     * Everything that clears `prli_activated` is one-way. GL's
     * `checkLicenseActivationStatus()` returns early when the flag is already
     * off, so the twice-daily cron can revoke but never restore; the REST
     * surface exposes activate and deactivate but no re-check; and
     * `maybeRefreshLicenseInfo()` bails on `!isActive()`. So any transient
     * wobble that flips the flag — a dropped status check, a phantom
     * key-overwrite, a licenses server having a bad ten minutes — leaves a
     * paying site holding a perfectly good key and reporting itself
     * unlicensed, with re-typing the key the only way out. develop self-healed
     * here on every admin_init.
     *
     * Only ever turns the flag ON. Revocation stays with the paths that own
     * it, so a genuine 401/403/404 is not second-guessed: the activation
     * lookup simply fails and the site stays inactive.
     *
     * @return boolean True when a lapsed activation was restored.
     */
    private function maybeRecoverActivation(): bool
    {
        if ($this->isActive()) {
            return false;
        }

        $key = $this->credentials()->getLicenseKey();
        if ($key === '') {
            return false;
        }

        $domain = $this->credentials()->getDomain();
        if ($domain === '') {
            return false;
        }

        if (get_transient(self::TRANSIENT_RECHECK_ATTEMPT)) {
            return false;
        }
        set_transient(self::TRANSIENT_RECHECK_ATTEMPT, time(), self::RECHECK_RETRY);

        $activation = $this->container()
            ->get(LicenseActivations::class)
            ->retrieveLicenseActivation($key, $domain);

        if ($activation->isError()) {
            // Includes the legitimate 401/403/404 "this license is gone" case.
            // Leave the flag off and let the throttle hold off the next try.
            return false;
        }

        $before = $this->edition();

        $this->connection()->setLicenseActivationStatus(true);
        $this->mothership()->syncActivationTransient();
        delete_transient(self::TRANSIENT_RECHECK_ATTEMPT);

        do_action('prli_license_activated', self::actionPayload($activation));
        $this->fireEditionChangedIfDifferent($before);

        return true;
    }

    /**
     * Re-sync the cached activation metadata from the server.
     *
     * @return array<string, mixed>|null
     */
    public function refreshLicenseInfo(): ?array
    {
        if ($this->credentials()->getLicenseKey() === '') {
            return null;
        }
        $this->mothership()->syncActivationTransient();
        return $this->licenseInfo();
    }

    /**
     * Refill the activation metadata when its cache has lapsed.
     *
     * {@see ActivationTransient} holds the product slug, name and expiry for a
     * day, and Mothership's twice-daily cron normally re-syncs it well inside
     * that window. When the cron doesn't run — `DISABLE_WP_CRON` with a missing
     * or broken system cron, a site with no traffic to spawn it — the transient
     * lapses and nothing on the request path refills it. `edition()` then
     * reports `free`, `planSlug()` returns '', `EditionMismatch::detect()`
     * returns null so the "install Pro" notice disappears, and
     * `InstallLicensedEdition::install()` fails asking for a license refresh
     * with no UI anywhere that performs one. A Lite install holding a valid Pro
     * key silently reverts to behaving unlicensed, and deactivate + reactivate
     * is the only escape — on exactly the path a paying customer takes.
     *
     * Runs on `admin_init`, so visiting any admin screen restores the payload.
     * Throttled by a short attempt marker so an unreachable licenses server
     * costs one request per window rather than one per page load.
     *
     * @return void
     */
    public function maybeRefreshLicenseInfo(): void
    {
        if (!$this->isActive()) {
            return;
        }
        if ($this->hasLicenseDetails()) {
            return;
        }
        if (get_site_transient(self::TRANSIENT_LICENSE_INFO_ATTEMPT)) {
            return;
        }
        set_site_transient(self::TRANSIENT_LICENSE_INFO_ATTEMPT, time(), self::LICENSE_INFO_RETRY);

        $this->refreshLicenseInfo();

        // Only clear the marker if the refresh actually filled the details in.
        // licenseInfo() is non-null whenever *either* the key or the slug is
        // set, so testing its return here would clear the marker on every run
        // for a record holding a key and nothing else — turning a 15-minute
        // throttle into one licensing request per admin page load.
        if ($this->hasLicenseDetails()) {
            delete_site_transient(self::TRANSIENT_LICENSE_INFO_ATTEMPT);
        }
    }

    /**
     * One-time migration of the legacy v3/early-v4 `prli_license_info` cache
     * into ground-level-mothership's {@see ActivationTransient}, so existing
     * activated installs keep their edition + license details on screen
     * immediately after upgrading (rather than waiting for the next server
     * status sync). Self-clearing and idempotent.
     */
    public function migrateLegacyLicenseInfo(): void
    {
        $sentinel = 'prli_activation_migrated';
        if (get_option($sentinel)) {
            return;
        }

        $legacy = get_site_transient(self::TRANSIENT_LICENSE_INFO);

        if (!is_array($legacy)) {
            // Nothing to migrate, and nothing that can arrive later — the
            // legacy cache only ever had one writer and it is gone.
            update_option($sentinel, 1, false);
            return;
        }

        $key = $this->credentials()->getLicenseKey();

        if ($key === '') {
            // A key may still be activated before the legacy transient expires.
            // Burning the sentinel here would mean the payload is never used,
            // AND the half-empty record it would otherwise write blocks
            // maybeRefreshLicenseInfo() for the transient's full 24h TTL.
            return;
        }

        $activation = $this->activation();

        // Only seed when the new transient hasn't been populated yet, so a
        // genuine server sync always wins over the legacy snapshot.
        if (!$activation->exists()) {
            // Version 3 nests part of the payload under `license_key`; later
            // shapes put the same fields at the top level. Read both — whichever
            // the site actually has, the other simply isn't there.
            $nested = is_array($legacy['license_key'] ?? null) ? $legacy['license_key'] : [];

            $activation->licenseKey       = $key;
            $activation->productSlug      = (string) ($legacy['product_slug'] ?? $nested['product_slug'] ?? '');
            $activation->productName      = (string) ($legacy['product_name'] ?? $nested['product_name'] ?? '');
            $activation->licenseExpiresAt = (string) ($legacy['expires_at'] ?? $nested['expires_at'] ?? '');
            $activation->downloadUrl      = (string) ($legacy['url'] ?? $nested['url'] ?? '');
            $activation->versionNumber    = (string) ($legacy['version'] ?? $nested['version'] ?? '');

            // Previously dropped entirely, so the licence panel showed blank
            // activation counts until the next successful server sync.
            $activation->prodActivationsUsed    = (int) ($legacy['activation_count'] ?? $nested['activation_count'] ?? 0);
            $activation->prodActivationsAllowed = (int) ($legacy['max_activations'] ?? $nested['max_activations'] ?? 0);

            $activation->save();
        }

        update_option($sentinel, 1, false);

        // Deliberately NOT deleted. The sentinel above already makes this
        // one-shot, and the legacy transient carries its own TTL, so it costs
        // nothing to leave behind — while deleting it removes the only thing a
        // site rolled back to develop could rebuild its licence details from.
    }

    /**
     * Whether the bleeding-edge (prerelease) update channel is enabled.
     *
     * @return boolean
     */
    public function edgeEnabled(): bool
    {
        if (defined('PRETTYLINK_EDGE') && constant('PRETTYLINK_EDGE')) {
            return true;
        }
        return (bool) get_option(self::OPTION_EDGE_UPDATES, false);
    }

    /**
     * Activate the license key from the `PRETTYLINK_LICENSE_KEY` constant when
     * it's set in wp-config.php and differs from the stored key. Mirrors v3's
     * `activate_from_define()`:
     *
     *  - Gated on the Pro entry file being on disk.
     *  - Retires the previous key only once the new one activates cleanly.
     *  - Forces edge-updates off so the define stays the source of truth.
     *  - Activation errors surface through the React notice strip.
     */
    public function activateFromDefine(): void
    {
        $defined = $this->definedLicenseKey();
        if (null === $defined) {
            return;
        }
        if (!ProState::isProInstalled()) {
            return;
        }

        $current = $this->credentials()->getLicenseKey();
        if ($defined === $current) {
            return;
        }

        // Ground Level composes its OWN constant/env name from the plugin
        // prefix — PRLI_LICENSE_KEY — and Credentials refuses to write the
        // database whenever that is set. PRETTYLINK_LICENSE_KEY, the constant
        // this method exists for and the one the docs name, is a different
        // name entirely. Define both to different values and the two never
        // converge: getLicenseKey() keeps answering with GL's constant, this
        // method keeps seeing a mismatch, and every 15-minute window spends two
        // blocking requests — an activate that cannot persist, and a
        // releaseSupersededKey() that retires the very key authenticating it.
        // Forever.
        //
        // There is no winner to pick here: honouring GL's constant would
        // silently ignore the documented one, and honouring the documented one
        // cannot be made to stick. Say so once and stop.
        $glOverride = $this->credentials()->isCredentialSetInEnvironmentOrConstants('license_key');
        if (is_string($glOverride) && $glOverride !== '' && $glOverride !== $defined) {
            Notices::addOnce(
                self::NOTICE_DEFINE_ERROR,
                'error',
                esc_html__(
                    // phpcs:ignore Generic.Files.LineLength.TooLong
                    'PRETTYLINK_LICENSE_KEY and PRLI_LICENSE_KEY are both set to different license keys. Pretty Links cannot apply either one — remove one of them.',
                    'pretty-link'
                )
            );
            return;
        }

        // Pace attempts. This runs on every admin_init, including
        // admin-ajax.php, and each failure is a blocking HTTP request against
        // the licenses server — so an unreachable mothership would otherwise
        // mean a stack of them per page load. Per-site: see the constant.
        if (get_transient(self::TRANSIENT_DEFINE_ATTEMPT)) {
            return;
        }
        set_transient(self::TRANSIENT_DEFINE_ATTEMPT, time(), self::DEFINE_RETRY);

        update_option(self::OPTION_EDGE_UPDATES, false);

        // Activate FIRST, and only retire the old key once the new one is known
        // good. Deactivating unconditionally meant a network failure left the
        // site with no license at all — and our deactivate() clears local state
        // even when the server refuses, so the loss was total.
        $response = $this->activate($defined);
        if (!isset($response['error'])) {
            if ($current !== '' && $current !== $defined) {
                $this->releaseSupersededKey($current);
            }
            delete_transient(self::TRANSIENT_DEFINE_ATTEMPT);
            Notices::remove(self::NOTICE_DEFINE_ERROR);
            return;
        }

        // phpcs:ignore Squiz.Commenting.InlineComment.NotCapital -- translators comment must stay lowercase for WP i18n.
        // translators: %s: error message returned by the licenses server.
        $template = __('Error with PRETTYLINK_LICENSE_KEY: %s', 'pretty-link');

        Notices::add(
            self::NOTICE_DEFINE_ERROR,
            'error',
            esc_html(sprintf($template, (string) $response['error']))
        );
    }

    /**
     * Release a superseded key on the licenses server without touching local
     * state.
     *
     * {@see self::deactivate()} is the wrong tool once a replacement key is
     * already active: Mothership's `deactivateLicense()` clears the stored key,
     * the activation flag, and the activation transient we just wrote. All that
     * is wanted here is freeing the old key's activation slot, so this goes
     * straight to the activations endpoint. A failure isn't worth surfacing —
     * the new key is live either way.
     *
     * Signed with the key being released, not the one that replaced it. The
     * endpoint names the old key in its path
     * (`licenses/{key}/activations/{domain}/deactivate`) while GL's auth header
     * carries whatever `Credentials::getLicenseKey()` answers — by this point,
     * the new key. Whether the server authorises from the path or the header is
     * a question for the server, and if it is the header then the mismatch made
     * this a silent no-op that left the old seat consumed. Signing with the old
     * key is the one form that is right under either answer.
     * {@see MothershipConnector::withLicenseKey()}.
     *
     * @param  string $key The superseded license key.
     * @return void
     */
    private function releaseSupersededKey(string $key): void
    {
        if ($key === '') {
            return;
        }

        $domain = $this->credentials()->getDomain();

        $response = MothershipConnector::withLicenseKey(
            $key,
            function () use ($key, $domain) {
                return $this->container()
                    ->get(LicenseActivations::class)
                    ->deactivate($key, $domain);
            }
        );

        if (!$response->isError()) {
            return;
        }

        // Not retried: the new key is live and the site is in the state the
        // operator asked for, so blocking or repeating on this would be worse
        // than the symptom. But the old key still holds an activation slot and
        // nothing else would ever say so.
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- The old key still holds an activation slot; without this the failure is invisible to the operator.
        error_log(sprintf(
            '[PrettyLinks] could not release the superseded license key; '
            . 'it may still hold an activation slot: %s',
            (string) $response->getErrorMessage()
        ));
    }

    /**
     * The license key declared via the `PRETTYLINK_LICENSE_KEY` constant, or
     * null when it isn't defined. Isolated as a seam so
     * {@see self::activateFromDefine()} can be exercised in tests without
     * define()ing a process-global constant.
     *
     * @return string|null
     */
    protected function definedLicenseKey(): ?string
    {
        return defined('PRETTYLINK_LICENSE_KEY') ? (string) constant('PRETTYLINK_LICENSE_KEY') : null;
    }

    /**
     * Fire `prli_plugin_edition_changed` when the edition differs from the
     * supplied previous value. Keeps add-ons that gate features on the edition
     * transition working across activate/deactivate.
     *
     * @param  string $beforeEdition The edition prior to the operation.
     * @return void
     */
    private function fireEditionChangedIfDifferent(string $beforeEdition): void
    {
        $afterEdition = $this->edition();
        if ($beforeEdition !== $afterEdition) {
            /**
             * Action: prli_plugin_edition_changed
             *
             * Fires here when license activation or deactivation changes the
             * edition (free↔plus↔pro). Add-ons hook this to rebuild
             * feature-gated state. InstallLicensedEdition is the other emitter;
             * docs/LICENSING.md covers both.
             *
             * @param string $after  New edition ('free', 'plus', 'pro').
             * @param string $before Previous edition.
             */
            do_action('prli_plugin_edition_changed', $afterEdition, $beforeEdition);
        }
    }

    /**
     * Map a product slug to our simple 3-tier edition string.
     *
     * @param  string $slug The product slug.
     * @return string One of `free`, `plus`, or `pro`.
     */
    public static function normalizeEdition(string $slug): string
    {
        $slug = strtolower($slug);
        if ($slug === '' || strpos($slug, 'lite') !== false || strpos($slug, 'free') !== false) {
            return 'free';
        }
        if (strpos($slug, 'plus') !== false) {
            return 'plus';
        }
        // Pro-blogger, pro-developer, beginner, marketer, executive → pro tier.
        return 'pro';
    }

    /**
     * The ground-level-mothership license manager from the container.
     *
     * @return MothershipLicenseManager
     */
    private function mothership(): MothershipLicenseManager
    {
        return $this->container()->get(MothershipLicenseManager::class);
    }

    /**
     * The Mothership credentials service from the container.
     *
     * @return Credentials
     */
    private function credentials(): Credentials
    {
        return $this->container()->get(Credentials::class);
    }

    /**
     * The plugin connection from the container.
     *
     * @return AbstractPluginConnection
     */
    private function connection(): AbstractPluginConnection
    {
        return $this->container()->get(AbstractPluginConnection::class);
    }

    /**
     * The GL add-ons manager, whose cache has to be dropped alongside a
     * deactivation so the catalog stops advertising licensed downloads.
     *
     * @return AddonsManager
     */
    private function addons(): AddonsManager
    {
        return $this->container()->get(AddonsManager::class);
    }

    /**
     * The (auto-loaded) activation transient from the container.
     *
     * @return ActivationTransient
     */
    private function activation(): ActivationTransient
    {
        return $this->container()->get(ActivationTransient::class);
    }

    /**
     * The plugin's DI container.
     *
     * @return \PrettyLinks\GroundLevel\Container\Container
     */
    private function container(): \PrettyLinks\GroundLevel\Container\Container
    {
        return Bootstrap::instance()->container();
    }
}
