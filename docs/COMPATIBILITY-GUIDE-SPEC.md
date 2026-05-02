# FisHotel Compatibility Guide — Build-a-Tank v1 Spec

**Owner:** Code · **Author:** Claude.ai · **Sign-off:** Dierks · **Target launch:** May 18, 2026  
**Repo:** Dierks27/FisHotel-Theme · branch main · **Staging:** woocommerce-1611979-6343482.cloudwaysapps.com

---

## 1. Overview

Replace the static /compatibility-guide/ page (currently just FishChart.jpg from 2020) with an interactive **build-a-tank** tool. User enters tank volume, adds fish to two zones — **My Tank** (current livestock) and **Considering** (planning) — and the tool surfaces compatibility conflicts in real time using a 40×40 category matrix, a Cirrhilabrus sub-system based on Hunter Hammond's phylogram, and tank-volume-aware verdicts. Hardcoded JSON data for v1; admin UI for editing cells comes in v2 post-launch.

---

## 2. Page Architecture

- **WP page id:** 6512 (existing) · slug /compatibility-guide/
- **Template:** page-compatibility-guide.php
- **Body class on render:** page-template-page-compatibility-guide
- **CSS:** assets/css/compatibility-guide.css — conditional enqueue via is_page_template('page-compatibility-guide.php')
- **JS:** assets/js/compatibility-guide.js — same conditional enqueue

**⚠️ Bootstrap caveat (we hit this on Contacts and Newsletter):**
The page already exists on default template. The bootstrap function MUST set _wp_page_template post meta if not already set — otherwise the conditional CSS/JS never enqueues.

```php
function fishotel_bootstrap_compat_template() {
    $page_id = 6512;
    $current = get_post_meta( $page_id, '_wp_page_template', true );
    if ( $current !== 'page-compatibility-guide.php' ) {
        update_post_meta( $page_id, '_wp_page_template', 'page-compatibility-guide.php' );
    }
}
add_action( 'after_switch_theme', 'fishotel_bootstrap_compat_template' );
add_action( 'admin_init', 'fishotel_bootstrap_compat_template' );
```

---

## 3. Page Layout (top to bottom)

### 3.1 Hero
- Eyebrow: `✦ THE COMPATIBILITY DESK ✦` (matches site brand pattern from Newsletter Gazette eyebrow)
- H1: **Build Your Reef**
- Subhead: *"Add the fish you have. Try the fish you're considering. We'll flag conflicts before you buy."*

### 3.2 Tank Volume Input
- Label: "Tank Volume (gallons)"
- Range: 20 – 500
- Default: empty with placeholder "Enter your tank volume"
- Persists to localStorage.fishotel_tank_state_v1.volume
- Helper text: *"Volume affects compatibility — bigger tanks tolerate more."*
- Optional unit toggle (gallons / liters) — defer to v2

### 3.3 Build-a-Tank Two-Column Zone
- **Desktop:** two columns side-by-side, equal width
- **Mobile (<768px):** stacked
- **Left:** "My Tank" with subtitle *"Fish you currently keep"*
- **Right:** "Considering" with subtitle *"Fish you're thinking about adding"*
- Each zone has:
  - Header with count badge (`3 fish`)
  - **+ Add Fish** primary button → opens modal
  - Vertical stack of fish cards
  - Empty state placeholder

### 3.4 Add Fish Modal
- Two tabs: **Browse** | **Search**
- Browse: 40 category cards (4-col desktop, 2-col mobile)
  - Each card: category name, genus, 1-line desc, species count
  - Click → expands species list
  - "Any species in this category" generic option
- Search: type-ahead common-name search
- Footer: zone selector (radio: "My Tank" / "Considering") + Add button
- Close: X icon, ESC key, or backdrop click

