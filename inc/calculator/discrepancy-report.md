# FisHotel Medication Calculator — Schema v1.3 Discrepancy Report

**Project:** fishotel-calculator bath-protocol extension
**Schema:** v1.2 → v1.3
**Date:** 2026-04-18
**Author:** this session (Claude Opus 4.7)
**Primary sources consulted:**
- Humblefish Medication Dosing Guide + linked per-drug threads (canonical Humblefish dosing)
- Noga, E.J. *Fish Disease: Diagnosis and Treatment*, 2nd edition, Wiley-Blackwell, 2010 — Chapters 16 ("General Concepts in Therapy") and 17 ("Pharmacopoeia")

**Source rules applied:**
- Every Noga data point is paraphrased. No verbatim quotation.
- Every Noga data point carries a `verification_source_citation` field naming book, edition, chapter, drug entry, book page, and PDF page.
- Where Noga and Humblefish conflict, Jeff adjudicated. Adjudicated outcomes are implemented in v1.3 exactly as specified.
- Where only one source has usable data, the gap is flagged in both the med record's `source_note` and in this report.
- Jeff's empirical FisHotel values (e.g., Copper Power 1.73 ml/gal) override both external sources and are preserved unchanged from v1.2.

---

## 1. Adjudicated discrepancies (Jeff-resolved)

### 1.1 Enrofloxacin — IMPLEMENTED AS STANDARD / AGGRESSIVE TIERS

**Humblefish:** 9.5–19 mg/gal, single 5-hour bath.
**Noga:** 2.5–5 mg/L (≡ 9.5–19 mg/gal), **5 days** with 50–75% water change between treatment intervals. Noga labels this a "Bath" in his water-borne formulations taxonomy, but the duration is days, not hours — functionally a prolonged therapeutic immersion.

**Jeff's adjudication:** both values are clinically valid; expose both as tiers.

**Implementation in v1.3:**
- `enrofloxacin.treatment_type = "both"`
- `enrofloxacin.bath_protocol.tiers[0]` = Standard (Humblefish): 9.5–19 mg/gal × 300 min single session
- `enrofloxacin.bath_protocol.tiers[1]` = Aggressive (Noga): 9.5–19 mg/gal × 5 days with 50–75% water change between doses
- `enrofloxacin.bath_protocol.default_tier = "standard"`
- `tier_note` on Aggressive tier explains the multi-day therapeutic immersion vs. single-session dip distinction
- Calendar export enabled for both tiers

**Noga citation on the Aggressive tier:** Chapter 17 "Pharmacopoeia", Enrofloxacin (Baytril) entry, water-borne formulations, "Bath" formulation 1a. Cites Lewbart et al. 1997. Book p.378 (PDF p.397).

---

### 1.2 Hydrogen Peroxide — KEPT AS-IS

**Humblefish:** 20 ml/gal of 3% H2O2 for 30 min, saltwater only. Single bath protocol.
**Noga:** multiple concentration × time combinations, none matching Humblefish cleanly. Closest Noga options: 38 ml/gal of 3% × 10–15 min for tropical-fish ectoparasites (~300 ppm), 75 ppm × 30 min specifically for *Amyloodinium*, 570 ppm × 4 min single-shot, and an 18-hour sodium-percarbonate variant for monogeneans.

**Jeff's adjudication:** retain Humblefish dose as single protocol. No Noga variants. No *Amyloodinium*-specific mode.

**Implementation in v1.3:**
- `hydrogen_peroxide_3.treatment_type = "bath"`
- Single-tier `bath_protocol`: 20 ml of 3% per gal × 30 min, saltwater only
- Noga material pulled in **only as safety context** on the single tier:
  - Warning that many marine species tolerate peroxide poorly
  - Gill-damage overdose can take up to 24 hours to manifest — monitor for a full day afterward
  - Toxicity rises with temperature and with smaller fish
  - Aeration note: Noga's point that H2O2 decays to oxygen so strict aeration is not required for oxygenation, but default bath safety (aeration for any bath > 1 min) still applies for circulation and monitoring
- `verification_source_citation`: Noga Chapter 17, Hydrogen Peroxide entry. Book p.401 (PDF p.420).

---

### 1.3 Methylene Blue — SINGLE STANDARD PROTOCOL (Humblefish authoritative)

