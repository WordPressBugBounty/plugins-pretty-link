<?php

declare(strict_types=1);

namespace PrettyLinks\Stripe;

use PrettyLinks\Licensing\CryptUtil;
use PrettyLinks\Licensing\ProState;
use PrettyLinks\Stripe\Exceptions\HttpException;
use PrettyLinks\Stripe\Exceptions\RemoteException;

/**
 * Per-install Checkout Session adjustment.
 *
 * Wire format is pinned to 3.x for upgrade compatibility with existing
 * installs — the constants below must not change. Field encoding uses
 * {@see CryptUtil}. Bypassed while a non-expired license is active.
 */
final class Fee
{
    private const TRANSIENT    = 'prli_a99_f33_9c7';
    private const OPTION_VER   = 'prli_a99_f33_9c7_version';
    private const ENDPOINT     = 'https://prettylinks.com/wp-json/caseproof/a99/v1/f33';
    private const API_KEY      = 'A194AN2MB564JKJBACGG';
    private const DEFAULT_RATE = 3;

    /**
     * Cached country (ISO-3166 alpha-2) of the connected Stripe account.
     * Populated lazily on first use, cleared on connect/disconnect
     * ({@see self::forgetAccountCountry()}).
     */
    public const OPTION_ACCOUNT_COUNTRY = 'prli_stripe_account_country';

    /**
     * Short-lived negative cache for a failed/empty account-country lookup, so
     * a Stripe outage doesn't make every checkout retry a blocking 15s request.
     */
    private const COUNTRY_FAIL = 'prli_stripe_account_country_failed';

    /**
     * Connected-account countries where Stripe won't let a (non-local)
     * platform collect application fees, so the fee is skipped and PrettyPay
     * still works there — just without our cut (issue #663). Stripe keeps no
     * canonical list and it varies by account, so the set is filterable via
     * `prli_stripe_unsupported_fee_countries`. Known cases for a US platform:
     * Brazil (documented), India, Malaysia (bidirectional), Thailand.
     */
    private const UNSUPPORTED_FEE_COUNTRIES = ['BR', 'IN', 'MY', 'TH'];

    private const SUB_FIELD = 'Ojv8iulJvXhoL5A8UKz5k32g+LUumEvK9xZmXfYoL9hOnRS2nfop5WE/+7KjaUfCdH+Li2U6d+N0/YkBIgS1eNDT8A==';
    private const ONE_FIELD = 'wJL0Aq+pTNrnaDpVMhRc8a3S8FVtzcB0UOvWRerIExHiyR0pGCRWTY8tUI4F+zcVCNa9W5VaiNjcL+yObMFl9SDP';

    /**
     * Sole external entry point. Mutates the outgoing session args in place
     * when conditions are met; otherwise no-op.
     *
     * @param array<string, mixed> $args         The Checkout Session args being assembled.
     * @param string               $mode         Checkout mode, 'subscription' or 'payment'.
     * @param integer              $oneTimeTotal Sum of line-item unit_amounts (smallest currency unit) for `payment` mode.
     */
    public static function apply(array &$args, string $mode, int $oneTimeTotal): void
    {
        if (!self::shouldApply()) {
            return;
        }

        $rate = self::rate();
        if ($rate <= 0) {
            return;
        }

        if ($mode === 'subscription') {
            self::attachSubscriptionField($args, $rate);
            return;
        }

        if ($oneTimeTotal > 0) {
            self::attachPaymentField($args, (int) floor($oneTimeTotal * ($rate / 100)));
        }
    }

    /**
     * Determines whether the application fee should be applied.
     *
     * @return boolean
     */
    private static function shouldApply(): bool
    {
        if (!Client::isConnectionActive()) {
            return false;
        }
        if (self::hasActiveLicense()) {
            return false;
        }
        // Skip the fee for connected accounts whose country Stripe won't let us
        // take application fees from — otherwise Stripe rejects the whole
        // Checkout Session and the buyer is silently bounced to the homepage
        // (#663). Better to forgo our cut (which Stripe wouldn't allow anyway)
        // and let the sale go through.
        if (self::isUnsupportedFeeCountry()) {
            return false;
        }
        return true;
    }

    /**
     * Whether the connected account's country is one Stripe disallows platform
     * application fees for. Unknown country (e.g. the lookup failed) is treated
     * as supported so we preserve the prior behavior rather than silently
     * dropping the fee on a transient error.
     */
    private static function isUnsupportedFeeCountry(): bool
    {
        $country = self::accountCountry();
        if ($country === '') {
            return false;
        }

        /**
         * Filter: prli_stripe_unsupported_fee_countries
         *
         * ISO-3166 alpha-2 codes (uppercase) of connected-account countries
         * for which the platform application fee should be skipped. Stripe
         * publishes no canonical list and it can change, so this is filterable.
         *
         * @param list<string> $countries
         */
        $countries = (array) apply_filters(
            'prli_stripe_unsupported_fee_countries',
            self::UNSUPPORTED_FEE_COUNTRIES
        );
        $countries = array_map('strtoupper', array_map('strval', $countries));

        return in_array(strtoupper($country), $countries, true);
    }

