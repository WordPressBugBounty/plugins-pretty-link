<?php

declare(strict_types=1);

/**
 * License step (STEP_LICENSE).
 *
 * @var \PrettyLinks\Onboarding\Wizard $this
 * @var array<string, mixed>           $license
 * @var array<string, mixed>           $last
 */

defined('ABSPATH') || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
// View template — $info/$last/$license are local to this included file, not globals.
$this->openForm();
?>
<h1><?php esc_html_e('Activate your license', 'pretty-link'); ?></h1>

<?php if (!empty($license['activated'])) : ?>
    <p class="prli-onboarding-status is-active">
        <?php esc_html_e('License activated.', 'pretty-link'); ?>
    </p>
    <?php
    $info = is_array($license['info'] ?? null) ? $license['info'] : [];
    if (!empty($info['product_name'])) :
        ?>
        <p class="description">
            <?php
            echo esc_html(sprintf(
                // phpcs:ignore Squiz.Commenting.InlineComment.NotCapital,Squiz.Commenting.InlineComment.InvalidEndChar -- translators comment must remain verbatim.
                // translators: %s: product name
                __('Plan: %s', 'pretty-link'),
                (string) $info['product_name']
            ));
            ?>
        </p>
    <?php endif; ?>
<?php else : ?>
    <p>
        <?php esc_html_e('Enter your Pretty Links Pro license key to unlock automatic updates and Pro features. This step is optional — you can activate a key later under Pretty Links → Settings.', 'pretty-link'); ?>
    </p>
    <?php if (!empty($last['attempted']) && empty($last['activated'])) : ?>
        <p class="prli-onboarding-status is-error">
            <?php echo esc_html((string) ($last['message'] ?? __('Activation failed. Double-check the key and try again.', 'pretty-link'))); ?>
        </p>
    <?php endif; ?>
    <p>
        <label for="prli_license_key"><?php esc_html_e('License Key', 'pretty-link'); ?></label>
        <input type="text" id="prli_license_key" name="prli_license_key" class="regular-text code" autocomplete="off" />
    </p>
<?php endif; ?>

<div class="prli-onboarding-actions-inline">
    <?php $this->renderInlineBack(); ?>
    <button type="submit" name="prli_action" value="activate" class="button button-primary">
        <?php echo esc_html(!empty($license['activated']) ? __('Continue', 'pretty-link') : __('Activate', 'pretty-link')); ?>
    </button>
    <?php if (empty($license['activated'])) : ?>
        <button type="submit" name="prli_action" value="skip" class="button button-secondary">
            <?php esc_html_e('Skip for now', 'pretty-link'); ?>
        </button>
    <?php endif; ?>
</div>
<?php
$this->closeForm();
