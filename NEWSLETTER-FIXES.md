# Newsletter — Fix Round 1

## Issues to fix:

### 1. "NEWS LETTER" heading broken — fix in `page-newsletter.php`
The h1 is rendering "NEWS LETTER" with a word break.
Change the hardcoded heading from "News Letter" or "NEWS LETTER" 
to "Newsletter" (one word).

### 2. Form only showing one field — fix in both templates
The `[newsletter_form]` shortcode is only rendering email + subscribe.
First Name and Last Name fields are missing.

Two possible fixes — try in this order:
- Switch from `[newsletter_form]` to `[newsletter]` in both files
- OR check if the Newsletter plugin has a form ID parameter:
  `[newsletter_form form="1"]`

The live site (fishotel.com) shows 3 fields: First Name, Last Name, Email.
We need to match that.

### 3. Footer newsletter — make it less invasive / more compact

Current: Big section with heading + subtitle on left, form on right — 
feels like a separate page section rather than a footer element.

Fix: Make it a slim single-row bar, more like a footer widget:

```css
/* Tighter footer newsletter */
.fh-footer-newsletter {
    padding: 32px var(--fh-page-pad, 24px);  /* was 60px */
    background: var(--fh-bg-dkr);            /* slightly darker than footer */
}
.fh-footer-newsletter__inner {
    gap: 32px;                                /* was 60px */
    align-items: center;
}
.fh-footer-newsletter__title {
    font-size: 16px;                          /* was 24px — much smaller */
    font-family: 'Montserrat', sans-serif;    /* not Roboto Slab */
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    margin: 4px 0;
}
.fh-footer-newsletter__copy p {
    font-size: 12px;
}
/* Make form inline — all fields in one row */
.fh-footer-newsletter .tnp-subscription {
    display: flex;
    flex-wrap: nowrap;
    gap: 8px;
    align-items: center;
}
```

### 4. Newsletter page — add more visual interest

The page looks sparse. Add:
- A subtle dark background panel behind the form on the right
- More padding in the content area
- The page content (`the_content()`) should come BEFORE the two-column
  layout as an intro paragraph — the WP page has description text in it

In `page-newsletter.php`, add the page content as intro text:
```php
// Show any content from the WP page itself as intro
if ( have_posts() ) {
    the_post();
    $content = get_the_content();
    if ( $content ) {
        echo '<div class="fh-newsletter-page__intro">';
        echo apply_filters( 'the_content', $content );
        echo '</div>';
    }
}
```

