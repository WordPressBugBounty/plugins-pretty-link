<?php

declare(strict_types=1);

namespace PrettyLinks\Stripe;

/**
 * Ports of v3 `PrliStripeHelper` + `PrliUtils::countries/currencies` with
 * 1:1 output: same zero-decimal currency list, same shipping-country
 * exclusions, same tax/subscription portal defaults.
 */
final class Helper
{
    private const ZERO_DECIMAL_CURRENCIES = [
        'BIF',
        'CLP',
        'DJF',
        'GNF',
        'JPY',
        'KMF',
        'KRW',
        'MGA',
        'PYG',
        'RWF',
        'UGX',
        'VND',
        'VUV',
        'XAF',
        'XOF',
        'XPF',
    ];

    /**
     * Countries Stripe refuses for shipping collection (embargoed /
     * unsupported territories). Matches v3 `PrliStripeHelper::shipping_countries()`.
     *
     * @var list<string>
     */
    private const SHIPPING_EXCLUDED = [
        'AS',
        'CX',
        'CC',
        'CU',
        'HM',
        'IR',
        'KP',
        'MH',
        'FM',
        'NF',
        'MP',
        'PW',
        'SD',
        'SY',
        'UM',
        'VI',
    ];

    /**
     * Returns the list of supported countries.
     *
     * @return array<string, string>
     */
    public static function countries(): array
    {
        /**
         * The raw country list from the data file.
         *
         * @var array<string, string> $countries
         */
        $countries = require __DIR__ . '/data/countries.php';
        /**
         * The filtered country list.
         *
         * @var array<string, string> $filtered
         */
        $filtered = apply_filters('prli_countries', $countries);
        return $filtered;
    }

    /**
     * Returns the list of supported currencies.
     *
     * @return array<string, string>
     */
    public static function currencies(): array
    {
        /**
         * The raw currency list from the data file.
         *
         * @var array<string, string> $currencies
         */
        $currencies = require __DIR__ . '/data/currencies.php';
        /**
         * The filtered currency list.
         *
         * @var array<string, string> $filtered
         */
        $filtered = apply_filters('prli_currencies', $currencies);
        return $filtered;
    }

    /**
     * Returns the list of supported shipping countries.
     *
     * @return array<string, string>
     */
    public static function shippingCountries(): array
    {
        $countries = self::countries();
        foreach (self::SHIPPING_EXCLUDED as $code) {
            unset($countries[$code]);
        }
        /**
         * The filtered shipping-country list.
         *
         * @var array<string, string> $filtered
         */
        $filtered = apply_filters('prli_stripe_shipping_countries', $countries);
        return $filtered;
    }

    /**
     * Returns true when the currency uses zero decimal places.
     *
     * @param  string $currency The ISO currency code.
     * @return boolean
     */
    public static function isZeroDecimalCurrency(string $currency): bool
    {
        /**
         * The filtered list of zero-decimal currency codes.
         *
         * @var list<string> $zero
         */
        $zero = apply_filters('prli_stripe_zero_decimal_currencies', self::ZERO_DECIMAL_CURRENCIES);
        return in_array(strtoupper($currency), $zero, true);
    }

    /**
     * Converts a major-unit amount into Stripe's smallest currency unit.
     *
     * @param  float  $amount   The amount in major units.
     * @param  string $currency The ISO currency code.
     * @return integer
     */
    public static function toZeroDecimalAmount(float $amount, string $currency): int
    {
        return self::isZeroDecimalCurrency($currency) ? (int) $amount : (int) round($amount * 100);
    }

    /**
     * Formats a Stripe unit amount into a localized display string.
     *
     * @param  float  $amount   The amount in the smallest currency unit.
     * @param  string $currency The ISO currency code.
     * @return string
     */
    public static function formatUnitAmount(float $amount, string $currency): string
    {
        if (self::isZeroDecimalCurrency($currency)) {
            return number_format_i18n($amount);
        }
        return number_format_i18n($amount / 100, 2);
    }

    /**
     * Formats a Stripe Price object into a display label.
     *
     * @param array<string, mixed> $price Stripe Price object.
     *
     * @return string
     */
    public static function formatPrice(array $price): string
    {
        $interval = '';
        if (!empty($price['recurring']) && is_array($price['recurring'])) {
            $intervalLabel = (string) ($price['recurring']['interval'] ?? '');
            $count         = (int) ($price['recurring']['interval_count'] ?? 1);
            if ($count !== 1) {
                $intervalLabel = sprintf('%d %ss', $count, $intervalLabel);
            }
            $interval = ' / ' . $intervalLabel;
        }

        return sprintf(
            '%s %s%s',
            strtoupper((string) ($price['currency'] ?? '')),
            self::formatUnitAmount((float) ($price['unit_amount'] ?? 0), (string) ($price['currency'] ?? '')),
            $interval
        );
    }

    /**
     * Returns the configured default currency, or USD as a fallback.
     *
     * @return string
     */
    public static function defaultCurrency(): string
    {
        $options = (array) (get_option('prli_options', []) ?: []);
        $code    = (string) ($options['prettypay_default_currency'] ?? '');
        return $code !== '' ? $code : 'USD';
    }

    /**
     * Returns the configured global thank-you page ID.
     *
     * @return integer
     */
    public static function thankYouPageId(): int
    {
        $options = (array) (get_option('prli_options', []) ?: []);
        return (int) ($options['prettypay_thank_you_page_id'] ?? 0);
    }
}
