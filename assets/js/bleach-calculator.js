/**
 * FisHotel Bleach-Out Calculator — front-end logic
 *
 * State, math, persistence, timer, illustration tween, timeline graph,
 * print, and .ics export. State lives in localStorage `fishotel_bleach_v1`.
 * Re-render on every input change (debounced through requestAnimationFrame).
 */

(function () {
	'use strict';

	var STORAGE_KEY = 'fishotel_bleach_v1';
	var L_PER_GAL = 3.785;
	var SODIUM_THIOSULFATE_FACTOR = 7.4;

	var PRESETS = {
		between_qt_fish: { target_ppm: 200, contact_min: 30 },
		bleach_bomb:     { target_ppm: 500, contact_min: 60 }
	};

	var DEFAULTS = {
		volume: 75,
		unit: 'gallons',
		concentration_pct: 8.25,
		preset: 'between_qt_fish',
		custom_target_ppm: 200,
		custom_contact_min: 30,
		neutralizer: 'thiosulfate',
		timer_started_at: null
	};

	var state = loadState();
	var rafQueued = false;
	var timerInterval = null;

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
		try { localStorage.setItem(STORAGE_KEY, JSON.stringify(state)); }
		catch (_) {}
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

	// Render -----------------------------------------------------------------

	function render() {
		rafQueued = false;
		var c = compute();

		// Inputs (echo state into DOM where needed)
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

		// Step 2
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

		updateIllustration(c);
		updateTimeline(c);
		updateTimerUI(c);
	}

	function updateIllustration(c) {
		// Bottle: scale ml against a 100 ml reference (cap at 1.0).
		var bottleFrac = Math.min(1, c.bleach_ml / 100);
		var bottleTop = 36;
		var bottleBottom = 200;
		var bottleH = (bottleBottom - bottleTop) * bottleFrac;
		var bottle = document.querySelector('.fh-bleach__bottle-fill');
		if (bottle) {
			bottle.setAttribute('y', String(bottleBottom - bottleH));
			bottle.setAttribute('height', String(bottleH));
		}

		// Tank: scale ppm against a 1000 ppm reference (cap at 1.0).
		var tankFrac = Math.min(1, c.target_ppm / 1000);
		var tankTop = 22;
		var tankBottom = 180;
		var tankH = (tankBottom - tankTop) * tankFrac;
		var tank = document.querySelector('.fh-bleach__tank-fill');
		if (tank) {
			tank.setAttribute('y', String(tankBottom - tankH));
			tank.setAttribute('height', String(tankH));
		}
	}

	function updateTimeline(c) {
		// X axis: contact_min (rise + plateau) + 15 min for neutralizer + rinse window.
		// Coordinate frame: x 40..580 (540 wide), y 50..150 (100 tall = ppm scale).
		var xLeft = 40, xRight = 580, yTop = 20, yBase = 150;
		var totalMin = c.contact_min + 15;
		var neutX = xLeft + ((c.contact_min / totalMin) * (xRight - xLeft));

		// ppm-target line — use yTop+30 (~y=50) as the visual "target" plateau.
		var yTarget = yTop + 30;

		var line = document.querySelector('[data-fh="tl_line"]');
		if (line) {
			line.setAttribute('points',
				xLeft + ',' + yBase + ' ' +
				xLeft + ',' + yTarget + ' ' +
				neutX + ',' + yTarget + ' ' +
				neutX + ',' + yBase + ' ' +
				xRight + ',' + yBase);
		}

		var danger = document.querySelector('[data-fh="tl_danger"]');
		if (danger) {
			danger.setAttribute('x', String(xLeft));
			danger.setAttribute('width', String(neutX - xLeft));
			danger.setAttribute('y', String(yTarget));
			danger.setAttribute('height', String(yBase - yTarget));
		}
		var safe = document.querySelector('[data-fh="tl_safe"]');
		if (safe) {
			safe.setAttribute('x', String(neutX));
			safe.setAttribute('width', String(xRight - neutX));
			safe.setAttribute('y', String(yTop));
			safe.setAttribute('height', String(yBase - yTop));
		}

		setText('tl_label_neut', c.contact_min + 'm');
		setText('tl_label_end', totalMin + 'm');
		setText('tl_label_target', String(c.target_ppm));
	}

	// Timer ------------------------------------------------------------------

	function updateTimerUI(c) {
		var fill = document.querySelector('[data-fh="timer_fill"]');
		var read = document.querySelector('[data-fh="timer_readout"]');
		var totalSec = c.contact_min * 60;
		var elapsedSec = state.timer_started_at
			? Math.min(totalSec, Math.floor((Date.now() - state.timer_started_at) / 1000))
			: 0;
		var pct = totalSec > 0 ? Math.min(100, (elapsedSec / totalSec) * 100) : 0;
		if (fill) fill.style.width = pct + '%';
		if (read) {
			var rem = Math.max(0, totalSec - elapsedSec);
			read.textContent = mmss(elapsedSec) + ' / ' + mmss(totalSec) +
				(state.timer_started_at && rem === 0 ? ' — done' : '');
		}
		var bar = document.querySelector('.fh-bleach__timer-bar');
		if (bar) bar.setAttribute('aria-valuenow', String(Math.round(pct)));

		if (state.timer_started_at && elapsedSec >= totalSec) {
			stopTimer(false);
		}
	}

	function mmss(sec) {
		var m = Math.floor(sec / 60);
		var s = sec % 60;
		return m + ':' + (s < 10 ? '0' : '') + s;
	}

	function startTimer() {
		if (state.timer_started_at) return;
		state.timer_started_at = Date.now();
		saveState();
		tickTimer();
		if (!timerInterval) timerInterval = setInterval(tickTimer, 1000);
	}

	function stopTimer(clear) {
		if (timerInterval) { clearInterval(timerInterval); timerInterval = null; }
		if (clear) {
			state.timer_started_at = null;
			saveState();
		}
		schedule();
	}

	function tickTimer() { schedule(); }

	// Print + ICS ------------------------------------------------------------

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

		var startBtn = document.querySelector('[data-fh="timer_start"]');
		if (startBtn) startBtn.addEventListener('click', startTimer);
		var resetBtn = document.querySelector('[data-fh="timer_reset"]');
		if (resetBtn) resetBtn.addEventListener('click', function () { stopTimer(true); });

		var printBtn = document.querySelector('[data-fh="action_print"]');
		if (printBtn) printBtn.addEventListener('click', doPrint);
		var icsBtn = document.querySelector('[data-fh="action_ics"]');
		if (icsBtn) icsBtn.addEventListener('click', doIcs);

		// Resume timer if running.
		if (state.timer_started_at && !timerInterval) {
			timerInterval = setInterval(tickTimer, 1000);
		}

		render();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
