/**
 * SPDX-FileCopyrightText: 2026 KTEC
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Model picker and connection test for the Talk Bot admin settings.
 */
(function () {
	'use strict';

	var t = function (text) {
		return (typeof OC !== 'undefined' && OC.L10N) ? OC.L10N.translate('talkbot', text) : text;
	};

	var el = function (id) {
		return document.getElementById(id);
	};

	function url(path) {
		return OC.generateUrl('/apps/talkbot' + path);
	}

	function request(method, path, body) {
		return fetch(url(path), {
			method: method,
			headers: {
				'Content-Type': 'application/json',
				'requesttoken': OC.requestToken,
				'Accept': 'application/json'
			},
			body: body ? JSON.stringify(body) : undefined
		}).then(function (response) {
			return response.json().then(function (data) {
				if (!response.ok) {
					throw new Error((data && data.detail) || ('HTTP ' + response.status));
				}
				return data;
			});
		});
	}

	function busy(on) {
		var spinner = el('tb-spinner');
		if (spinner) {
			spinner.classList.toggle('tb-hidden', !on);
		}
		['tb-fetch', 'tb-test', 'tb-model-save'].forEach(function (id) {
			var button = el(id);
			if (button) {
				button.disabled = on;
			}
		});
	}

	function say(message, ok) {
		var box = el('tb-result');
		if (!box) {
			return;
		}
		box.textContent = message;
		box.classList.remove('tb-ok', 'tb-err');
		if (message) {
			box.classList.add(ok ? 'tb-ok' : 'tb-err');
		}
	}

	function renderModels(data) {
		var wrap = el('tb-models-wrap');
		var select = el('tb-model-select');
		var current = el('tb-current');
		if (!wrap || !select) {
			return;
		}

		select.innerHTML = '';
		(data.models || []).forEach(function (model) {
			var option = document.createElement('option');
			option.value = model;
			option.textContent = model;
			if (model === data.current.model) {
				option.selected = true;
			}
			select.appendChild(option);
		});

		if (current) {
			current.textContent = data.current.provider + ' / ' + data.current.mode
				+ (data.current.model ? ' — ' + data.current.model : '');
		}
		wrap.classList.toggle('tb-hidden', (data.models || []).length === 0);
	}

	function renderEngines(engines) {
		var wrap = el('tb-engines-wrap');
		var list = el('tb-engines');
		if (!wrap || !list) {
			return;
		}

		list.innerHTML = '';
		(engines || []).forEach(function (engine) {
			var item = document.createElement('li');
			item.className = engine.ready ? 'tb-ready' : 'tb-not-ready';
			item.textContent = (engine.ready ? '✓ ' : '· ') + engine.label
				+ (engine.detail ? ' — ' + engine.detail : '');
			list.appendChild(item);
		});
		wrap.classList.toggle('tb-hidden', (engines || []).length === 0);
	}

	function loadModels() {
		busy(true);
		say('', true);
		request('GET', '/tools/models').then(function (data) {
			renderModels(data);
			renderEngines(data.engines);
			if (data.note) {
				say(data.note, true);
			} else {
				say(t('Loaded {count} models.').replace('{count}', (data.models || []).length), true);
			}
		}).catch(function (error) {
			say(error.message, false);
		}).then(function () {
			busy(false);
		});
	}

	function saveModel() {
		var select = el('tb-model-select');
		if (!select || !select.value) {
			return;
		}
		busy(true);
		request('POST', '/tools/model', { model: select.value }).then(function (data) {
			say(t('Model set to {model}.').replace('{model}', data.model), true);
			var current = el('tb-current');
			if (current) {
				current.textContent = current.textContent.replace(/—.*$/, '— ' + data.model);
			}
		}).catch(function (error) {
			say(error.message, false);
		}).then(function () {
			busy(false);
		});
	}

	function testConnection() {
		busy(true);
		say(t('Contacting the AI service…'), true);
		request('POST', '/tools/test', {}).then(function (data) {
			if (data.ok) {
				say(t('{engine} answered using {model}: {reply}')
					.replace('{engine}', data.engine)
					.replace('{model}', data.model)
					.replace('{reply}', data.reply), true);
			} else {
				say(t('No answer: {detail}').replace('{detail}', data.detail || ''), false);
			}
		}).catch(function (error) {
			say(error.message, false);
		}).then(function () {
			busy(false);
		});
	}

	document.addEventListener('DOMContentLoaded', function () {
		var fetchButton = el('tb-fetch');
		var testButton = el('tb-test');
		var saveButton = el('tb-model-save');
		if (!fetchButton) {
			return;
		}
		fetchButton.addEventListener('click', loadModels);
		if (testButton) {
			testButton.addEventListener('click', testConnection);
		}
		if (saveButton) {
			saveButton.addEventListener('click', saveModel);
		}
	});
})();
