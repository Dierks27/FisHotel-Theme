<?php
/**
 * Template Name: Newsletter
 * FisHotel — Newsletter Page
 * @package FisHotel
 */
get_header(); ?>

<div class="page-hero">
    <div class="page-hero__inner">
        <nav class="page-hero__breadcrumb" aria-label="Breadcrumb">
            <a href="<?php echo esc_url( home_url('/') ); ?>">Home</a>
            <span>/</span>
            <span style="color:var(--fh-text-2)">Newsletter</span>
        </nav>
        <span class="fh-eyebrow" style="margin-bottom:8px; display:block;">Stay Connected</span>
        <h1 class="page-hero__title">
            <span class="word-1">News</span>&nbsp;<span class="word-2">letter</span>
        </h1>
        <p style="color:var(--fh-text-3); font-size:14px; margin-top:10px;">Be first to know when new fish clear quarantine.</p>
    </div>
</div>

<div class="fh-newsletter-page">
    <div class="fh-newsletter-page__inner">

        <div class="fh-newsletter-page__copy">
            <p class="fh-newsletter-page__intro">We keep our newsletter fun and useful — no spam, no filler. Just the good stuff for fellow reefers.</p>

            <ul class="fh-newsletter-page__benefits">
                <li>24 hours (or more) heads up when fish go live on the website</li>
                <li>Exclusive deals and drawings</li>
                <li>Fun fish content</li>
            </ul>

            <p class="fh-newsletter-page__trust">We will NOT sell any data. We will not bug you with nonsense.</p>
        </div>

        <div class="fh-newsletter-page__form">
            <h3 class="fh-newsletter-page__form-title">Sign Up for Our Newsletter</h3>
            <?php echo do_shortcode('[newsletter_form]'); ?>
            <p class="fh-newsletter-page__thanks">Thank you for supporting small business!</p>
        </div>

    </div>
</div>

<?php get_footer(); ?>
