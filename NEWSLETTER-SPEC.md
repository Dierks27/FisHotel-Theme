# Newsletter Integration Spec

## The Plugin
**Newsletter plugin** (already installed on staging)
- Form class: `tnp-subscription`
- Fields: First Name (`nn`), Last Name (`ns`), Email (`ne`)
- Submits to: `/?na=s`
- Shortcode: `[newsletter]`
- Shortcode with form only: `[newsletter_form]`

## Two placements needed:

---

## 1. Footer Newsletter Block

Add a newsletter signup section to `footer.php`, above the existing footer columns.

```php
<div class="fh-footer-newsletter">
    <div class="fh-footer-newsletter__inner">
        <div class="fh-footer-newsletter__copy">
            <span class="fh-eyebrow">Stay in the loop</span>
            <h2 class="fh-footer-newsletter__title">Fish Availability Updates</h2>
            <p>Be the first to know when new fish clear quarantine.</p>
        </div>
        <div class="fh-footer-newsletter__form">
            <?php echo do_shortcode('[newsletter_form]'); ?>
        </div>
    </div>
</div>
```

CSS for `.fh-footer-newsletter` in `assets/css/woocommerce.css`:
```css
.fh-footer-newsletter {
    background: var(--fh-bg-dk);
    border-top: 1px solid rgba(255,255,255,0.06);
    border-bottom: 1px solid rgba(255,255,255,0.06);
    padding: 60px var(--fh-page-pad, 24px);
}
.fh-footer-newsletter__inner {
    max-width: var(--fh-max-width, 1200px);
    margin: 0 auto;
    display: flex;
    align-items: center;
    gap: 60px;
}
.fh-footer-newsletter__copy {
    flex: 1;
}
.fh-footer-newsletter__title {
    font-family: 'Roboto Slab', serif;
    color: var(--fh-text-1);
    font-size: 24px;
    margin: 8px 0;
}
.fh-footer-newsletter__copy p {
    color: var(--fh-text-2);
    margin: 0;
}
.fh-footer-newsletter__form {
    flex: 1;
}

/* Style the Newsletter plugin form to match FisHotel theme */
.fh-footer-newsletter .tnp-subscription {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
}
.fh-footer-newsletter .tnp-field {
    flex: 1;
    min-width: 140px;
}
.fh-footer-newsletter .tnp-name,
.fh-footer-newsletter .tnp-surname,
.fh-footer-newsletter .tnp-email {
    width: 100%;
    background: var(--fh-bg-dkr);
    border: 1px solid rgba(255,255,255,0.12);
    color: var(--fh-text-1);
    padding: 10px 14px;
    font-family: 'Montserrat', sans-serif;
    font-size: 13px;
    border-radius: 2px;
}
.fh-footer-newsletter .tnp-name:focus,
.fh-footer-newsletter .tnp-surname:focus,
.fh-footer-newsletter .tnp-email:focus {
    outline: none;
    border-color: var(--fh-gold);
}
.fh-footer-newsletter .tnp-field-button {
    display: flex;
    align-items: flex-end;
}
.fh-footer-newsletter .tnp-submit {
    background: var(--fh-gold);
    color: #1c1c1c;
    border: none;
    padding: 10px 24px;
    font-family: 'Montserrat', sans-serif;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    cursor: pointer;
    border-radius: 2px;
    white-space: nowrap;
}
.fh-footer-newsletter .tnp-submit:hover {
    background: var(--fh-gold-lt);
}
.fh-footer-newsletter label {
    display: none; /* hide labels, use placeholders instead */
}
/* Add placeholders via CSS attr workaround — also set in plugin settings */
```

Also update the Newsletter plugin settings (WP Admin → Newsletter → Subscription):
- Set placeholder for First Name field: "First Name"
- Set placeholder for Last Name field: "Last Name"  
- Set placeholder for Email field: "Your Email"

---

## 2. Dedicated Newsletter Page

Create a WordPress page called "Newsletter" at `/newsletter/`.

The page template should use the standard page layout but inject 
a styled hero + the full newsletter form.

Option A (simplest): Create a WP Page with this content:
```
[newsletter]
```
With a custom page template `page-newsletter.php` that wraps it 
in a styled hero section.

Option B: Add the newsletter page as a standard WP Page with 
the shortcode in the content — theme handles the styling via CSS.

**Recommended: Option A** — create `page-newsletter.php`:
```php
<?php get_header(); ?>
<div class="fh-page-hero">
    <div class="fh-page-hero__inner">
        <span class="fh-eyebrow">Stay Connected</span>
        <h1 class="fh-page-hero__title">Newsletter</h1>
        <p class="fh-page-hero__sub">Be first to know when new fish clear quarantine.</p>
    </div>
</div>
<div class="fh-newsletter-page">
    <div class="fh-newsletter-page__inner">
        <?php while ( have_posts() ) : the_post(); ?>
            <div class="fh-newsletter-page__content">
                <?php the_content(); ?>
            </div>
        <?php endwhile; ?>
    </div>
</div>
<?php get_footer(); ?>
```

CSS for `.fh-newsletter-page` — style same as footer form but 
centered, single column, larger inputs.

---

## Nav Menu
Jeff will add "Newsletter" to the top nav manually via:
WP Admin → Appearance → Menus → add the Newsletter page

---

## Files to create/edit
- `footer.php` — add newsletter block above footer columns
- `page-newsletter.php` — new dedicated page template
- `assets/css/woocommerce.css` — Newsletter plugin form styles


---

## Newsletter Page Copy (from live site)

The page content should preserve this messaging — it's good copy:

**Headline:** Newsletter

**Body copy:**
"So we will keep our newsletter around, but we will now turn this mainly 
into a celebration and a thank you by providing more of just the fun stuff."

**Bullet benefits:**
- 24 hours (or more) heads up when fish go live on the website
- Exclusive deals and drawings
- Fun fish content

**CTA line before form:** "Sign up for our Newsletter Here:"

**Footer line after form:** "Thank You for supporting small business!"

**Trust line:** "We will NOT sell any data. We will not bug you with nonsense."

---

## Newsletter Page Design (new theme)

`page-newsletter.php` layout — top to bottom:

```
┌─────────────────────────────────────────────┐
│  [HERO]                                      │
│  STAY CONNECTED          ← gold eyebrow     │
│  Newsletter              ← big heading      │
│  Be first to know when fish clear QT        │
└─────────────────────────────────────────────┘

┌─────────────────────────────────────────────┐
│  [TWO COLUMN — max-width 900px centered]    │
│                                             │
│  LEFT: Copy block                           │
│  • 24hr heads up on new fish                │
│  • Exclusive deals & drawings               │
│  • No spam. No data selling. Ever.          │
│                                             │
│  RIGHT: The form                            │
│  [ First Name  ] [ Last Name  ]             │
│  [ Email Address              ]             │
│  [ SIGN UP NOW ← gold button  ]             │
│                                             │
│  "Thank you for supporting small business!" │
└─────────────────────────────────────────────┘
```

Use same CSS classes as the footer newsletter form where possible
so styles are shared — `.tnp-name`, `.tnp-email`, `.tnp-submit` etc.
Add a wrapper class `.fh-newsletter-page` to scope any page-specific styles.

