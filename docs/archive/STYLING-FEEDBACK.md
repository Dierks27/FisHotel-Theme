# Styling Feedback — Round 1
*From Claude.ai after visual review of staging*

The functionality from the spec landed correctly — all 9 items are present. 
The problem is CSS. Everything is plain unstyled text/elements sitting on the dark background. 
Here is exactly what needs CSS treatment:

---

## 1. QT CERTIFICATE PANEL — Needs a real box

Currently: Plain text floating above the price with zero visual container.

Needs to be a proper styled panel:
```css
.fh-qt-certificate {
    background: var(--fh-bg-dk);          /* #1c1c1c */
    border-left: 3px solid #5aaa78;       /* green accent */
    border-radius: 4px;
    padding: 16px 20px;
    margin-bottom: 24px;
}
.fh-qt-certificate__title {
    color: #5aaa78;                        /* green */
    font-family: 'Montserrat', sans-serif;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.15em;
    text-transform: uppercase;
    margin-bottom: 8px;
}
.fh-qt-certificate__lines {
    color: var(--fh-text-1);              /* #e8e4dc */
    font-size: 13px;
    line-height: 1.8;
}
.fh-qt-certificate__region {
    color: var(--fh-text-2);              /* #a09890 */
    font-size: 12px;
    margin-top: 6px;
}
```

---

## 2. TRUST STRIP — Needs visual separation from the button

Currently: Plain small text inline, invisible against the background.

Needs a clear visual strip:
```css
.fh-trust-strip {
    display: flex;
    gap: 20px;
    padding: 12px 0;
    border-top: 1px solid rgba(255,255,255,0.06);
    margin-top: 12px;
}
.fh-trust-strip__item {
    color: var(--fh-text-2);
    font-size: 11px;
    font-family: 'Montserrat', sans-serif;
    letter-spacing: 0.05em;
    display: flex;
    align-items: center;
    gap: 5px;
}
.fh-trust-strip__item::before {
    content: '✓';
    color: #5aaa78;
    font-weight: 700;
}
```

---

## 3. VARIATION BUTTONS (SIZE) — Need the styled treatment

Currently: Plain grey WooCommerce default buttons.

The variation buttons (MEDIUM / LARGE) need to match the design system:
```css
.fh-variation-btn {
    background: transparent;
    border: 1px solid rgba(255,255,255,0.15);
    color: var(--fh-text-1);
    padding: 8px 20px;
    font-family: 'Montserrat', sans-serif;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    cursor: pointer;
    transition: all 0.2s;
}
.fh-variation-btn.active,
.fh-variation-btn:hover {
    background: var(--fh-gold);
    border-color: var(--fh-gold);
    color: #1c1c1c;
}
```

---

## 4. ABOUT THIS SPECIES — Stat grid needs the two-column treatment

Currently: Still rendering as `Scientific Name: value` text dump.

The grid needs actual CSS:
```css
.fh-spec-grid {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 32px;
}
.fh-spec-grid tr:nth-child(even) {
    background: rgba(255,255,255,0.02);
}
.fh-spec-grid td {
    padding: 10px 14px;
    font-size: 13px;
    line-height: 1.5;
    vertical-align: top;
}
.fh-spec-grid td:first-child {
    color: var(--fh-text-2);   /* muted label */
    font-family: 'Montserrat', sans-serif;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    width: 38%;
    white-space: nowrap;
}
.fh-spec-grid td:last-child {
    color: var(--fh-text-1);   /* warm white value */
}
```

---

## 5. FISH DOSSIER CONTENT BLOCKS — Need visual separation

Currently: Content blocks exist but blend into the background.

```css
.fh-dossier-block {
    background: var(--fh-bg-dk);
    padding: 24px;
    border-radius: 4px;
}
.fh-dossier-block__label {
    color: var(--fh-gold);
    font-family: 'Montserrat', sans-serif;
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 0.15em;
    text-transform: uppercase;
    margin-bottom: 12px;
}
.fh-dossier-block__content {
    color: var(--fh-text-2);
    font-size: 14px;
    line-height: 1.7;
}
```

---

## 6. DESCRIPTION AND FUN FACTS — Need spacing and a subtle accent

```css
.fh-species-description {
    color: var(--fh-text-2);
    font-size: 14px;
    line-height: 1.8;
    margin-bottom: 32px;
}
.fh-fun-facts {
    border-left: 2px solid var(--fh-gold);
    padding-left: 16px;
    margin-top: 24px;
}
.fh-fun-facts__label {
    color: var(--fh-gold);
    font-family: 'Montserrat', sans-serif;
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 0.15em;
    text-transform: uppercase;
    margin-bottom: 8px;
}
```

---

## Summary

All content is in the right place — the PHP/HTML work is done.
The issue is purely CSS. Every new element added in this round 
needs its styles added to `assets/css/woocommerce.css`.

The design tokens to use throughout:
```
--fh-bg:      #252525
--fh-bg-dk:   #1c1c1c  
--fh-bg-dkr:  #151515
--fh-gold:    #c9963a
--fh-gold-lt: #deb96a
--fh-text-1:  #e8e4dc   warm white
--fh-text-2:  #a09890   body grey  
--fh-green:   #5aaa78   (for QT certificate)
Font: Montserrat for all UI labels/caps, Roboto Slab for display
```
