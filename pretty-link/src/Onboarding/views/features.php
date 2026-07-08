<?php

declare(strict_types=1);

/**
 * Features step (STEP_FEATURES).
 *
 * @var \PrettyLinks\Onboarding\Wizard $this
 * @var array<string, mixed>           $state
 * @var array<string, mixed>           $last
 * @var array<string, mixed>           $opts
 * @var string                         $miInstall
 * @var bool                           $miActive
 */

use PrettyLinks\Onboarding\Wizard;

defined('ABSPATH') || exit;

$this->openForm();
?>
<h1><?php esc_html_e('Choose your features', 'pretty-link'); ?></h1>
<p><?php esc_html_e('Pick the features you want on. You can change any of these later under Pretty Links → Settings.', 'pretty-link'); ?></p>

<?php if ($miInstall === 'installed') : ?>
    <p class="prli-onboarding-status is-active">
        <?php esc_html_e('MonsterInsights installed and activated.', 'pretty-link'); ?>
    </p>
<?php elseif ($miInstall === 'failed') : ?>
    <p class="prli-onboarding-status is-error">
        <?php esc_html_e('We couldn\'t install MonsterInsights automatically. Install it manually from Plugins → Add New.', 'pretty-link'); ?>
    </p>
<?php endif; ?>

<?php
/**
 * Action: prli_onboarding_features_status
 *
 * Fires above the feature list so plugins can surface install result
 * messages (e.g. Pro add-ons that ran a background install during the
 * previous save). Callback receives the previous feature-step record
 * so it can inspect its own keys.
 */
do_action('prli_onboarding_features_status', $last);
?>

<ul class="prli-onboarding-features">
    <?php
    Wizard::renderFeatureItem(
        'prli_feature_link_track_me',
        __('Link Tracking', 'pretty-link'),
        __('Track click counts and unique visitors on every link.', 'pretty-link'),
        __('Every click on a Pretty Link is recorded with the visitor\'s IP, user agent, referrer, and timestamp so you can see exactly which campaigns are earning attention. Leave this on if you want the Reports page to show anything — without it, click counts stay at zero. Tracking runs on your own server, so data never leaves the site.', 'pretty-link'),
        !empty($opts['link_track_me']),
        true,
        false,
        '',
        true
    );
    Wizard::renderFeatureItem(
        'prli_feature_link_nofollow',
        __('No Follow', 'pretty-link'),
        __('Add rel="nofollow" to every link by default.', 'pretty-link'),
        __('Tells search engines not to pass your site\'s ranking authority to the destination. Essential for affiliate, sponsored, or user-generated links so Google doesn\'t treat them as editorial endorsements — leaving affiliate links "followed" can trigger manual penalties. You can still override this per-link for trusted partners.', 'pretty-link'),
        !empty($opts['link_nofollow']),
        true,
        false,
        '',
        true
    );
    Wizard::renderFeatureItem(
        'prli_feature_link_sponsored',
        __('Sponsored', 'pretty-link'),
        __('Add rel="sponsored" to every link by default.', 'pretty-link'),
        __('The modern, Google-blessed signal for paid or affiliate relationships (introduced 2019). It communicates intent more precisely than nofollow alone and is what Google actually prefers on monetised links. Most affiliate marketers enable both — nofollow protects ranking authority, sponsored declares the commercial relationship.', 'pretty-link'),
        !empty($opts['link_sponsored']),
        true
    );

    /**
     * Action: prli_onboarding_render_feature_rows
     *
     * Fires inside the main feature list so plugins can append their own
     * rows (Link Health, Keyword Replacements, etc.). Callbacks should
     * call `Wizard::renderFeatureItem()` or emit compatible `<li>` markup.
     */
    do_action('prli_onboarding_render_feature_rows', $state);
    ?>
</ul>

<h2 class="prli-onboarding-subhead"><?php esc_html_e('Add-ons', 'pretty-link'); ?></h2>
<ul class="prli-onboarding-features">
    <?php
    Wizard::renderFeatureItem(
        'prli_addon_monsterinsights',
        __('MonsterInsights', 'pretty-link'),
        __('Install the free Google Analytics plugin for deeper traffic insights alongside Pretty Links stats.', 'pretty-link'),
        __('Pretty Links counts your clicks, but MonsterInsights shows what visitors do next — which pages they read, how long they stay, whether they convert. Together you get both sides of the funnel: which links pull traffic in, and what that traffic actually does on your site.', 'pretty-link'),
        !$miActive,
        !$miActive,
        $miActive,
        $miActive ? __('Already active', 'pretty-link') : ''
    );

    /**
     * Action: prli_onboarding_render_addon_rows
     *
     * Fires inside the add-ons list. Plugins append their own add-on rows
     * (Pretty Links Pro contributes Product Displays here when a license
     * is active).
     */
    do_action('prli_onboarding_render_addon_rows', $state);
    ?>
</ul>

<?php
$this->renderFooter(__('Continue', 'pretty-link'), true);
$this->closeForm();
