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
        <h1 class="page-hero__title">Newsletter</h1>
        <p style="color:var(--fh-text-3); font-size:14px; margin-top:10px;">Be first to know when new fish clear quarantine.</p>
    </div>
</div>

<div class="fh-newsletter-page">
    <div class="fh-newsletter-page__inner">

        <?php /* Pull WP page content as intro paragraph */ ?>
        <?php if ( have_posts() ) : the_post();
            $page_content = get_the_content();
            if ( $page_content ) : ?>
            <div class="fh-newsletter-page__wp-intro">
                <?php echo apply_filters( 'the_content', $page_content ); ?>
            </div>
        <?php endif; endif; ?>

        <div class="fh-newsletter-page__columns">
            <div class="fh-newsletter-page__copy">
                <ul class="fh-newsletter-page__benefits">
                    <li>24 hours (or more) heads up when fish go live on the website</li>
                    <li>Exclusive deals and drawings</li>
                    <li>Fun fish content</li>
                </ul>

                <p class="fh-newsletter-page__trust">We will NOT sell any data. We will not bug you with nonsense.</p>
            </div>

            <div class="fh-newsletter-page__form">
                <h3 class="fh-newsletter-page__form-title">Sign Up for Our Newsletter</h3>
                <?php echo do_shortcode('[newsletter]'); ?>
                <p class="fh-newsletter-page__thanks">Thank you for supporting small business!</p>
            </div>
        </div>

    </div>
</div>

<?php get_footer(); ?>
