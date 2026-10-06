<?php

declare(strict_types=1);

/**
 * First link step (STEP_LINK).
 *
 * @var \PrettyLinks\Onboarding\Wizard $this
 * @var int                            $existingId
 * @var array<string, mixed>|null      $link
 */

defined('ABSPATH') || exit;

$this->openForm();
?>
<h1><?php esc_html_e('Create your first Pretty Link', 'pretty-link'); ?></h1>
<p><?php esc_html_e('Paste any URL and we\'ll create a short, branded link on your domain. You can change or delete it at any time.', 'pretty-link'); ?></p>

<?php
$hasLink = ($existingId > 0 && is_array($link));
// Prefill from the link this step already made, so coming back here edits it
// instead of presenting an empty form that looks like nothing was saved.
$currentUrl  = $hasLink ? (string) ($link['url'] ?? '') : '';
$currentName = $hasLink ? (string) ($link['name'] ?? '') : '';
?>
<?php if ($hasLink) : ?>
    <p class="prli-onboarding-existing">
        <?php
        printf(
            // phpcs:ignore Squiz.Commenting.InlineComment.NotCapital,Squiz.Commenting.InlineComment.InvalidEndChar -- translators comment must remain verbatim.
            // translators: %s: pretty link URL
            esc_html__('Your first link is ready: %s', 'pretty-link'),
            '<code>' . esc_html((string) ($link['pretty_url'] ?? '')) . '</code>'
        );
        ?>
    </p>
<?php endif; ?>

<p>
    <label for="prli_target_url"><?php esc_html_e('Target URL', 'pretty-link'); ?></label>
    <input type="url" id="prli_target_url" name="prli_target_url" class="regular-text code" placeholder="https://example.com/long-url" value="<?php echo esc_attr($currentUrl); ?>" />
</p>

<p>
    <label for="prli_link_name"><?php esc_html_e('Name (optional)', 'pretty-link'); ?></label>
    <input type="text" id="prli_link_name" name="prli_link_name" class="regular-text" value="<?php echo esc_attr($currentName); ?>" />
</p>

<div class="prli-onboarding-actions-inline">
    <?php $this->renderInlineBack(); ?>
    <button type="submit" name="prli_action" value="create" class="button button-primary">
        <?php $hasLink ? esc_html_e('Save Link', 'pretty-link') : esc_html_e('Create Link', 'pretty-link'); ?>
    </button>
    <button type="submit" name="prli_action" value="skip" class="button button-secondary">
        <?php esc_html_e('Skip for now', 'pretty-link'); ?>
    </button>
</div>
<?php
$this->closeForm();