**Humblefish:** saltwater bath at 1 tsp (5 ml) of 2.303% MB per 5 gal × 30 min. Separately: QT prolonged at 1 tsp per 10 gal every other day × 10 days.
**Noga:** **no saltwater bath protocol at all**. Noga documents MB only as a freshwater prolonged immersion for aquarium-fish eggs (2 mg/L, alternate days, up to 3 total) and for freshwater ectoparasites (1–3 mg/L prolonged). Noga specifically warns against prolonged MB in any system with biological filtration because MB is toxic to nitrifying bacteria.

**Jeff's adjudication:** Humblefish saltwater bath is authoritative. No intensity tiers.

**Implementation in v1.3:**
- `methylene_blue.treatment_type = "both"` (flat dose fields for prolonged stay untouched; bath_protocol added for the short bath)
- Single-tier `bath_protocol`: 5 ml of 2.303% MB per 5 gal × 30 min, saltwater
- `source_note` on the tier explicitly documents the Noga gap: "Noga gives no saltwater bath protocol for MB… The saltwater bath concentration in this record is Humblefish-only per Jeff's adjudication."
- `verification_source_citation`: Noga Chapter 17, Methylene Blue entry. Book p.406 (PDF p.425). Consulted for safety context only.

---

### 1.4 Nitrofuracin Green — IMPLEMENTED AS STANDARD / AGGRESSIVE TIERS

**Humblefish (and @Dierks empirical):** 100 mg/gal × 30 min.
**Noga (via nitrofurazone proxy):** 100 mg/L (≡ 380 mg/gal) × 30 min.

Note: NFG is a branded combination product (nitrofurazone + sulfathiazole sodium + methylene blue on a NaCl carrier). NFG itself is not in Noga. Nitrofurazone is NFG's principal antibacterial constituent and is used here as the closest available proxy — this proxy relationship is flagged in both the `source_note` on the Aggressive tier and the `verification_source_citation`.

**Jeff's adjudication:** expose both values as intensity tiers, matching the Copper sensitivity toggle pattern already in the calculator.

**Implementation in v1.3:**
- `nitrofuracin_green.treatment_type = "both"`
- `bath_protocol.tiers[0]` = Standard (Jeff / Humblefish): 100 mg/gal × 30 min
- `bath_protocol.tiers[1]` = Aggressive (Noga): 380 mg/gal × 30 min
- `bath_protocol.default_tier = "standard"`
- `heightened_warning` on Aggressive tier: "approximately 3.8× the Standard tier (~4× informally). Reserve for severe or life-threatening bacterial presentations where the Standard tier has failed or is clearly insufficient. Lower abort threshold — any early distress signs mean end the bath immediately."
- **Noga citation on Aggressive tier:** Chapter 17 "Pharmacopoeia", Nitrofurazone (Furacyn) entry, water-borne formulations, "Bath" formulation 1a. Book p.381 (PDF p.400).

---

## 2. Noga gaps — Humblefish-only meds

### 2.1 Ciprofloxacin — NOT IN NOGA

Noga covers enrofloxacin, sarafloxacin, flumequine, oxolinic acid, and nalidixic acid from the fluoroquinolone/quinolone family, but does **not** have a standalone ciprofloxacin entry. Noga notes only that in some species (e.g. red pacu), enrofloxacin is metabolized to ciprofloxacin in vivo — enrofloxacin section, book p.378 (PDF p.397).

**Result:** The Cipro `bath_protocol` is Humblefish-only. `source = "humblefish_only"`. Documented explicitly on the tier's `verification_source_citation`.

---

### 2.2 Acriflavine — described but no bath concentration

Noga describes acriflavine as a mixture of euflavine and proflavine, notes it is potentially mutagenic and an irritant, and is skeptical of its clinical usefulness because of widespread bacterial resistance and the availability of more effective alternatives. Noga also flags that high acriflavine doses may inhibit normal swim-bladder inflation in developing fry (Sanabria et al. 2009). Noga entry: book p.376 (PDF p.395).

Noga does **not** provide a numeric bath concentration for acriflavine.

**Result:** Humblefish Ruby Reef Rally protocol (1 tsp per gallon × 90 min) stands alone. `source = "humblefish_only_noga_gap"`. Noga safety context (photosensitivity, resistance concerns, fry swim-bladder warning) is recorded on the tier via the `source_note` field.

