# FisHotel Bleach-Out Calculator — Build Spec v1

**Owner:** Code · **Author:** Claude.ai · **Sign-off:** Dierks
**Repo:** Dierks27/FisHotel-Theme · branch `feature/bleach-calculator` → merge to `main`
**Staging:** woocommerce-1611979-6343482.cloudwaysapps.com

---

## 1. Overview

Standalone calculator for tank/equipment sterilization. Computes bleach dose, contact time, and sodium thiosulfate (or Prime) neutralizer for a given volume + bleach concentration + use case. Lives at `/bleach-calculator/`, listed in Tools dropdown nav. Visual identity matches the existing `/medication-dosing/` calculator — retro steampunk-pharmacy aesthetic, gold-on-near-black palette, Playfair Display headings, FisHotel hotel-metaphor voice.

Customer-requested feature. Will also be referenced from the future QT Scheduler — keep the URL stable so the scheduler can deep-link to it.

---

## 2. Page Architecture

- **WP page:** auto-created on `admin_init` via bootstrap pattern (mirror `inc/compatibility-guide-bootstrap.php`)
- **Slug:** `/bleach-calculator/`
- **Template:** `page-bleach-calculator.php`
- **Body class on render:** `page-template-page-bleach-calculator`
- **Conditional CSS/JS enqueue** with `filemtime()` versioning
- **Tools dropdown:** add menu item between Medication Dosing and Compatibility Guide (admin task — see §10)

Bootstrap function MUST set `_wp_page_template` post meta on every `admin_init`, idempotent — same durable pattern used by compat-guide / about-us / contacts / newsletter after the fix audit.

---

## 3. Visual Identity

Match existing calculator. Key tokens already defined in theme:

```
--fh-gold:       #c9963a
--fh-amber:      #d4903a
--fh-bg:         #252525
--fh-cream:      (existing)
--fh-teal-black: (existing)
```

Plus one new token:

```
--fh-bleach:     #b8d4e3   /* faded teal/blue — chlorine in dilute form */
```

Headings: Playfair Display, Georgia fallback.
Body: existing inherited stack.

**Eyebrow:** `✦ THE LAUNDRY ROOM ✦`
**H1:** `Bleach-Out Calculator`
**Subhead:** *"Tank teardown math: how much bleach, how long, and how to neutralize."*

---

## 4. Inputs (persisted to localStorage `fishotel_bleach_v1`)

### 4.1 Volume
- Number input + unit toggle (gallons / liters)
- Default: 75 gallons
- Min 1, max 1000

### 4.2 Bleach concentration (%)
- Free-input number field
- Default: 8.25, min: 1, max: 15, step: 0.25
- Helper: *"Household bleach: 3–8.25%. Liquid pool chlorine: 10–15%. Liquid sodium hypochlorite only — no granular pool shock or tablets."*

### 4.3 Use-case preset (radio cards)

| Card label | Target ppm | Contact min | Description |
|---|---|---|---|
| **Between QT Fish** *(default)* | 200 | 30 | Standard sanitize between residents |
| **Bleach Bomb** | 500 | 60 | Post-outbreak / Velvet eradication |
| **Custom** | user-input | user-input | Free fields for special situations |

Custom shows two number inputs: target ppm (50–2000) and contact minutes (5–240).

### 4.4 Neutralizer type (toggle)
- Sodium Thiosulfate *(default)*
- Seachem Prime — auto-warns if dose exceeds Prime's effective range.

---

## 5. Output Panel

Four steps in 2×2 grid on desktop:

1. **Bleach Dose** — `{X} ml` with math line.
2. **Contact Time** — `{N} min` + Start Timer (visual count-up bar, persists across reload).
3. **Neutralize** — thiosulfate g OR Prime ml, with above-range warning.
4. **Rinse** — 2–3 fresh-water rinses, test for zero ppm.

---

## 6. Hero Visual

Inline SVG: vintage apothecary bleach bottle (left) + empty tank silhouette (right), connected by pouring stream. Bottle measure-line and tank fill-line tween smoothly when preset changes.

Style: monoline strokes 1.5–2px, light cross-hatching, gold on dark teal-black, hand-drawn FisHotel Gazette feel.

---

## 7. Output Extras

