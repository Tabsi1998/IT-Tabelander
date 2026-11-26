/*
 * IT-Tabelander PDF - signature pads (sign.php). A finger or pen draws on a
 * canvas; "Speichern" crops the drawing, sends it as base64 without the
 * "data:" prefix and "=" padding (Dolibarr's WAF refuses both in a POST) to
 * ajax/sign.php, which writes the PDF again; then the page reloads.
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

	function Pad(canvas) {
		var self = this;
		var ink = '#1b3348';
		var ratio = Math.max(window.devicePixelRatio || 1, 1);
		var drawing = false;
		var last = null;
		self.strokes = 0;
		self.bounds = null;

		function size() {
			var rect = canvas.getBoundingClientRect();
			canvas.width = Math.round(rect.width * ratio);
			canvas.height = Math.round(rect.height * ratio);
			var context = canvas.getContext('2d');
			context.setTransform(ratio, 0, 0, ratio, 0, 0);
			context.lineCap = 'round';
			context.lineJoin = 'round';
			context.strokeStyle = ink;
			context.fillStyle = ink;
			self.strokes = 0;
			self.bounds = null;
			canvas.parentNode.classList.remove('has-ink');
		}

		function point(event) {
			var rect = canvas.getBoundingClientRect();
			return {x: event.clientX - rect.left, y: event.clientY - rect.top, p: event.pressure || 0.5};
		}

		function grow(p) {
			var b = self.bounds || {x1: p.x, y1: p.y, x2: p.x, y2: p.y};
			b.x1 = Math.min(b.x1, p.x);
			b.y1 = Math.min(b.y1, p.y);
			b.x2 = Math.max(b.x2, p.x);
			b.y2 = Math.max(b.y2, p.y);
			self.bounds = b;
		}

		canvas.addEventListener('pointerdown', function (event) {
			event.preventDefault();
			canvas.setPointerCapture(event.pointerId);
			drawing = true;
			last = point(event);
			grow(last);
			var context = canvas.getContext('2d');
			context.beginPath();
			context.arc(last.x, last.y, 1.2, 0, Math.PI * 2);
			context.fill();
			self.strokes++;
			canvas.parentNode.classList.add('has-ink');
		});
		canvas.addEventListener('pointermove', function (event) {
			if (!drawing) {
				return;
			}
			event.preventDefault();
			var next = point(event);
			var context = canvas.getContext('2d');
			// A pen presses harder in the middle of a stroke: a little wider there.
			context.lineWidth = event.pointerType === 'pen' ? 1.4 + next.p * 2 : 2.4;
			var mid = {x: (last.x + next.x) / 2, y: (last.y + next.y) / 2};
			context.beginPath();
			context.moveTo(last.mid ? last.mid.x : last.x, last.mid ? last.mid.y : last.y);
			context.quadraticCurveTo(last.x, last.y, mid.x, mid.y);
			context.stroke();
			next.mid = mid;
			last = next;
			grow(next);
		});
		['pointerup', 'pointercancel', 'pointerleave'].forEach(function (type) {
			canvas.addEventListener(type, function () {
				drawing = false;
			});
		});

		self.clear = size;
		// The drawn part only, with a margin, so it sits well on the PDF line.
		self.image = function () {
			if (!self.strokes || !self.bounds) {
				return '';
			}
			var margin = 8;
			var b = self.bounds;
			var x = Math.max(b.x1 - margin, 0);
			var y = Math.max(b.y1 - margin, 0);
			var w = Math.min(b.x2 + margin, canvas.width / ratio) - x;
			var h = Math.min(b.y2 + margin, canvas.height / ratio) - y;
			var out = document.createElement('canvas');
			out.width = Math.max(Math.round(w * ratio), 20);
			out.height = Math.max(Math.round(h * ratio), 10);
			out.getContext('2d').drawImage(canvas, x * ratio, y * ratio, w * ratio, h * ratio, 0, 0, out.width, out.height);
			return out.toDataURL('image/png').split(',')[1].replace(/=+$/, '');
		};
		size();
		window.addEventListener('resize', function () {
			if (!self.strokes) {
				size();
			}
		});
	}

	ready(function () {
		var root = document.getElementById('ittabsig');
		if (!root || !window.fetch) {
			return;
		}
		Array.prototype.forEach.call(root.querySelectorAll('.ittabsig-card'), function (card) {
			var canvas = card.querySelector('canvas');
			var status = card.querySelector('.ittabsig-status');
			var details = card.querySelector('details');
			var pad = null;
			function start() {
				if (!pad && canvas.getBoundingClientRect().width > 0) {
					pad = new Pad(canvas);
				}
			}
			start();
			if (details) {
				details.addEventListener('toggle', start);
			}
			card.querySelector('.ittabsig-clear').addEventListener('click', function () {
				if (pad) {
					pad.clear();
				}
				status.textContent = '';
			});
			card.querySelector('.ittabsig-save').addEventListener('click', function (event) {
				var button = event.currentTarget;
				var name = card.querySelector('.ittabsig-name').value.trim();
				var png = pad ? pad.image() : '';
				if (!png) {
					status.textContent = root.getAttribute('data-empty');
					return;
				}
				if (!name) {
					status.textContent = root.getAttribute('data-noname');
					card.querySelector('.ittabsig-name').focus();
					return;
				}
				var body = new URLSearchParams();
				body.append('action', 'sign');
				body.append('token', root.getAttribute('data-token'));
				body.append('kind', root.getAttribute('data-kind'));
				body.append('id', root.getAttribute('data-id'));
				body.append('role', card.getAttribute('data-role'));
				body.append('name', name);
				body.append('png', png);
				button.disabled = true;
				status.textContent = root.getAttribute('data-saving');
				fetch(root.getAttribute('data-endpoint'), {method: 'POST', body: body, credentials: 'same-origin'})
					.then(function (response) {
						return response.json().then(function (data) {
							if (!response.ok || !data.ok) {
								throw new Error(data.error || root.getAttribute('data-failed'));
							}
						});
					})
					.then(function () {
						window.location.reload();
					})
					.catch(function (error) {
						button.disabled = false;
						status.textContent = error.message || root.getAttribute('data-failed');
					});
			});
		});
	});
}());
