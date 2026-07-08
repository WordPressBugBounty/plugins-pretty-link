<?php

declare(strict_types=1);

/**
 * Pay Links step (STEP_PAY). Optional Stripe connection.
 *
 * @var \PrettyLinks\Onboarding\Wizard $this
 * @var bool        $isConnected
 * @var bool        $isEnrolled
 * @var string|null $connectUrl
 * @var string      $accountName
 */

defined('ABSPATH') || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
// View template — variables are local to this included file, not globals.
$this->openForm();
?>
<h1><?php esc_html_e('Set up PrettyPay™ Links', 'pretty-link'); ?></h1>
<p><?php esc_html_e('PrettyPay™ Links turn any Pretty Link into a Stripe checkout — perfect for selling digital goods, donations, or one-off charges without a full store.', 'pretty-link'); ?></p>

<div class="prli-onboarding-paylinks-fee">
    <?php
    $defaultNotice = '<p class="description prli-onboarding-paylinks-note">'
        . esc_html__('Start accepting payments right away. A small platform fee applies per sale; Stripe\'s own processing fees also apply.', 'pretty-link')
        . '</p>';
    /**
     * Filters the PrettyPay™ fee notice shown during onboarding. Extensions
     * (e.g. Pretty Links Pro) may replace it to reflect their licensing state.
     */
    echo wp_kses_post(apply_filters('prli_onboarding_paylinks_fee_notice', $defaultNotice));
    ?>
</div>

<?php if ($isConnected) : ?>
    <p class="prli-onboarding-status is-active">
        <?php
        if ($accountName !== '') {
            printf(
                // phpcs:ignore Squiz.Commenting.InlineComment.NotCapital,Squiz.Commenting.InlineComment.InvalidEndChar -- translators comment must remain verbatim.
                // translators: %s: Stripe account name
                esc_html__('Stripe connected (%s). You can create PrettyPay™ Links right after setup.', 'pretty-link'),
                '<strong>' . esc_html($accountName) . '</strong>'
            );
        } else {
            esc_html_e('Stripe connected. You can create PrettyPay™ Links right after setup.', 'pretty-link');
        }
        ?>
    </p>
<?php endif; ?>

<?php if (!$isConnected && !$isEnrolled) : ?>
    <p class="description">
        <?php esc_html_e('Connecting Stripe uses a secure Caseproof handoff — you\'ll enroll your account, then land on Stripe to finish the connection.', 'pretty-link'); ?>
    </p>
<?php endif; ?>

<div class="prli-onboarding-actions-inline">
    <?php $this->renderInlineBack(); ?>
    <?php if (!$isConnected && $connectUrl !== null) : ?>
        <a href="<?php echo esc_url($connectUrl); ?>" class="button button-primary">
            <?php esc_html_e('Connect Stripe', 'pretty-link'); ?>
        </a>
    <?php endif; ?>
    <button type="submit" name="prli_action" value="skip" class="button button-secondary">
        <?php echo esc_html($isConnected ? __('Continue', 'pretty-link') : __('Skip for now', 'pretty-link')); ?>
    </button>
</div>
<?php
$this->closeForm();
