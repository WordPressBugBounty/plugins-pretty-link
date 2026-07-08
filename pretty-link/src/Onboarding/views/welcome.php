<?php

declare(strict_types=1);

/**
 * Welcome splash step (STEP_WELCOME).
 *
 * @var string $startUrl
 * @var string $skipUrl
 */

defined('ABSPATH') || exit;
?>
<div class="prli-onboarding-welcome">
    <h1><?php esc_html_e('Welcome to Pretty Links', 'pretty-link'); ?></h1>
    <p class="prli-onboarding-intro">
        <?php esc_html_e('This setup walks you through activating your license, picking the features you want, creating your first Pretty Link, and organizing it into a category.', 'pretty-link'); ?>
    </p>
    <p class="prli-onboarding-intro">
        <?php esc_html_e('You can skip any step and revisit it later from the Pretty Links menu.', 'pretty-link'); ?>
    </p>
    <p class="prli-onboarding-get-started">
        <a href="<?php echo esc_url($startUrl); ?>" class="button button-primary button-hero">
            <?php esc_html_e('Get Started', 'pretty-link'); ?>
        </a>
    </p>
    <p class="prli-onboarding-skip-welcome">
        <a href="<?php echo esc_url($skipUrl); ?>" class="button-link prli-onboarding-skip">
            <?php esc_html_e('Skip for now', 'pretty-link'); ?>
        </a>
    </p>
</div>
