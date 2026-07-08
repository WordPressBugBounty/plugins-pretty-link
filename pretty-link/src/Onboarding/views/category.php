<?php

declare(strict_types=1);

/**
 * Category step (STEP_CATEGORY).
 *
 * @var \PrettyLinks\Onboarding\Wizard $this
 * @var bool                           $hasSupport
 * @var int                            $linkId
 * @var array<int, array<string, mixed>> $categories
 * @var int                            $currentCat
 * @var array<string, mixed>|null      $currentCatRow
 */

defined('ABSPATH') || exit;

$this->openForm();
?>
<h1><?php esc_html_e('Categorize your link', 'pretty-link'); ?></h1>

<?php if (!$hasSupport) : ?>
    <div class="prli-onboarding-empty">
        <?php
        // Categorization requires a plugin that supplies the
        // `prli_category_create` + `prli_categories_all` hooks. When none
        // is installed, show a "skip" step — plugins that want to surface
        // an upsell here can hook `prli_onboarding_render_category_empty`.
        do_action('prli_onboarding_render_category_empty');
        ?>
    </div>
    <div class="prli-onboarding-actions-inline">
        <?php $this->renderInlineBack(); ?>
        <button type="submit" name="prli_action" value="skip" class="button button-primary">
            <?php esc_html_e('Skip & Continue', 'pretty-link'); ?>
        </button>
    </div>
    <?php
    $this->closeForm();
    return;
endif;
?>

<?php if ($linkId === 0) : ?>
    <p><?php esc_html_e('You didn\'t create a link in the previous step — skip this and add categories later from the Pretty Links dashboard.', 'pretty-link'); ?></p>
    <div class="prli-onboarding-actions-inline">
        <?php $this->renderInlineBack(); ?>
        <button type="submit" name="prli_action" value="skip" class="button button-primary">
            <?php esc_html_e('Continue', 'pretty-link'); ?>
        </button>
    </div>
    <?php
    $this->closeForm();
    return;
endif;
?>

<p><?php esc_html_e('Categories keep things tidy as your link collection grows. Create a new one for this link or pick an existing category.', 'pretty-link'); ?></p>

<?php if (is_array($currentCatRow)) : ?>
    <p class="prli-onboarding-status is-active">
        <?php
        printf(
            // phpcs:ignore Squiz.Commenting.InlineComment.NotCapital,Squiz.Commenting.InlineComment.InvalidEndChar -- translators comment must remain verbatim.
            // translators: %s: category name
            esc_html__('Link assigned to: %s', 'pretty-link'),
            '<strong>' . esc_html((string) $currentCatRow['name']) . '</strong>'
        );
        ?>
    </p>
<?php endif; ?>

<p>
    <label for="prli_category_name"><?php esc_html_e('New category name', 'pretty-link'); ?></label>
    <input type="text" id="prli_category_name" name="prli_category_name" class="regular-text" placeholder="<?php esc_attr_e('e.g. Affiliate Products', 'pretty-link'); ?>" />
</p>

<?php if (!empty($categories)) : ?>
    <p>
        <label for="prli_category_id"><?php esc_html_e('…or pick one', 'pretty-link'); ?></label>
        <select id="prli_category_id" name="prli_category_id">
            <option value="0"><?php esc_html_e('— Select —', 'pretty-link'); ?></option>
            <?php foreach ($categories as $prliCategory) : ?>
                <option value="<?php echo esc_attr((string) (int) $prliCategory['id']); ?>" <?php selected($currentCat, (int) $prliCategory['id']); ?>>
                    <?php echo esc_html((string) $prliCategory['name']); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </p>
<?php endif; ?>

<div class="prli-onboarding-actions-inline">
    <?php $this->renderInlineBack(); ?>
    <button type="submit" name="prli_action" value="create" class="button button-primary">
        <?php esc_html_e('Create & Assign', 'pretty-link'); ?>
    </button>
    <?php if (!empty($categories)) : ?>
        <button type="submit" name="prli_action" value="pick" class="button button-secondary">
            <?php esc_html_e('Assign Selected', 'pretty-link'); ?>
        </button>
    <?php endif; ?>
    <button type="submit" name="prli_action" value="skip" class="button button-secondary">
        <?php esc_html_e('Skip', 'pretty-link'); ?>
    </button>
</div>
<?php
$this->closeForm();
