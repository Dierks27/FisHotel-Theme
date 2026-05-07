/**
 * FisHotel QT Scheduler — method catalog (v1).
 *
 * Static literal: adding a fifth method = one new entry below; adding a ramp
 * variant = one option on configurable_params. No render-code changes.
 *
 * Per-dose math is reused from window.FISHOTEL_MEDS (medication-data-seed.json).
 * The scheduler does not duplicate variant data — for chloroquine_phosphate,
 * variants are read live from FISHOTEL_MEDS.medications[chloroquine_phosphate].dosing_variants.
 *
 * @package fishotel-theme
 */
(function () {
  'use strict';

  window.FISHOTEL_QT_METHODS = {
    $schema_version: '1.1',
    generated_at: '2026-05-07T00:00:00Z',
    methods: [

      /* ------------------------------------------------------------------
       * 5.1 Copper + Formalin QT
       * ------------------------------------------------------------------ */
      {
        method_id: 'copper_formalin_qt',
        name: 'Copper + Formalin QT',
        tagline: 'Aggressive intake — copper-class parasites + flukes',
        indication_summary: 'Aggressive intake. Ich, velvet, brook, uronema (copper) AND external flukes/monogeneans (formalin). Default 5-day ramp; 14-day therapeutic at 2.5 ppm Cu; transfer; 14-day clean observation.',
        duration_badge: '33D',
        is_default_recommendation: false,
        not_reef_safe: true,
        primary_med_ids: ['copper_power', 'formalin_37'],
        configurable_params: [
          {
            param_id: 'ramp_speed',
            label: 'Fish Sensitivity',
            help_text: 'How fast to ramp copper to 2.5 ppm. Sensitive species (anthias, dragonets, wrasses, scaleless) tolerate slower ramps better.',
            type: 'pill_toggle',
            default: 'standard',
            options: [
              { value: 'aggressive', label: 'Aggressive', sublabel: '3-day ramp', ramp_days: 3 },
              { value: 'standard',   label: 'Standard',   sublabel: '5-day ramp', ramp_days: 5 },
              { value: 'sensitive',  label: 'Sensitive',  sublabel: '7-day ramp', ramp_days: 7 }
            ]
          }
        ],
        optional_addons: [],
        formalin_dose_days_count: 10,
        therapeutic_ppm: 2.5,
        therapeutic_hold_days: 14,
        observation_days: 14,
        stage_overlay_template: [
          { phase: 'ramp',         label: 'Copper Ramp',       color_token: 'amber',
            collapsed_range_message: 'Continue copper ramp. Test daily.' },
          { phase: 'therapeutic',  label: 'Therapeutic Hold',  color_token: 'green',
            collapsed_range_message: 'Therapeutic Hold — maintain copper at 2.5 ppm. Test every 1–2 days. (Formalin window closed Day 10.)' },
          { phase: 'transfer',     label: 'Transfer',          color_token: 'slate', single_day: true,
            collapsed_range_message: null },
          { phase: 'observation',  label: 'Clean Observation', color_token: 'teal',
            collapsed_range_message: 'Clean observation — no meds. Watch for any returning symptoms.' }
        ],
        conflict_rules: [
          { trigger: 'temp_at_or_above_27c',       severity: 'block',   message: 'Tank temp ≥ 27°C — formalin is contraindicated. Reduce temperature or pick a copper-only protocol.' },
          { trigger: 'inverts_present',            severity: 'warn',    message: 'Strip all invertebrates before adding copper — copper is invert-toxic.' },
          { trigger: 'sensitive_species_present',  severity: 'warn',    message: 'Sensitive species (anthias, dragonets, wrasses, scaleless) tolerate the low end of copper better — drop target to ~2.2 ppm if any present.' }
        ],
        daily_reminders: [
          'Stagger copper and formalin doses by 2–3 hours when both are dosed on the same day. Don\'t slug them in back-to-back.',
          'Test copper with a Hanna HI702 daily during ramp; every 1–2 days during therapeutic hold.',
          'After every water change, retest copper and bump back to 2.5 ppm.',
          'Carbon, Purigen, and UV all OFF in the QT tank for the full course before transfer.',
          'Vigorous aeration is mandatory while formalin is being dosed.'
        ],
        required_gear: [
          { label: 'Hanna HI702 copper checker',   note: 'Required' },
          { label: 'Vigorous aeration / airstone', note: 'Required — formalin removes ~1 mg/L O₂ per 5 mg/L added' }
        ],
        references: [
          { label: 'Humblefish Medication Dosing Guide', url: 'https://humble.fish/community/threads/medication-dosing-guide.1124/' }
        ]
      },

      /* ------------------------------------------------------------------
       * 5.2 Copper + Metro QT — default recommendation
       * ------------------------------------------------------------------ */
      {
        method_id: 'copper_metro_qt',
        name: 'Copper + Metro QT',
        tagline: 'Routine intake — protozoans + internal flagellates',
        indication_summary: 'Routine intake. Covers ich, velvet, brook, uronema, plus internal flagellates and mild bacterial. Default Prazi add-on covers external flukes.',
        duration_badge: '33D',
        is_default_recommendation: true,
        not_reef_safe: true,
        primary_med_ids: ['copper_power', 'metronidazole'],
        configurable_params: [
          {
            param_id: 'ramp_speed',
            label: 'Fish Sensitivity',
            help_text: 'How fast to ramp copper to 2.5 ppm.',
            type: 'pill_toggle',
            default: 'standard',
            options: [
              { value: 'aggressive', label: 'Aggressive', sublabel: '3-day ramp', ramp_days: 3 },
              { value: 'standard',   label: 'Standard',   sublabel: '5-day ramp', ramp_days: 5 },
              { value: 'sensitive',  label: 'Sensitive',  sublabel: '7-day ramp', ramp_days: 7 }
            ]
          }
        ],
        optional_addons: [
          { addon_id: 'praziquantel', med_id: 'praziquantel', default_checked: true, label: 'Praziquantel (flukes)' }
        ],
        metro_dose_days: [1, 3, 5, 7, 9],
        metro_water_change_pct: 25,
        prazi_offsets_days: [2, 8],
        therapeutic_ppm: 2.5,
        therapeutic_hold_days: 14,
        observation_days: 14,
        stage_overlay_template: [
          { phase: 'ramp',         label: 'Copper Ramp',       color_token: 'amber',
            collapsed_range_message: 'Continue copper ramp. Test daily.' },
          { phase: 'therapeutic',  label: 'Therapeutic Hold',  color_token: 'green',
            collapsed_range_message: 'Therapeutic Hold — maintain copper at 2.5 ppm. Test every 1–2 days.' },
          { phase: 'transfer',     label: 'Transfer',          color_token: 'slate', single_day: true,
            collapsed_range_message: null },
          { phase: 'observation',  label: 'Clean Observation', color_token: 'teal',
            collapsed_range_message: 'Clean observation — no meds.' }
        ],
        conflict_rules: [
          { trigger: 'inverts_present',           severity: 'warn', message: 'Strip all invertebrates before adding copper — copper is invert-toxic.' },
          { trigger: 'sensitive_species_present', severity: 'warn', message: 'Sensitive species (anthias, dragonets, wrasses, scaleless) tolerate the low end of copper better — drop target to ~2.2 ppm if any present.' }
        ],
        daily_reminders: [
          'Test copper with a Hanna HI702 daily during ramp; every 1–2 days during therapeutic hold.',
          'After every 25% water change, retest copper and bump back to 2.5 ppm.',
          'Carbon, Purigen, and UV all OFF in the QT tank for the full course before transfer.',
          'Metro is photosensitive — keep tank lights dimmed during dosing.'
        ],
        required_gear: [
          { label: 'Hanna HI702 copper checker', note: 'Required' },
          { label: 'Aeration / airstone',        note: 'Required' }
        ],
        references: [
          { label: 'Humblefish Medication Dosing Guide', url: 'https://humble.fish/community/threads/medication-dosing-guide.1124/' }
        ]
      },

      /* ------------------------------------------------------------------
       * 5.3 Chloroquine Phosphate QT
       * ------------------------------------------------------------------ */
      {
        method_id: 'chloroquine_qt',
        name: 'Chloroquine Phosphate QT',
        tagline: 'Copper-incompatible setups, single-drug protocol',
        indication_summary: 'Covers ich, velvet, brook. Aggressive variant also covers Uronema. Variants drive dose math (no copper ramp). 14-day therapeutic + 14-day observation.',
        duration_badge: '28D',
        is_default_recommendation: false,
        not_reef_safe: true,
        primary_med_ids: ['chloroquine_phosphate'],
        // CP variants are read live from FISHOTEL_MEDS.medications[chloroquine_phosphate].dosing_variants
        // configurable_params declares the shape for the renderer; option values come from the seed.
        configurable_params: [
          {
            param_id: 'cp_protocol',
            label: 'Dosing Protocol',
            help_text: 'Pick the CP variant. Standard is the default; Aggressive treats Uronema; Starter+EOD is for established biofilters.',
            type: 'pill_toggle_from_med_variants',
            source_med_id: 'chloroquine_phosphate',
            default: 'one_and_done_standard'
          }
        ],
        optional_addons: [
          { addon_id: 'praziquantel', med_id: 'praziquantel', default_checked: true, label: 'Praziquantel (flukes)' }
        ],
        prazi_offsets_days: [2, 8],
        therapeutic_hold_days: 14,
        observation_days: 14,
        stage_overlay_template: [
          { phase: 'therapeutic',  label: 'Therapeutic Hold',  color_token: 'green',
            collapsed_range_message: 'Therapeutic Hold — daily ammonia testing. WC only if NH3 > 0.25 ppm; redose CP after any WC to maintain target.' },
          { phase: 'transfer',     label: 'Transfer',          color_token: 'slate', single_day: true,
            collapsed_range_message: null },
          { phase: 'observation',  label: 'Clean Observation', color_token: 'teal',
            collapsed_range_message: 'Clean observation — no meds.' }
        ],
        conflict_rules: [
          { trigger: 'inverts_present',           severity: 'warn', message: 'CP is toxic to invertebrates — strip all inverts before treatment.' },
          { trigger: 'sensitive_species_present', severity: 'warn', message: 'Sensitive species → use the One-and-Done Standard 60 mg/gal variant; the Aggressive 80 mg/gal variant causes appetite suppression and lethargy.' },
          { trigger: 'no_biofilter_one_and_done', severity: 'info', message: 'One-and-Done variants kill nitrifiers — run on a separate bare-bottom system with daily ammonia testing. Starter+EOD variant tolerates an established biofilter.' }
        ],
        daily_reminders: [
          'No reliable hobbyist CP test kit — track ammonia and visual fish state daily during the 14-day therapeutic.',
          'Carbon and UV OFF; Seachem Ammonia Alert badge gives passive monitoring.',
          'Water change only if NH3 > 0.25 ppm; for One-and-Done variants, redose CP into the replacement water to maintain concentration.',
          'CP is toxic to inverts — strip first.'
        ],
        required_gear: [
          { label: 'Seachem Ammonia Alert badge',          note: 'Recommended (no reliable hobbyist test for CP itself)' },
          { label: 'Bare-bottom QT, no biofilter media',   note: 'Required for One-and-Done variants' }
        ],
        references: [
          { label: 'Humblefish CP thread',                  url: 'https://humble.fish/community/threads/chloroquine-phosphate.16/' },
          { label: 'Humblefish CP biodegradation thread',   url: 'https://humble.fish/community/threads/new-study-on-chloroquine-degradation.8681/' },
          { label: 'Patin et al. 2021 (CP biodegradation)', url: 'https://pubmed.ncbi.nlm.nih.gov/34780861/' }
        ]
      },

      /* ------------------------------------------------------------------
       * 5.4 HTTM (Humblefish Tank Transfer Method)
       * ------------------------------------------------------------------ */
      {
        method_id: 'httm',
        name: 'HTTM (Tank Transfer Method)',
        tagline: 'Ich + flukes only, no chemicals — best for sensitive species',
        indication_summary: 'Two-tank rotation at 72-hour intervals. Bare-bottom QT, no copper, no chemical hold. Prazi at Transfers #1 and #3 covers flukes. 13 days, 4 transfers total.',
        duration_badge: '13D',
        is_default_recommendation: false,
        not_reef_safe: true,
        primary_med_ids: ['praziquantel'],
        configurable_params: [],
        optional_addons: [],
        // Day-keyed actions are explicit for HTTM (no ramp/hold model).
        transfer_days: [4, 7, 10, 13],
        prazi_transfer_indices: [0, 2], // dose Prazi at the 1st and 3rd transfers
        total_days: 13,
        stage_overlay_template: [
          { phase: 'tank_a',     label: 'Tank A',                 color_token: 'amber',  start_day: 1,  end_day: 3  },
          { phase: 'tank_b1',    label: 'Tank B (Prazi #1)',      color_token: 'green',  start_day: 4,  end_day: 6  },
          { phase: 'tank_a2',    label: 'Tank A',                 color_token: 'amber',  start_day: 7,  end_day: 9  },
          { phase: 'tank_b2',    label: 'Tank B (Prazi #2)',      color_token: 'green',  start_day: 10, end_day: 12 },
          { phase: 'final',      label: 'Final Transfer',         color_token: 'slate',  start_day: 13, end_day: 13, single_day: true }
        ],
        conflict_rules: [
          { trigger: 'velvet_or_brook_suspected', severity: 'warn', message: 'HTTM is ich + flukes only. If you suspect velvet, brook, or bacterial, pick Cu+Formalin or Chloroquine instead.' },
          { trigger: 'single_qt_only',            severity: 'warn', message: 'HTTM requires two identical tanks alternated every 72h — won\'t work with a single QT.' }
        ],
        daily_reminders: [
          'Maintain ~80°F continuously in both tanks.',
          '72-hour intervals are non-negotiable — set a calendar alarm.',
          'Bleach 10:1, rinse thoroughly, refill with fresh saltwater after every drain.',
          'Match SG and temp on the receiving tank before each transfer.'
        ],
        required_gear: [
          { label: 'Two identical bare-bottom tanks',                                  note: 'Required — Tank A and Tank B' },
          { label: 'Heaters maintaining ~80°F in both',                                note: 'Required' },
          { label: 'Bleach (10:1 dilution) for between-transfer sterilization',        note: 'Required' }
        ],
        references: [
          { label: 'Humblefish TTM thread', url: 'https://humble.fish/community/threads/tank-transfer-method-ttm.7/' }
        ]
      }
    ]
  };

})();