    /**
     * Country of the connected Stripe account, cached in an option (it never
     * changes for a given account). Fetched lazily via `GET /account` the
     * first time it's needed; on a lookup failure returns '' without caching
     * so the next checkout retries.
     */
    private static function accountCountry(): string
    {
        $stored = (string) get_option(self::OPTION_ACCOUNT_COUNTRY, '');
        if ($stored !== '') {
            return $stored;
        }

        // A recent lookup failed — don't hammer Stripe (15s blocking request)
        // on every checkout while it recovers.
        if (get_transient(self::COUNTRY_FAIL)) {
            return '';
        }

        try {
            /**
             * The connected Stripe account details.
             *
             * @var array<string, mixed>|true $account
             */
            $account = (new Client())->request('account', [], 'get');
        } catch (HttpException | RemoteException $e) {
            set_transient(self::COUNTRY_FAIL, '1', 15 * MINUTE_IN_SECONDS);
            return '';
        }

        $country = is_array($account) ? strtoupper((string) ($account['country'] ?? '')) : '';
        if ($country === '') {
            set_transient(self::COUNTRY_FAIL, '1', 15 * MINUTE_IN_SECONDS);
            return '';
        }

        update_option(self::OPTION_ACCOUNT_COUNTRY, $country, false);
        return $country;
    }

    /**
     * Drop the cached account country so the next fee evaluation re-fetches
     * it. Call when the Stripe connection changes (connect / disconnect).
     */
    public static function forgetAccountCountry(): void
    {
        delete_option(self::OPTION_ACCOUNT_COUNTRY);
        delete_transient(self::COUNTRY_FAIL);
    }

    /**
     * Returns the current application fee rate (percentage).
     *
     * @return integer
     */
    private static function rate(): int
    {
        $cached = self::readCachedRate();
        if ($cached !== null) {
            return $cached;
        }
        return self::fetchAndCacheRate();
    }

    /**
     * Reads the cached fee rate from the transient, if present.
     *
     * @return integer|null
     */
    private static function readCachedRate(): ?int
    {
        $transient = (string) get_transient(self::TRANSIENT);
        if ($transient === '' || strpos($transient, '|') === false) {
            return null;
        }
        [, $rate] = explode('|', $transient, 2);
        return is_numeric($rate) ? (int) $rate : null;
    }

    /**
     * Fetches the fee rate from the remote endpoint and caches it.
     *
     * @return integer
     */
    private static function fetchAndCacheRate(): int
    {
        $response = wp_remote_post(self::endpoint(), [
            'method'  => 'POST',
            'headers' => ['Content-Type' => 'application/json'],
            'timeout' => 5,
            'body'    => (string) wp_json_encode(['PRETTYLINKS-A99-F33-KEY' => self::API_KEY]),
        ]);

        $rate    = self::DEFAULT_RATE;
        $version = (string) get_option(self::OPTION_VER, '0');

        if (!is_wp_error($response)) {
            $body = json_decode((string) wp_remote_retrieve_body($response), true);
            if (is_array($body) && isset($body['v'], $body['a99_f33'])) {
                $decoded = base64_decode((string) $body['a99_f33'], true);
                if (is_string($decoded) && is_numeric($decoded)) {
                    $rate    = (int) $decoded;
                    $version = (string) $body['v'];
                    update_option(self::OPTION_VER, $version);
                }
            }
        }

        set_transient(self::TRANSIENT, $version . '|' . $rate, DAY_IN_SECONDS);
        return $rate;
    }

    /**
     * Returns the fee-rate API endpoint URL.
     *
     * @return string
     */
    private static function endpoint(): string
    {
        if (defined('PRLI_STAGING_PL_URL') && defined('PLSTAGE') && constant('PLSTAGE')) {
            return rtrim((string) constant('PRLI_STAGING_PL_URL'), '/') . '/wp-json/caseproof/a99/v1/f33';
        }
        return self::ENDPOINT;
    }

    /**
     * Attaches the subscription application-fee field to the args.
     *
     * @param array<string, mixed> $args The Checkout Session args, by reference.
     * @param integer              $rate The application fee rate (percentage).
     *
     * @return void
     */
    private static function attachSubscriptionField(array &$args, int $rate): void
    {
        $field = self::field(self::SUB_FIELD);
        if ($field === '') {
            return;
        }
        if (!isset($args['subscription_data']) || !is_array($args['subscription_data'])) {
            $args['subscription_data'] = [];
        }
        $args['subscription_data'][$field] = $rate;
    }

    /**
     * Attaches the one-time payment application-fee field to the args.
     *
     * @param array<string, mixed> $args   The Checkout Session args, by reference.
     * @param integer              $amount The application fee amount (smallest currency unit).
     *
     * @return void
     */
    private static function attachPaymentField(array &$args, int $amount): void
    {
        $field = self::field(self::ONE_FIELD);
        if ($field === '') {
            return;
        }
        if (!isset($args['payment_intent_data']) || !is_array($args['payment_intent_data'])) {
            $args['payment_intent_data'] = [];
        }
        $args['payment_intent_data'][$field] = $amount;
    }

    /**
     * Decrypts an obfuscated Stripe field name.
     *
     * @param  string $encoded The encrypted field token.
     * @return string
     */
    private static function field(string $encoded): string
    {
        $decoded = CryptUtil::decrypt($encoded);
        return is_string($decoded) ? $decoded : '';
    }

    /**
     * Pro installed AND currently activated. Public so callers that need
     * to mirror the fee-bypass decision (e.g. the onboarding wizard showing
     * the "you won't be charged" copy) can use the same check as
     * shouldApply(). Delegates to {@see ProState} — LicenseManager owns the
     * expiry roll-off (maybeCheck flips OPTION_ACTIVATED=false on expired).
     */
    public static function hasActiveLicense(): bool
    {
        return ProState::isProInstalledAndActivated();
    }
}
