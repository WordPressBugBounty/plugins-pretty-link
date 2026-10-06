<?php

declare(strict_types=1);

namespace PrettyLinks\Stripe;

use PrettyLinks\Repositories\LinkMetas;

/**
 * The PrettyPay™ fields of a link payload, stored as `prli_link_metas`
 * rows. `Links::save()` pulls them off the payload before the column
 * write and stores them after it; the links REST controller attaches
 * them to responses.
 */
final class LinkMeta
{
    /**
     * PrettyPay meta keys stored in `prli_link_metas`. Names match v3
     * exactly so an in-place upgrade finds existing rows as-is.
     *
     * @var list<string>
     */
    public const KEYS = [
        'stripe_line_items',
        'stripe_automatic_tax',
        'stripe_billing_address_collection',
        'stripe_shipping_address_collection',
        'stripe_shipping_address_allowed_countries',
        'stripe_phone_number_collection',
        'stripe_allow_promotion_codes',
        'stripe_tax_id_collection',
        'stripe_save_payment_details',
        'stripe_include_free_trial',
        'stripe_trial_period_days',
        'stripe_custom_text',
        'stripe_thank_you_page_id',
    ];

    /**
     * Link metas repository.
     *
     * @var LinkMetas
     */
    private LinkMetas $metas;

    /**
     * Constructor.
     *
     * @param LinkMetas $metas Link metas repository.
     */
    public function __construct(LinkMetas $metas)
    {
        $this->metas = $metas;
    }

    /**
     * Pulls the Stripe-specific fields out of a link payload so the Links
     * repo never sees them (it only knows about `prli_links` columns).
     *
     * @param  array<string, mixed> $data The link payload, by reference.
     * @return array<string, string>|null  null when the payload never mentioned Stripe (preserve existing meta)
     */
    public function extract(array &$data): ?array
    {
        $hasAny = false;
        $out    = [];
        foreach (self::KEYS as $key) {
            if (!array_key_exists($key, $data)) {
                continue;
            }
            $hasAny = true;
            $value  = $data[$key];
            unset($data[$key]);
            if ($key === 'stripe_shipping_address_allowed_countries' && is_array($value)) {
                $value = implode(',', array_map('strval', $value));
            }
            if (is_array($value)) {
                // Recursively scrub every scalar in the structure (e.g. the
                // line-items blob) before persisting, mirroring v3's
                // array_walk_recursive + sanitize_text_field pass. Admin-only
                // input that's always escaped on output — defense in depth.
                array_walk_recursive($value, static function (&$item): void {
                    if (is_string($item)) {
                        $item = sanitize_text_field($item);
                    }
                });
                $value = (string) wp_json_encode($value);
            }
            if (is_bool($value)) {
                $value = $value ? '1' : '0';
            }
            $out[$key] = (string) $value;
        }
        return $hasAny ? $out : null;
    }

    /**
     * Persists the extracted Stripe meta for a link.
     *
     * @param integer                    $linkId The link ID.
     * @param array<string, string>|null $meta   The extracted Stripe meta, or null to leave unchanged.
     *
     * @return void
     */
    public function persist(int $linkId, ?array $meta): void
    {
        if ($meta === null) {
            return;
        }
        foreach (self::KEYS as $key) {
            if (!array_key_exists($key, $meta)) {
                continue;
            }
            $value = $meta[$key];
            if ($value === '' || $value === '0') {
                $this->metas->delete($linkId, $key);
            } else {
                $this->metas->set($linkId, $key, $value);
            }
        }

        // Mirror v3: flag `prli_has_recurring_prettypay_link` whenever a
        // subscription-type price is saved so Settings → Payments can prompt
        // the merchant to configure the Stripe Customer Portal.
        if (isset($meta['stripe_line_items'])) {
            $decoded = json_decode($meta['stripe_line_items'], true);
            if (is_array($decoded)) {
                foreach ($decoded as $item) {
                    if (
                        is_array($item)
                        && isset($item['price']['recurring'])
                        && is_array($item['price']['recurring'])
                    ) {
                        update_option('prli_has_recurring_prettypay_link', true);
                        break;
                    }
                }
            }
        }
    }

    /**
     * Attaches stored Stripe meta values to a link payload.
     *
     * @param  integer              $linkId The link ID.
     * @param  array<string, mixed> $link   The link payload.
     * @return array<string, mixed>
     */
    public function attach(int $linkId, array $link): array
    {
        $all = $this->metas->all($linkId);
        foreach (self::KEYS as $key) {
            $raw = $all[$key] ?? null;
            if ($raw === null) {
                $link[$key] = self::defaultValue($key);
                continue;
            }
            $link[$key] = self::decode($key, $raw);
        }
        return $link;
    }

    /**
     * Returns the default value for a Stripe meta key.
     *
     * @param  string $key The Stripe meta key.
     * @return mixed
     */
    private static function defaultValue(string $key)
    {
        switch ($key) {
            case 'stripe_line_items':
                return [];
            case 'stripe_shipping_address_allowed_countries':
                return [];
            case 'stripe_trial_period_days':
            case 'stripe_thank_you_page_id':
                return 0;
            case 'stripe_custom_text':
                return '';
            default:
                return false;
        }
    }

    /**
     * Decodes a stored Stripe meta value into its typed form.
     *
     * @param  string $key The Stripe meta key.
     * @param  string $raw The raw stored meta value.
     * @return mixed
     */
    private static function decode(string $key, string $raw)
    {
        if ($key === 'stripe_line_items') {
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : [];
        }
        if ($key === 'stripe_shipping_address_allowed_countries') {
            return $raw === '' ? [] : array_values(array_filter(array_map('trim', explode(',', $raw))));
        }
        if ($key === 'stripe_custom_text') {
            return $raw;
        }
        if ($key === 'stripe_trial_period_days' || $key === 'stripe_thank_you_page_id') {
            return (int) $raw;
        }
        // Boolean-ish toggles.
        return $raw === '1';
    }
}