### 3.5 Fish Card
```html
<article class="fh-fish-card" data-fish-id="" data-category="" data-verdict="W">
  <span class="fh-fish-card__dot fh-fish-card__dot--w" aria-label="Caution"></span>
  <div class="fh-fish-card__body">
    <h3 class="fh-fish-card__name">Yellow Tang</h3>
    <p class="fh-fish-card__sci">Zebrasoma flavescens</p>
    <div class="fh-fish-card__meta">
      <span class="fh-fish-card__cat">Tangs — Zebrasoma</span>
      <span class="fh-fish-card__size">75g+</span>
    </div>
  </div>
  <button class="fh-fish-card__remove" aria-label="Remove">×</button>
</article>
```

Status dot = WORST verdict from any conflict involving this fish:
- 🟢 --c Compatible (no issues)
- 🟡 --w Watch / Caution
- 🔵 --o Order matters
- 🟠 --1 Same-genus / Single only
- 🔴 --n Not Recommended

### 3.6 Compatibility Panel
- Desktop: sticky sidebar
- Mobile: collapsible bottom drawer
- Header: "Conflicts (N)"
- Each row: severity icon, Fish A × Fish B pair, verdict label, note
- Sort: severity desc (N → 1 → O → W; never C)
- Empty state: "All clear! Your stocking plan looks compatible."

### 3.7 Sample Tanks (v1 = manual, v2 = inventory-driven)
- Header: "Tested Stocking Plans"
- 3–5 preset tanks in horizontal carousel
- "Try This Plan" replaces user's My Tank
- v1 presets in assets/data/sample-tanks.json

### 3.8 Full Matrix View (collapsible)
- Toggle: "Show Full Compatibility Matrix"
- 40×40 grid with colored cells
- Cell hover → tooltip with verdict + note

### 3.9 Footer
- Verdict legend (5 colored swatches)
- Disclaimer: *"Compatibility is a guideline, not a guarantee. Tank size, individual temperament, and order of introduction affect outcomes. When in doubt, contact us."*
- Credits: *"Compatibility data informed by H. Hammond's Cirrhilabrus phylogram, Humble.Fish community contributors, and FisHotel quarantine experience."*

---

## 4. Data Layer

### 4.1 Categories — assets/data/categories.json (40 entries)
### 4.2 Compatibility Matrix — assets/data/matrix.json (40×40, full)
### 4.3 Cirrhilabrus Rule Book — assets/data/cirrhilabrus.json (12 complexes, 67 species)
### 4.4 Species DB — assets/data/species.json (~130 entries for autocomplete)
### 4.5 Tank Volume Modifier Rules

PHP function in inc/compatibility-guide-data.php:
```php
function fishotel_compat_apply_volume_modifier( $verdict, $cat_a, $cat_b, $volume ) {
    // Rule 1: < 75g — 'W' between aggressive families tightens to 'N'
    // Rule 2: 250g+ — 'N' on cross-genus tangs/angels softens to 'W'
    // Rule 3: 180g+ — 'W' on cross-complex aggressive Cirrhilabrus softens to 'C'
    // Rule 4: < 50g — most non-clownfish/goby pairings tighten one tier
}
```

---

## 5. Conflict Detection Algorithm

```
function recalculateConflicts(state):
  conflicts = []
  allFish = [...state.myTank, ...state.considering]
  for i in 0..allFish.length:
    for j in i+1..allFish.length:
      baseVerdict = matrix[fishA.category][fishB.category]
      if both Cirrhilabrus:
        innerVerdict = cirrhilabrusCheck(fishA.species, fishB.species, state.volume)
        baseVerdict = worstOf(baseVerdict.v, innerVerdict.v)
      modifiedVerdict = applyVolumeModifier(baseVerdict, ..., state.volume)
      if modifiedVerdict.v !== 'C':
        conflicts.push({fishA, fishB, verdict, note})
  sort by severity: N=4, 1=3, O=2, W=1
  for fish: statusDot = worstOf(conflicts involving fish) ?? 'C'
```

### 5.1 Cirrhilabrus Inner Check
```
function cirrhilabrusCheck(speciesA, speciesB, volume):
  Same sub-group → N
  Same complex (different sub-group) → W
  Both highly aggressive complexes → W (≥180g) or O
  One aggressive → O (aggressive added LAST)
  Different complex, both peaceful → C
```

---

## 6. Brand Styling

