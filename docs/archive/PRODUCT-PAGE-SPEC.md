# FisHotel Product Page — Design Spec
*For Claude Code implementation*

---

## The Core Problem
The product page looks like a generic WooCommerce store. It needs to feel like a premium quarantine service where every fish has been through a real protocol. The buyer needs to feel confident BEFORE they add to cart.

---

## 1. PURCHASE PANEL — Needs More Weight

### Current state (bad):
- Price
- Size buttons
- Add to cart
- Category/Tags

### New state (what to build):

```
STARTING FROM
$49.00

[  MEDIUM  ]  [  LARGE  ]

[  1  ▲▼ ]  [  ADD TO CART ████████████████  ]

✓ 28-day QT protocol    ✓ Live arrival guarantee    ✓ Ships Mon–Tue
```

**The three trust signals are the key addition.** They sit right below the add-to-cart button. Small text, checkmarks, separated by spacing. These are always the same for every fish — hardcoded, not editable.

The exact three lines:
- `28-day QT protocol`
- `Live arrival guarantee`  
- `Ships Mon–Tue`

---

## 2. QT CERTIFICATE PANEL — New Section

Sits **above** the purchase panel (between the fish photo/info header and the price).

### What it looks like:
```
┌─────────────────────────────────────────┐
│  ✓  QUARANTINE COMPLETE                 │
│                                         │
│  14 days observation                    │
│  + 14 days treatment                    │
│                                         │
│  Region: Indo-Pacific                   │
└─────────────────────────────────────────┘
```

Dark background panel (`--fh-bg-dk`), gold checkmark/accent, warm white text. Clean and minimal. Feels like a certificate, not a status tracker.

### Data sources:
- **"14 days observation + 14 days treatment"** — hardcoded, always true, never changes
- **Region** — pulled from the product's existing WooCommerce **tags**. Look for a tag that matches a known region list: `Indo-Pacific`, `Caribbean`, `Red Sea`, `Eastern Pacific`, `Atlantic`. If no region tag found, omit the region line entirely. Do NOT add a new field for this.

### What NOT to include:
- No progress bars
- No arrival dates
- No "Day X of 30" countdown
- No health grades
- No importer/supplier info
- No treatment logs

---

## 3. TAGS — Keep Exactly As-Is

The colored tag chips are working perfectly. Do not change them.
Current colors:
- Carnivore → amber
- Peaceful → green  
- Reef Safe → teal
- Aggressive → red
- Fish / Cardinalfish / etc → neutral grey

**Leave these alone.**

---

## 4. HOTEL DATA META BOX — Simplify

The current `hotel-data.php` meta box in WP Admin has too many fields Jeff will never fill in. 

Strip it down to just TWO fields:
1. **Region** — text field (e.g. "Indo-Pacific") — optional, shows in certificate panel if filled
2. **Notes** — textarea — optional, shows as a small note below the certificate if filled

Remove: arrival date, days in QT, QT stage dropdown, health grade, treatments repeater.

Jeff lists fish AFTER QT is complete. There is no "in progress" state. Keep it simple.

---

## 5. HOMEPAGE HEADER — Restore the Original Feel

The current nav/header feels generic. What made the original better:

- **Tagline under logo**: "WE QUARANTINE. YOU REEF." — this is already there, keep it
- **Nav left items**: Home · Our Process · Shop  (currently shows Services · Features · Shop · Contacts — wrong)
- **Nav right items**: FAQ's · About Us · Cart  (this is correct, keep it)
- The header needs more **vertical breathing room** — currently feels compressed

---

## 6. "ALSO IN QUARANTINE" SECTION — Fix It

The related fish section at the bottom of the product page exists but the fish cards aren't rendering. Debug and fix so it shows actual related products with photo, name, price, and a link. Style to match the fish card design from the shop grid.

---

## Implementation Priority Order:
1. Purchase panel trust strip (quick win, high impact)
2. QT Certificate panel (the soul of the page)
3. Hotel data meta box simplification
4. Also in Quarantine cards
5. Homepage nav items
6. Homepage header spacing

