/**
 * FisHotel Medication Dosing Calculator
 *
 * Renders the selected-medication panel based on window.FISHOTEL_MEDS
 * (seeded from wp_options via wp_localize_script).
 *
 * Mockup math + UX locked in over 7 Prazi iterations + final Copper. Don't
 * change formulas without re-verifying against Humblefish reference doses.
 *
 * @package fishotel-theme
 */
(function () {
  'use strict';

  // ============================================================================
  // FORMULAS — copied verbatim from validated mockup iterations
  // ============================================================================

  var COPPER_RAMP_DAYS = { aggressive: 3, standard: 5, sensitive: 7 };

  function pickPipetteScale(ml) {
    var scales = [25, 50, 75, 150, 300, 500, 1000, 2000];
    for (var i = 0; i < scales.length; i++) {
      if (ml <= scales[i]) return scales[i];
    }
    return 5000;
  }

  function formatDoseMg(mg) {
    if (mg >= 1000) return { value: (mg / 1000).toFixed(2), unit: 'g' };
    if (mg >= 100)  return { value: Math.round(mg).toString(), unit: 'mg' };
    return { value: mg.toFixed(1), unit: 'mg' };
  }

  function pickDoseForSensitivity(low, high, sensitivity) {
    if (sensitivity === 'sensitive')  return low;
    if (sensitivity === 'aggressive') return high;
    return (low + high) / 2; // standard = midpoint
  }

  function brandMeasuresNeeded(tankGal, pureMgPerGal, activePct, measureMg) {
    if (!activePct || !measureMg) return null;
    var brandMgPerGal = pureMgPerGal / (activePct / 100);
    return (tankGal * brandMgPerGal) / measureMg;
  }

  /**
   * Praziquantel lifecycle math — locked in from mockup v7.
   * Enforces no-back-to-back via d2 = d3 - 2 (pull d2 earlier, never push d3 later).
   * Computes Dose 3 at lastSafeOffset = floor(infective - 0.5) so Dose 3 stays
   * clear of the Egg-laying boundary at all temperatures.
   */
  function praziLifecycle(tempF, salinitySG, gillOn, bodyOn, protocol) {
    if (!gillOn && !bodyOn) {
      return { error: 'Select at least one fluke type (Gill or Body/Eye).' };
    }
    var salPpt    = (salinitySG - 1) / 0.00075;
    var baseDays  = Math.max(4, Math.min(12, 12 - 0.15 * (tempF - 60)));
    var salMult   = Math.max(0.9, Math.min(1.2, 1 + (35 - salPpt) * 0.01));
    var speedMult = (gillOn && bodyOn) ? 1.0
                  : (gillOn)           ? 0.75
                                       : 1.15;
    var infective = baseDays * salMult * speedMult;
    var sexMat    = infective * 0.6;
    var matureStartOffset = Math.ceil(sexMat);
    var lastSafeOffset    = Math.floor(infective - 0.5);
    if (lastSafeOffset <= matureStartOffset) lastSafeOffset = matureStartOffset + 1;

    var doses;
    var narrowWindowWarning = false;
    if (protocol === 'standard') {
      doses = [1, lastSafeOffset + 1];
    } else {
      var d2 = matureStartOffset + 1;
      var d3 = lastSafeOffset + 1;
      if (d3 - d2 < 2) {
        d2 = Math.max(2, d3 - 2);
        narrowWindowWarning = true;
      }
      doses = [1, d2, d3];
    }

    return {
      doses: doses,
      sexMat: sexMat,
      infective: infective,
      matureStartOffset: matureStartOffset,
      lastSafeOffset: lastSafeOffset,
      narrowWindowWarning: narrowWindowWarning
    };
  }

  // ============================================================================
  // STATE
  // ============================================================================

  var state = {
    tankGal: 30,
    lastProlongedGal: 30,
    lastBathGal: 3,
    category: 'antibiotic',
    medId: null,
    sensitivity: 'standard',
    // Copper inputs
    currentPpm: 0.0,
    targetPpm: 2.5,
    // Prazi inputs
    tempF: 77,
    salinitySG: 1.026,
    gillOn: true,
    bodyOn: true,
    protocol: 'standard',
    // Bath inputs
    bathMode: 'prolonged',  // 'bath' | 'prolonged' for treatment_type === 'both'
    bathTier: null,          // tier_id, null = use default
    // Timer (never persisted; resets on nav/med-switch)
    timer: null,             // { totalSec, remainingSec, running, paused, completed, intervalId }
    // Computed schedule (for print + ics)
    lastComputed: null
  };

  // ============================================================================
  // DOM ELEMENTS
  // ============================================================================

  var $panel, $tankSlider, $tankGalOut, $tabs, $grid, $medButtons, $printBtn, $icsBtn;

  function el(tag, className, text) {
    var e = document.createElement(tag);
    if (className) e.className = className;
    if (text !== undefined && text !== null) e.textContent = text;
    return e;
  }

  function getMed(id) {
    var meds = (window.FISHOTEL_MEDS && window.FISHOTEL_MEDS.medications) || [];
    for (var i = 0; i < meds.length; i++) {
      if (meds[i].med_id === id) return meds[i];
    }
    return null;
  }

  // ============================================================================
  // RENDERERS — one per scheduleType
  // ============================================================================

  /**
   * Flat-daily antibiotic: neomycin, erythromycin, kanamycin, etc.
   */
  function renderFlatDaily(med) {
    var brands = med.brand_equivalents || [];
    var doseLow  = +med.dose_pure_low_mg_per_gal  || 0;
    var doseHigh = +med.dose_pure_high_mg_per_gal || doseLow;
    var hasRange = doseHigh > doseLow;

    var chosenMgPerGal = hasRange
      ? pickDoseForSensitivity(doseLow, doseHigh, state.sensitivity)
      : doseLow;

    var totalMg = state.tankGal * chosenMgPerGal;
    var formatted = formatDoseMg(totalMg);

    var freqHrs = +med.frequency_hours || 24;
    var durDays = +med.duration_days  || 10;
    var wcPct   = (med.water_change_pct_before_dose == null) ? 25 : +med.water_change_pct_before_dose;

    var doses = [];
    for (var d = 1; d <= durDays; d++) doses.push(d);

    state.lastComputed = {
      medName: med.name_generic,
      doseLabel: formatted.value + ' ' + formatted.unit + ' per dose',
      doseDays: doses,
      waterChangeDays: doses,
      totalDays: durDays
    };

    var root = el('div', 'fh-qh-card');
    root.appendChild(renderHeader(med, 'Antibiotic · Humblefish reference'));

    if (hasRange) root.appendChild(renderSensitivityToggle());

    // Dose readout
    var dose = el('div', 'fh-qh-doseblock');
    var scoops = el('div', 'fh-qh-scoops');
    var firstBrand = brands[0];
    if (firstBrand && firstBrand.measure_weight_mg && firstBrand.active_ingredient_pct) {
      var measures = brandMeasuresNeeded(state.tankGal, chosenMgPerGal, firstBrand.active_ingredient_pct, firstBrand.measure_weight_mg);
      var display = Math.min(30, Math.ceil(measures));
      for (var i = 0; i < display; i++) {
        scoops.appendChild(el('span', 'fh-qh-scoop'));
      }
      if (measures > 30) {
        scoops.appendChild(el('span', 'fh-qh-scoop-more', '+' + Math.ceil(measures - 30) + ' more'));
      }
    }
    dose.appendChild(scoops);

    var info = el('div', 'fh-qh-doseinfo');
    var num = el('div');
    num.innerHTML = '<span class="fh-qh-dosenum">' + formatted.value + '</span><span class="fh-qh-doseunit">' + formatted.unit + '</span>';
    info.appendChild(num);
    info.appendChild(el('div', 'fh-qh-dosesub', 'Generic · Per Dose'));
    if (hasRange) {
      info.appendChild(el('div', 'fh-qh-doseswing', chosenMgPerGal.toFixed(1) + ' mg/gal (range ' + doseLow + '–' + doseHigh + ')'));
    } else {
      info.appendChild(el('div', 'fh-qh-doseswing', chosenMgPerGal.toFixed(1) + ' mg/gal'));
    }
    dose.appendChild(info);
    root.appendChild(dose);

    // Brand equivalents
    root.appendChild(renderBrandGrid(brands, chosenMgPerGal));

    // Schedule cells
    root.appendChild(renderScheduleCells([
      { label: 'Frequency',     value: (freqHrs === 24 ? 'Daily' : 'Every ' + freqHrs + ' h'), sub: '' },
      { label: 'Duration',      value: durDays + ' days', sub: '' },
      { label: 'Water change',  value: wcPct + '%', sub: 'before each dose' }
    ]));

    // Timeline — flat daily
    root.appendChild(renderFlatDailyTimeline(durDays));

    // KanaPlex under-dosing warning
    if (med.med_id === 'kanamycin_sulfate') {
      root.appendChild(renderWarning(
        'Seachem KanaPlex label (1 measure per 5 gal) delivers only ~8 mg/gal kanamycin — well below Humblefish\u2019s 25–37.5 mg/gal therapeutic range. To match therapeutic, dose 6.25 measures per 10 gal or weigh pharma-grade kanamycin on a scale.'
      ));
    }

    // Generic note
    if (med.special_notes_generic) {
      root.appendChild(renderNote(med.special_notes_generic));
    }

    return root;
  }

  /**
   * Ramp-hold copper: Copper Power, Cupramine, Cuprion, Coppersafe, Cu sulfate pent.
   */
  function renderRampHold(med) {
    var brands = med.brand_equivalents || [];
    var isCopperPower = med.med_id === 'copper_power';

    // Empirical 1.73 ml/gal for Copper Power; fall back to label value for others
    var mlPerGalAtTarget = isCopperPower ? 1.73 : (med.ml_per_gal_at_target || 1.73);
    var targetDefault    = +med.target_default_ppm || 2.5;

    var ml = (state.targetPpm - state.currentPpm) * state.tankGal * mlPerGalAtTarget / targetDefault;
    if (ml < 0) ml = 0;

    var rampDays = COPPER_RAMP_DAYS[state.sensitivity];
    var holdDays = +med.duration_days || 14;
    var totalDays = rampDays + holdDays;
    var mlPerRampDay = ml / rampDays;

    var doses = [];
    for (var d = 1; d <= totalDays; d++) doses.push(d);

    state.lastComputed = {
      medName: med.name_generic,
      doseLabel: ml.toFixed(1) + ' ml total; ' + mlPerRampDay.toFixed(1) + ' ml/day during ramp',
      doseDays: doses,
      waterChangeDays: [totalDays], // water change at end
      totalDays: totalDays
    };

    var root = el('div', 'fh-qh-card');
    root.appendChild(renderHeader(med, 'Ionic · ' + state.targetPpm.toFixed(2) + ' ppm therapeutic'));

    // Ppm inputs
    var ppmRow = el('div', 'fh-qh-ppmrow');
    ppmRow.appendChild(ppmBox('Current ppm', 'fh-qh-cur', state.currentPpm.toFixed(2)));
    ppmRow.appendChild(ppmBox('Target ppm',  'fh-qh-tgt', state.targetPpm.toFixed(2)));
    root.appendChild(ppmRow);

    root.appendChild(renderSensitivityToggle([
      { key: 'aggressive', label: 'Aggressive', sub: '3-day ramp' },
      { key: 'standard',   label: 'Standard',   sub: '5-day ramp' },
      { key: 'sensitive',  label: 'Sensitive',  sub: '7-day ramp' }
    ], 'Fish Sensitivity'));

    // Pipette + readout
    var dose = el('div', 'fh-qh-doseblock fh-qh-doseblock-copper');
    dose.appendChild(renderPipette(ml));
    var info = el('div', 'fh-qh-doseinfo');
    info.innerHTML =
      '<div><span class="fh-qh-dosenum">' + ml.toFixed(1) + '</span><span class="fh-qh-doseunit">ml</span></div>' +
      '<div class="fh-qh-dosesub">Total to reach target</div>' +
      '<div class="fh-qh-doseswing">' + state.currentPpm.toFixed(2) + ' \u2192 ' + state.targetPpm.toFixed(2) + ' ppm</div>';
    dose.appendChild(info);
    root.appendChild(dose);

    root.appendChild(renderBrandGrid(brands, null));

    root.appendChild(renderScheduleCells([
      { label: 'Ramp',         value: rampDays + ' days',  sub: mlPerRampDay.toFixed(1) + ' ml/day' },
      { label: 'Therapeutic',  value: holdDays + ' days', sub: 'hold at ' + state.targetPpm.toFixed(2) + ' ppm' },
      { label: 'Total course', value: totalDays + ' days', sub: 'before observation' }
    ]));

    root.appendChild(renderRampHoldTimeline(state.targetPpm, rampDays, holdDays));

    if (isCopperPower) {
      root.appendChild(renderNote(
        'Uses FisHotel\u2019s Hanna-verified 1.73 ml/gal to 2.5 ppm (manufacturer label 1.48 ml/gal under-doses by ~15–20%). Verify with Hanna HI702 before every top-off.'
      ));
    } else if (med.special_notes_generic) {
      root.appendChild(renderNote(med.special_notes_generic));
    }

    return root;
  }

  /**
   * Praziquantel — two/three dose lifecycle with temp + salinity awareness.
   */
  function renderPrazi(med) {
    var brands = med.brand_equivalents || [];
    var doseMgPerGal = +med.dose_pure_low_mg_per_gal || 9.5;
    var totalMg      = state.tankGal * doseMgPerGal;
    var formatted    = formatDoseMg(totalMg);

    var life = praziLifecycle(state.tempF, state.salinitySG, state.gillOn, state.bodyOn, state.protocol);

    var root = el('div', 'fh-qh-card');
    root.appendChild(renderHeader(med, 'Anthelmintic · Humblefish ' + doseMgPerGal + ' mg/gal'));

    // Fluke type + protocol
    var ctrlsRow = el('div', 'fh-qh-prazi-ctrls');
    ctrlsRow.appendChild(flukeCheckbox('gill',  'Gill flukes (Dactylogyridae)',  state.gillOn));
    ctrlsRow.appendChild(flukeCheckbox('body',  'Body/eye flukes (Capsalidae)', state.bodyOn));
    root.appendChild(ctrlsRow);

    root.appendChild(renderSensitivityToggle([
      { key: 'standard',   label: '2-dose Standard',   sub: 'safer, fewer stressors' },
      { key: 'aggressive', label: '3-dose Aggressive', sub: 'for heavy loads' }
    ], 'Protocol', 'protocol'));

    // Temp + salinity sliders
    root.appendChild(renderPraziSliders());

    // Dose readout (single number — applies to every dose in the schedule)
    var dose = el('div', 'fh-qh-doseblock');
    var info = el('div', 'fh-qh-doseinfo');
    info.innerHTML =
      '<div><span class="fh-qh-dosenum">' + formatted.value + '</span><span class="fh-qh-doseunit">' + formatted.unit + '</span></div>' +
      '<div class="fh-qh-dosesub">Per dose · ' + doseMgPerGal + ' mg/gal</div>';
    dose.appendChild(info);
    root.appendChild(dose);

    if (life.error) {
      root.appendChild(renderWarning(life.error));
      return root;
    }

    state.lastComputed = {
      medName: med.name_generic,
      doseLabel: formatted.value + ' ' + formatted.unit + ' per dose',
      doseDays: life.doses,
      waterChangeDays: life.doses.map(function (d) { return d; }),
      totalDays: 14
    };

    root.appendChild(renderBrandGrid(brands, doseMgPerGal));

    root.appendChild(renderScheduleCells([
      { label: 'Dose 1', value: 'Day 1', sub: 'hit adults + juveniles' },
      { label: 'Dose 2', value: 'Day ' + life.doses[1], sub: ((life.doses[1] - 0.5) >= life.sexMat) ? 'enter Maturing' : 'catch juveniles' },
      life.doses[2]
        ? { label: 'Dose 3', value: 'Day ' + life.doses[2], sub: 'before egg-laying' }
        : { label: 'Duration', value: '14 days', sub: 'observation' }
    ]));

    root.appendChild(renderPraziTimeline(life, 14));

    if (life.narrowWindowWarning) {
      root.appendChild(renderWarning(
        'Maturing window is narrow at this temperature — Dose 2 has been pulled earlier to maintain a 1-day gap between doses. Standard 2-dose protocol is safer at this temperature.'
      ));
    }

    if (med.special_notes_generic) root.appendChild(renderNote(med.special_notes_generic));

    return root;
  }

  /**
   * Bath treatment renderer — v1.3 bath_protocol data.
   * Covers cipro, enro (std), fenbendazole ext, formalin, H2O2, MB, acriflavine, NFG.
   */
  function renderBathCard(med) {
    var bp = med.bath_protocol || {};
    var tiers = bp.tiers || [];
    var tier = getActiveBathTier(med);
    var root = el('div', 'fh-qh-card fh-qh-bathcard');

    root.appendChild(renderHeader(med, 'Bath · ' + (tier && tier.tier_label ? tier.tier_label : 'Standard')));

    // Mode toggle — only when treatment_type === 'both'
    if (med.treatment_type === 'both') {
      root.appendChild(renderBathModeToggle());
    }

    // Tier toggle — only when multiple tiers
    if (tiers.length > 1) {
      var opts = tiers.map(function (t) {
        return { key: t.tier_id, label: t.tier_label, sub: t.tier_note ? shortenTierNote(t.tier_note) : '' };
      });
      root.appendChild(renderSensitivityToggle(opts, 'Bath Tier', 'bathTier'));
    }

    if (!tier) {
      root.appendChild(renderWarning('No bath protocol available for this medication.'));
      return root;
    }

    // Formalin temperature contraindication (rendered in commit 5 — placeholder guard)
    var formalinBlocked = checkFormalinContraindication(med);
    if (formalinBlocked) {
      root.appendChild(formalinBlocked);
    }

    // Concentration readout
    if (!formalinBlocked) {
      root.appendChild(renderBathConcentration(med, tier));
    }

    // Brand equivalents
    root.appendChild(renderBrandGrid(med.brand_equivalents || [], null));

    // Duration readout
    root.appendChild(renderBathDurationBlock(tier));

    // Countdown timer (manual start, no persistence)
    root.appendChild(renderTimerWidget(med, tier));

    // Recovery instructions
    if (tier.recovery_instructions) {
      root.appendChild(renderBathSection('Recovery', tier.recovery_instructions));
    }

    // Abort criteria (inherits default unless explicitly overridden)
    root.appendChild(renderAbortCriteria(med, tier));

    // Aeration reminder
    root.appendChild(renderAerationNote(med, tier));

    // Scaleless sensitivity
    root.appendChild(renderScalelessSensitivity(med, tier));

    // Session details
    root.appendChild(renderBathSessionDetails(tier));

    // Record for .ics export — only eligible for multi-session series
    var calEnabled = !!bp.calendar_export_enabled;
    state.lastComputed = {
      medName: med.name_generic,
      mode: 'bath',
      bathCalendarEnabled: calEnabled,
      bathTier: tier,
      bathProtocol: bp,
      doseLabel: (bp.calendar_event_label || (med.name_generic + ' bath')),
      doseDays: [],
      waterChangeDays: [],
      totalDays: 1
    };

    // Special notes
    if (med.special_notes_generic) {
      root.appendChild(renderNote(med.special_notes_generic));
    }

    return root;
  }

  /**
   * Stub renderer for scheduleTypes we haven't built yet — shows basic dose info.
   */
  function renderGeneric(med) {
    var root = el('div', 'fh-qh-card');
    root.appendChild(renderHeader(med, (med.category || '').replace('_', ' ')));

    var brands = med.brand_equivalents || [];
    var doseLow = med.dose_pure_low_mg_per_gal;
    var unit = med.dose_unit_override || 'mg/gal';

    var info = el('div', 'fh-qh-doseblock');
    var right = el('div', 'fh-qh-doseinfo');
    if (doseLow != null) {
      var totalMg = state.tankGal * (+doseLow);
      var f = formatDoseMg(totalMg);
      right.innerHTML =
        '<div><span class="fh-qh-dosenum">' + f.value + '</span><span class="fh-qh-doseunit">' + f.unit + '</span></div>' +
        '<div class="fh-qh-dosesub">Generic · Per dose</div>' +
        '<div class="fh-qh-doseswing">' + doseLow + ' ' + unit + '</div>';
    } else if (med.dose_label_value) {
      right.innerHTML =
        '<div><span class="fh-qh-dosenum">' + med.dose_label_value + '</span></div>' +
        '<div class="fh-qh-dosesub">Per label</div>';
    } else {
      right.innerHTML = '<div class="fh-qh-dosesub">Protocol — see notes below</div>';
    }
    info.appendChild(right);
    root.appendChild(info);

    root.appendChild(renderBrandGrid(brands, doseLow));

    if (med.frequency_hours || med.duration_days) {
      root.appendChild(renderScheduleCells([
        { label: 'Frequency', value: med.frequency_hours ? (med.frequency_hours === 24 ? 'Daily' : 'Every ' + med.frequency_hours + ' h') : '—', sub: '' },
        { label: 'Duration',  value: med.duration_days ? med.duration_days + ' days' : '—', sub: '' },
        { label: 'Water change', value: (med.water_change_pct_before_dose != null) ? med.water_change_pct_before_dose + '%' : '—', sub: 'before each dose' }
      ]));
    }

    if (med.special_notes_generic) root.appendChild(renderNote(med.special_notes_generic));
    return root;
  }

  // ============================================================================
  // HELPERS — reusable bits (header, brands, schedule cells, warnings, notes)
  // ============================================================================

  function renderHeader(med, tag) {
    var h = el('div', 'fh-qh-phead');
    h.appendChild(el('div', 'fh-qh-pname', med.name_generic));
    h.appendChild(el('div', 'fh-qh-ptag',  tag));
    return h;
  }

  function renderSensitivityToggle(options, label, bindKey) {
    options = options || [
      { key: 'aggressive', label: 'Aggressive', sub: 'hardy fish' },
      { key: 'standard',   label: 'Standard',   sub: 'default' },
      { key: 'sensitive',  label: 'Sensitive',  sub: 'scaleless, anthias' }
    ];
    label   = label   || 'Fish Sensitivity';
    bindKey = bindKey || 'sensitivity';

    var wrap = el('div', 'fh-qh-sens');
    wrap.appendChild(el('div', 'fh-qh-senslabel', label));

    var tabs = el('div', 'fh-qh-senstabs');
    options.forEach(function (opt) {
      var t = el('button', 'fh-qh-senstab' + (state[bindKey] === opt.key ? ' is-on' : ''));
      t.type = 'button';
      t.setAttribute('data-bind', bindKey);
      t.setAttribute('data-val',  opt.key);
      t.innerHTML = opt.label + '<small>' + (opt.sub || '') + '</small>';
      t.addEventListener('click', function () {
        state[bindKey] = opt.key;
        rerenderPanel();
      });
      tabs.appendChild(t);
    });
    wrap.appendChild(tabs);
    return wrap;
  }

  function renderBrandGrid(brands, pureMgPerGal) {
    if (!brands || !brands.length) return el('div');
    var grid = el('div', 'fh-qh-brands');
    brands.slice(0, 2).forEach(function (b) {
      var box = el('div', 'fh-qh-brandbox');
      var name = b.brand_name || 'Brand';
      box.appendChild(el('div', 'fh-qh-brandname', name));

      var computed = null;
      if (pureMgPerGal && b.active_ingredient_pct && b.measure_weight_mg) {
        computed = brandMeasuresNeeded(state.tankGal, pureMgPerGal, b.active_ingredient_pct, b.measure_weight_mg);
      }

      if (b.fishotel_default && b.fishotel_default.dose) {
        box.appendChild(el('div', 'fh-qh-brandval', b.fishotel_default.dose));
        box.appendChild(el('div', 'fh-qh-brandsub', 'FisHotel default'));
      } else if (computed != null) {
        var unit = (b.measure_unit_label || 'measure') + (computed > 1.5 ? 's' : '');
        box.appendChild(el('div', 'fh-qh-brandval', computed.toFixed(2) + ' ' + unit));
        var labelSub = b.manufacturer_label_dose || '';
        if (labelSub) box.appendChild(el('div', 'fh-qh-brandsub', labelSub));
      } else if (b.manufacturer_label_dose) {
        box.appendChild(el('div', 'fh-qh-brandval', b.manufacturer_label_dose));
      }

      if (b.ui_user_surface_note) {
        box.appendChild(el('div', 'fh-qh-brandnote', b.ui_user_surface_note));
      }
      grid.appendChild(box);
    });
    return grid;
  }

  function renderScheduleCells(cells) {
    var grid = el('div', 'fh-qh-sched');
    cells.forEach(function (c) {
      var cell = el('div', 'fh-qh-schedcell');
      cell.appendChild(el('div', 'fh-qh-schedlabel', c.label));
      cell.appendChild(el('div', 'fh-qh-schedval',   c.value));
      if (c.sub) cell.appendChild(el('div', 'fh-qh-schedsub', c.sub));
      grid.appendChild(cell);
    });
    return grid;
  }

  function renderWarning(text) {
    var w = el('div', 'fh-qh-warning');
    w.innerHTML = '<span class="fh-qh-warning-icon">\u26A0</span>' + text;
    return w;
  }

  function renderNote(text) {
    return el('div', 'fh-qh-note', text);
  }

  function ppmBox(label, inputId, defaultVal) {
    var box = el('div', 'fh-qh-ppmbox');
    box.appendChild(el('span', 'fh-qh-ppmlabel', label));
    var input = el('input', 'fh-qh-ppminput');
    input.id = inputId;
    input.type = 'number';
    input.step = '0.01';
    input.min = '0';
    input.max = '2.5';
    input.value = defaultVal;
    input.addEventListener('input', function () {
      var v = Math.max(0, Math.min(2.5, +input.value || 0));
      if (inputId === 'fh-qh-cur') state.currentPpm = v;
      else if (inputId === 'fh-qh-tgt') state.targetPpm = v;
      rerenderPanel();
    });
    box.appendChild(input);
    return box;
  }

  function flukeCheckbox(kind, label, checked) {
    var id = 'fh-qh-fluke-' + kind;
    var row = el('label', 'fh-qh-check');
    row.htmlFor = id;
    var input = el('input');
    input.type = 'checkbox';
    input.id = id;
    input.checked = checked;
    input.addEventListener('change', function () {
      if (kind === 'gill') state.gillOn = input.checked;
      if (kind === 'body') state.bodyOn = input.checked;
      rerenderPanel();
    });
    row.appendChild(input);
    row.appendChild(el('span', 'fh-qh-check-label', label));
    return row;
  }

  // ---- Bath helpers -------------------------------------------------------

  function getActiveBathTier(med) {
    var tiers = (med.bath_protocol && med.bath_protocol.tiers) || [];
    if (!tiers.length) return null;
    if (state.bathTier) {
      for (var i = 0; i < tiers.length; i++) {
        if (tiers[i].tier_id === state.bathTier) return tiers[i];
      }
    }
    var defId = med.bath_protocol.default_tier;
    if (defId) {
      for (var j = 0; j < tiers.length; j++) {
        if (tiers[j].tier_id === defId) return tiers[j];
      }
    }
    return tiers[0];
  }

  function shortenTierNote(note) {
    var colonIdx = note.indexOf(':');
    if (colonIdx > -1 && colonIdx < 40) return note.slice(colonIdx + 1).trim().split('.')[0].slice(0, 48);
    return note.split('.')[0].slice(0, 48);
  }

  function renderBathModeToggle() {
    return renderSensitivityToggle(
      [
        { key: 'prolonged', label: 'In-Tank', sub: 'prolonged immersion' },
        { key: 'bath',      label: 'Bath',    sub: 'short-soak protocol' }
      ],
      'Delivery',
      'bathMode'
    );
  }

  function renderBathConcentration(med, tier) {
    var dose = el('div', 'fh-qh-doseblock');
    var info = el('div', 'fh-qh-doseinfo');
    var f = formatBathConcentration(tier);
    var totalLine = bathTotalForTank(tier, state.tankGal);

    info.innerHTML =
      '<div><span class="fh-qh-dosenum">' + f.value + '</span><span class="fh-qh-doseunit">' + f.unit + '</span></div>' +
      '<div class="fh-qh-dosesub">Bath concentration</div>' +
      (totalLine ? '<div class="fh-qh-doseswing">' + totalLine + '</div>' : '') +
      (f.sub ? '<div class="fh-qh-doseswing">' + f.sub + '</div>' : '');
    dose.appendChild(info);
    return dose;
  }

  function formatBathConcentration(tier) {
    var unit = tier.concentration_unit || '';
    if (tier.concentration_value_low != null && tier.concentration_value_high != null) {
      return { value: tier.concentration_value_low + '\u2013' + tier.concentration_value_high, unit: unit, sub: tier.concentration_metric_equivalent || '' };
    }
    if (tier.concentration_value != null) {
      var sub = '';
      if (tier.concentration_value_range) {
        sub = 'range ' + tier.concentration_value_range.min + '\u2013' + tier.concentration_value_range.max + ' ' + unit;
      } else if (tier.concentration_metric_equivalent) {
        sub = tier.concentration_metric_equivalent;
      } else if (tier.concentration_equivalent_drops_per_gal) {
        sub = '\u2248 ' + tier.concentration_equivalent_drops_per_gal + ' drops/gal';
      } else if (tier.concentration_equivalent) {
        sub = tier.concentration_equivalent;
      }
      return { value: String(tier.concentration_value), unit: unit, sub: sub };
    }
    return { value: '\u2014', unit: '', sub: '' };
  }

  /**
   * Best-effort total-for-tank line. Returns null when the unit doesn't cleanly
   * scale per-gallon (e.g. "per 5 gal" compound units).
   */
  function bathTotalForTank(tier, tankGal) {
    var unit = (tier.concentration_unit || '').toLowerCase();
    if (!/\/\s*gal|per\s+gal/i.test(tier.concentration_unit || '')) return null;

    var base = null;
    if (tier.concentration_value_low != null && tier.concentration_value_high != null) {
      var lo = tier.concentration_value_low * tankGal;
      var hi = tier.concentration_value_high * tankGal;
      return 'Total for ' + tankGal + ' gal: ' + formatNum(lo) + '\u2013' + formatNum(hi) + ' ' + unitHead(tier.concentration_unit);
    }
    if (tier.concentration_value != null) {
      base = tier.concentration_value * tankGal;
      return 'Total for ' + tankGal + ' gal: ' + formatNum(base) + ' ' + unitHead(tier.concentration_unit);
    }
    return null;
  }

  function unitHead(unit) {
    if (!unit) return '';
    return unit.replace(/\s*\/\s*gal.*/i, '').replace(/\s*per\s+gal.*/i, '').trim();
  }

  function formatNum(n) {
    if (n >= 1000) return (n / 1000).toFixed(2) + 'k';
    if (n >= 100)  return Math.round(n).toString();
    if (n >= 10)   return n.toFixed(1).replace(/\.0$/, '');
    return n.toFixed(2).replace(/\.?0+$/, '');
  }

  function renderBathDurationBlock(tier) {
    var wrap = el('div', 'fh-qh-bathduration');
    wrap.appendChild(el('div', 'fh-qh-bathduration-label', 'Bath Time'));
    var val = formatDuration(tier);
    wrap.appendChild(el('div', 'fh-qh-bathduration-val', val));
    return wrap;
  }

  function formatDuration(tier) {
    if (tier.duration_minutes == null && tier.duration_days == null) return '\u2014';
    var m = tier.duration_minutes;
    if (tier.duration_days && (!m || m >= 1440)) return tier.duration_days + ' days';
    if (m < 60) return m + ' minutes';
    var h = Math.floor(m / 60), rem = m % 60;
    var base = h + ' h' + (rem ? ' ' + rem + ' min' : '');
    if (tier.duration_range_minutes) {
      base = tier.duration_range_minutes.min + '\u2013' + tier.duration_range_minutes.max + ' minutes';
    }
    return base;
  }

  function renderBathSection(label, text) {
    var sec = el('div', 'fh-qh-bathsection');
    sec.appendChild(el('div', 'fh-qh-bathsection-label', label));
    sec.appendChild(el('div', 'fh-qh-bathsection-body', text));
    return sec;
  }

  function renderAbortCriteria(med, tier) {
    var defaults = (window.FISHOTEL_MEDS && window.FISHOTEL_MEDS.default_bath_safety) || {};
    var abort = defaults.abort_criteria || {};
    var signs = abort.signs || [];
    var wrap = el('div', 'fh-qh-bathabort');
    wrap.appendChild(el('div', 'fh-qh-bathsection-label', 'Abort the Bath If'));
    var ul = el('ul', 'fh-qh-bathabort-list');
    signs.forEach(function (s) { ul.appendChild(el('li', '', s)); });
    wrap.appendChild(ul);
    if (tier.abort_criteria_ref === 'inherits_default_bath_safety_with_heightened_vigilance' && tier.heightened_warning) {
      wrap.appendChild(renderWarning(tier.heightened_warning));
    }
    if (abort.immediate_action) {
      wrap.appendChild(el('div', 'fh-qh-bathabort-action', abort.immediate_action));
    }
    return wrap;
  }

  function renderAerationNote(med, tier) {
    var defaults = (window.FISHOTEL_MEDS && window.FISHOTEL_MEDS.default_bath_safety) || {};
    var a = defaults.aeration_requirement || {};
    var text = tier.aeration_note || a.description || 'Vigorous aeration is mandatory during any bath longer than one minute.';
    return renderBathSection('Aeration', text);
  }

  function renderScalelessSensitivity(med, tier) {
    var defaults = (window.FISHOTEL_MEDS && window.FISHOTEL_MEDS.default_bath_safety) || {};
    var d = defaults.default_species_sensitivity || {};
    if (tier.suppress_default_scaleless_sensitivity) return el('div');
    var text = d.scaleless_species_warning || '';
    var sup = tier.species_sensitivity_supplement ? ' ' + tier.species_sensitivity_supplement : '';
    return renderBathSection('Species Sensitivity', text + sup);
  }

  function renderBathSessionDetails(tier) {
    var cells = [];
    if (tier.sessions_total) {
      cells.push({ label: 'Sessions', value: String(tier.sessions_total), sub: '' });
    }
    if (tier.repeat_schedule) {
      cells.push({ label: 'Schedule', value: tier.repeat_schedule, sub: '' });
    }
    if (!cells.length) return el('div');
    var wrap = el('div', 'fh-qh-bathsessions');
    wrap.appendChild(el('div', 'fh-qh-bathsection-label', 'Session Plan'));
    cells.forEach(function (c) {
      var row = el('div', 'fh-qh-bathsessions-row');
      row.appendChild(el('span', 'fh-qh-bathsessions-key', c.label));
      row.appendChild(el('span', 'fh-qh-bathsessions-val', c.value));
      wrap.appendChild(row);
    });
    return wrap;
  }

  // Placeholder; replaced by the real implementation in the formalin-warning commit.
  function checkFormalinContraindication(med) { return null; }

  // ---- Countdown timer ---------------------------------------------------

  function timerSecondsFor(tier) {
    if (tier.duration_minutes && tier.duration_minutes < 1440) {
      return Math.round(tier.duration_minutes * 60);
    }
    return null;
  }

  function renderTimerWidget(med, tier) {
    var totalSec = timerSecondsFor(tier);
    var wrap = el('div', 'fh-qh-timer');
    wrap.appendChild(el('div', 'fh-qh-timer-label', 'Countdown Timer'));

    if (totalSec == null) {
      wrap.appendChild(el('div', 'fh-qh-timer-unsupported', 'This treatment is measured in days — use your calendar export below.'));
      return wrap;
    }

    // Initialise timer state if missing or tier changed
    var t = state.timer;
    var tierKey = med.med_id + ':' + tier.tier_id;
    if (!t || t.key !== tierKey) {
      t = state.timer = { key: tierKey, totalSec: totalSec, remainingSec: totalSec, running: false, paused: false, completed: false, intervalId: null };
    }

    var display = el('div', 'fh-qh-timer-display');
    display.textContent = formatMMSS(t.remainingSec);
    wrap.appendChild(display);

    var controls = el('div', 'fh-qh-timer-controls');

    if (t.completed) {
      var done = el('div', 'fh-qh-timer-complete', 'Bath complete — return fish to clean, aerated water now.');
      wrap.appendChild(done);
      var resetBtn = timerButton('Start New Bath', 'fh-qh-timer-btn fh-qh-timer-btn-primary', function () {
        clearTimer();
        rerenderPanel();
      });
      controls.appendChild(resetBtn);
      wrap.appendChild(controls);
      return wrap;
    }

    if (!t.running && !t.paused && t.remainingSec === t.totalSec) {
      controls.appendChild(timerButton('Begin Bath', 'fh-qh-timer-btn fh-qh-timer-btn-primary', function () { startTimer(); rerenderPanel(); }));
    } else if (t.running && !t.paused) {
      controls.appendChild(timerButton('Pause', 'fh-qh-timer-btn', function () { pauseTimer(); rerenderPanel(); }));
      controls.appendChild(timerButton('Abort', 'fh-qh-timer-btn fh-qh-timer-btn-abort', function () { abortTimer(); rerenderPanel(); }));
    } else if (t.paused) {
      controls.appendChild(timerButton('Resume', 'fh-qh-timer-btn fh-qh-timer-btn-primary', function () { startTimer(); rerenderPanel(); }));
      controls.appendChild(timerButton('Abort', 'fh-qh-timer-btn fh-qh-timer-btn-abort', function () { abortTimer(); rerenderPanel(); }));
    }

    wrap.appendChild(controls);
    wrap.appendChild(el('div', 'fh-qh-timer-note', 'Timer never auto-starts; begin after the fish is in the treatment container.'));
    return wrap;
  }

  function timerButton(label, cls, onClick) {
    var b = el('button', cls, label);
    b.type = 'button';
    b.addEventListener('click', onClick);
    return b;
  }

  function formatMMSS(sec) {
    if (sec < 0) sec = 0;
    var m = Math.floor(sec / 60);
    var s = sec % 60;
    return (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s;
  }

  function startTimer() {
    var t = state.timer;
    if (!t) return;
    if (t.intervalId) return;
    t.running = true;
    t.paused = false;
    t.intervalId = setInterval(function () {
      t.remainingSec -= 1;
      var d = document.querySelector('.fh-qh-timer-display');
      if (d) d.textContent = formatMMSS(t.remainingSec);
      if (t.remainingSec <= 0) {
        clearInterval(t.intervalId);
        t.intervalId = null;
        t.running = false;
        t.completed = true;
        try { playTimerChime(); } catch (e) { /* audio optional */ }
        rerenderPanel();
      }
    }, 1000);
  }

  function pauseTimer() {
    var t = state.timer;
    if (!t) return;
    if (t.intervalId) { clearInterval(t.intervalId); t.intervalId = null; }
    t.running = false;
    t.paused = true;
  }

  function abortTimer() {
    var t = state.timer;
    if (!t) return;
    if (t.intervalId) { clearInterval(t.intervalId); t.intervalId = null; }
    state.timer = null;
  }

  function playTimerChime() {
    if (typeof window === 'undefined' || !window.AudioContext && !window.webkitAudioContext) return;
    var Ctx = window.AudioContext || window.webkitAudioContext;
    var ctx = new Ctx();
    var osc = ctx.createOscillator(), gain = ctx.createGain();
    osc.frequency.value = 660; osc.type = 'sine';
    gain.gain.value = 0.05;
    osc.connect(gain); gain.connect(ctx.destination);
    osc.start(); osc.stop(ctx.currentTime + 0.5);
  }

  function renderPraziSliders() {
    var wrap = el('div', 'fh-qh-prazi-sliders');

    var tempRow = el('div', 'fh-qh-slrow');
    tempRow.appendChild(el('span', 'fh-qh-sllabel', 'Water Temp'));
    var tempIn = el('input', 'fh-qh-slider');
    tempIn.type = 'range'; tempIn.min = '60'; tempIn.max = '86'; tempIn.step = '1'; tempIn.value = state.tempF;
    tempIn.addEventListener('input', function () { state.tempF = +tempIn.value; rerenderPanel(); });
    tempRow.appendChild(tempIn);
    var tempOut = el('span', 'fh-qh-slval', state.tempF + '°F');
    tempRow.appendChild(tempOut);
    wrap.appendChild(tempRow);

    var salRow = el('div', 'fh-qh-slrow');
    salRow.appendChild(el('span', 'fh-qh-sllabel', 'Salinity SG'));
    var salIn = el('input', 'fh-qh-slider');
    salIn.type = 'range'; salIn.min = '1.009'; salIn.max = '1.030'; salIn.step = '0.001'; salIn.value = state.salinitySG;
    salIn.addEventListener('input', function () { state.salinitySG = +salIn.value; rerenderPanel(); });
    salRow.appendChild(salIn);
    salRow.appendChild(el('span', 'fh-qh-slval', (+state.salinitySG).toFixed(3)));
    wrap.appendChild(salRow);
    return wrap;
  }

  // ============================================================================
  // SVG RENDERERS
  // ============================================================================

  function renderPipette(ml) {
    var scale = pickPipetteScale(ml);
    var pct   = Math.min(1, ml / scale);
    var fullH = 107;
    var fillH = Math.max(1, fullH * pct);
    var mid   = Math.round(scale / 2);

    var svg =
      '<svg width="88" height="140" viewBox="0 0 88 140" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" class="fh-qh-pipette">' +
        '<rect x="36" y="0" width="16" height="6" fill="none" stroke="#d4a574" stroke-width="0.8"/>' +
        '<rect x="30" y="6" width="28" height="4" fill="#d4a574" opacity="0.4"/>' +
        '<path d="M30 10 L30 118 Q30 128 44 128 Q58 128 58 118 L58 10 Z" fill="none" stroke="#d4a574" stroke-width="0.8"/>' +
        '<clipPath id="fh-qh-pipe-clip"><path d="M31 11 L31 118 Q31 127 44 127 Q57 127 57 118 L57 11 Z"/></clipPath>' +
        '<rect x="30" y="' + (128 - fillH) + '" width="28" height="' + fillH + '" fill="#d4a574" opacity="0.75" clip-path="url(#fh-qh-pipe-clip)"/>' +
        '<g stroke="#d4a574" stroke-width="0.5" opacity="0.6">' +
          '<line x1="58" y1="14" x2="64" y2="14"/>' +
          '<line x1="58" y1="67" x2="64" y2="67"/>' +
          '<line x1="58" y1="120" x2="64" y2="120"/>' +
        '</g>' +
        '<g font-family="Josefin Sans, sans-serif" font-size="7" fill="#807469">' +
          '<text x="66" y="17">' + scale + '</text>' +
          '<text x="66" y="70">' + mid + '</text>' +
          '<text x="66" y="123">0</text>' +
        '</g>' +
      '</svg>';

    var div = el('div', 'fh-qh-pipette-wrap');
    div.innerHTML = svg;
    return div;
  }

  function renderFlatDailyTimeline(days) {
    var W = 580, H = 90, pad = 6, colW = (W - pad * 2) / days;
    var svg = '<svg width="100%" height="90" viewBox="0 0 580 90" preserveAspectRatio="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">';
    for (var d = 0; d < days; d++) {
      var x = pad + d * colW;
      var cx = x + colW / 2;
      svg += '<rect x="' + (x + 1) + '" y="20" width="' + (colW - 2) + '" height="45" fill="rgba(212,165,116,0.08)" stroke="rgba(212,165,116,0.3)" stroke-width="0.5"/>';
      svg += '<circle cx="' + cx + '" cy="35" r="3" fill="#6b8db8"/>'; // water change
      svg += '<circle cx="' + cx + '" cy="52" r="4" fill="#d4a574"/>'; // dose
      svg += '<text x="' + cx + '" y="82" text-anchor="middle" font-family="Josefin Sans,sans-serif" font-size="9" fill="#6b6058">D' + (d + 1) + '</text>';
    }
    svg += '</svg>';

    var wrap = el('div', 'fh-qh-timeline');
    wrap.appendChild(el('div', 'fh-qh-timeline-title', 'Dosing Schedule'));
    var legend = el('div', 'fh-qh-legend');
    legend.innerHTML = '<span class="fh-qh-leg-item"><span class="fh-qh-dot fh-qh-dot-water"></span>water change</span>' +
                       '<span class="fh-qh-leg-item"><span class="fh-qh-dot fh-qh-dot-dose"></span>dose</span>';
    wrap.appendChild(legend);
    var chart = el('div'); chart.innerHTML = svg;
    wrap.appendChild(chart);
    return wrap;
  }

  function renderRampHoldTimeline(target, rampDays, holdDays) {
    var days = rampDays + holdDays;
    var W = 580, H = 120, pad = 6, colW = (W - pad * 2) / days;
    var svg = '<svg width="100%" height="120" viewBox="0 0 580 120" preserveAspectRatio="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">';
    for (var d = 0; d < days; d++) {
      var x = pad + d * colW, dayNum = d + 1, isRamp = dayNum <= rampDays;
      var level = isRamp ? (dayNum / rampDays) * target : target;
      var barH = (level / 2.5) * (H - 32), barY = H - 10 - barH;
      var fill = isRamp ? '#d4a574' : 'rgba(212,165,116,0.28)';
      var stroke = isRamp ? 'none' : '#d4a574';
      var sw = isRamp ? 0 : 0.5;
      svg += '<rect x="' + (x + 1) + '" y="' + barY + '" width="' + (colW - 2) + '" height="' + barH + '" fill="' + fill + '" stroke="' + stroke + '" stroke-width="' + sw + '"/>';
      svg += '<text x="' + (x + colW / 2) + '" y="' + (H - 1) + '" text-anchor="middle" font-family="Josefin Sans,sans-serif" font-size="8" fill="#6b6058">D' + dayNum + '</text>';
    }
    var yTgt = H - 10 - ((target / 2.5) * (H - 32));
    svg += '<line x1="' + pad + '" y1="' + yTgt + '" x2="' + (W - pad) + '" y2="' + yTgt + '" stroke="#d4a574" stroke-width="0.5" stroke-dasharray="2 3" opacity="0.5"/>';
    svg += '<text x="' + (W - pad - 4) + '" y="' + (yTgt - 2) + '" text-anchor="end" font-family="Josefin Sans,sans-serif" font-size="8" fill="#d4a574">' + target.toFixed(2) + ' ppm target</text>';
    svg += '</svg>';

    var wrap = el('div', 'fh-qh-timeline');
    wrap.appendChild(el('div', 'fh-qh-timeline-title', 'Cu Level Over Time'));
    var legend = el('div', 'fh-qh-legend');
    legend.innerHTML = '<span class="fh-qh-leg-item"><span class="fh-qh-sq fh-qh-sq-ramp"></span>ramp day</span>' +
                       '<span class="fh-qh-leg-item"><span class="fh-qh-sq fh-qh-sq-hold"></span>hold · test daily with Hanna</span>';
    wrap.appendChild(legend);
    var chart = el('div'); chart.innerHTML = svg;
    wrap.appendChild(chart);
    return wrap;
  }

  function renderPraziTimeline(life, days) {
    var W = 580, H = 110, pad = 6, colW = (W - pad * 2) / days;
    var safeEnd   = Math.min(days, life.sexMat);
    var matureEnd = Math.min(days, life.infective);

    var xSafeEnd   = pad + (safeEnd / days) * (W - pad * 2);
    var xMatureEnd = pad + (matureEnd / days) * (W - pad * 2);

    var svg = '<svg width="100%" height="110" viewBox="0 0 580 110" preserveAspectRatio="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">';
    // Lifecycle band
    svg += '<rect x="' + pad + '" y="18" width="' + (xSafeEnd - pad) + '" height="32" fill="rgba(78,165,138,0.25)" stroke="#4ea58a" stroke-width="0.5"/>';
    svg += '<rect x="' + xSafeEnd + '" y="18" width="' + (xMatureEnd - xSafeEnd) + '" height="32" fill="rgba(230,176,98,0.25)" stroke="#e6b062" stroke-width="0.5"/>';
    svg += '<rect x="' + xMatureEnd + '" y="18" width="' + (W - pad - xMatureEnd) + '" height="32" fill="rgba(212,106,90,0.2)" stroke="#d46a5a" stroke-width="0.5"/>';
    // Zone labels
    svg += '<text x="' + ((pad + xSafeEnd) / 2) + '" y="37" text-anchor="middle" font-family="Josefin Sans,sans-serif" font-size="9" fill="#4ea58a">Post-dose</text>';
    svg += '<text x="' + ((xSafeEnd + xMatureEnd) / 2) + '" y="37" text-anchor="middle" font-family="Josefin Sans,sans-serif" font-size="9" fill="#e6b062">Maturing</text>';
    svg += '<text x="' + ((xMatureEnd + W - pad) / 2) + '" y="37" text-anchor="middle" font-family="Josefin Sans,sans-serif" font-size="9" fill="#d46a5a">Egg-laying</text>';
    // Dose markers
    life.doses.forEach(function (d, idx) {
      var cx = pad + ((d - 0.5) / days) * (W - pad * 2);
      svg += '<line x1="' + cx + '" y1="18" x2="' + cx + '" y2="65" stroke="#EDE0C0" stroke-width="1.5" opacity="0.75"/>';
      svg += '<circle cx="' + cx + '" cy="65" r="8" fill="#d4a574" stroke="#0f0f0f" stroke-width="1.5"/>';
      svg += '<text x="' + cx + '" y="68.5" text-anchor="middle" font-family="Playfair Display,serif" font-size="11" font-weight="bold" fill="#0f0f0f">' + (idx + 1) + '</text>';
      svg += '<text x="' + cx + '" y="84" text-anchor="middle" font-family="Playfair Display,serif" font-size="10" font-style="italic" fill="#EDE0C0">Dose ' + (idx + 1) + '</text>';
    });
    // Day axis — every day labeled
    for (var d = 1; d <= days; d++) {
      var x = pad + (d - 0.5) / days * (W - pad * 2);
      svg += '<text x="' + x + '" y="103" text-anchor="middle" font-family="Josefin Sans,sans-serif" font-size="8" fill="#6b6058">D' + d + '</text>';
    }
    svg += '</svg>';

    var wrap = el('div', 'fh-qh-timeline');
    wrap.appendChild(el('div', 'fh-qh-timeline-title', 'Fluke Lifecycle'));
    var chart = el('div'); chart.innerHTML = svg;
    wrap.appendChild(chart);
    return wrap;
  }

  // ============================================================================
  // PANEL RENDERING DISPATCH
  // ============================================================================

  function rerenderPanel() {
    if (!state.medId) return;
    var med = getMed(state.medId);
    if (!med) return;

    // Bath-mode routing: v1.3 treatment_type drives the renderer
    var tt = med.treatment_type || 'prolonged';
    var showBath = tt === 'bath' || (tt === 'both' && state.bathMode === 'bath');

    // Adapt tank slider range to the active mode
    applyAdaptiveTankRange(showBath);

    var cat = med.category;
    var node;

    if (showBath) {
      node = renderBathCard(med);
    } else if (med.med_id === 'praziquantel') {
      node = renderPrazi(med);
    } else if (cat === 'copper') {
      node = renderRampHold(med);
    } else if (cat === 'antibiotic') {
      node = renderFlatDaily(med);
    } else {
      node = renderGeneric(med);
    }

    $panel.innerHTML = '';
    $panel.appendChild(node);
  }

  /**
   * Bath mode clamps the tank volume slider to 1–10 gal; prolonged mode
   * opens it back up to 1–500 gal. Current value is preserved across modes
   * (bath value remembered in lastBathGal; prolonged in lastProlongedGal).
   */
  function applyAdaptiveTankRange(isBath) {
    if (!$tankSlider) return;
    var max = isBath ? 10 : 500;
    var min = 1;
    $tankSlider.min = String(min);
    $tankSlider.max = String(max);

    if (isBath) {
      if (+$tankSlider.value > max) {
        state.lastProlongedGal = state.tankGal > 10 ? state.tankGal : state.lastProlongedGal;
        state.tankGal = state.lastBathGal <= max ? state.lastBathGal : max;
      }
    } else {
      if (state.lastProlongedGal && state.tankGal <= 10) {
        state.lastBathGal = state.tankGal;
        state.tankGal = state.lastProlongedGal;
      }
    }

    $tankSlider.value = String(state.tankGal);
    if ($tankGalOut) $tankGalOut.textContent = state.tankGal;
  }

  /**
   * Annotate each med tile with a treatment-type badge.
   * Called once at boot — tiles are PHP-rendered.
   */
  function initTileBadges() {
    var meds = (window.FISHOTEL_MEDS && window.FISHOTEL_MEDS.medications) || [];
    var byId = {};
    meds.forEach(function (m) { byId[m.med_id] = m; });

    [].forEach.call($medButtons, function (btn) {
      var med = byId[btn.getAttribute('data-med-id')];
      if (!med) return;
      var badge = badgeTextFor(med);
      if (!badge) return;
      var span = document.createElement('span');
      span.className = 'fh-qh-med-badge';
      span.textContent = badge;
      btn.appendChild(span);
    });
  }

  function badgeTextFor(med) {
    var tt = med.treatment_type || 'prolonged';
    var days = med.duration_days;
    if (tt === 'bath') return 'BATH';
    if (tt === 'both') {
      return days ? 'BATH or ' + days + 'd' : 'BATH';
    }
    return days ? days + 'd' : null;
  }

  function selectMed(medId) {
    // Reset per-med bath state on every med switch (default per task: prolonged for 'both')
    clearTimer();
    state.medId = medId;
    state.bathTier = null;
    var med = getMed(medId);
    if (med) {
      state.bathMode = (med.treatment_type === 'bath') ? 'bath' : 'prolonged';
    }
    if (med && med.category) {
      var cat = med.category === 'dewormer_internal' || med.category === 'protocol' ? 'misc' : med.category;
      setActiveTab(cat);
    }
    [].forEach.call($medButtons, function (b) {
      b.classList.toggle('is-on', b.getAttribute('data-med-id') === medId);
    });
    rerenderPanel();
  }

  function clearTimer() {
    var t = state.timer;
    if (t && t.intervalId) { clearInterval(t.intervalId); t.intervalId = null; }
    state.timer = null;
  }

  function setActiveTab(cat) {
    state.category = cat;
    [].forEach.call($tabs, function (t) {
      t.classList.toggle('is-on', t.getAttribute('data-cat') === cat);
    });
    [].forEach.call($medButtons, function (b) {
      var show = b.getAttribute('data-cat') === cat;
      b.style.display = show ? '' : 'none';
    });
  }

  // ============================================================================
  // PRINT + ICS EXPORT
  // ============================================================================

  function handlePrint() { window.print(); }

  function handleIcs() {
    var c = state.lastComputed;
    if (!c) { alert('Select a medication first.'); return; }

    if (c.mode === 'bath') {
      if (!c.bathCalendarEnabled) {
        alert('This bath protocol is a single-session treatment; no calendar export is available.');
        return;
      }
      return exportBathIcs(c);
    }

    exportScheduleIcs(c);
  }

  function icsFmt(dt) {
    var y = dt.getUTCFullYear();
    var mo = String(dt.getUTCMonth() + 1).padStart(2, '0');
    var d  = String(dt.getUTCDate()).padStart(2, '0');
    var h  = String(dt.getUTCHours()).padStart(2, '0');
    var mi = String(dt.getUTCMinutes()).padStart(2, '0');
    var s  = String(dt.getUTCSeconds()).padStart(2, '0');
    return y + mo + d + 'T' + h + mi + s + 'Z';
  }

  function icsEscape(text) {
    if (!text) return '';
    return String(text).replace(/[\\;,]/g, function (ch) { return '\\' + ch; }).replace(/\n/g, '\\n');
  }

  function icsDownload(lines, filename) {
    lines.push('END:VCALENDAR');
    var blob = new Blob([lines.join('\r\n')], { type: 'text/calendar' });
    var url  = URL.createObjectURL(blob);
    var a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a); a.click(); document.body.removeChild(a);
    URL.revokeObjectURL(url);
  }

  function exportScheduleIcs(c) {
    var lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//FisHotel//Medication Schedule//EN'];
    var dayMs = 86400000, now = new Date();
    var start = new Date(now.getFullYear(), now.getMonth(), now.getDate());

    (c.doseDays || []).forEach(function (day, i) {
      var dt = new Date(start.getTime() + (day - 1) * dayMs + 9 * 3600000);
      var dt2 = new Date(dt.getTime() + 3600000);
      lines.push(
        'BEGIN:VEVENT',
        'UID:' + Date.now() + '-d' + i + '@fishotel.com',
        'DTSTAMP:' + icsFmt(new Date()),
        'DTSTART:' + icsFmt(dt),
        'DTEND:' + icsFmt(dt2),
        'SUMMARY:' + icsEscape(c.medName + ' — Dose ' + (i + 1) + ' (' + state.tankGal + ' gal)'),
        'DESCRIPTION:' + icsEscape(c.doseLabel),
        'END:VEVENT'
      );
    });
    icsDownload(lines, (c.medName || 'medication').toLowerCase().replace(/\s+/g, '-') + '-schedule.ics');
  }

  function exportBathIcs(c) {
    var bp = c.bathProtocol || {};
    var tier = c.bathTier || {};
    var sessions = bathSessionOffsets(tier);
    var lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//FisHotel//Bath Schedule//EN'];
    var dayMs = 86400000, now = new Date();
    var start = new Date(now.getFullYear(), now.getMonth(), now.getDate());
    var durMs = (tier.duration_minutes ? tier.duration_minutes : 60) * 60 * 1000;
    var summaryBase = bp.calendar_event_label || (c.medName + ' bath');

    sessions.forEach(function (offsetDays, i) {
      var dt = new Date(start.getTime() + offsetDays * dayMs + 9 * 3600000);
      var dt2 = new Date(dt.getTime() + durMs);
      var desc = (bp.calendar_event_note || '') + ' · Session ' + (i + 1) + ' of ' + sessions.length;
      lines.push(
        'BEGIN:VEVENT',
        'UID:' + Date.now() + '-bath' + i + '@fishotel.com',
        'DTSTAMP:' + icsFmt(new Date()),
        'DTSTART:' + icsFmt(dt),
        'DTEND:' + icsFmt(dt2),
        'SUMMARY:' + icsEscape(summaryBase + ' — Session ' + (i + 1) + ' (' + state.tankGal + ' gal bath)'),
        'DESCRIPTION:' + icsEscape(desc),
        'END:VEVENT'
      );
    });
    icsDownload(lines, (c.medName || 'medication').toLowerCase().replace(/\s+/g, '-') + '-bath.ics');
  }

  /**
   * Translate a tier's repeat_schedule into day offsets (Day 1 = offset 0).
   * Uses declarative fields where present; falls back to parsing the
   * natural-language repeat_schedule string for "daily for N days" and
   * "N days after the first session".
   */
  function bathSessionOffsets(tier) {
    var total = tier.sessions_total || 1;
    var rep = (tier.repeat_schedule || '').toLowerCase();
    var offsets = [];

    var dailyMatch = rep.match(/daily for (\d+)/);
    if (dailyMatch) {
      var n = parseInt(dailyMatch[1], 10);
      for (var i = 0; i < n; i++) offsets.push(i);
      return offsets;
    }

    var weekMatch = rep.match(/(?:approximately\s+)?(\d+)\s*days?\s*after the first/);
    if (weekMatch) {
      offsets.push(0);
      offsets.push(parseInt(weekMatch[1], 10));
      return offsets;
    }

    if (total === 1) return [0];

    // Fallback: space sessions one day apart
    for (var k = 0; k < total; k++) offsets.push(k);
    return offsets;
  }

  // ============================================================================
  // BOOT
  // ============================================================================

  document.addEventListener('DOMContentLoaded', function () {
    $panel       = document.getElementById('fh-qh-panel');
    $tankSlider  = document.getElementById('fh-qh-tank');
    $tankGalOut  = document.getElementById('fh-qh-gal');
    $tabs        = document.querySelectorAll('.fh-qh-tab');
    $grid        = document.getElementById('fh-qh-medgrid');
    $medButtons  = document.querySelectorAll('.fh-qh-med');
    $printBtn    = document.getElementById('fh-qh-print');
    $icsBtn      = document.getElementById('fh-qh-ics');

    if (!window.FISHOTEL_MEDS) {
      $panel.innerHTML = '<div class="fh-qh-error">Medication data failed to load. Reload the page or contact FisHotel.</div>';
      return;
    }

    state.tankGal = +$tankSlider.value || 30;

    $tankSlider.addEventListener('input', function () {
      state.tankGal = +$tankSlider.value;
      $tankGalOut.textContent = state.tankGal;
      rerenderPanel();
    });

    [].forEach.call($tabs, function (t) {
      t.addEventListener('click', function () {
        setActiveTab(t.getAttribute('data-cat'));
      });
    });

    [].forEach.call($medButtons, function (b) {
      b.addEventListener('click', function () {
        selectMed(b.getAttribute('data-med-id'));
      });
    });

    initTileBadges();

    $printBtn.addEventListener('click', handlePrint);
    $icsBtn.addEventListener('click', handleIcs);

    // Initial selection from query string or default
    var params = new URLSearchParams(window.location.search);
    var initialMedId = params.get('med') || 'neomycin_sulfate';
    selectMed(initialMedId);
  });

})();