```css
:root {
  --fh-verdict-c: #4caf50;
  --fh-verdict-w: #d4a574;
  --fh-verdict-o: #4a90c2;
  --fh-verdict-1: #e8a87c;
  --fh-verdict-n: #c44545;
}
```

Site tokens: --fh-gold #d4a574, --fh-cream #e8dcc4, --fh-teal-black #1a2424. Headings: Playfair Display. Body: Josefin Sans.

---

## 7. JS State Management

- Vanilla JS, no framework
- Global: window.FishotelTank
- Persistence: localStorage key fishotel_tank_state_v1
- State: { volume, myTank: [...], considering: [...] }
- Debounced save (300ms)

---

## 8. WordPress Integration

### 8.1 Page Bootstrap (§2)
### 8.2 Product → Category Mapping
Custom field _fishotel_compat_category on each WC product. Editable via product edit screen meta box.
### 8.3 Settings Stub
Informational copy in FisHotel Settings; v2 brings full editor.

---

## 9. File Structure

```
fishotel-theme/
├── page-compatibility-guide.php
├── assets/
│   ├── css/compatibility-guide.css
│   ├── js/compatibility-guide.js
│   └── data/
│       ├── matrix.json
│       ├── categories.json
│       ├── cirrhilabrus.json
│       ├── species.json
│       └── sample-tanks.json
├── inc/
│   ├── compatibility-guide-bootstrap.php
│   ├── compatibility-guide-data.php
│   ├── compatibility-guide-enqueue.php
│   └── compatibility-guide-product-meta.php
└── parts/
    ├── compat-hero.php
    ├── compat-tank-volume.php
    ├── compat-tank-zones.php
    ├── compat-add-modal.php
    ├── compat-conflicts-panel.php
    ├── compat-sample-tanks.php
    └── compat-matrix-view.php
```

---

## 10. Acceptance Criteria

### 10.1 Functional
- Page renders at /compatibility-guide/ with body class page-template-page-compatibility-guide and compatibility-guide.css enqueued
- Tank volume persists across reloads
- Adding fish renders card with status dot
- Test: Yellow Tang + Purple Tang at 75g → N
- Test: Yellow Tang + Powder Blue at 125g → W; at 50g → tightens to N
- Test: 2 Lubbocki Cirrhilabrus → N
- Test: 1 Lubbocki + 1 Bathyphilus → C
- Conflict panel sorted N → 1 → O → W
- Status dot = worst verdict per fish
- Sample tanks load
- Full Matrix view renders

### 10.2 Mobile
- Stack at <768px
- Modal full-screen on mobile
- Conflict panel collapses to drawer
- Tap targets ≥ 44×44px

### 10.3 Performance
- Combined JSON data files <75KB
- recalculateConflicts() <50ms for 30-fish tanks

### 10.4 Brand
- Uses --fh-gold, --fh-cream, --fh-teal-black
- Playfair Display + Josefin Sans
- Eyebrow pattern matches Newsletter

---

## 11. Out of Scope (v2 backlog)

- Admin UI for cell-by-cell matrix editing
- Macropharyngodon (Leopard) inner sub-system
- Centropyge harem rules sub-system
- Auto-generated preset tanks from in-stock products
- User account tank-state persistence
- Share-tank URL
- Print-friendly tank diagram

---

## 12. Standing Conventions (per Dierks)

- Nothing hardcoded that affects content/copy — use wp_options or FisHotel Settings (matrix excepted in v1)
- All admin URLs route through /wp-admin/edit.php?post_type=product&page=fishotel-settings
- Cache-bust enqueues with filemtime() ?v=
- Cloudways deploy: /apps/6343482/deployment → Pull → wait for "Repository has been updated" toast

---

## 13. Build Order

1. Bootstrap + template assignment
2. PHP template skeleton + section parts
3. Data file loaders
4. CSS + brand styling
5. JS state + add/remove
6. Conflict detection
7. Cirrhilabrus inner check
8. Sample tanks
9. Full matrix view
10. Mobile responsive
11. Product meta box
12. QA pass

---

**End of spec.**
