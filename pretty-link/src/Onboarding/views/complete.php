<?php

declare(strict_types=1);

/**
 * Complete step (STEP_COMPLETE).
 *
 * @var \PrettyLinks\Onboarding\Wizard $this
 */

defined('ABSPATH') || exit;

$this->openForm();
?>
<h1><?php esc_html_e('You\'re all set', 'pretty-link'); ?></h1>
<p><?php esc_html_e('Pretty Links is ready to use. Head to the dashboard to see your links and click reports.', 'pretty-link'); ?></p>

<ul class="prli-onboarding-next">
    <li><?php esc_html_e('Create more links under Pretty Links → Add New.', 'pretty-link'); ?></li>
    <li><?php esc_html_e('Review tracking and retention settings under Pretty Links → Options.', 'pretty-link'); ?></li>
    <li><?php esc_html_e('Install the Pretty Link block in Gutenberg to drop links into posts.', 'pretty-link'); ?></li>
</ul>
<?php
$this->renderFooter(__('Finish Setup', 'pretty-link'), false);
$this->closeForm();
