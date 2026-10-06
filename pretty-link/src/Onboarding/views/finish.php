<?php

declare(strict_types=1);

/**
 * Finish summary step (STEP_FINISH).
 *
 * @var \PrettyLinks\Onboarding\Wizard $this
 * @var array<string, mixed>|null $link
 * @var array<string, mixed>|null $categoryRow
 * @var bool                      $licenseActivated
 * @var bool                      $stripeConnected
 * @var string                    $stripeAccountName
 * @var string[]                  $enabledFeatureLabels
 * @var array{plan:string,planLabel:string,heading:string,label:string,url:string}|null $upgradeCta
 * @var array{features:string[],addons_installed:string[],addons_failed:string[]}|null $drainOutcome
 * @var string[]                  $addonsInstalledLabels
 * @var string[]                  $addonsFailedLabels
 * @var boolean                   $canDrain
 */

defined('ABSPATH') || exit;
?>
<?php if ($canDrain) : ?>
    <?php $this->openForm(); ?>
    <div class="prli-onboarding-resume-result">
        <p><?php esc_html_e('Your license is active. Turn on the features you selected earlier and install the add-ons they need.', 'pretty-link'); ?></p>
        <button type="submit" name="prli_action" value="drain" class="button button-primary" data-busy-label="<?php esc_attr_e('Working…', 'pretty-link'); ?>">
            <?php esc_html_e('Finish setup', 'pretty-link'); ?>
        </button>
    </div>
    <?php $this->closeForm(); ?>
<?php endif; ?>
<?php
/*
 * `!$canDrain` means nothing is left queued. Without it an add-on the active
 * plan does not entitle — drainQueue() `continue`s past those, so they land in
 * neither outcome list — left addons_failed empty and printed "All set!"
 * directly above the upgrade CTA that exists because the add-on is still
 * waiting. It also stops the banner appearing above the "Finish setup" button.
 */
?>
<?php if (is_array($drainOutcome) && (!empty($drainOutcome['features']) || !empty($drainOutcome['addons_installed'])) && empty($drainOutcome['addons_failed']) && !$canDrain) : ?>
    <div class="prli-onboarding-resume-result is-success">
        <?php
        /*
         * Neutral wording: the drain now runs on the Features save too, so this
         * is reached in the same sitting as well as after a checkout round trip.
         */
        ?>
        <p><strong><?php esc_html_e('All set!', 'pretty-link'); ?></strong>
        <?php esc_html_e('We finished setting up the features and add-ons you selected.', 'pretty-link'); ?></p>
    </div>
<?php endif; ?>
<?php if (is_array($upgradeCta)) : ?>
    <div class="prli-onboarding-upgrade-cta">
        <h2><?php echo esc_html($upgradeCta['heading']); ?></h2>
        <p><?php
            printf(
                // phpcs:ignore Squiz.Commenting.InlineComment.NotCapital,Squiz.Commenting.InlineComment.InvalidEndChar -- translators comment must remain verbatim.
                // translators: %s: Pretty Links plan label (e.g. "Pro").
                esc_html__('Some of the features you selected need Pretty Links %s. Upgrade to finish your setup — we\'ll bring you right back here and turn them on for you.', 'pretty-link'),
                esc_html($upgradeCta['planLabel'])
            );
            ?></p>
        <a class="button button-primary button-hero" href="<?php echo esc_url($upgradeCta['url']); ?>" target="_blank" rel="noopener noreferrer">
            <?php echo esc_html($upgradeCta['label']); ?>
        </a>
    </div>
<?php endif; ?>

<?php $this->openForm(); ?>
<h1><?php esc_html_e('Here\'s what\'s ready', 'pretty-link'); ?></h1>

<ul class="prli-onboarding-summary">
    <?php if (is_array($link)) : ?>
        <li>
            <strong><?php esc_html_e('Your first Pretty Link', 'pretty-link'); ?></strong><br />
            <code><?php echo esc_html((string) ($link['pretty_url'] ?? '')); ?></code>
        </li>
    <?php else : ?>
        <li><?php esc_html_e('No link created yet — you can add one from Pretty Links → Add New.', 'pretty-link'); ?></li>
    <?php endif; ?>

    <?php if (is_array($categoryRow)) : ?>
        <li>
            <strong><?php esc_html_e('Category', 'pretty-link'); ?></strong><br />
            <?php echo esc_html((string) $categoryRow['name']); ?>
        </li>
    <?php endif; ?>

    <?php if ($licenseActivated) : ?>
        <li>
            <strong><?php esc_html_e('License', 'pretty-link'); ?></strong><br />
            <?php esc_html_e('Activated', 'pretty-link'); ?>
        </li>
    <?php endif; ?>

    <?php if ($stripeConnected) : ?>
        <li>
            <strong><?php esc_html_e('Stripe', 'pretty-link'); ?></strong><br />
            <?php
            if ($stripeAccountName !== '') {
                echo esc_html(sprintf(
                    // phpcs:ignore Squiz.Commenting.InlineComment.NotCapital,Squiz.Commenting.InlineComment.InvalidEndChar -- translators comment must remain verbatim.
                    // translators: %s: Stripe account name
                    __('Connected (%s)', 'pretty-link'),
                    $stripeAccountName
                ));
            } else {
                esc_html_e('Connected', 'pretty-link');
            }
            ?>
        </li>
    <?php endif; ?>

    <?php if (!empty($enabledFeatureLabels)) : ?>
        <li>
            <strong><?php esc_html_e('Features enabled', 'pretty-link'); ?></strong><br />
            <?php echo esc_html(implode(', ', $enabledFeatureLabels)); ?>
        </li>
    <?php endif; ?>

    <?php if (!empty($addonsInstalledLabels)) : ?>
        <li>
            <strong><?php esc_html_e('Add-ons installed', 'pretty-link'); ?></strong><br />
            <?php echo esc_html(implode(', ', $addonsInstalledLabels)); ?>
        </li>
    <?php endif; ?>

    <?php if (!empty($addonsFailedLabels)) : ?>
        <li class="is-error">
            <strong><?php esc_html_e('Add-ons not installed', 'pretty-link'); ?></strong><br />
            <?php echo esc_html(implode(', ', $addonsFailedLabels)); ?><br />
            <?php esc_html_e('Install from Pretty Links → Add-ons.', 'pretty-link'); ?>
        </li>
    <?php endif; ?>
</ul>
<?php
$this->renderFooter(__('Continue', 'pretty-link'), true);
$this->closeForm();
