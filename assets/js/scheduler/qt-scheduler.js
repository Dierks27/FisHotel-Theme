/**
 * FisHotel QT Scheduler
 *
 * Pick a method → generate a day-by-day schedule + meds list.
 *
 * Reads window.FISHOTEL_QT_METHODS for method catalog and window.FISHOTEL_MEDS
 * for per-dose math (the same source as the medication calculator). The
 * scheduler is a process generator on top of that data — never the source of
 * dose values.
 *
 * @package fishotel-theme
 */
(function () {
  'use strict';

  // ============================================================================
  // STATE
  // ============================================================================

  var state = {
    tankGal: 30,
    tempF: 77,
    methodId: null,
    params: {},   // { ramp_speed: 'standard', cp_protocol: 'one_and_done_standard', ... }
    addons: {},   // { praziquantel: true }
    startDate: null,   // YYYY-MM-DD; null = today
    lastSchedule: null  // { entries, stages, totalDays, methodName, methodId, keyMeds }
  };

  // ============================================================================
  // DOM REFS
  // ============================================================================

  var $tankSlider, $tankText, $tempText, $methodGrid, $configPanel,
      $generateBtn, $output, $printBtn, $icsBtn, $startDateInput, $medList;

  function el(tag, className, text) {
    var e = document.createElement(tag);
    if (className) e.className = className;
    if (text !== undefined && text !== null) e.textContent = text;
    return e;
  }

  // ============================================================================
  // DATA LOOKUPS
  // ============================================================================

  function getMethods() {
    return (window.FISHOTEL_QT_METHODS && window.FISHOTEL_QT_METHODS.methods) || [];
  }

  function getMethod(id) {
    var methods = getMethods();
    for (var i = 0; i < methods.length; i++) {
      if (methods[i].method_id === id) return methods[i];
    }
    return null;
  }

  function getMed(id) {
    var meds = (window.FISHOTEL_MEDS && window.FISHOTEL_MEDS.medications) || [];
    for (var i = 0; i < meds.length; i++) {
      if (meds[i].med_id === id) return meds[i];
    }
    return null;
  }

  function getCpVariant(variantId) {
    var cp = getMed('chloroquine_phosphate');
    if (!cp || !cp.dosing_variants) return null;
    for (var i = 0; i < cp.dosing_variants.length; i++) {
      if (cp.dosing_variants[i].variant_id === variantId) return cp.dosing_variants[i];
    }
    return null;
  }

  // ============================================================================
  // DOSE MATH HELPERS — mirror calculator conventions
  // ============================================================================

  function formatDoseMg(mg) {
    if (mg >= 1000) return (mg / 1000).toFixed(2) + ' g';
    if (mg >= 100)  return Math.round(mg) + ' mg';
    return mg.toFixed(1) + ' mg';
  }

  function formatMl(ml) {
    return ml.toFixed(1) + ' ml';
  }

  /** Copper Power liquid dispense: ml/gal/ppm × tankGal × deltaPpm. */
  function copperPowerMl(tankGal, deltaPpm) {
    var med = getMed('copper_power');
    var formula = (med && med.dispensing_formula) || { ml_per_gal_per_ppm: 0.692 };
    var rate = +formula.ml_per_gal_per_ppm || 0.692;
    return tankGal * rate * deltaPpm;
  }

  /** Formalin 37%: 1 ml per 10 gal. */
  function formalinMl(tankGal) {
    return tankGal / 10;
  }

  /** Metronidazole: 12.5–25 mg/gal pure. Use midpoint 18.75 mg/gal as the dose. */
  function metroDoseMg(tankGal) {
    return tankGal * 18.75;
  }

  /** Praziquantel: 9.5 mg/gal pure. */
  function praziDoseMg(tankGal) {
    return tankGal * 9.5;
  }

  /** Chloroquine phosphate: mgPerGal × tankGal. */
  function cpDoseMg(tankGal, mgPerGal) {
    return tankGal * mgPerGal;
  }

  // ============================================================================
  // MED-LINK HELPER
  // ============================================================================

  var MED_SKU_MAP = {
    copper_power:         { sku: 'copper-power-2oz',         brand: 'Copper Power' },
    formalin_37:          { sku: 'formalin-37-pint',         brand: 'Formalin 37%' },
    metronidazole:        { sku: 'metronidazole-100g',       brand: 'Metronidazole' },
    praziquantel:         { sku: 'praziquantel-100g',        brand: 'Praziquantel' },
    chloroquine_phosphate:{ sku: 'chloroquine-phosphate-25g',brand: 'Chloroquine Phosphate' }
  };

  function medLink(medId, displayName) {
    var info = MED_SKU_MAP[medId] || { sku: medId, brand: displayName || medId };
    var name = displayName || info.brand;
    return '<a class="fst-med-link" data-med-sku="' + info.sku + '" data-brand-name="' + info.brand +
           '" href="#" aria-label="Buy ' + info.brand + ' at FisHotel">' + name + '</a>';
  }

  // ============================================================================
  // SCHEDULE GENERATION — one function per method (each emits flat entries[])
  // ============================================================================

  function generateSchedule(method) {
    if (!method) return null;
    switch (method.method_id) {
      case 'copper_formalin_qt': return generateCopperFormalin(method);
      case 'copper_metro_qt':    return generateCopperMetro(method);
      case 'chloroquine_qt':     return generateChloroquine(method);
      case 'httm':               return generateHttm(method);
    }
    return null;
  }

  function rampDaysFor(method) {
    var paramDef = (method.configurable_params || []).filter(function (p) { return p.param_id === 'ramp_speed'; })[0];
    if (!paramDef) return 0;
    var chosenVal = state.params.ramp_speed || paramDef.default;
    for (var i = 0; i < paramDef.options.length; i++) {
      if (paramDef.options[i].value === chosenVal) return paramDef.options[i].ramp_days;
    }
    return paramDef.options[0].ramp_days;
  }

  function copperRampDoses(rampDays, targetPpm) {
    // Equal-step climb 0 → targetPpm in rampDays days. Each step = targetPpm/rampDays.
    var doses = [];
    for (var d = 1; d <= rampDays; d++) {
      doses.push({
        day: d,
        levelPpm: (d / rampDays) * targetPpm,
        deltaPpm: targetPpm / rampDays
      });
    }
    return doses;
  }

  // ---- Cu + Formalin ---------------------------------------------------------

  function generateCopperFormalin(method) {
    var rampDays = rampDaysFor(method);
    var therapeutic = method.therapeutic_ppm || 2.5;
    var holdDays = method.therapeutic_hold_days || 14;
    var obsDays = method.observation_days || 14;
    var transferDay = rampDays + holdDays + 1;
    var totalDays = transferDay + obsDays;

    var entries = [];
    var rampDoses = copperRampDoses(rampDays, therapeutic);

    // Day 1 setup line
    entries.push({ day: 1, type: 'structural', text: 'Bare-bottom QT, vigorous aeration ON. Carbon, Purigen, UV all OFF.' });

    rampDoses.forEach(function (r, i) {
      var ml = copperPowerMl(state.tankGal, r.deltaPpm);
      var label = (i === 0)
        ? 'Dose ' + medLink('copper_power') + ' to ' + r.levelPpm.toFixed(2) + ' ppm — <strong>' + formatMl(ml) + '</strong>'
        : 'Bump ' + medLink('copper_power') + ' to ' + r.levelPpm.toFixed(2) + ' ppm — <strong>' + formatMl(ml) + '</strong>';
      if (i === rampDoses.length - 1) label += ' <em>(final ramp dose)</em>';
      entries.push({ day: r.day, type: 'dose', medId: 'copper_power', text: label });
    });

    // Formalin: 1 ml per 10 gal, daily Days 1–10
    var formalinDays = method.formalin_dose_days_count || 10;
    var fMl = formalinMl(state.tankGal);
    for (var fd = 1; fd <= formalinDays; fd++) {
      var ftext = 'Dose ' + medLink('formalin_37') + ' — <strong>' + formatMl(fMl) + '</strong> (1 ml per 10 gal)';
      if (fd === formalinDays) ftext += ' <em>(final formalin dose)</em>';
      entries.push({ day: fd, type: 'dose', medId: 'formalin_37', text: ftext });
    }

    // Therapeutic phase markers + maintenance
    var therapStart = rampDays + 1;
    entries.push({ day: therapStart, type: 'marker', text: 'Day 1 of ' + holdDays + ' at therapeutic. Maintain Cu at ' + therapeutic.toFixed(2) + ' ppm.' });

    // Mid-hold reminder
    var midHold = therapStart + Math.floor(holdDays / 2);
    if (midHold > therapStart && midHold < transferDay - 1) {
      entries.push({ day: midHold, type: 'marker', text: 'Test Cu — bump back to ' + therapeutic.toFixed(2) + ' ppm if drifted.' });
    }

    // End of therapeutic
    entries.push({ day: transferDay - 1, type: 'marker', text: 'Day ' + holdDays + ' at therapeutic — last day in QT tank.' });

    // Transfer
    entries.push({ day: transferDay, type: 'structural', text: 'TRANSFER — drip-acclimate fish into a clean, fully-cycled observation tank ≥10 ft from QT. End copper.' });

    // Observation
    entries.push({ day: transferDay + 1, type: 'marker', text: 'Begin clean observation — no meds. Watch fish for 14 days.' });
    entries.push({ day: totalDays, type: 'marker', text: 'End QT — fish cleared.' });

    var stages = [
      { phase: 'ramp',         label: 'Copper Ramp',       color_token: 'amber', startDay: 1,                 endDay: rampDays },
      { phase: 'therapeutic',  label: 'Therapeutic Hold',  color_token: 'green', startDay: therapStart,       endDay: transferDay - 1 },
      { phase: 'transfer',     label: 'Transfer',          color_token: 'slate', startDay: transferDay,       endDay: transferDay },
      { phase: 'observation',  label: 'Clean Observation', color_token: 'teal',  startDay: transferDay + 1,   endDay: totalDays }
    ];

    return {
      entries: entries,
      stages: stages,
      totalDays: totalDays,
      methodName: method.name,
      methodId: method.method_id,
      keyMeds: keyMedsForMethod(method)
    };
  }

  // ---- Cu + Metro ------------------------------------------------------------

  function generateCopperMetro(method) {
    var rampDays = rampDaysFor(method);
    var therapeutic = method.therapeutic_ppm || 2.5;
    var holdDays = method.therapeutic_hold_days || 14;
    var obsDays = method.observation_days || 14;
    var transferDay = rampDays + holdDays + 1;
    var totalDays = transferDay + obsDays;

    var entries = [];
    var rampDoses = copperRampDoses(rampDays, therapeutic);

    entries.push({ day: 1, type: 'structural', text: 'Bare-bottom QT. Carbon, Purigen, UV all OFF. Lights dimmed during metro dosing.' });

    rampDoses.forEach(function (r, i) {
      var ml = copperPowerMl(state.tankGal, r.deltaPpm);
      var label = (i === 0)
        ? 'Dose ' + medLink('copper_power') + ' to ' + r.levelPpm.toFixed(2) + ' ppm — <strong>' + formatMl(ml) + '</strong>'
        : 'Bump ' + medLink('copper_power') + ' to ' + r.levelPpm.toFixed(2) + ' ppm — <strong>' + formatMl(ml) + '</strong>';
      if (i === rampDoses.length - 1) label += ' <em>(final ramp dose)</em>';
      entries.push({ day: r.day, type: 'dose', medId: 'copper_power', text: label });
    });

    // Metronidazole — Days 1, 3, 5, 7, 9 with 25% WC before each dose (after Day 1)
    var metroDays = method.metro_dose_days || [1, 3, 5, 7, 9];
    var metroMg = metroDoseMg(state.tankGal);
    var metroFmt = formatDoseMg(metroMg);
    var wcPct = method.metro_water_change_pct || 25;
    metroDays.forEach(function (md, idx) {
      if (idx > 0) {
        // 25% WC + retest + bump copper before dosing metro
        entries.push({
          day: md,
          type: 'water_change',
          text: '<strong>' + wcPct + '% water change</strong>, then retest + bump ' + medLink('copper_power') + ' to ' + therapeutic.toFixed(2) + ' ppm.'
        });
      }
      var mtext = 'Dose ' + medLink('metronidazole') + ' — <strong>' + metroFmt + '</strong> (≈18.75 mg/gal, range 12.5–25)';
      if (idx === metroDays.length - 1) mtext += ' <em>(final metro dose)</em>';
      entries.push({ day: md, type: 'dose', medId: 'metronidazole', text: mtext });
    });

    // Praziquantel addon — Days 1 and 8
    if (state.addons.praziquantel) {
      var prMg = praziDoseMg(state.tankGal);
      var prFmt = formatDoseMg(prMg);
      (method.prazi_offsets_days || [1, 8]).forEach(function (pd, i) {
        var ptext = (i === 0 ? 'Dose ' : 'Redose ') + medLink('praziquantel') + ' — <strong>' + prFmt + '</strong> (9.5 mg/gal)';
        entries.push({ day: pd, type: 'dose', medId: 'praziquantel', text: ptext });
      });
    }

    var therapStart = rampDays + 1;
    entries.push({ day: therapStart, type: 'marker', text: 'Day 1 of ' + holdDays + ' at therapeutic. Maintain Cu at ' + therapeutic.toFixed(2) + ' ppm.' });

    entries.push({ day: transferDay - 1, type: 'marker', text: 'Day ' + holdDays + ' at therapeutic — last day in QT tank.' });
    entries.push({ day: transferDay, type: 'structural', text: 'TRANSFER — drip-acclimate fish into a clean, fully-cycled observation tank ≥10 ft from QT. End copper.' });
    entries.push({ day: transferDay + 1, type: 'marker', text: 'Begin clean observation — no meds.' });
    entries.push({ day: totalDays, type: 'marker', text: 'End QT — fish cleared.' });

    var stages = [
      { phase: 'ramp',         label: 'Copper Ramp',       color_token: 'amber', startDay: 1,                 endDay: rampDays },
      { phase: 'therapeutic',  label: 'Therapeutic Hold',  color_token: 'green', startDay: therapStart,       endDay: transferDay - 1 },
      { phase: 'transfer',     label: 'Transfer',          color_token: 'slate', startDay: transferDay,       endDay: transferDay },
      { phase: 'observation',  label: 'Clean Observation', color_token: 'teal',  startDay: transferDay + 1,   endDay: totalDays }
    ];

    return {
      entries: entries,
      stages: stages,
      totalDays: totalDays,
      methodName: method.name,
      methodId: method.method_id,
      keyMeds: keyMedsForMethod(method)
    };
  }

  // ---- Chloroquine -----------------------------------------------------------

  function generateChloroquine(method) {
    var holdDays = method.therapeutic_hold_days || 14;
    var obsDays = method.observation_days || 14;
    var transferDay = holdDays + 1;
    var totalDays = transferDay + obsDays;

    var variantId = state.params.cp_protocol || 'one_and_done_standard';
    var variant = getCpVariant(variantId);

    var entries = [];

    if (!variant) {
      entries.push({ day: 1, type: 'marker', text: 'CP variant data missing — pick a variant to generate the schedule.' });
      return { entries: entries, stages: [], totalDays: totalDays, methodName: method.name, methodId: method.method_id, keyMeds: [] };
    }

    var biofilterNote = variant.biofilter_compatible
      ? 'Biofilter OK for this variant.'
      : 'No biofilter — bare-bottom only (CP kills nitrifiers).';
    entries.push({ day: 1, type: 'structural', text: 'Bare-bottom QT. ' + biofilterNote + ' Carbon, UV all OFF. Strip all inverts.' });

    if (variant.variant_id === 'starter_eod') {
      // Starter dose Day 1
      var starterMg = cpDoseMg(state.tankGal, +variant.starter_dose_mg_per_gal);
      entries.push({
        day: 1,
        type: 'dose',
        medId: 'chloroquine_phosphate',
        text: 'Dose ' + medLink('chloroquine_phosphate') + ' starter — <strong>' + formatDoseMg(starterMg) + '</strong> (' + variant.starter_dose_mg_per_gal + ' mg/gal)'
      });
      // Maintenance doses on Days 3, 5, 7, 9, 11, 13
      var maintMg = cpDoseMg(state.tankGal, +variant.maintenance_dose_mg_per_gal);
      var maintFmt = formatDoseMg(maintMg);
      (variant.maintenance_offsets_days || []).forEach(function (md, idx) {
        var last = (idx === variant.maintenance_offsets_days.length - 1);
        var t = 'Maintenance dose ' + medLink('chloroquine_phosphate') + ' — <strong>+' + maintFmt + '</strong> (' + variant.maintenance_dose_mg_per_gal + ' mg/gal)';
        if (last) t += ' <em>(final maintenance)</em>';
        entries.push({ day: md, type: 'dose', medId: 'chloroquine_phosphate', text: t });
      });
    } else {
      // One-and-done — single dose Day 1
      var doseMg = cpDoseMg(state.tankGal, +variant.dose_per_session_mg_per_gal);
      entries.push({
        day: 1,
        type: 'dose',
        medId: 'chloroquine_phosphate',
        text: 'Dose ' + medLink('chloroquine_phosphate') + ' — <strong>' + formatDoseMg(doseMg) + '</strong> (' + variant.dose_per_session_mg_per_gal + ' mg/gal, single shot)'
      });
    }

    // Praziquantel addon
    if (state.addons.praziquantel) {
      var prMg = praziDoseMg(state.tankGal);
      var prFmt = formatDoseMg(prMg);
      (method.prazi_offsets_days || [1, 8]).forEach(function (pd, i) {
        var ptext = (i === 0 ? 'Dose ' : 'Redose ') + medLink('praziquantel') + ' — <strong>' + prFmt + '</strong> (9.5 mg/gal)';
        entries.push({ day: pd, type: 'dose', medId: 'praziquantel', text: ptext });
      });
    }

    // Daily ammonia test reminders for therapeutic phase (low-frequency markers)
    entries.push({ day: 2, type: 'marker', text: 'Daily NH3 test — water-change only if NH3 > 0.25 ppm.' });
    entries.push({ day: Math.floor(holdDays / 2), type: 'marker', text: 'Continue daily NH3 testing. Watch fish state — no reliable CP test kit exists.' });

    entries.push({ day: holdDays, type: 'marker', text: 'Day ' + holdDays + ' at therapeutic — last day in QT tank.' });

    // Variant-specific warning rendered as a structural callout on Day 1
    if (variant.warnings && variant.warnings.length) {
      variant.warnings.forEach(function (w) {
        entries.push({ day: 1, type: 'warning', text: w });
      });
    }

    entries.push({ day: transferDay, type: 'structural', text: 'TRANSFER — drip-acclimate fish into a clean, fully-cycled observation tank ≥10 ft from QT.' });
    entries.push({ day: transferDay + 1, type: 'marker', text: 'Begin clean observation — no meds.' });
    entries.push({ day: totalDays, type: 'marker', text: 'End QT — fish cleared.' });

    var stages = [
      { phase: 'therapeutic',  label: 'Therapeutic Hold',  color_token: 'green', startDay: 1,                 endDay: holdDays },
      { phase: 'transfer',     label: 'Transfer',          color_token: 'slate', startDay: transferDay,       endDay: transferDay },
      { phase: 'observation',  label: 'Clean Observation', color_token: 'teal',  startDay: transferDay + 1,   endDay: totalDays }
    ];

    return {
      entries: entries,
      stages: stages,
      totalDays: totalDays,
      methodName: method.name + ' (' + variant.label + ')',
      methodId: method.method_id,
      keyMeds: keyMedsForMethod(method)
    };
  }

  // ---- HTTM ------------------------------------------------------------------

  function generateHttm(method) {
    var totalDays = method.total_days || 13;
    var transferDays = method.transfer_days || [4, 7, 10, 13];
    var praziIdx = method.prazi_transfer_indices || [0, 2];
    var entries = [];

    entries.push({ day: 1, type: 'structural', text: 'Set up Tank A and Tank B identically — bare-bottom, ~80°F. Drip-acclimate fish into <strong>Tank A</strong>.' });
    entries.push({ day: 2, type: 'marker', text: 'Observe fish in Tank A. Bleach + rinse + refill Tank B for the Day 4 transfer.' });
    entries.push({ day: 3, type: 'marker', text: 'Observe in Tank A. Confirm Tank B at ~80°F and ready.' });

    var prMg = praziDoseMg(state.tankGal);
    var prFmt = formatDoseMg(prMg);

    // Generate transfer days
    transferDays.forEach(function (tDay, idx) {
      var fromTank = (idx % 2 === 0) ? 'A' : 'B';
      var toTank   = (idx % 2 === 0) ? 'B' : 'A';
      var isFinal  = (idx === transferDays.length - 1);
      var label;
      if (isFinal) {
        label = '<strong>Transfer #' + (idx + 1) + ' (final):</strong> Tank ' + fromTank + ' → display or follow-up QT. Drain + bleach Tank ' + fromTank + '. <em>HTTM complete.</em>';
      } else {
        label = '<strong>Transfer #' + (idx + 1) + ':</strong> net fish from Tank ' + fromTank + ' → Tank ' + toTank + '. Drain + bleach Tank ' + fromTank + '.';
      }
      entries.push({ day: tDay, type: 'structural', text: label });

      // Prazi at the configured transfer indices (#1 and #3 = idx 0 and 2)
      if (praziIdx.indexOf(idx) !== -1) {
        entries.push({
          day: tDay,
          type: 'dose',
          medId: 'praziquantel',
          text: 'Dose ' + medLink('praziquantel') + ' into Tank ' + toTank + ' — <strong>' + prFmt + '</strong> (9.5 mg/gal)'
        });
      }

      // Refill the drained tank a day later (except after the final transfer)
      if (!isFinal && tDay + 1 <= totalDays) {
        entries.push({ day: tDay + 1, type: 'marker', text: 'Refill Tank ' + fromTank + ' with fresh saltwater, heat to ~80°F.' });
      }
    });

    var stages = (method.stage_overlay_template || []).map(function (s) {
      return {
        phase: s.phase, label: s.label, color_token: s.color_token,
        startDay: s.start_day, endDay: s.end_day, single_day: !!s.single_day
      };
    });

    return {
      entries: entries,
      stages: stages,
      totalDays: totalDays,
      methodName: method.name,
      methodId: method.method_id,
      keyMeds: keyMedsForMethod(method)
    };
  }

  // ============================================================================
  // KEY MEDS — consolidated meds list with data-med-sku slots
  // ============================================================================

  function keyMedsForMethod(method) {
    var ids = (method.primary_med_ids || []).slice();
    (method.optional_addons || []).forEach(function (a) {
      if (state.addons[a.addon_id]) ids.push(a.med_id);
    });
    // Dedup, preserve order
    var seen = {}, out = [];
    ids.forEach(function (id) {
      if (!seen[id]) { seen[id] = 1; out.push(id); }
    });
    return out.map(function (id) {
      var m = getMed(id);
      var info = MED_SKU_MAP[id] || { sku: id, brand: id };
      return {
        med_id: id,
        name: (m && m.name_generic) || info.brand,
        sku: info.sku
      };
    });
  }

  // ============================================================================
  // CONFLICT EVALUATION
  // ============================================================================

  function evaluateConflicts(method) {
    var fired = [];
    (method.conflict_rules || []).forEach(function (rule) {
      var hit = false;
      switch (rule.trigger) {
        case 'temp_at_or_above_27c':
          // 27°C = 80.6°F
          if (state.tempF >= 80.6) hit = true;
          break;
        // Other triggers (inverts, sensitive species, no biofilter, single QT) fire
        // unconditionally as "informational" warnings the user reads and judges.
        case 'inverts_present':
        case 'sensitive_species_present':
        case 'no_biofilter_one_and_done':
        case 'velvet_or_brook_suspected':
        case 'single_qt_only':
          hit = true;
          break;
      }
      if (hit) fired.push(rule);
    });
    return fired;
  }

  // ============================================================================
  // RENDERERS
  // ============================================================================

  function renderMethodGrid() {
    $methodGrid.innerHTML = '';
    getMethods().forEach(function (m) {
      var tile = el('button', 'fst-method-tile' + (m.is_default_recommendation ? ' fst-method-tile--default' : ''));
      tile.type = 'button';
      tile.setAttribute('data-method-id', m.method_id);
      if (state.methodId === m.method_id) tile.classList.add('is-on');

      var head = el('div', 'fst-method-head');
      head.appendChild(el('div', 'fst-method-name', m.name));
      head.appendChild(el('span', 'fst-method-badge', m.duration_badge));
      tile.appendChild(head);

      tile.appendChild(el('div', 'fst-method-tagline', m.tagline));
      tile.appendChild(el('div', 'fst-method-summary', m.indication_summary));

      tile.addEventListener('click', function () { selectMethod(m.method_id); });
      $methodGrid.appendChild(tile);
    });
  }

  function selectMethod(id) {
    state.methodId = id;
    var method = getMethod(id);
    if (!method) return;

    // Reset params + addons to defaults for the chosen method
    state.params = {};
    (method.configurable_params || []).forEach(function (p) {
      state.params[p.param_id] = p.default;
    });
    state.addons = {};
    (method.optional_addons || []).forEach(function (a) {
      state.addons[a.addon_id] = !!a.default_checked;
    });

    state.lastSchedule = null;
    renderMethodGrid();
    renderConfigPanel();
    renderOutput(); // clear it
  }

  function renderConfigPanel() {
    $configPanel.innerHTML = '';
    if (!state.methodId) {
      $configPanel.style.display = 'none';
      return;
    }
    var method = getMethod(state.methodId);
    if (!method) return;
    $configPanel.style.display = '';

    // Header
    var head = el('div', 'fst-config-head');
    head.appendChild(el('div', 'fst-config-title', method.name));
    head.appendChild(el('div', 'fst-config-tag', method.duration_badge + ' · ' + (method.not_reef_safe ? 'Not reef safe' : 'Reef safe')));
    $configPanel.appendChild(head);

    // Configurable params
    (method.configurable_params || []).forEach(function (p) {
      $configPanel.appendChild(renderParamToggle(method, p));
    });

    // Optional addons
    if (method.optional_addons && method.optional_addons.length) {
      var addonWrap = el('div', 'fst-config-addons');
      addonWrap.appendChild(el('div', 'fst-config-label', 'Add-ons'));
      method.optional_addons.forEach(function (a) {
        var row = el('label', 'fst-addon-row');
        var input = el('input');
        input.type = 'checkbox';
        input.checked = !!state.addons[a.addon_id];
        input.addEventListener('change', function () {
          state.addons[a.addon_id] = input.checked;
        });
        row.appendChild(input);
        row.appendChild(el('span', 'fst-addon-label', a.label));
        addonWrap.appendChild(row);
      });
      $configPanel.appendChild(addonWrap);
    }

    // Soft conflicts
    var fired = evaluateConflicts(method);
    if (fired.length) {
      var cwrap = el('div', 'fst-conflicts');
      fired.forEach(function (c) {
        var div = el('div', 'fst-conflict fst-conflict--' + (c.severity || 'warn'));
        div.innerHTML = '<span class="fst-conflict-icon">⚠</span><div class="fst-conflict-text">' + c.message + '</div>';
        cwrap.appendChild(div);
      });
      $configPanel.appendChild(cwrap);
    }

    // Generate button
    var btnRow = el('div', 'fst-generate-row');
    var btn = el('button', 'fst-generate-btn', 'Generate Schedule');
    btn.type = 'button';
    btn.id = 'fst-generate';
    btn.addEventListener('click', handleGenerate);
    btnRow.appendChild(btn);
    $configPanel.appendChild(btnRow);
  }

  function renderParamToggle(method, paramDef) {
    var wrap = el('div', 'fst-param');
    wrap.appendChild(el('div', 'fst-config-label', paramDef.label));

    var options = paramDef.options;
    if (paramDef.type === 'pill_toggle_from_med_variants') {
      // Build options from the source med's dosing_variants
      var src = getMed(paramDef.source_med_id);
      var variants = (src && src.dosing_variants) || [];
      options = variants.map(function (v) {
        return { value: v.variant_id, label: v.label, sublabel: v.sublabel };
      });
    }

    var tabs = el('div', 'fst-pills');
    (options || []).forEach(function (opt) {
      var tab = el('button', 'fst-pill' + (state.params[paramDef.param_id] === opt.value ? ' is-on' : ''));
      tab.type = 'button';
      tab.innerHTML = opt.label + '<small>' + (opt.sublabel || '') + '</small>';
      tab.addEventListener('click', function () {
        state.params[paramDef.param_id] = opt.value;
        renderConfigPanel();
      });
      tabs.appendChild(tab);
    });
    wrap.appendChild(tabs);

    if (paramDef.help_text) {
      wrap.appendChild(el('div', 'fst-param-help', paramDef.help_text));
    }
    return wrap;
  }

  function handleGenerate() {
    var method = getMethod(state.methodId);
    if (!method) return;
    state.lastSchedule = generateSchedule(method);
    renderOutput();
    // Scroll output into view
    if ($output) {
      var rect = $output.getBoundingClientRect();
      window.scrollBy({ top: rect.top - 80, behavior: 'smooth' });
    }
  }

  // ============================================================================
  // DATE HELPERS — drive Day labels, range labels, and ICS dates from startDate
  // ============================================================================

  function dayDate(dayNum) {
    var start = getStartDate();
    return new Date(start.getTime() + (dayNum - 1) * 86400000);
  }

  function fmtShort(d, opts) {
    try { return d.toLocaleDateString('en-US', opts); }
    catch (e) { return d.toDateString(); }
  }

  function formatDayLabel(dayNum) {
    var d = dayDate(dayNum);
    var weekday = fmtShort(d, { weekday: 'short' });
    var month   = fmtShort(d, { month: 'short' });
    return 'Day ' + dayNum + ' (' + weekday + ', ' + month + ' ' + d.getDate() + ')';
  }

  function formatRangeLabel(startDay, endDay) {
    var d1 = dayDate(startDay);
    var d2 = dayDate(endDay);
    var w1 = fmtShort(d1, { weekday: 'short' });
    var w2 = fmtShort(d2, { weekday: 'short' });
    var m1 = fmtShort(d1, { month: 'short' });
    var m2 = fmtShort(d2, { month: 'short' });
    return 'Days ' + startDay + '–' + endDay
         + ' (' + w1 + ' ' + m1 + ' ' + d1.getDate()
         + ' — ' + w2 + ' ' + m2 + ' ' + d2.getDate() + ')';
  }

  function formatHeaderDateRange(totalDays) {
    var d1 = dayDate(1);
    var d2 = dayDate(totalDays);
    var w1 = fmtShort(d1, { weekday: 'short' });
    var w2 = fmtShort(d2, { weekday: 'short' });
    var m1 = fmtShort(d1, { month: 'short' });
    var m2 = fmtShort(d2, { month: 'short' });
    return 'Day 1 (' + w1 + ' ' + m1 + ' ' + d1.getDate() + ')'
         + ' → Day ' + totalDays + ' (' + w2 + ' ' + m2 + ' ' + d2.getDate() + ')';
  }

  // ============================================================================
  // VISUAL DAY BUILDER — collapses runs of empty days into range cards
  //   - empty = no dose/water_change/structural entries (markers don't count)
  //   - first day of every stage stays as its own card (phase boundary marker)
  //   - ranges never cross a phase boundary
  //   - single-day empty stays as own card (range needs ≥2 days)
  // ============================================================================

  function buildVisualDays(s) {
    var byDay = {};
    s.entries.forEach(function (e) {
      if (!byDay[e.day]) byDay[e.day] = [];
      byDay[e.day].push(e);
    });

    function isActive(day) {
      var actions = byDay[day] || [];
      for (var i = 0; i < actions.length; i++) {
        var t = actions[i].type;
        if (t === 'dose' || t === 'water_change' || t === 'structural') return true;
      }
      return false;
    }

    function stageOf(day) {
      for (var i = 0; i < s.stages.length; i++) {
        if (day >= s.stages[i].startDay && day <= s.stages[i].endDay) return s.stages[i];
      }
      return null;
    }

    // The last day of each stage often carries a transition marker
    // ("Day 14 at therapeutic — last day in QT tank", "End QT — fish cleared")
    // and stays as its own card. Stage-start days collapse normally based on
    // their action content — phase boundaries between stages always break runs.
    function isStageEnd(day) {
      var st = stageOf(day);
      return !!st && st.endDay === day;
    }

    var result = [];
    var d = 1;
    while (d <= s.totalDays) {
      var stage = stageOf(d);
      var stageHasCollapse = stage && stage.collapsed_range_message;

      if (isActive(d) || isStageEnd(d) || !stageHasCollapse) {
        result.push({ kind: 'day', day: d, stage: stage, actions: byDay[d] || [] });
        d++;
        continue;
      }

      // Try to collapse a run of empty days within this stage, never crossing
      // into the stage's last day (which always stands alone).
      var runStart = d, runEnd = d;
      while (runEnd + 1 <= s.totalDays) {
        var next = runEnd + 1;
        var nextStage = stageOf(next);
        if (nextStage !== stage) break;     // phase boundary breaks
        if (isStageEnd(next)) break;        // stage-end always own card
        if (isActive(next)) break;
        runEnd++;
      }
      if (runEnd === runStart) {
        // Single-day empty stays as its own card — ranges need ≥2 days
        result.push({ kind: 'day', day: runStart, stage: stage, actions: byDay[runStart] || [] });
        d = runStart + 1;
      } else {
        result.push({ kind: 'range', startDay: runStart, endDay: runEnd, stage: stage });
        d = runEnd + 1;
      }
    }
    return result;
  }

  // ============================================================================
  // OUTPUT RENDER — Method header → Meds + Gear → Reminders → Day list → Refs
  // ============================================================================

  function renderOutput() {
    $output.innerHTML = '';
    var s = state.lastSchedule;
    if (!s) {
      $output.style.display = 'none';
      // Hide actions footer too (until a schedule exists)
      var act = document.getElementById('fst-actions');
      if (act) act.style.display = 'none';
      if ($medList) $medList.style.display = 'none';
      return;
    }
    $output.style.display = '';
    var method = getMethod(state.methodId);

    // 1. Method header with date range
    var head = el('div', 'fst-output-head');
    head.appendChild(el('div', 'fst-output-title', s.methodName));
    head.appendChild(el('div', 'fst-output-meta', s.totalDays + '-day course · ' + state.tankGal + ' gal'));
    head.appendChild(el('div', 'fst-output-daterange', formatHeaderDateRange(s.totalDays)));
    $output.appendChild(head);

    // 2. Meds for this protocol + required gear (inside output)
    $output.appendChild(renderMedsBlock(s.keyMeds, method));

    // 3. Daily reminders (collapsible, expanded by default)
    if (method && method.daily_reminders && method.daily_reminders.length) {
      $output.appendChild(renderRemindersBlock(method));
    }

    // 4. Day-by-day section
    var dayLabel = el('div', 'fst-section-label', 'Day-by-day');
    dayLabel.classList.add('fst-section-label--inline');
    $output.appendChild(dayLabel);

    var visual = buildVisualDays(s);
    var daysWrap = el('div', 'fst-days');
    visual.forEach(function (item) {
      daysWrap.appendChild(item.kind === 'range' ? renderRangeCard(item) : renderDayCard(item));
    });
    $output.appendChild(daysWrap);

    // 5. References
    if (method && method.references && method.references.length) {
      var refs = el('div', 'fst-references');
      refs.appendChild(el('div', 'fst-reminders-title', 'References'));
      var ul2 = el('ul');
      method.references.forEach(function (r) {
        var li = el('li');
        var a = el('a', '', r.label);
        a.href = r.url; a.target = '_blank'; a.rel = 'noopener';
        li.appendChild(a);
        ul2.appendChild(li);
      });
      refs.appendChild(ul2);
      $output.appendChild(refs);
    }

    // Show action footer (Print/Save buttons live there)
    var actionsRow = document.getElementById('fst-actions');
    if (actionsRow) actionsRow.style.display = '';

    // Hide the now-redundant external meds-list slot (we render inside output now)
    if ($medList) $medList.style.display = 'none';
  }

  function renderDayCard(item) {
    var stage = item.stage;
    var card = el('div', 'fst-day fst-day--' + (stage ? stage.color_token : 'none'));
    var band = el('div', 'fst-day-band');
    band.appendChild(el('span', 'fst-day-num', formatDayLabel(item.day)));
    if (stage) band.appendChild(el('span', 'fst-day-stage', stage.label));
    card.appendChild(band);

    var actionList = el('div', 'fst-day-actions');
    var actions = item.actions || [];
    if (!actions.length) {
      actionList.appendChild(el('div', 'fst-day-action fst-day-action--obs', 'No action required this day.'));
    } else {
      actions.forEach(function (a) {
        var line = el('div', 'fst-day-action fst-day-action--' + a.type);
        if (a.type === 'warning') {
          line.innerHTML = '<span class="fst-icon">⚠</span><span>' + a.text + '</span>';
        } else if (a.type === 'water_change') {
          line.innerHTML = '<span class="fst-icon fst-icon--water">~</span><span>' + a.text + '</span>';
        } else if (a.type === 'dose') {
          line.innerHTML = '<span class="fst-icon fst-icon--dose">●</span><span>' + a.text + '</span>';
        } else if (a.type === 'structural') {
          line.innerHTML = '<span class="fst-icon fst-icon--struct">▶</span><span>' + a.text + '</span>';
        } else {
          line.innerHTML = '<span class="fst-icon">·</span><span>' + a.text + '</span>';
        }
        actionList.appendChild(line);
      });
    }
    card.appendChild(actionList);
    return card;
  }

  function renderRangeCard(item) {
    var stage = item.stage;
    var card = el('div', 'fst-day fst-day--range fst-day--' + (stage ? stage.color_token : 'none'));
    var band = el('div', 'fst-day-band');
    band.appendChild(el('span', 'fst-day-num', formatRangeLabel(item.startDay, item.endDay)));
    if (stage) band.appendChild(el('span', 'fst-day-stage', stage.label));
    card.appendChild(band);

    var msg = (stage && stage.collapsed_range_message) || 'No action required these days.';
    var body = el('div', 'fst-day-actions');
    var line = el('div', 'fst-day-action fst-day-action--range');
    line.innerHTML = '<span class="fst-icon">·</span><span>' + msg + '</span>';
    body.appendChild(line);
    card.appendChild(body);
    return card;
  }

  function renderMedsBlock(keyMeds, method) {
    var wrap = el('section', 'fst-medsblock');
    wrap.appendChild(el('div', 'fst-medsblock-title', 'Meds for this protocol'));

    if (keyMeds && keyMeds.length) {
      var ul = el('ul', 'fst-medlist');
      keyMeds.forEach(function (m) {
        var li = el('li');
        var a = el('a', 'fst-med-link');
        a.setAttribute('data-med-sku', m.sku);
        a.setAttribute('data-brand-name', m.name);
        a.href = '#';
        a.setAttribute('aria-label', 'Buy ' + m.name + ' at FisHotel');
        a.textContent = m.name;
        li.appendChild(a);
        ul.appendChild(li);
      });
      wrap.appendChild(ul);
    }

    if (method && method.required_gear && method.required_gear.length) {
      var gear = el('div', 'fst-gear');
      gear.appendChild(el('div', 'fst-gear-label', 'Required gear'));
      var gul = el('ul', 'fst-gear-list');
      method.required_gear.forEach(function (g) {
        var li = el('li');
        li.innerHTML = '<span class="fst-gear-item">' + g.label + '</span>'
                     + (g.note ? '<span class="fst-gear-note"> — ' + g.note + '</span>' : '');
        gul.appendChild(li);
      });
      gear.appendChild(gul);
      wrap.appendChild(gear);
    }
    return wrap;
  }

  function renderRemindersBlock(method) {
    var details = document.createElement('details');
    details.className = 'fst-reminders';
    details.open = true;

    var summary = document.createElement('summary');
    summary.className = 'fst-reminders-summary';
    summary.textContent = 'Daily reminders';
    details.appendChild(summary);

    var ul = el('ul', 'fst-reminders-list');
    method.daily_reminders.forEach(function (txt) { ul.appendChild(el('li', '', txt)); });
    details.appendChild(ul);
    return details;
  }

  // ============================================================================
  // PRINT + ICS
  // ============================================================================

  function handlePrint() {
    if (!state.lastSchedule) { alert('Generate a schedule first.'); return; }
    window.print();
  }

  function pad2(n) { return (n < 10 ? '0' : '') + n; }

  /** ICS DATE form: YYYYMMDD (no time). */
  function icsDate(date) {
    return date.getFullYear().toString() + pad2(date.getMonth() + 1) + pad2(date.getDate());
  }

  function icsDateTimeUtc(date) {
    return date.getUTCFullYear() + pad2(date.getUTCMonth() + 1) + pad2(date.getUTCDate())
         + 'T' + pad2(date.getUTCHours()) + pad2(date.getUTCMinutes()) + pad2(date.getUTCSeconds()) + 'Z';
  }

  function icsEscape(text) {
    if (!text) return '';
    return String(text).replace(/[\\;,]/g, function (ch) { return '\\' + ch; }).replace(/\r?\n/g, '\\n');
  }

  /** RFC 5545 line folding at 75 octets — required for strict iCal compliance. */
  function icsFold(line) {
    var out = '';
    var bytes = unescape(encodeURIComponent(line)); // approximate octet count
    var i = 0;
    var first = true;
    while (i < bytes.length) {
      var chunk = bytes.substr(i, first ? 75 : 74);
      out += (first ? '' : '\r\n ') + decodeUtf8(chunk);
      i += chunk.length;
      first = false;
    }
    return out;
  }

  function decodeUtf8(s) {
    try { return decodeURIComponent(escape(s)); } catch (e) { return s; }
  }

  function stripHtml(html) {
    return String(html || '').replace(/<[^>]*>/g, '').replace(/\s+/g, ' ').trim();
  }

  function getStartDate() {
    if (state.startDate) {
      var parts = state.startDate.split('-');
      if (parts.length === 3) {
        return new Date(+parts[0], +parts[1] - 1, +parts[2]);
      }
    }
    var now = new Date();
    return new Date(now.getFullYear(), now.getMonth(), now.getDate());
  }

  function handleIcs() {
    var s = state.lastSchedule;
    if (!s) { alert('Generate a schedule first.'); return; }

    var start = getStartDate();
    var dayMs = 86400000;
    var visual = buildVisualDays(s);

    var lines = [];
    lines.push('BEGIN:VCALENDAR');
    lines.push('VERSION:2.0');
    lines.push('PRODID:-//FisHotel//QT Scheduler//EN');
    lines.push('CALSCALE:GREGORIAN');

    var now = new Date();
    var nowStamp = icsDateTimeUtc(now);
    var epoch = Date.now();

    visual.forEach(function (item) {
      if (item.kind === 'range') {
        // Multi-day VEVENT: DTEND is exclusive per RFC 5545, so day after last
        var startDate = new Date(start.getTime() + (item.startDay - 1) * dayMs);
        var endDate   = new Date(start.getTime() + (item.endDay)       * dayMs);
        var phaseLabel = (item.stage && item.stage.label) || 'Observation';
        var summary = s.methodName + ' — Days ' + item.startDay + '–' + item.endDay + ': ' + phaseLabel;
        var description = (item.stage && item.stage.collapsed_range_message)
          || 'No action required these days.';

        lines.push('BEGIN:VEVENT');
        lines.push('UID:fishotel-qt-' + s.methodId + '-range' + item.startDay + '-' + item.endDay + '-' + epoch + '@fishotel.com');
        lines.push('DTSTAMP:' + nowStamp);
        lines.push('DTSTART;VALUE=DATE:' + icsDate(startDate));
        lines.push('DTEND;VALUE=DATE:'   + icsDate(endDate));
        lines.push(icsFold('SUMMARY:'     + icsEscape(summary)));
        lines.push(icsFold('DESCRIPTION:' + icsEscape(description)));
        lines.push('LOCATION:FisHotel QT');
        lines.push('CATEGORIES:FisHotel,Quarantine');
        lines.push('END:VEVENT');
        return;
      }

      // Single day
      var d = item.day;
      var dayD = new Date(start.getTime() + (d - 1) * dayMs);
      var dayEnd = new Date(dayD.getTime() + dayMs);
      var actions = item.actions || [];
      var headlineEntry = actions.filter(function (a) { return a.type === 'dose' || a.type === 'structural'; })[0]
                        || actions[0];
      var headline = headlineEntry ? stripHtml(headlineEntry.text) : 'Observation';
      if (headline.length > 70) headline = headline.slice(0, 67) + '...';

      var desc = actions.length
        ? actions.map(function (a) { return '• ' + stripHtml(a.text); }).join('\n')
        : 'No action required this day.';

      var summary2 = s.methodName + ' — Day ' + d + ': ' + headline;

      lines.push('BEGIN:VEVENT');
      lines.push('UID:fishotel-qt-' + s.methodId + '-day' + d + '-' + epoch + '@fishotel.com');
      lines.push('DTSTAMP:' + nowStamp);
      lines.push('DTSTART;VALUE=DATE:' + icsDate(dayD));
      lines.push('DTEND;VALUE=DATE:'   + icsDate(dayEnd));
      lines.push(icsFold('SUMMARY:'     + icsEscape(summary2)));
      lines.push(icsFold('DESCRIPTION:' + icsEscape(desc)));
      lines.push('LOCATION:FisHotel QT');
      lines.push('CATEGORIES:FisHotel,Quarantine');
      lines.push('END:VEVENT');
    });

    lines.push('END:VCALENDAR');

    var blob = new Blob([lines.join('\r\n')], { type: 'text/calendar' });
    var url = URL.createObjectURL(blob);
    var a = document.createElement('a');
    a.href = url;
    a.download = 'qt-schedule-' + s.methodId + '-' + state.tankGal + 'gal.ics';
    document.body.appendChild(a); a.click(); document.body.removeChild(a);
    URL.revokeObjectURL(url);
  }

  // ============================================================================
  // BOOT
  // ============================================================================

  function todayIso() {
    var d = new Date();
    return d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate());
  }

  document.addEventListener('DOMContentLoaded', function () {
    $tankSlider     = document.getElementById('fst-tank');
    $tankText       = document.getElementById('fst-tank-text');
    $tempText       = document.getElementById('fst-temp');
    $methodGrid     = document.getElementById('fst-method-grid');
    $configPanel    = document.getElementById('fst-config-panel');
    $output         = document.getElementById('fst-output');
    $printBtn       = document.getElementById('fst-print');
    $icsBtn         = document.getElementById('fst-ics');
    $startDateInput = document.getElementById('fst-start-date');
    $medList        = document.getElementById('fst-medlist-wrap');

    if (!window.FISHOTEL_QT_METHODS || !window.FISHOTEL_MEDS) {
      var root = document.getElementById('fst-root');
      if (root) root.innerHTML = '<div class="fst-error">Scheduler data failed to load. Reload the page or contact FisHotel.</div>';
      return;
    }

    state.tankGal = +($tankSlider && $tankSlider.value) || 30;
    if ($startDateInput) {
      // Default to today (local TZ, YYYY-MM-DD)
      $startDateInput.value = todayIso();
      state.startDate = todayIso();
      $startDateInput.addEventListener('change', function () {
        var v = $startDateInput.value;
        if (!v) {
          // Defend against manual clear — snap back to today
          v = todayIso();
          $startDateInput.value = v;
        }
        state.startDate = v;
        // Re-render inline if a schedule already exists (no second Generate click required)
        if (state.lastSchedule && state.methodId) {
          renderOutput();
        }
      });
    }

    if ($tankSlider) {
      $tankSlider.addEventListener('input', function () {
        state.tankGal = +$tankSlider.value;
        if ($tankText) $tankText.value = String(state.tankGal);
        // Re-render output if a schedule already exists, so volumetric lines stay current
        if (state.lastSchedule && state.methodId) {
          state.lastSchedule = generateSchedule(getMethod(state.methodId));
          renderOutput();
        }
      });
    }
    if ($tankText) {
      $tankText.addEventListener('input', function () {
        var v = parseInt($tankText.value, 10);
        if (isNaN(v)) return;
        var min = +($tankSlider && $tankSlider.min || 3);
        var max = +($tankSlider && $tankSlider.max || 500);
        if (v < min || v > max) return;
        state.tankGal = v;
        if ($tankSlider) $tankSlider.value = String(v);
        if (state.lastSchedule && state.methodId) {
          state.lastSchedule = generateSchedule(getMethod(state.methodId));
          renderOutput();
        }
      });
      $tankText.addEventListener('blur', function () {
        var min = +($tankSlider && $tankSlider.min || 3);
        var max = +($tankSlider && $tankSlider.max || 500);
        var v = parseInt($tankText.value, 10);
        if (isNaN(v)) v = state.tankGal;
        if (v < min) v = min;
        if (v > max) v = max;
        state.tankGal = v;
        if ($tankSlider) $tankSlider.value = String(v);
        $tankText.value = String(v);
      });
    }

    if ($tempText) {
      $tempText.addEventListener('input', function () {
        var v = parseFloat($tempText.value);
        if (!isNaN(v)) {
          state.tempF = v;
          // Re-evaluate conflicts (formalin contraindication)
          if (state.methodId) renderConfigPanel();
        }
      });
    }

    if ($printBtn) $printBtn.addEventListener('click', handlePrint);
    if ($icsBtn)   $icsBtn.addEventListener('click', handleIcs);

    renderMethodGrid();
    renderConfigPanel(); // hidden until method picked
    renderOutput();      // hidden until generated
  });

})();