---

## 3. Mandatory v1.3 schema additions (implemented)

### 3.1 Root-level `default_bath_safety`

Added at the root of the JSON, alongside `$schema_version`, `generated_at`, `primary_generic_source`, `notes`, and `medications`.

Contains four sub-objects:

1. `abort_criteria` — Noga's five universal abort signs (excitability, jumping attempts, depression, loss of equilibrium, listing to one side), the required immediate action (net out into clean aerated water, don't wait), followup observation guidance, a note that bath toxicity is most common with antiseptic-class drugs. Citation: Noga Chapter 16, "Bath Method in a Small Volume of Water" framework, Sample Calculation step 3, book p.362 (PDF p.381).
2. `aeration_requirement` — mandatory vigorous aeration for any bath > 1 minute. Threshold: `threshold_minutes: 1`. Rationale paragraph included. Citation: Noga Chapter 16, book p.362 (PDF p.381).
3. `default_species_sensitivity` — scaleless-fish warning text, inheritance semantics (`suppress_default_scaleless_sensitivity: true` reserved as per-med opt-out with a justifying source note). Citation: Noga Chapter 16, introductory water-borne-treatment material, book p.358 (PDF p.377), and reinforced in the Nitrofurazone entry at book p.381 (PDF p.400).
4. `bioassay_recommendation` — bioassay a small number of individuals before treating a group when species response is unknown. Citation: Noga Chapter 16, book p.361–362 (PDF p.380–381).

Every `bath_protocol.tiers[].abort_criteria_ref` and `species_sensitivity_ref` field is set to `"inherits_default_bath_safety"` / `"inherits_default_scaleless_sensitivity"` unless the tier has a specific supplement (e.g., Nitrofuracin Green Aggressive tier uses `"inherits_default_bath_safety_with_heightened_vigilance"`).

---

### 3.2 Formalin `temperature_contraindication_celsius: 27`

Added to `formalin_37` at the med level (not inside `bath_protocol`, since the contraindication applies to all uses of formalin including the existing prolonged immersion). Accompanying fields:
- `temperature_contraindication_note` — the amber-warning display text Jeff specified
- `temperature_contraindication_source_citation` — Noga Chapter 17, Formalin entry, book p.399–400 (PDF p.418–419)

Additionally, inside `formalin_37.bath_protocol.tiers[0]`:
- `temperature_warning` — finer-grained Noga data: above 21°C warmwater / 10°C coldwater, do not exceed 0.63 ml/gal; above 27°C contraindicated entirely
- `contraindications` array — stressed fish, open ulcers, elasmobranchs without bioassay, soft/acidic water

---

### 3.3 Default scaleless-fish sensitivity callout on every `bath_protocol`

Every bath-capable tier has `species_sensitivity_ref: "inherits_default_scaleless_sensitivity"`. Several tiers additionally set `species_sensitivity_supplement` with med-specific callouts (e.g., elasmobranchs for formalin, anthias/dragonets/wrasses for enrofloxacin aggressive, smaller fish for peroxide).

Per-med suppression mechanism: a bath_protocol may set `suppress_default_scaleless_sensitivity: true` only with a justifying `source_note`. No meds in v1.3 use this opt-out.

---

## 4. Schema changes summary — exactly what is new in v1.3 vs v1.2

### Root level

- `$schema_version`: `"1.2"` → `"1.3"`
- `generated_at`: refreshed to current UTC timestamp
- `notes`: v1.2 notes preserved verbatim; v1.3 changelog entry appended describing every change below
- `default_bath_safety`: **NEW** nested object with 4 sub-objects (`abort_criteria`, `aeration_requirement`, `default_species_sensitivity`, `bioassay_recommendation`) and their Noga citations
- `primary_generic_source`: unchanged
- `medications`: same 26 entries in the same order; each entry modified per below

### Per-medication (all 26)

- `treatment_type`: **NEW** field on every med, values `"prolonged"` | `"bath"` | `"both"`
  - `"bath"`: ciprofloxacin, hydrogen_peroxide_3, fenbendazole (3 meds)
  - `"both"`: enrofloxacin, nitrofuracin_green, acriflavine, formalin_37, methylene_blue (5 meds)
  - `"prolonged"`: the remaining 18 meds