- **Timeline graph** — chlorine ppm over time (rises to target, plateau, drops to 0 at neutralizer).
- **Print Schedule** — checkbox-list teardown protocol.
- **Save to Calendar (.ics)** — two events (start dose, add neutralizer + rinse) with VALARM 5 min before.

---

## 8. Hard-Coded Warnings

`THE FRONT DESK SAYS:`
1. Never bleach with livestock present. Empty tank first.
2. Ventilate.
3. Test before reusing — chlorine strip → 0 ppm.

---

## 9. JS State & Math

State (localStorage `fishotel_bleach_v1`):

```js
{
  volume: 75,
  unit: 'gallons',                 // 'gallons' | 'liters'
  concentration_pct: 8.25,
  preset: 'between_qt_fish',       // 'between_qt_fish' | 'bleach_bomb' | 'custom'
  custom_target_ppm: 200,
  custom_contact_min: 30,
  neutralizer: 'thiosulfate',      // 'thiosulfate' | 'prime'
  timer_started_at: null
}
```

Math:

```js
const L_PER_GAL = 3.785;
const SODIUM_THIOSULFATE_FACTOR = 7.4;  // mg per L per ppm chlorine

const volume_gal = state.unit === 'liters' ? state.volume / L_PER_GAL : state.volume;
const bleach_ml      = (target_ppm * volume_gal * L_PER_GAL) / (concentration_pct * 10);
const thiosulfate_g  = (target_ppm * volume_gal * L_PER_GAL * SODIUM_THIOSULFATE_FACTOR) / 1000;

const prime_multiplier =
  target_ppm <= 4   ? 1 :
  target_ppm <= 50  ? 5 :
  null;  // null = above Prime's range
const prime_ml = prime_multiplier === null ? null : volume_gal * 0.1 * prime_multiplier;
```

Round to 1 decimal (or whole numbers above 100).

---

## 10. File Structure

```
fishotel-theme/
├── page-bleach-calculator.php
├── inc/
│   ├── bleach-calculator-bootstrap.php
│   └── bleach-calculator-enqueue.php
├── parts/
│   ├── bleach-hero.php
│   ├── bleach-inputs.php
│   ├── bleach-illustration.php
│   ├── bleach-output.php
│   └── bleach-warnings.php
├── assets/
│   ├── css/bleach-calculator.css
│   └── js/bleach-calculator.js
```

### Tools-dropdown menu task

Adding the calculator to the Tools dropdown is a one-time WP-admin action (Appearance → Menus → Primary). Add a new menu item between Medication Dosing and Compatibility Guide pointing at `/bleach-calculator/`. The page is auto-created (Draft) by the bootstrap; publish it in WP-admin before the menu item goes live.

---

## 11. Acceptance Criteria

### Functional
1. `/bleach-calculator/` renders with 75 gal, 8.25%, Between QT Fish, Sodium Thiosulfate.
2. Defaults produce ~9 ml bleach, 30 min, ~5.5 g thiosulfate.
3. Bleach Bomb → ~22 ml, 60 min, ~14 g.
4. 12.5% concentration → bleach ml drops proportionally (~6 ml at 200 ppm).
5. Liter unit converts correctly.
6. Custom preset reveals ppm + contact_min fields.
7. Prime at 200 ppm → out-of-range warning replaces ml number.
8. Prime at 4 ppm → ~7.5 ml.
9. Timer fills, stop/reset works.
10. Print Schedule opens dialog with checkbox layout.
11. iCal downloads with 2 events.
12. Reload preserves inputs; running timer resumes.
13. Tools dropdown lists Bleach Calculator between Medication Dosing and Compatibility Guide.

### Visual
14. Eyebrow renders `✦ THE LAUNDRY ROOM ✦`.
15. Bottle measure-line tweens on preset change.
16. Tank fill-level tweens on preset change.
17. Timeline graph renders correctly.

### Mobile (<768px)
18. Inputs stack vertically.
19. Hero illustration shrinks to ~60%, both bottle and tank stacked.
20. Output cards single-column.
21. Tap targets ≥44×44px.

### Performance
22. Initial load <2s on staging.
23. Re-calc <50ms on input change.

---

## 12. Out of Scope (v2)

- Hydrogen peroxide, vinegar/acid calculators
- Bulk-batch mode
- Granular pool shock / pool tablets
- Real-time inventory integration

---

**End of spec.**
