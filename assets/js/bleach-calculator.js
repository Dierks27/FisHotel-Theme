/**
 * FisHotel Bleach-Out Calculator — front-end logic
 *
 * State + math live in localStorage `fishotel_bleach_v1`. Re-renders on
 * every input change (rAF-coalesced). Timer mirrors the existing
 * /medication-dosing/ countdown — Begin / Pause / Resume / Abort, bell
 * chime on completion, never auto-starts, never persisted across reload.
 */

(function () {
	'use strict';

	var STORAGE_KEY = 'fishotel_bleach_v1';
	var L_PER_GAL = 3.785;
	var SODIUM_THIOSULFATE_FACTOR = 7.4;
	var US_CUP_ML = 236.588;
	var US_OZ_ML  = 29.5735;
	var CUP_DISPLAY_CAP = 8;         // max cup icons shown in the row before overflow indicator
	var CUP_FILL_TOP = 13, CUP_FILL_BOTTOM = 38; // SVG coords inside one cup icon

	var PRESETS = {
		between_qt_fish: { target_ppm: 200, contact_min: 30 },
		bleach_bomb:     { target_ppm: 500, contact_min: 60 }
	};

	var PERSISTED_KEYS = [
		'volume', 'unit', 'concentration_pct', 'preset',
		'custom_target_ppm', 'custom_contact_min', 'neutralizer'
	];

	var DEFAULTS = {
		volume: 75,
		unit: 'gallons',
		concentration_pct: 8.25,
		preset: 'between_qt_fish',
		custom_target_ppm: 200,
		custom_contact_min: 30,
		neutralizer: 'thiosulfate'
	};

	var state = loadState();
	var rafQueued = false;

	// Timer state — never persisted, resets on reload (matches existing calc).
	var timer = { totalSec: 0, remainingSec: 0, running: false, paused: false, completed: false, intervalId: null };

	function loadState() {
		try {
			var raw = localStorage.getItem(STORAGE_KEY);
			if (!raw) return Object.assign({}, DEFAULTS);
			var parsed = JSON.parse(raw);
			return Object.assign({}, DEFAULTS, parsed);
		} catch (_) {
			return Object.assign({}, DEFAULTS);
		}
	}

	function saveState() {
		try {
			var snap = {};
			PERSISTED_KEYS.forEach(function (k) { snap[k] = state[k]; });
			localStorage.setItem(STORAGE_KEY, JSON.stringify(snap));
		} catch (_) {}
	}

	function resolvePreset() {
		if (state.preset === 'custom') {
			return { target_ppm: state.custom_target_ppm, contact_min: state.custom_contact_min };
		}
		return PRESETS[state.preset] || PRESETS.between_qt_fish;
	}

	function compute() {
		var preset = resolvePreset();
		var volume_gal = state.unit === 'liters' ? (state.volume / L_PER_GAL) : state.volume;
		var conc = Math.max(0.01, Number(state.concentration_pct) || 0);
		var target = preset.target_ppm;

		var bleach_ml = (target * volume_gal * L_PER_GAL) / (conc * 10);
		var contact_min = preset.contact_min;
		var thiosulfate_g = (target * volume_gal * L_PER_GAL * SODIUM_THIOSULFATE_FACTOR) / 1000;

		var prime_multiplier = target <= 4 ? 1 : (target <= 50 ? 5 : null);
		var prime_ml = prime_multiplier === null ? null : volume_gal * 0.1 * prime_multiplier;

		return {
			target_ppm: target,
			contact_min: contact_min,
			volume_gal: volume_gal,
			bleach_ml: bleach_ml,
			thiosulfate_g: thiosulfate_g,
			prime_ml: prime_ml,
			conc: conc
		};
	}

	function fmt(n) {
		if (!isFinite(n)) return '—';
		if (n >= 100) return Math.round(n).toString();
		return (Math.round(n * 10) / 10).toString();
	}

	function fmt1(n) { return (Math.round(n * 10) / 10).toFixed(1); }

	// Render -----------------------------------------------------------------

	function render() {
		rafQueued = false;
		var c = compute();

		setVal('fh-bleach-volume', state.volume);
		setVal('fh-bleach-conc', state.concentration_pct);
		setVal('fh-bleach-custom-ppm', state.custom_target_ppm);
		setVal('fh-bleach-custom-min', state.custom_contact_min);

		document.querySelectorAll('.fh-bleach__unit').forEach(function (b) {
			b.classList.toggle('is-on', b.dataset.unit === state.unit);
			b.setAttribute('aria-selected', b.dataset.unit === state.unit ? 'true' : 'false');
		});
		document.querySelectorAll('.fh-bleach__preset').forEach(function (lbl) {
			var input = lbl.querySelector('input');
			var on = input && input.value === state.preset;
			lbl.classList.toggle('is-on', !!on);
			if (input) input.checked = !!on;
		});
		document.querySelectorAll('.fh-bleach__neut').forEach(function (lbl) {
			var input = lbl.querySelector('input');
			var on = input && input.value === state.neutralizer;
			lbl.classList.toggle('is-on', !!on);
			if (input) input.checked = !!on;
		});

		var customWrap = document.querySelector('.fh-bleach__custom');
		if (customWrap) customWrap.hidden = state.preset !== 'custom';

		// Step 1
		setText('bleach_ml', fmt(c.bleach_ml));
		setText('bleach_sub',
			fmt(c.bleach_ml) + ' ml household bleach (' + fmt(c.conc) + '%) → ' +
			fmt(c.target_ppm) + ' ppm chlorine in ' + fmt(state.volume) + ' ' + state.unit);
		setText('bleach_math',
			'(' + fmt(c.target_ppm) + ' × ' + fmt(c.volume_gal) + ' × 3.785) ÷ (' +
			fmt(c.conc) + ' × 10) = ' + fmt(c.bleach_ml) + ' ml');

		// Step 2 — contact minutes (the timer keeps its own remainingSec)
		setText('contact_min', fmt(c.contact_min));

		// Step 3
		var box = document.querySelector('[data-fh="neut_box"]');
		var warn = document.querySelector('[data-fh="neut_warn"]');
		if (state.neutralizer === 'thiosulfate') {
			if (warn) warn.hidden = true;
			if (box) box.style.display = '';
			setText('neut_amount', fmt(c.thiosulfate_g));
			setText('neut_unit', 'g sodium thiosulfate');
			setText('neut_math',
				'(' + fmt(c.target_ppm) + ' × ' + fmt(c.volume_gal) + ' × 3.785 × 7.4) ÷ 1000 = ' +
				fmt(c.thiosulfate_g) + ' g');
		} else {
			if (c.prime_ml === null) {
				if (warn) warn.hidden = false;
				if (box) box.style.display = 'none';
				setText('neut_math', '');
			} else {
				if (warn) warn.hidden = true;
				if (box) box.style.display = '';
				setText('neut_amount', fmt(c.prime_ml));
				setText('neut_unit', 'ml Seachem Prime');
				var mult = c.target_ppm <= 4 ? 1 : 5;
				setText('neut_math',
					fmt(c.volume_gal) + ' × 0.1 × ' + mult + '× = ' + fmt(c.prime_ml) + ' ml');
			}
		}

		// Print payload
		setText('print_bleach', fmt(c.bleach_ml));
		setText('print_conc', fmt(c.conc));
		setText('print_contact', fmt(c.contact_min));
		setText('print_neut',
			state.neutralizer === 'thiosulfate'
				? fmt(c.thiosulfate_g) + ' g sodium thiosulfate'
				: (c.prime_ml === null ? 'sodium thiosulfate (Prime out of range)' : fmt(c.prime_ml) + ' ml Seachem Prime'));

		updateMeasuringCup(c);
		syncTimer(c);
		renderTimer();
	}

	// Measuring cup row -----------------------------------------------------

	var CUP_SVG_TEMPLATE =
		'<svg class="fh-bleach__cup-icon" viewBox="0 0 36 44" role="img" aria-hidden="true">' +
			'<path d="M5 13 Q1 17 1 24 Q1 31 5 33" fill="none" stroke="currentColor" stroke-width="1.1"/>' +
			'<rect class="fh-bleach__cup-icon-fill" x="7" y="38" width="22" height="0" fill="url(#fh-bleach-cup-hatch)"/>' +
			'<path d="M5 10 L31 10 Q33 10 32.5 13 L29 38 Q28.5 40 26 40 L10 40 Q7.5 40 7 38 L3.5 13 Q3 10 5 10 Z" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/>' +
			'<ellipse cx="18" cy="10" rx="13" ry="1.6" fill="none" stroke="currentColor" stroke-width="0.9" opacity="0.55"/>' +
		'</svg>';

	function updateMeasuringCup(c) {
		var ml       = c.bleach_ml;
		var cups     = ml / US_CUP_ML;
		var oz       = ml / US_OZ_ML;
		var fullRange = CUP_FILL_BOTTOM - CUP_FILL_TOP;
		var displayed = Math.min(Math.max(0, Math.ceil(cups)), CUP_DISPLAY_CAP);

		var row = document.querySelector('[data-fh="cup_row"]');
		if (row) {
			// Add or remove cup elements to match `displayed`. Reusing existing
			// nodes lets the CSS transition on y/height tween fills in place.
			var holder = document.createElement('div');
			while (row.children.length < displayed) {
				holder.innerHTML = CUP_SVG_TEMPLATE;
				row.appendChild(holder.firstChild);
			}
			while (row.children.length > displayed) {
				row.removeChild(row.lastChild);
			}

			for (var i = 0; i < displayed; i++) {
				var slot = row.children[i];
				var slotFrac = Math.max(0, Math.min(1, cups - i));
				var h = fullRange * slotFrac;
				var fill = slot.querySelector('.fh-bleach__cup-icon-fill');
				if (fill) {
					fill.setAttribute('y', String(CUP_FILL_BOTTOM - h));
					fill.setAttribute('height', String(h));
				}
				slot.classList.toggle('is-empty', slotFrac === 0);
			}
		}

		setText('cup_label', fmt(ml) + ' ml · ' + fmt1(cups) + ' cups · ' + fmt1(oz) + ' oz');

		var repeat = document.querySelector('[data-fh="cup_repeat"]');
		if (repeat) {
			if (cups > CUP_DISPLAY_CAP) {
				var extra = cups - CUP_DISPLAY_CAP;
				repeat.textContent = '+ ' + fmt1(extra) + ' more cups (= ' + fmt1(cups) + ' cups · ' + fmt(ml) + ' ml total)';
				repeat.hidden = false;
			} else {
				repeat.hidden = true;
			}
		}
	}

	// Timer (countdown — matches /medication-dosing/) -----------------------

	function syncTimer(c) {
		var newTotal = Math.round(c.contact_min * 60);
		// If the timer is idle, slave its total + remaining to the current preset.
		if (!timer.running && !timer.paused && !timer.completed) {
			timer.totalSec = newTotal;
			timer.remainingSec = newTotal;
		}
	}

	function renderTimer() {
		var displayEl = document.querySelector('[data-fh="timer_display"]');
		if (displayEl) displayEl.textContent = formatMMSS(timer.remainingSec);

		var doneEl = document.querySelector('[data-fh="timer_complete"]');
		if (doneEl) doneEl.hidden = !timer.completed;

		var idle      = !timer.running && !timer.paused && !timer.completed && timer.remainingSec === timer.totalSec;
		var running   = timer.running && !timer.paused;
		var paused    = timer.paused;
		var completed = timer.completed;

		showTimerBtn('begin',   idle);
		showTimerBtn('pause',   running);
		showTimerBtn('resume',  paused);
		showTimerBtn('reset',   running || paused);
		showTimerBtn('restart', completed);
	}

	function showTimerBtn(name, on) {
		var b = document.querySelector('[data-fh-timer="' + name + '"]');
		if (b) b.hidden = !on;
	}

	function formatMMSS(sec) {
		if (sec < 0) sec = 0;
		var m = Math.floor(sec / 60);
		var s = sec % 60;
		return (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s;
	}

	function startTimer() {
		if (timer.intervalId) return;
		timer.running = true;
		timer.paused = false;
		timer.intervalId = setInterval(function () {
			timer.remainingSec -= 1;
			var d = document.querySelector('[data-fh="timer_display"]');
			if (d) d.textContent = formatMMSS(timer.remainingSec);
			if (timer.remainingSec <= 0) {
				clearInterval(timer.intervalId);
				timer.intervalId = null;
				timer.running = false;
				timer.completed = true;
				playBellChime();
				schedule();
			}
		}, 1000);
		schedule();
	}

	function pauseTimer() {
		if (timer.intervalId) { clearInterval(timer.intervalId); timer.intervalId = null; }
		timer.running = false;
		timer.paused = true;
		schedule();
	}

	function abortTimer() {
		if (timer.intervalId) { clearInterval(timer.intervalId); timer.intervalId = null; }
		// Reset to idle, then re-sync to current preset on next render.
		timer.running = false;
		timer.paused = false;
		timer.completed = false;
		timer.totalSec = 0;
		timer.remainingSec = 0;
		schedule();
	}

	function playBellChime() {
		try {
			var Ctx = window.AudioContext || window.webkitAudioContext;
			if (!Ctx) return;
			var ctx = new Ctx();
			var now = ctx.currentTime;

			var playPartial = function (freq, gain, startOffset, decayTime) {
				var osc = ctx.createOscillator();
				var g = ctx.createGain();
				osc.type = 'sine';
				osc.frequency.setValueAtTime(freq, now + startOffset);
				g.gain.setValueAtTime(0.0001, now + startOffset);
				g.gain.exponentialRampToValueAtTime(gain, now + startOffset + 0.005);
				g.gain.exponentialRampToValueAtTime(0.0001, now + startOffset + decayTime);
				osc.connect(g);
				g.connect(ctx.destination);
				osc.start(now + startOffset);
				osc.stop(now + startOffset + decayTime + 0.05);
			};

			playPartial(660,  0.25, 0,    2.5);
			playPartial(990,  0.12, 0,    1.8);
			playPartial(1320, 0.08, 0,    1.2);
			playPartial(660,  0.18, 0.65, 2.2);
			playPartial(990,  0.09, 0.65, 1.6);
			playPartial(1320, 0.06, 0.65, 1.1);

			setTimeout(function () { try { ctx.close(); } catch (e) {} }, 3500);
		} catch (e) {
			// AudioContext unavailable — no-op
		}
	}

	// Print + ICS -----------------------------------------------------------

	function doPrint() { window.print(); }

	function doIcs() {
		var c = compute();
		var now = new Date();
		var start = now;
		var end = new Date(start.getTime() + c.contact_min * 60 * 1000);

		var pad = function (n) { return n < 10 ? '0' + n : '' + n; };
		var icsDate = function (d) {
			return d.getUTCFullYear().toString() +
				pad(d.getUTCMonth() + 1) +
				pad(d.getUTCDate()) + 'T' +
				pad(d.getUTCHours()) +
				pad(d.getUTCMinutes()) +
				pad(d.getUTCSeconds()) + 'Z';
		};

		var neutLabel = state.neutralizer === 'thiosulfate'
			? fmt(c.thiosulfate_g) + ' g sodium thiosulfate'
			: (c.prime_ml === null ? 'sodium thiosulfate (Prime out of range)' : fmt(c.prime_ml) + ' ml Seachem Prime');

		var uid1 = 'fh-bleach-start-' + start.getTime() + '@fishotel';
		var uid2 = 'fh-bleach-end-' + end.getTime() + '@fishotel';
		var stamp = icsDate(now);

		var lines = [
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:-//FisHotel//Bleach Calculator//EN',
			'BEGIN:VEVENT',
			'UID:' + uid1,
			'DTSTAMP:' + stamp,
			'DTSTART:' + icsDate(start),
			'DTEND:' + icsDate(new Date(start.getTime() + 5 * 60 * 1000)),
			'SUMMARY:Bleach dose: ' + fmt(c.bleach_ml) + ' ml @ ' + fmt(c.conc) + '%',
			'DESCRIPTION:Add ' + fmt(c.bleach_ml) + ' ml bleach to ' + fmt(state.volume) + ' ' + state.unit + '. Target ' + fmt(c.target_ppm) + ' ppm. Contact ' + c.contact_min + ' min.',
			'BEGIN:VALARM',
			'TRIGGER:-PT5M',
			'ACTION:DISPLAY',
			'DESCRIPTION:Bleach dose in 5 min',
			'END:VALARM',
			'END:VEVENT',
			'BEGIN:VEVENT',
			'UID:' + uid2,
			'DTSTAMP:' + stamp,
			'DTSTART:' + icsDate(end),
			'DTEND:' + icsDate(new Date(end.getTime() + 15 * 60 * 1000)),
			'SUMMARY:Add neutralizer + rinse',
			'DESCRIPTION:Add ' + neutLabel + '. Wait 5 min. Then 2-3 fresh-water rinses. Confirm 0 ppm before reuse.',
			'BEGIN:VALARM',
			'TRIGGER:-PT5M',
			'ACTION:DISPLAY',
			'DESCRIPTION:Neutralizer in 5 min',
			'END:VALARM',
			'END:VEVENT',
			'END:VCALENDAR'
		];

		var blob = new Blob([lines.join('\r\n')], { type: 'text/calendar' });
		var url = URL.createObjectURL(blob);
		var a = document.createElement('a');
		var ymd = now.getFullYear() + '-' + pad(now.getMonth() + 1) + '-' + pad(now.getDate());
		a.href = url;
		a.download = 'fishotel-bleach-' + ymd + '.ics';
		document.body.appendChild(a);
		a.click();
		document.body.removeChild(a);
		setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
	}

	// Helpers ---------------------------------------------------------------

	function setText(name, value) {
		document.querySelectorAll('[data-fh="' + name + '"]').forEach(function (el) {
			el.textContent = value;
		});
	}

	function setVal(id, value) {
		var el = document.getElementById(id);
		if (el && el.value !== String(value) && document.activeElement !== el) {
			el.value = value;
		}
	}

	function schedule() {
		if (rafQueued) return;
		rafQueued = true;
		requestAnimationFrame(render);
	}

	function clamp(n, lo, hi) { return Math.max(lo, Math.min(hi, n)); }

	// Wire up ---------------------------------------------------------------

	function init() {
		var vol = document.getElementById('fh-bleach-volume');
		if (vol) vol.addEventListener('input', function (e) {
			var n = Number(e.target.value);
			if (!isFinite(n)) return;
			state.volume = clamp(n, 1, 1000);
			saveState(); schedule();
		});

		var conc = document.getElementById('fh-bleach-conc');
		if (conc) conc.addEventListener('input', function (e) {
			var n = Number(e.target.value);
			if (!isFinite(n)) return;
			state.concentration_pct = clamp(n, 1, 15);
			saveState(); schedule();
		});

		document.querySelectorAll('.fh-bleach__unit').forEach(function (b) {
			b.addEventListener('click', function () {
				state.unit = b.dataset.unit;
				saveState(); schedule();
			});
		});

		document.querySelectorAll('input[name="fh-bleach-preset"]').forEach(function (r) {
			r.addEventListener('change', function () {
				state.preset = r.value;
				saveState(); schedule();
			});
		});

		var cppm = document.getElementById('fh-bleach-custom-ppm');
		if (cppm) cppm.addEventListener('input', function (e) {
			var n = Number(e.target.value);
			if (!isFinite(n)) return;
			state.custom_target_ppm = clamp(n, 50, 2000);
			saveState(); schedule();
		});
		var cmin = document.getElementById('fh-bleach-custom-min');
		if (cmin) cmin.addEventListener('input', function (e) {
			var n = Number(e.target.value);
			if (!isFinite(n)) return;
			state.custom_contact_min = clamp(n, 5, 240);
			saveState(); schedule();
		});

		document.querySelectorAll('input[name="fh-bleach-neut"]').forEach(function (r) {
			r.addEventListener('change', function () {
				state.neutralizer = r.value;
				saveState(); schedule();
			});
		});

		var printBtn = document.querySelector('[data-fh="action_print"]');
		if (printBtn) printBtn.addEventListener('click', doPrint);
		var icsBtn = document.querySelector('[data-fh="action_ics"]');
		if (icsBtn) icsBtn.addEventListener('click', doIcs);

		bindTimerBtn('begin',   startTimer);
		bindTimerBtn('pause',   pauseTimer);
		bindTimerBtn('resume',  startTimer);
		bindTimerBtn('reset',   abortTimer);
		bindTimerBtn('restart', abortTimer);

		render();
	}

	function bindTimerBtn(name, fn) {
		var b = document.querySelector('[data-fh-timer="' + name + '"]');
		if (b) b.addEventListener('click', fn);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
