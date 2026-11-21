# Styling Feedback — Round 2
*CSS selector mismatches — exact class names from live HTML*

The woocommerce.css is loading correctly. The HTML structure is solid.
The ONLY problem is the CSS selectors in woocommerce.css don't match 
the actual class names in the HTML. Here is the exact mapping:

---

## EXACT CLASS NAMES IN THE HTML (use these in CSS)

### QT Certificate
```
.fh-qt-cert              — the outer panel (needs: dark bg + green left border)
.fh-qt-cert__header      — the title row
.fh-qt-cert__check       — the ✓ checkmark span (needs: green color)
.fh-qt-cert__title       — "Quarantine Complete" text (needs: green, uppercase, tracked)
.fh-qt-cert__protocol    — "14 days observation + 14 days treatment" (needs: text-1)
```

### Trust Strip
```
.fh-trust-strip          — flex container (needs: border-top hairline, flex, gap)
.fh-trust-strip__item    — each ✓ item (needs: text-2, small, Montserrat)
```
Note: the ✓ is already in the HTML text, don't add it via ::before

### Variation Buttons
```
.fh-var-btn              — each button (currently rgb(46,46,46) grey — needs design treatment)
.fh-var-btn.active       — selected state (needs gold bg)
```

### Species Section
```
.fh-species              — outer wrapper
.fh-species__prose       — currently dumping raw text (needs a proper display — see below)
```
NOTE: The spec grid was NOT implemented. fh-species__prose still 
contains raw "Scientific Name: value" text. This needs to be either:
a) Parsed with JS into a proper grid, OR
b) Claude Code needs to rebuild the PHP to output an actual <table> or grid

### Care Guide / Fish Dossier
```
.fh-careguide            — outer wrapper
.fh-careguide__blocks    — flex container for the two blocks
.fh-careguide__block     — each content block (needs: dark bg container)
.fh-careguide__block-title — "HABITAT & BEHAVIOR" etc (needs: gold, uppercase, tracked)
.fh-careguide__block-text  — body text (needs: text-2, readable line-height)
```
NOTE: Only ONE block is rendering ("HABITAT & BEHAVIOR") — Foods & Feeding 
block is missing. Also the block-text starts with ": Carnivorous..." — 
the label is bleeding into the content. PHP needs fixing.

### Related Fish ("Also in Quarantine")
```
.fh-related              — wrapper ✅ working
.fh-product-grid         — grid ✅ working  
.fh-fish-card            — each card ✅ rendering (bg: rgb(37,37,37))
.fh-fish-card__image     — image div ✅ (bg: rgb(28,28,28))
.fh-fish-card__name      — name ✅
.fh-fish-card__latin     — latin name ✅
.fh-fish-card__price     — price ✅
```
Related fish ARE rendering — Canary Blenny, Huchtii Anthias, Tenneti Tang, 
Whitetail Pygmy Angelfish all showing. 

---

## CSS TO ADD/FIX in woocommerce.css

```css
/* QT Certificate — correct selectors */
.fh-qt-cert {
    background: #1c1c1c;
    border-left: 3px solid #5aaa78;
    border-radius: 4px;
    padding: 16px 20px;
    margin-bottom: 24px;
}
.fh-qt-cert__check {
    color: #5aaa78;
    font-weight: 700;
    margin-right: 6px;
}
.fh-qt-cert__title {
    color: #5aaa78;
    font-family: 'Montserrat', sans-serif;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.15em;
    text-transform: uppercase;
}
.fh-qt-cert__protocol {
    color: #e8e4dc;
    font-size: 13px;
    line-height: 1.8;
    margin-top: 6px;
}

/* Trust Strip — correct selectors */
.fh-trust-strip {
    display: flex;
    gap: 20px;
    padding: 12px 0;
    border-top: 1px solid rgba(255,255,255,0.06);
    margin-top: 12px;
    flex-wrap: wrap;
}
.fh-trust-strip__item {
    color: #a09890;
    font-size: 11px;
    font-family: 'Montserrat', sans-serif;
    letter-spacing: 0.05em;
}

/* Variation buttons — correct selectors */
.fh-var-btn {
    background: transparent;
    border: 1px solid rgba(255,255,255,0.15);
    color: #e8e4dc;
    padding: 8px 20px;
    font-family: 'Montserrat', sans-serif;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    cursor: pointer;
    transition: all 0.2s;
    border-radius: 2px;
}
.fh-var-btn:hover,
.fh-var-btn.active {
    background: #c9963a;
    border-color: #c9963a;
    color: #1c1c1c;
}

/* Care guide blocks — correct selectors */
.fh-careguide__blocks {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
}
.fh-careguide__block {
    background: #1c1c1c;
    border-radius: 4px;
    padding: 24px;
}
.fh-careguide__block-title {
    color: #c9963a;
    font-family: 'Montserrat', sans-serif;
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 0.15em;
    text-transform: uppercase;
    margin-bottom: 12px;
}
.fh-careguide__block-text {
    color: #a09890;
    font-size: 14px;
    line-height: 1.7;
}

@media (max-width: 768px) {
    .fh-careguide__blocks { grid-template-columns: 1fr; }
    .fh-trust-strip { flex-direction: column; gap: 8px; }
}
```

---

## STILL NEEDS PHP FIX (not CSS)

1. **fh-species__prose** — still raw text dump. Need the two-column stat 
   grid built in PHP outputting an actual HTML table or dl/dt/dd structure.

2. **fh-careguide__block** — only ONE block rendering, missing Foods & Feeding. 
   Also block-text starts with ": " (colon-space) — the label is leaking 
   into the content string. Fix the PHP parsing.

