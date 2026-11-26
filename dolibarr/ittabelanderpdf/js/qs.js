/*
 * IT-Tabelander PDF - QS checklist (qs.php): every change of a point is saved
 * at once through ajax/qs.php; the counters at the top follow. Without
 * JavaScript the form posts everything with its own button.
 */
(function () {
	'use strict';

	function ready(fn) {
		if (document.readyState !== 'loading') {
			fn();
		} else {
			document.addEventListener('DOMContentLoaded', fn);
		}
	}

	ready(function () {
		var form = document.getElementById('ittabqs-form');
		if (!form || !window.fetch) {
			return;
		}
		var status = document.getElementById('ittabqs-status');
		var timers = {};

		function field(item, prefix) {
			return item.querySelector('[name="' + prefix + item.getAttribute('data-id') + '"]');
		}

		function stateOf(item) {
			var skip = field(item, 'skip_');
			var tick = field(item, 'checked_');
			if (skip && skip.checked) {
				return 2;
			}
			return tick && tick.checked ? 1 : 0;
		}

		function isDone(item) {
			var state = stateOf(item);
			if (state === 2) {
				return true;
			}
			if (item.getAttribute('data-kind') === 'check') {
				return state === 1;
			}
			var value = field(item, 'value_');
			return !!value && value.value.trim() !== '';
		}

		function showCounts(counts) {
			if (!status || !counts) {
				return;
			}
			var text = status.querySelector('.ittabqs-status-text');
			var line = text.getAttribute('data-done-label').replace('%d', counts.done).replace('%t', counts.total);
			text.textContent = line;
			if (counts.required_open > 0) {
				var strong = document.createElement('strong');
				strong.textContent = text.getAttribute('data-required-label').replace('%r', counts.required_open);
				text.appendChild(document.createTextNode(' · '));
				text.appendChild(strong);
			}
		}

		function save(item) {
			var meta = item.querySelector('.ittabqs-meta');
			var value = field(item, 'value_');
			var note = field(item, 'note_');
			var body = new URLSearchParams();
			body.append('action', 'saveitem');
			body.append('token', form.getAttribute('data-token'));
			body.append('run', form.getAttribute('data-run'));
			body.append('item', item.getAttribute('data-id'));
			body.append('checked', stateOf(item));
			body.append('value', value ? value.value : '');
			body.append('note', note ? note.value : '');
			item.classList.add('is-saving');
			item.classList.remove('is-error');
			fetch(form.getAttribute('data-endpoint'), {method: 'POST', body: body, credentials: 'same-origin'})
				.then(function (response) {
					return response.json().then(function (data) {
						if (!response.ok || !data.ok) {
							throw new Error(data.error || response.status);
						}
						return data;
					});
				})
				.then(function (data) {
					item.classList.remove('is-saving');
					item.classList.add('is-saved');
					meta.textContent = data.meta || form.getAttribute('data-saved');
					showCounts(data.counts);
					window.setTimeout(function () {
						item.classList.remove('is-saved');
					}, 1200);
				})
				.catch(function () {
					item.classList.remove('is-saving');
					item.classList.add('is-error');
					meta.textContent = form.getAttribute('data-failed');
				});
		}

		function refresh(item) {
			item.classList.toggle('is-done', isDone(item));
			item.classList.toggle('is-skipped', stateOf(item) === 2);
			if (isDone(item)) {
				item.classList.remove('is-missing');
			}
		}

		Array.prototype.forEach.call(form.querySelectorAll('.ittabqs-item'), function (item) {
			var tick = field(item, 'checked_');
			var skip = field(item, 'skip_');
			// Ticked and "does not apply" exclude each other.
			if (tick && skip) {
				tick.addEventListener('change', function () {
					if (tick.checked) {
						skip.checked = false;
					}
				});
				skip.addEventListener('change', function () {
					if (skip.checked) {
						tick.checked = false;
					}
				});
			}
			item.addEventListener('change', function () {
				window.clearTimeout(timers[item.getAttribute('data-id')]);
				refresh(item);
				save(item);
			});
			// Typing: save after a pause, so a value is safe before the phone locks.
			item.addEventListener('input', function (event) {
				if (event.target.type === 'checkbox') {
					return;
				}
				refresh(item);
				var id = item.getAttribute('data-id');
				window.clearTimeout(timers[id]);
				timers[id] = window.setTimeout(function () {
					save(item);
				}, 900);
			});
		});
	});
}());