---

## Design Tokens (reference):
```
--fh-bg:      #252525
--fh-bg-dk:   #1c1c1c
--fh-bg-dkr:  #151515
--fh-gold:    #c9963a
--fh-gold-lt: #deb96a
--fh-text-1:  #e8e4dc  (warm white)
--fh-text-2:  #a09890  (body grey)
--fh-green:   #5aaa78
--fh-amber:   #d4903a
--fh-blue:    #4a9db8
```

---

## 7. "ABOUT THIS SPECIES" — Needs Proper Formatting

### Current problem:
It's a wall of text. Every field is `Bold Label: value` crammed together with no visual separation. Looks like a database dump.

### What to build instead:
A clean two-column grid of stat pills/rows for the quick specs, with the description as a proper paragraph below.

```
┌─────────────────────────────────────────────────────┐
│  About This Species                                  │
├──────────────────────┬──────────────────────────────┤
│  Scientific Name     │  Sphaeramia nematoptera       │
│  Common Names        │  Pajama Cardinalfish, ...     │
│  Max Length          │  3.3 inches (8.5 cm)          │
│  Min Tank Size       │  30 gallons                   │
│  Temperament         │  Peaceful, schooling          │
│  Reef Safe           │  ✓ Yes                        │
└──────────────────────┴──────────────────────────────┘

  [Description paragraph — full width, readable prose]

  [Fun Facts — separate small section below, maybe with a 
   subtle gold left-border accent]
```

Rules:
- Labels in `--fh-text-2` (muted grey), values in `--fh-text-1` (warm white)
- Alternating row backgrounds — very subtle, just `rgba(255,255,255,0.02)` difference
- Description and Fun Facts get their own clearly separated area below the grid
- No bold-everything formatting — let the grid structure do the work

---

## 8. FISH DOSSIER — Fix or Replace

### Current problem:
"FOODS AND FEEDING" and "Habits" look like clickable tabs but are NOT — they're just static column headers in a flat `fh-spec-table`. Clicking does nothing. It's a broken UI promise. The whole section appears as a large featureless dark box.

### Two options — pick the simpler one to implement:

**Option A (simplest):** Kill the fake tabs entirely. Replace with two clean labeled sections side by side or stacked:

```
CARE GUIDE

Foods & Feeding
[prose content from WP description field]

Habitat & Behavior  
[prose content from WP description field]
```

No tabs, no interaction — just clearly labeled content blocks with a subtle divider between them. Honest about what it is.

**Option B (interactive tabs):** Actually implement working JS tabs — click "Foods & Feeding" highlights it and shows that panel, click "Habits" switches to that panel. Standard tab pattern. Content still comes from the product description fields.

**Recommendation: Option A.** Simpler, faster, no JS required, and it won't break. The fake tab UI was the problem — remove the promise you can't keep.

### Content source:
The content for these sections comes from the WooCommerce product description. Either:
- Parse it from the existing description text (it's already structured with these labels)
- Or add two simple meta fields: "Foods & Feeding" textarea + "Habitat & Behavior" textarea on the product edit screen

---

## Summary of ALL Changes

| # | Section | Change |
|---|---------|--------|
| 1 | Purchase panel | Add 3 trust signals below Add to Cart |
| 2 | QT Certificate | New panel: "✓ QUARANTINE COMPLETE · 14 days obs + 14 days treatment · Region" |
| 3 | Hotel Data meta box | Strip to 2 fields: Region + Notes |
| 4 | Tags | Leave exactly as-is |
| 5 | Also in Quarantine | Fix fish cards rendering |
| 6 | Homepage nav | Fix left items: Home · Our Process · Shop |
| 7 | Homepage header | More vertical breathing room |
| 8 | About This Species | Two-column stat grid + prose description below |
| 9 | Fish Dossier | Remove fake tabs, replace with clean labeled content blocks |

