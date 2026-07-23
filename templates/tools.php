<?php
/**
 * SPDX-FileCopyrightText: 2026 KTEC
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @var \OCP\IL10N $l
 */
\OCP\Util::addScript('talkbot', 'tools');
\OCP\Util::addStyle('talkbot', 'tools');
?>
<div id="talkbot-tools" class="section">
	<h2><?php p($l->t('Model and connection test')); ?></h2>
	<p class="settings-hint">
		<?php p($l->t('Save the settings above, then load the models your key can use, choose one, and test that it answers. The model is set here and nowhere else.')); ?>
	</p>

	<div class="tb-actions">
		<button id="tb-fetch" class="button primary" type="button"><?php p($l->t('Load models')); ?></button>
		<button id="tb-test" class="button" type="button"><?php p($l->t('Test connection')); ?></button>
		<span id="tb-spinner" class="tb-hidden" aria-hidden="true">⏳</span>
	</div>

	<div id="tb-result" role="status" aria-live="polite"></div>

	<div id="tb-models-wrap" class="tb-hidden">
		<h3><?php p($l->t('Model')); ?> <small id="tb-current"></small></h3>
		<div class="tb-model-row">
			<label class="tb-visually-hidden" for="tb-model-select"><?php p($l->t('Model')); ?></label>
			<select id="tb-model-select"></select>
			<button id="tb-model-save" class="button primary" type="button"><?php p($l->t('Use this model')); ?></button>
		</div>
	</div>

	<div id="tb-engines-wrap" class="tb-hidden">
		<h3><?php p($l->t('What is ready to use')); ?></h3>
		<ul id="tb-engines"></ul>
	</div>
</div>