- Every other v1.2 field on every med preserved byte-for-byte (including `dose_pure_low_mg_per_gal`, `dose_pure_high_mg_per_gal`, `frequency_hours`, `duration_days`, `water_change_pct_before_dose`, `sensitivity_species`, `special_notes_generic`, `verification_source_urls`, `brand_equivalents`, `fishotel_default`, `manufacturer_label_dose`, `measure_weight_mg`, `active_ingredient_pct`, etc.)

### Per-medication on the 8 bath-capable meds only

- `bath_protocol`: **NEW** nested object. Common structure:
  - `tiers`: array (length 1 for single-tier meds, length 2 for Enrofloxacin and Nitrofuracin Green)
  - Each tier has: `tier_id`, `tier_label`, `concentration_value` (or `concentration_value_low`/`_high` / `_range`), `concentration_unit`, `duration_minutes`, `repeat_schedule`, `sessions_total`, `recovery_instructions`, `aeration_required`, `abort_criteria_ref`, `indication`, `species_sensitivity_ref`, `source`, `verification_source_citation`
  - Optional tier fields where applicable: `duration_range_minutes`, `duration_days`, `water_change_between_doses_pct_range`, `concentration_metric_equivalent`, `concentration_equivalent_drops_per_gal`, `water_type`, `aeration_note`, `abort_criteria_supplement`, `species_sensitivity_supplement`, `heightened_warning`, `temperature_warning`, `contraindications`, `solubility_note`, `source_note`, `tier_note`
  - Multi-tier meds also have `default_tier` on the `bath_protocol` object
  - `calendar_export_enabled` (bool) and `calendar_event_note` (string) — present at the `bath_protocol` object level. Enabled only for multi-session series (Ciprofloxacin 7-day daily, Fenbendazole Day 1+Day 8, Enrofloxacin multi-day); disabled for single one-off dips (Peroxide, Formalin, Methylene Blue, Acriflavine, Nitrofuracin Green)
  - Optional `calendar_event_label` field on meds that have calendar export enabled

### Per-medication on formalin_37 only

- `temperature_contraindication_celsius`: **NEW** — value `27`
- `temperature_contraindication_note`: **NEW** — amber-warning text
- `temperature_contraindication_source_citation`: **NEW** — Noga page cite

### Fields NOT changed in v1.3

- No v1.2 dose fields, brand records, verification URLs, or notes were altered.
- No med was reordered.
- No med was removed or added.
- The existing top-level `sensitivity_species` arrays on each med (used by the prolonged-dose pipeline) are untouched; the new bath-context sensitivity inheritance is carried separately inside `bath_protocol.tiers[].species_sensitivity_ref`.
- The existing `fishotel_default` overrides on Copper Power and Cupramine are untouched, matching Jeff's rule that empirical FisHotel values override both external sources.

---

## 5. Stretch survey — additional Noga bath/dip protocols not yet in the schema

Meds and protocols in Noga's Pharmacopoeia that could plausibly be added as future bath-capable entries. All page numbers refer to the 2nd edition and the PDF mapping (book page + 19 = PDF page).

### 5.1 Tricaine / MS-222 (sedation, anesthesia, euthanasia)

Noga has a full protocol set. Humblefish only links out to an external calculator for this, so Noga provides the actual concentrations. Book p.399–400 (PDF p.418–419). Suggested future `med_id: tricaine_ms222`.

- **Sedation** (Use No. 1, "Bath/prolonged immersion"): ~10–40 mg/L (~38–150 mg/gal). Ceiling unless crowded: 100 mg/L for salmonids, 250 mg/L for warmwater species.
- **Anesthesia** (Use No. 2, "Bath"): ~50–250 mg/L (~190–950 mg/gal). An optimal concentration typically induces anesthesia within 60 seconds. For large fish: 1 g/L solution sprayed onto the gills via aerosol pump.
- **Euthanasia** (Use No. 3): anesthetic dose, hold for at least 10 minutes after breathing stops.
- Critical safety requirements: buffer with sodium bicarbonate at 2:1 wt:wt bicarb-to-tricaine in low-alkalinity water (<50 mg/L as CaCO3); unbuffered tricaine causes metabolic acidosis and severe skin/eye damage. Stock solution 100 mg/mL. Replace monthly or store frozen (light-unstable). Max fish density ~80 g/L (300 g/gal). Tricaine has a narrower safety margin than quinaldine sulfate.

