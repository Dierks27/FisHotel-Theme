/**
 * FisHotel — Compatibility Guide (Build-a-Tank v1)
 *
 * Vanilla JS, no framework. Loads 5 JSON fixtures from /assets/data/,
 * runs an O(n^2) pairwise compatibility scan over the user's tank, and
 * renders fish cards / a conflicts panel that update on every change.
 *
 * Data files: categories, matrix, cirrhilabrus, species, sampleTanks
 * Persistence: localStorage[fishotel_tank_state_v1]
 * Debug handle: window.FishotelTank
 */
(function () {
	'use strict';

	const CFG           = window.fishotelCompat || {};
	const URLS          = CFG.urls || {};
	const STORAGE_KEY   = CFG.storageKey || 'fishotel_tank_state_v1';
	const VERDICT_LABEL = CFG.verdictLabels || {
		C: 'Compatible', W: 'Watch', O: 'Order matters', '1': 'Single only', N: 'Not recommended'
	};
	const VERDICT_RANK  = { C: 0, W: 1, O: 2, '1': 3, N: 4 };

	// Volume modifier rule sets — kept in sync with PHP twin in
	// inc/compatibility-guide-data.php.
	const PEACEFUL = new Set([
		'clownfish', 'cardinalfish',
		'gobies_cryptocentrus', 'gobies_elacatinus',
		'royal_gramma', 'firefish', 'blennies_salarias'
	]);
	const AGGRESSIVE_FAMILIES = new Set([
		'tangs_acanthurus', 'tangs_zebrasoma',
		'dwarf_angels_centropyge',
		'large_angels_pomacanthus', 'large_angels_holacanthus',
		'triggerfish', 'puffers'
	]);
	const TANGS_OR_ANGELS = new Set([
		'tangs_acanthurus', 'tangs_zebrasoma', 'tangs_ctenochaetus', 'tangs_naso', 'tangs_paracanthurus',
		'dwarf_angels_centropyge',
		'large_angels_pomacanthus', 'large_angels_holacanthus',
		'genicanthus_angels'
	]);
	const TIGHTEN_ONE = { C: 'W', W: 'O', O: 'N', '1': 'N', N: 'N' };

	let state = { volume: '', myTank: [], considering: [] };
	let data = null;
	let conflicts = [];
	let pendingPick = null;
	let saveTimer = null;
	let matrixRendered = false;

	/* ──────────────────────────────────────────
	 * DOM helpers
	 * ────────────────────────────────────────── */
	const $  = (sel, ctx) => (ctx || document).querySelector(sel);
	const $$ = (sel, ctx) => Array.from((ctx || document).querySelectorAll(sel));
	const uid = () => 'f' + Date.now().toString(36) + Math.random().toString(36).slice(2, 6);
	function escapeHtml(s) {
		return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
			return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
		});
	}
	function worstOf(a, b) {
		if (!(a in VERDICT_RANK)) return b;
		if (!(b in VERDICT_RANK)) return a;
		return VERDICT_RANK[a] >= VERDICT_RANK[b] ? a : b;
	}

	/* ──────────────────────────────────────────
	 * State persistence
	 * ────────────────────────────────────────── */
	function loadState() {
		try {
			const raw = localStorage.getItem(STORAGE_KEY);
			if (!raw) return;
			const stored = JSON.parse(raw);
			if (stored && typeof stored === 'object') {
				state.volume      = stored.volume || '';
				state.myTank      = Array.isArray(stored.myTank) ? stored.myTank : [];
				state.considering = Array.isArray(stored.considering) ? stored.considering : [];
			}
		} catch (e) { /* swallow — bad JSON / quota / privacy mode */ }
	}
	function saveState() {
		clearTimeout(saveTimer);
		saveTimer = setTimeout(function () {
			try { localStorage.setItem(STORAGE_KEY, JSON.stringify(state)); }
			catch (e) { /* quota / privacy mode */ }
		}, 300);
	}

	/* ──────────────────────────────────────────
	 * Data loading
	 * ────────────────────────────────────────── */
	async function loadData() {
		const fetchJson = (u) => fetch(u, { credentials: 'same-origin' }).then((r) => {
			if (!r.ok) throw new Error('HTTP ' + r.status + ' ' + u);
			return r.json();
		});
		const [categories, matrix, cirrhilabrus, species, sampleTanks] = await Promise.all([
			fetchJson(URLS.categories),
			fetchJson(URLS.matrix),
			fetchJson(URLS.cirrhilabrus),
			fetchJson(URLS.species),
			fetchJson(URLS.sampleTanks),
		]);
		return { categories, matrix, cirrhilabrus, species, sampleTanks };
	}

	function categoryByKey(key) {
		if (!data || !data.categories) return null;
		for (const c of data.categories) if (c.key === key) return c;
		return null;
	}
	function speciesInCategory(key) {
		if (!data || !data.species) return [];
		return data.species.filter((s) => s.category === key);
	}
	function matrixCell(catA, catB) {
		const m = data && data.matrix;
		if (!m) return { v: 'C', note: '' };
		if (m[catA] && m[catA][catB]) return m[catA][catB];
		if (m[catB] && m[catB][catA]) return m[catB][catA];
		return { v: 'C', note: '' };
	}

	/* ──────────────────────────────────────────
	 * Cirrhilabrus inner check
	 * ────────────────────────────────────────── */
	function isCirrhilabrus(fish) {
		if (!fish) return false;
		if (fish.category === 'wrasses_cirrhilabrus' || fish.category === 'cirrhilabrus') return true;
		if (fish.sci && /^C\.\s*/i.test(fish.sci)) return true;
		return false;
	}
	// species.json stores the full binomial ("Cirrhilabrus lubbocki"),
	// cirrhilabrus.json uses the abbreviated form ("C. lubbocki"). Normalize
	// to the abbreviated form for matching.
	function normalizeCirrSci(sci) {
		return String(sci || '')
			.trim()
			.replace(/^cirrhilabrus\b/i, 'c.')
			.replace(/\s+/g, ' ')
			.toLowerCase();
	}
	function findCirrComplex(sci) {
		if (!sci || !data || !data.cirrhilabrus || !data.cirrhilabrus.complexes) return null;
		const target = normalizeCirrSci(sci);
		if (!target) return null;
		const complexes = data.cirrhilabrus.complexes;
		for (const key of Object.keys(complexes)) {
			const sp = complexes[key].species || [];
			for (const s of sp) {
				if (normalizeCirrSci(s.sci) === target) {
					return { key, complex: complexes[key], species: s };
				}
			}
		}
		return null;
	}
	function cirrhilabrusCheck(fishA, fishB, volume) {
		const cA = findCirrComplex(fishA.sci);
		const cB = findCirrComplex(fishB.sci);
		if (!cA || !cB) return null; // can't resolve — fall back to category-level matrix

		// Same species → not recommended (per spec test 6: 2 Lubbocki → N).
		// Most Cirrhilabrus go terminal/territorial as adults; a bonded pair is
		// the rare exception, not the default.
		if (fishA.sci && fishB.sci &&
			normalizeCirrSci(fishA.sci) === normalizeCirrSci(fishB.sci)) {
			return { v: 'N', note: 'Same species — single specimen only' };
		}

		// Same complex → watch (data file has no sub-group field; W is the spec's ceiling)
		if (cA.key === cB.key) {
			return { v: 'W', note: 'Same Cirrhilabrus complex (' + cA.key + ') — watch behavior' };
		}

		const aggressive = ['mid-high', 'high', 'highest'];
		const aAgg = aggressive.indexOf(cA.complex.aggression) !== -1;
		const bAgg = aggressive.indexOf(cB.complex.aggression) !== -1;

		if (aAgg && bAgg) {
			if (parseInt(volume, 10) >= 180) {
				return { v: 'W', note: 'Both aggressive Cirrhilabrus complexes — workable in 180g+' };
			}
			return { v: 'O', note: 'Both aggressive complexes — add the more aggressive last' };
		}
		if (aAgg || bAgg) {
			return { v: 'O', note: 'Aggressive Cirrhilabrus — add it last' };
		}
		return { v: 'C', note: 'Different complex, both peaceful' };
	}

	/* ──────────────────────────────────────────
	 * Volume modifier (matches PHP twin)
	 * ────────────────────────────────────────── */
	function applyVolumeModifier(verdict, catA, catB, volume) {
		const v = parseInt(volume, 10);
		if (!v || v <= 0) return verdict;
		let result = verdict;

		// Rule 2 first — softens 'N' to 'W' for cross-genus tang/angel in 250g+.
		if (v >= 250 && result === 'N' && catA !== catB &&
			TANGS_OR_ANGELS.has(catA) && TANGS_OR_ANGELS.has(catB)) {
			result = 'W';
		}

		// Rule 1 — < 75g, 'W' between aggressive families becomes 'N'.
		// Apply BEFORE Rule 4 so we don't lose a 'W' to one-tier-tighten first.
		if (v < 75 && result === 'W' &&
			AGGRESSIVE_FAMILIES.has(catA) && AGGRESSIVE_FAMILIES.has(catB)) {
			result = 'N';
		}

		// Rule 4 — < 50g, tighten one tier for any non-peaceful pair.
		if (v < 50) {
			const peaceful = PEACEFUL.has(catA) && PEACEFUL.has(catB);
			if (!peaceful && TIGHTEN_ONE[result]) result = TIGHTEN_ONE[result];
		}

		return result;
	}

	/* ──────────────────────────────────────────
	 * Conflict detection
	 * ────────────────────────────────────────── */
	function recalculateConflicts() {
		const all = state.myTank.concat(state.considering);
		const out = [];
		for (let i = 0; i < all.length; i++) {
			for (let j = i + 1; j < all.length; j++) {
				const a = all[i];
				const b = all[j];
				const cell = matrixCell(a.category, b.category);
				let v = cell.v || 'C';
				let note = cell.note || '';

				// Cirrhilabrus pairs: the genus-level matrix cell is too coarse —
				// the phylogram-derived inner check is the source of truth.
				// Override (don't merge) when the inner check returns a verdict.
				if (isCirrhilabrus(a) && isCirrhilabrus(b)) {
					const inner = cirrhilabrusCheck(a, b, state.volume);
					if (inner) {
						v = inner.v;
						note = inner.note;
					}
				}

				v = applyVolumeModifier(v, a.category, b.category, state.volume);

				if (v !== 'C') {
					out.push({ a, b, v, note });
				}
			}
		}
		out.sort((x, y) => VERDICT_RANK[y.v] - VERDICT_RANK[x.v]);
		conflicts = out;
		return out;
	}
	function worstVerdictForFish(fishId) {
		let worst = 'C';
		for (const c of conflicts) {
			if (c.a.id === fishId || c.b.id === fishId) worst = worstOf(worst, c.v);
		}
		return worst;
	}
	function displayName(fish) {
		if (fish.common) return fish.common;
		const cat = categoryByKey(fish.category);
		return cat ? cat.name : fish.category;
	}

	/* ──────────────────────────────────────────
	 * Render — zones + fish cards
	 * ────────────────────────────────────────── */
	function renderZones() {
		['my_tank', 'considering'].forEach(function (zone) {
			const list  = $('[data-fh-list="' + zone + '"]');
			const empty = $('[data-fh-empty="' + zone + '"]');
			const count = $('[data-fh-count="' + zone + '"]');
			if (!list || !empty || !count) return;
			const arr = (zone === 'my_tank') ? state.myTank : state.considering;

			count.textContent = arr.length === 1 ? '1 fish' : arr.length + ' fish';

			list.innerHTML = '';
			if (!arr.length) { empty.hidden = false; return; }
			empty.hidden = true;
			for (const f of arr) list.appendChild(buildFishCard(f, zone));
		});
	}
	function buildFishCard(fish, zone) {
		const cat = categoryByKey(fish.category);
		const verdict = worstVerdictForFish(fish.id);
		const card = document.createElement('article');
		card.className = 'fh-fish-card';
		card.dataset.fishId  = fish.id;
		card.dataset.category = fish.category;
		card.dataset.verdict  = verdict;
		card.dataset.zone     = zone;
		const removeLabel = 'Remove ' + (fish.common || (cat && cat.name) || '');
		card.innerHTML =
			'<span class="fh-fish-card__dot fh-fish-card__dot--' + verdict.toLowerCase() + '" aria-label="' + escapeHtml(VERDICT_LABEL[verdict] || verdict) + '"></span>' +
			'<div class="fh-fish-card__body">' +
				'<h3 class="fh-fish-card__name">' + escapeHtml(fish.common || (cat ? cat.name : fish.category)) + '</h3>' +
				(fish.sci ? '<p class="fh-fish-card__sci">' + escapeHtml(fish.sci) + '</p>' : '') +
				'<div class="fh-fish-card__meta">' +
					(cat ? '<span class="fh-fish-card__cat">' + escapeHtml(cat.name) + '</span>' : '') +
					(fish.min_tank ? '<span class="fh-fish-card__size">' + parseInt(fish.min_tank, 10) + 'g+</span>' : '') +
				'</div>' +
			'</div>' +
			'<button type="button" class="fh-fish-card__remove" aria-label="' + escapeHtml(removeLabel) + '" data-fh-remove="' + escapeHtml(fish.id) + '">×</button>';
		return card;
	}

	/* ──────────────────────────────────────────
	 * Render — conflicts panel
	 * ────────────────────────────────────────── */
	function renderConflicts() {
		const list  = $('[data-fh-conflicts-list]');
		const empty = $('[data-fh-conflicts-empty]');
		const count = $('[data-fh-conflicts-count]');
		if (!list || !empty || !count) return;
		count.textContent = '(' + conflicts.length + ')';
		list.innerHTML = '';
		if (!conflicts.length) {
			list.hidden = true; empty.hidden = false; return;
		}
		empty.hidden = true; list.hidden = false;
		for (const c of conflicts) {
			const li = document.createElement('li');
			li.className = 'fh-conflict fh-conflict--' + c.v.toLowerCase();
			li.innerHTML =
				'<span class="fh-conflict__icon fh-conflict__icon--' + c.v.toLowerCase() + '" aria-hidden="true"></span>' +
				'<div>' +
					'<p class="fh-conflict__pair">' +
						escapeHtml(displayName(c.a)) + ' <span aria-hidden="true">×</span> ' + escapeHtml(displayName(c.b)) +
						'<span class="fh-conflict__verdict">' + escapeHtml(VERDICT_LABEL[c.v] || c.v) + '</span>' +
					'</p>' +
					(c.note ? '<p class="fh-conflict__note">' + escapeHtml(c.note) + '</p>' : '') +
				'</div>';
			list.appendChild(li);
		}
	}

	/* ──────────────────────────────────────────
	 * Modal — categories / species / search
	 * ────────────────────────────────────────── */
	function renderCategoriesGrid() {
		const grid = $('[data-fh-categories]');
		if (!grid) return;
		grid.innerHTML = '';
		for (const cat of data.categories) {
			const sp = speciesInCategory(cat.key);
			const btn = document.createElement('button');
			btn.type = 'button';
			btn.className = 'fh-compat-cat';
			btn.dataset.fhCatKey = cat.key;
			btn.innerHTML =
				'<div class="fh-compat-cat__name">' + escapeHtml(cat.name) + '</div>' +
				'<div class="fh-compat-cat__sci">' + escapeHtml(cat.scientific || '') + '</div>' +
				'<div class="fh-compat-cat__count">' + (sp.length ? sp.length + ' species' : 'Generic match') + '</div>';
			grid.appendChild(btn);
		}
	}
	function showSpeciesList(catKey) {
		const cat = categoryByKey(catKey);
		if (!cat) return;
		const sp = speciesInCategory(catKey);
		$('[data-fh-categories]').hidden = true;
		const wrap = $('[data-fh-species]');
		wrap.hidden = false;
		$('[data-fh-species-title]').textContent = cat.name;
		$('[data-fh-species-desc]').textContent  = cat.description || '';
		const list = $('[data-fh-species-list]');
		list.innerHTML = '';

		// Generic catch-all
		const generic = document.createElement('button');
		generic.type = 'button';
		generic.className = 'fh-compat-species__row';
		generic.dataset.fhPick = JSON.stringify({ category: cat.key, common: cat.name + ' (any species)' });
		generic.innerHTML =
			'<span class="fh-compat-species__row-common">Any species in this category</span>' +
			'<span class="fh-compat-species__row-sci">' + escapeHtml(cat.scientific || '') + '</span>';
		list.appendChild(generic);

		for (const s of sp) {
			const row = document.createElement('button');
			row.type = 'button';
			row.className = 'fh-compat-species__row';
			row.dataset.fhPick = JSON.stringify(s);
			row.innerHTML =
				'<span class="fh-compat-species__row-common">' + escapeHtml(s.common || s.sci) + '</span>' +
				'<span class="fh-compat-species__row-sci">' + escapeHtml(s.sci || '') +
					(s.min_tank ? ' · ' + s.min_tank + 'g+' : '') + '</span>';
			list.appendChild(row);
		}

		// Modal body doesn't grow to fit the species list — scroll it into
		// view so users see the result of their click. Focus the title for
		// screen-reader / keyboard users (preventScroll so we don't fight
		// the smooth scroll).
		const modalBody = $('.fh-compat-modal__body');
		if (modalBody) {
			const offset = wrap.offsetTop - modalBody.offsetTop;
			modalBody.scrollTo({ top: offset, behavior: 'smooth' });
		}
		const title = $('[data-fh-species-title]');
		if (title) {
			title.setAttribute('tabindex', '-1');
			try { title.focus({ preventScroll: true }); } catch (e) { title.focus(); }
		}
	}
	function backToCategories() {
		$('[data-fh-categories]').hidden = false;
		$('[data-fh-species]').hidden = true;
		clearPick();
		const modalBody = $('.fh-compat-modal__body');
		if (modalBody) modalBody.scrollTo({ top: 0, behavior: 'smooth' });
	}
	function runSearch(query) {
		const results = $('[data-fh-search-results]');
		if (!results) return;
		results.innerHTML = '';
		const q = String(query || '').trim().toLowerCase();
		if (q.length < 2) return;
		const matches = data.species.filter(function (s) {
			return (s.common && s.common.toLowerCase().includes(q)) ||
				   (s.sci    && s.sci.toLowerCase().includes(q));
		}).slice(0, 30);
		if (!matches.length) {
			results.innerHTML = '<li class="fh-compat-species__row" style="cursor:default;">No matches.</li>';
			return;
		}
		for (const s of matches) {
			const row = document.createElement('button');
			row.type = 'button';
			row.className = 'fh-compat-species__row';
			row.dataset.fhPick = JSON.stringify(s);
			row.innerHTML =
				'<span class="fh-compat-species__row-common">' + escapeHtml(s.common || s.sci) + '</span>' +
				'<span class="fh-compat-species__row-sci">' + escapeHtml(s.sci || '') + '</span>';
			results.appendChild(row);
		}
	}
	function clearPick() {
		pendingPick = null;
		$$('[data-fh-pick]').forEach((el) => el.classList.remove('is-selected'));
		const addBtn = $('[data-fh-modal-add]');
		if (addBtn) addBtn.disabled = true;
	}
	function setPick(picked, sourceEl) {
		pendingPick = picked;
		$$('[data-fh-pick]').forEach((el) => el.classList.remove('is-selected'));
		if (sourceEl) sourceEl.classList.add('is-selected');
		const addBtn = $('[data-fh-modal-add]');
		if (addBtn) addBtn.disabled = false;
	}
	function openModal(zone) {
		const modal = $('#fh-compat-modal');
		if (!modal) return;
		modal.classList.add('is-open');
		modal.setAttribute('aria-hidden', 'false');
		if (zone) {
			const radio = $('input[name="fh-compat-zone"][value="' + zone + '"]');
			if (radio) radio.checked = true;
		}
		switchTab('browse');
		backToCategories();
		const search = $('#fh-compat-search-input');
		if (search) search.value = '';
		const results = $('[data-fh-search-results]');
		if (results) results.innerHTML = '';
	}
	function closeModal() {
		const modal = $('#fh-compat-modal');
		if (!modal) return;
		modal.classList.remove('is-open');
		modal.setAttribute('aria-hidden', 'true');
		clearPick();
	}
	function switchTab(name) {
		$$('.fh-compat-modal__tab').forEach(function (t) {
			const on = t.dataset.fhTab === name;
			t.classList.toggle('is-active', on);
			t.setAttribute('aria-selected', on ? 'true' : 'false');
		});
		$$('.fh-compat-modal__pane').forEach(function (p) {
			const on = p.dataset.fhPane === name;
			p.classList.toggle('is-active', on);
			p.hidden = !on;
		});
	}

	/* ──────────────────────────────────────────
	 * Sample tanks
	 * ────────────────────────────────────────── */
	function renderSampleTanks() {
		const wrap = $('[data-fh-samples-list]');
		if (!wrap) return;
		wrap.innerHTML = '';
		for (const tank of (data.sampleTanks || [])) {
			const card = document.createElement('article');
			card.className = 'fh-sample-card';
			const lines = (tank.fish || []).map(function (entry) {
				const cat = categoryByKey(entry.category);
				const name = entry.common || (cat ? cat.name : entry.category);
				const c = entry.count > 1 ? ' ×' + entry.count : '';
				return '<li>' + escapeHtml(name) + escapeHtml(c) + '</li>';
			}).join('');
			card.innerHTML =
				'<h3 class="fh-sample-card__name">' + escapeHtml(tank.name) + '</h3>' +
				(tank.subtitle ? '<p class="fh-sample-card__sub">' + escapeHtml(tank.subtitle) + '</p>' : '') +
				'<p class="fh-sample-card__meta">' + (tank.volume ? escapeHtml(tank.volume + ' gal') : '') + '</p>' +
				'<ul class="fh-sample-card__list">' + lines + '</ul>' +
				(tank.notes ? '<p class="fh-sample-card__notes">' + escapeHtml(tank.notes) + '</p>' : '') +
				'<button type="button" class="fh-sample-card__btn" data-fh-sample-load="' + escapeHtml(tank.key) + '">Try This Plan</button>';
			wrap.appendChild(card);
		}
	}
	function loadSampleTank(key) {
		const tank = (data.sampleTanks || []).find((t) => t.key === key);
		if (!tank) return;
		if (state.myTank.length && !window.confirm('Replace your current "My Tank" with the "' + tank.name + '" preset?')) return;

		state.volume = String(tank.volume || '');
		const volInput = $('#fh-compat-volume');
		if (volInput) volInput.value = state.volume;

		state.myTank = [];
		for (const entry of (tank.fish || [])) {
			const count = entry.count || 1;
			const cat = categoryByKey(entry.category);
			for (let i = 0; i < count; i++) {
				state.myTank.push({
					id: uid(),
					common:   entry.common || (cat ? cat.name : entry.category),
					sci:      entry.sci || '',
					category: entry.category,
					min_tank: entry.min_tank || 0
				});
			}
		}
		saveState();
		renderAll();
	}

	/* ──────────────────────────────────────────
	 * Inventory panel — live "What You Can Add Right Now"
	 * Fetches /wp-json/fishotel/v1/compat-products filtered to category
	 * keys that are 'C' against EVERY fish currently in state.myTank
	 * (volume modifier applied). Debounced + signature-cached.
	 * ────────────────────────────────────────── */
	const InventoryPanel = (function () {
		let lastSig = '';
		let scheduleTimer = null;
		let inflight = null;

		function compatibleCategories() {
			if (!data || !data.categories || !state.myTank.length) return [];
			const out = [];
			for (const cat of data.categories) {
				let allOk = true;
				for (const fish of state.myTank) {
					const cell = matrixCell(cat.key, fish.category);
					let v = cell.v || 'C';
					v = applyVolumeModifier(v, cat.key, fish.category, state.volume);
					if (v !== 'C') { allOk = false; break; }
				}
				if (allOk) out.push(cat.key);
			}
			return out;
		}

		function renderProducts(products, grid) {
			grid.innerHTML = '';
			if (!products.length) {
				const msg = document.createElement('p');
				msg.className = 'fh-compat-inventory__msg';
				msg.textContent = 'No compatible fish in stock right now.';
				grid.appendChild(msg);
				return;
			}
			for (const p of products) {
				const card = document.createElement('article');
				card.className = 'fh-inventory-card';
				card.dataset.productId = p.id;

				if (p.image_url) {
					const a = document.createElement('a');
					a.className = 'fh-inventory-card__thumb';
					a.href = p.permalink;
					a.target = '_blank';
					a.rel = 'noopener';
					const img = document.createElement('img');
					img.src = p.image_url;
					img.alt = p.name;
					img.loading = 'lazy';
					a.appendChild(img);
					card.appendChild(a);
				}

				const body = document.createElement('div');
				body.className = 'fh-inventory-card__body';
				if (p.category_label) {
					const cat = document.createElement('span');
					cat.className = 'fh-inventory-card__cat';
					cat.textContent = p.category_label;
					body.appendChild(cat);
				}
				const name = document.createElement('h3');
				name.className = 'fh-inventory-card__name';
				const nameLink = document.createElement('a');
				nameLink.href = p.permalink;
				nameLink.target = '_blank';
				nameLink.rel = 'noopener';
				nameLink.textContent = p.name;
				name.appendChild(nameLink);
				body.appendChild(name);

				if (p.price_html) {
					const price = document.createElement('div');
					price.className = 'fh-inventory-card__price';
					// price_html is server-rendered WC output — trusted.
					price.innerHTML = p.price_html;
					body.appendChild(price);
				}

				const actions = document.createElement('div');
				actions.className = 'fh-inventory-card__actions';

				const addBtn = document.createElement('button');
				addBtn.type = 'button';
				addBtn.className = 'fh-inventory-card__add';
				addBtn.textContent = 'Add to Considering';
				addBtn.dataset.fhInvAdd = JSON.stringify({
					common:   p.name,
					sci:      '',
					category: p.category_key,
					min_tank: 0,
					productId: p.id
				});

				const view = document.createElement('a');
				view.className = 'fh-inventory-card__view';
				view.href = p.permalink;
				view.target = '_blank';
				view.rel = 'noopener';
				view.textContent = 'View Product';

				actions.appendChild(addBtn);
				actions.appendChild(view);
				body.appendChild(actions);

				card.appendChild(body);
				grid.appendChild(card);
			}
		}

		async function update() {
			const grid  = $('[data-fh-inventory-grid]');
			const empty = $('[data-fh-inventory-empty]');
			if (!grid || !empty) return;

			if (!state.myTank.length) {
				grid.innerHTML = '';
				empty.hidden = false;
				lastSig = '';
				return;
			}
			empty.hidden = true;

			const cats = compatibleCategories();
			const sig  = cats.slice().sort().join('|') + '@' + (state.volume || '');
			if (sig === lastSig) return; // identical state, skip refetch
			lastSig = sig;

			if (!cats.length) {
				const msg = document.createElement('p');
				msg.className = 'fh-compat-inventory__msg';
				msg.textContent = 'No fully-compatible categories with your current tank.';
				grid.innerHTML = '';
				grid.appendChild(msg);
				return;
			}

			const url = (CFG.urls && CFG.urls.inventory) || '/wp-json/fishotel/v1/compat-products';
			const params = new URLSearchParams();
			for (const k of cats) params.append('categories[]', k);
			params.set('limit', '20');
			const fullUrl = url + (url.indexOf('?') === -1 ? '?' : '&') + params.toString();

			// Loading affordance
			grid.classList.add('is-loading');

			try {
				const res = await fetch(fullUrl, { credentials: 'same-origin' });
				if (!res.ok) throw new Error('HTTP ' + res.status);
				const products = await res.json();
				if (sig !== lastSig) return; // state changed since fetch fired — drop stale result
				renderProducts(products, grid);
			} catch (err) {
				if (window.console) console.error('Inventory fetch failed', err);
				const msg = document.createElement('p');
				msg.className = 'fh-compat-inventory__msg';
				msg.textContent = 'Couldn\'t load inventory right now.';
				grid.innerHTML = '';
				grid.appendChild(msg);
			} finally {
				grid.classList.remove('is-loading');
			}
		}

		function schedule() {
			clearTimeout(scheduleTimer);
			scheduleTimer = setTimeout(update, 300);
		}

		function addProductToConsidering(payload, sourceBtn) {
			state.considering.push({
				id: uid(),
				common:   payload.common || '',
				sci:      payload.sci    || '',
				category: payload.category,
				min_tank: payload.min_tank || 0
			});
			saveState();
			renderAll();
			if (sourceBtn) {
				sourceBtn.disabled = true;
				sourceBtn.textContent = 'Added';
			}
		}

		return { schedule, update, addProductToConsidering };
	})();

	/* ──────────────────────────────────────────
	 * Full matrix view (lazy)
	 * ────────────────────────────────────────── */
	function renderMatrix() {
		if (matrixRendered) return;
		const body = $('[data-fh-matrix-body]');
		if (!body) return;
		const cats = data.categories;
		const grid = document.createElement('div');
		grid.className = 'fh-compat-matrix__grid';
		grid.style.gridTemplateColumns = 'minmax(140px, max-content) repeat(' + cats.length + ', 18px)';

		// Header row
		grid.appendChild(makeHeadCell('', ''));
		for (const c of cats) grid.appendChild(makeHeadCell(c.name, 'col-head'));

		for (const rowCat of cats) {
			grid.appendChild(makeHeadCell(rowCat.name, 'row-head'));
			for (const colCat of cats) {
				const cell = matrixCell(rowCat.key, colCat.key);
				const v = (cell.v || 'C').toLowerCase();
				const c = document.createElement('div');
				c.className = 'fh-compat-matrix__cell fh-compat-matrix__cell--' + v;
				c.title = rowCat.name + ' × ' + colCat.name + ' — ' + (VERDICT_LABEL[cell.v] || cell.v) + (cell.note ? ': ' + cell.note : '');
				grid.appendChild(c);
			}
		}
		body.innerHTML = '';
		body.appendChild(grid);
		matrixRendered = true;
	}
	function makeHeadCell(label, modifier) {
		const c = document.createElement('div');
		c.className = 'fh-compat-matrix__cell fh-compat-matrix__cell--head' + (modifier ? ' fh-compat-matrix__cell--' + modifier : '');
		c.textContent = label;
		return c;
	}

	/* ──────────────────────────────────────────
	 * Master render + add/remove
	 * ────────────────────────────────────────── */
	function renderAll() {
		recalculateConflicts();
		renderZones();
		renderConflicts();
		InventoryPanel.schedule();
	}
	function addPendingFish() {
		if (!pendingPick) return;
		const radio = $('input[name="fh-compat-zone"]:checked');
		const zone = radio ? radio.value : 'my_tank';
		const cat = categoryByKey(pendingPick.category);
		const fish = {
			id: uid(),
			common:   pendingPick.common || (cat ? cat.name : pendingPick.category),
			sci:      pendingPick.sci || '',
			category: pendingPick.category,
			min_tank: pendingPick.min_tank || 0
		};
		(zone === 'considering' ? state.considering : state.myTank).push(fish);
		saveState();
		renderAll();
		closeModal();
	}
	function removeFish(id) {
		state.myTank      = state.myTank.filter((f) => f.id !== id);
		state.considering = state.considering.filter((f) => f.id !== id);
		saveState();
		renderAll();
	}

	/* ──────────────────────────────────────────
	 * Event wiring
	 * ────────────────────────────────────────── */
	function attach() {
		const vol = $('#fh-compat-volume');
		if (vol) {
			if (state.volume) vol.value = state.volume;
			vol.addEventListener('input', function () {
				state.volume = vol.value || '';
				saveState();
				renderAll();
			});
		}

		$$('[data-fh-open-modal]').forEach(function (btn) {
			btn.addEventListener('click', function () { openModal(btn.dataset.fhOpenModal); });
		});

		document.addEventListener('click', function (e) {
			if (e.target.closest('[data-fh-modal-close]')) { closeModal(); return; }

			const cat = e.target.closest('[data-fh-cat-key]') || e.target.closest('.fh-compat-cat');
			if (cat && cat.dataset.fhCatKey) { showSpeciesList(cat.dataset.fhCatKey); return; }

			if (e.target.closest('[data-fh-species-back]')) { backToCategories(); return; }

			const tab = e.target.closest('[data-fh-tab]');
			if (tab) { switchTab(tab.dataset.fhTab); return; }

			const pick = e.target.closest('[data-fh-pick]');
			if (pick) {
				try { setPick(JSON.parse(pick.dataset.fhPick), pick); } catch (err) { /* malformed */ }
				return;
			}

			const remove = e.target.closest('[data-fh-remove]');
			if (remove) { removeFish(remove.dataset.fhRemove); return; }

			const sample = e.target.closest('[data-fh-sample-load]');
			if (sample) { loadSampleTank(sample.dataset.fhSampleLoad); return; }

			const invAdd = e.target.closest('[data-fh-inv-add]');
			if (invAdd) {
				try {
					InventoryPanel.addProductToConsidering(JSON.parse(invAdd.dataset.fhInvAdd), invAdd);
				} catch (err) { /* malformed payload */ }
				return;
			}

			if (e.target.closest('[data-fh-modal-add]')) { addPendingFish(); return; }

			const conflictToggle = e.target.closest('[data-fh-conflicts-toggle]');
			if (conflictToggle) {
				const panel = $('[data-fh-conflicts-panel]');
				const collapsed = panel.classList.toggle('is-collapsed');
				conflictToggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
				const txt = $('.fh-compat-conflicts__toggle-text', conflictToggle);
				if (txt) txt.textContent = collapsed ? 'Show' : 'Hide';
				return;
			}

			const matrixToggle = e.target.closest('[data-fh-matrix-toggle]');
			if (matrixToggle) {
				const body = $('[data-fh-matrix-body]');
				const expanded = matrixToggle.getAttribute('aria-expanded') === 'true';
				const titleEl = $('#fh-compat-matrix-title');
				if (expanded) {
					body.hidden = true;
					matrixToggle.setAttribute('aria-expanded', 'false');
					if (titleEl) titleEl.textContent = 'Show Full Compatibility Matrix';
				} else {
					renderMatrix();
					body.hidden = false;
					matrixToggle.setAttribute('aria-expanded', 'true');
					if (titleEl) titleEl.textContent = 'Hide Full Compatibility Matrix';
				}
				return;
			}
		});

		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape') closeModal();
		});

		const search = $('#fh-compat-search-input');
		if (search) {
			let t = null;
			search.addEventListener('input', function () {
				clearTimeout(t);
				t = setTimeout(function () { runSearch(search.value); }, 120);
			});
		}
	}

	/* ──────────────────────────────────────────
	 * Init
	 * ────────────────────────────────────────── */
	async function init() {
		if (!URLS.categories) return; // not on the guide page
		loadState();
		try {
			data = await loadData();
		} catch (err) {
			if (window.console) console.error('FisHotel compatibility data load failed', err);
			return;
		}
		renderCategoriesGrid();
		renderSampleTanks();
		renderAll();
		attach();
		window.FishotelTank = {
			state,
			data,
			recalc: recalculateConflicts,
			getConflicts: () => conflicts.slice()
		};
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
