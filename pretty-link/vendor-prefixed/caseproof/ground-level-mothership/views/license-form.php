<?php

declare(strict_types=1);

use PrettyLinks\GroundLevel\Support\Html;

/**
 * License activation/deactivation form view.
 *
 * @var string $pluginId         The plugin ID for form namespacing.
 * @var string $licenseKey       The current license key value.
 * @var string $activationDomain The activation domain.
 * @var string $action           Either 'activate' or 'deactivate'.
 * @var bool   $licenseIsStored  Whether the license is stored in env/constants (activate form only).
 */
$isActivate  = 'activate' === $action;
$readonly    = ! $isActivate || $licenseIsStored;
$disabled    = $isActivate && $licenseIsStored;
$submitLabel = $isActivate ? __('Activate License', 'pretty-link') : __('Deactivate License', 'pretty-link');
$buttonClass = Html::classes(
    'button',
    [
        'button-primary'   => $isActivate,
        'button-secondary' => ! $isActivate,
    ],
    $pluginId . '-button-' . $action
);
?>
<form method="post" action="" name="<?php echo esc_attr($pluginId); ?>_<?php echo esc_attr($action); ?>_license_form">
    <div class="<?php echo esc_attr($pluginId); ?>-licence-div-form">
        <label for="license_key"><?php esc_html_e('License Key: ', 'pretty-link'); ?></label>
        <input name="license_key"
            type="text"
            id="license_key"
            value="<?php echo esc_attr($licenseKey); ?>"
            class="regular-text"
            <?php if ($readonly) :
                ?>readonly <?php
            endif; ?>
        >
        <input type="hidden"
            name="activation_domain"
            value="<?php echo esc_attr($activationDomain); ?>"
        >
        <?php wp_nonce_field('mothership_' . $action . '_license', '_wpnonce'); ?>
        <input
            type="hidden"
            name="<?php echo esc_attr($pluginId); ?>_license_button"
            value="<?php echo esc_attr($action); ?>"
        >
        <input
            type="submit"
            value="<?php echo esc_attr($submitLabel); ?>"
            class="<?php echo esc_attr($buttonClass); ?>"
            <?php echo $disabled ? 'disabled' : ''; ?>
        >
    </div>
</form>