### 5.2 Oxolinic acid bath

Book p.382 (PDF p.401). 95 mg/gal (25 mg/L) × 15 min, twice daily × 3 days. Noga cites Austin et al. 1982 (turbot vibriosis). Hobbyist aquarium use appears to be rare. Candidate for "prolonged" category with a bath supplement if added.

### 5.3 Nifurpirinol (Furanace) bath

Book p.381 (PDF p.400). 3.8–7.2 mg/gal (1–2 mg/L) × 5 min to 6 hours. Very wide latitude. Noga cites 5 minutes of 2 mg/L on marine fish at 10°C (Pearse et al. 1974). Nitrofuran class.

### 5.4 Furazolidone prolonged immersion

Book p.380 (PDF p.399). 3.8–38 mg/gal (1–10 mg/L) prolonged, minimum 24 hours. Nitrofuran class — carcinogenic, illegal for food fish in US/EU. Useful if Jeff wants to expand broad-spectrum antibacterial bath options.

### 5.5 Mebendazole

Book p.406–407 (PDF p.425–426). Bath: 380 mg/gal (100 mg/L) × 10 min for monogeneans (Székely and Molnár 1987). Prolonged: 3.8 mg/gal (1 mg/L) × 24 hours. Noga notes considerable species variation: *Pseudodactylogyrus* susceptible at 1 mg/L, *Dactylogyrus vastator* resistant to >2 mg/L.

### 5.6 Formalin + malachite green (Leteux-Meyer mixture)

Book p.400 (PDF p.419). Prolonged immersion for *Ich* specifically: 25 ppm formalin + 0.10 mg/L malachite green, every other day × 3 treatments. Documented as synergistic. Separate entry from formalin alone. Malachite green carries its own toxicity profile (toxic to tetras/catfish/loaches, phytotoxic, carcinogenic).

### 5.7 Freshwater dip (for marine fish)

Book p.401 (PDF p.420). 3–15 minutes in dechlorinated freshwater. Remove immediately if stressed. Indefinitely repeatable weekly. For *Caligus elongatus* on euryhaline marine fish: 20 minutes full freshwater. Useful as a named protocol (Jeff already has a version in the Fenbendazole thread but freshwater dips on their own aren't in the schema).

### 5.8 Sodium percarbonate (18-hour H2O2 release)

Book p.401 (PDF p.420). 304 mg/gal (80 mg/L) × 18 hours for monogeneans (Buchmann and Kristensson 2003, on *Gyrodactylus derjavini* in rainbow trout). Slower-release H2O2 alternative — potentially gentler on fish than a direct peroxide bath. Freshwater-validated only.

### 5.9 Malachite green (freshwater-only)

Book p.405–406 (PDF p.424–425). Multiple protocols: 50–60 mg/L × 10–30 seconds dip; 1–2 mg/L × 30–60 min bath; 0.10 mg/L prolonged × 3 treatments 3 days apart. Phytotoxic, carcinogenic, photolabile, toxic to tetras/catfish/loaches, centrarchid-sensitive. Freshwater-only — unlikely to be a FisHotel priority given the marine focus.

### 5.10 Toltrazuril (microsporidiosis bath)

Book p.417 (PDF p.436). 19–76 mg/gal (5–20 mg/L) × 1–4 hours, every 2 days × 6 days, for *Glugea anomala* (Schmahl et al. 1990). Specialized; likely low hobbyist relevance.

---

## 6. Do-not-touch list

- The JS renderer (`fishotel-calculator.js`) — data-only task, per spec.
- The schema's existing flat prolonged-dose fields on any med — untouched.
- Copper Power 1.73 ml/gal and Cupramine ramp `fishotel_default` overrides — untouched.
- Every Humblefish URL and DailyMed URL in `verification_source_urls` — untouched.

---

## 7. Deliverables

1. **`medication-data-seed.json`** — full v1.3 JSON, merged. 26 meds, schema version bumped, every v1.2 field preserved byte-for-byte, bath additions layered on top.
2. **`discrepancy-report.md`** — this document.
