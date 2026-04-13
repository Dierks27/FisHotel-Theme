<?php
/**
 * Template Name: Newsletter
 * The FisHotel Newsletter signup page
 */
get_header();
if ( have_posts() ) : the_post(); endif;
?>

<div class="page-hero">
    <div class="page-hero__inner">
        <nav class="page-hero__breadcrumb">
            <a href="<?php echo esc_url( home_url('/') ); ?>">Home</a>
            <span>/</span>
            <span>Newsletter</span>
        </nav>
        <h1 class="page-hero__title">Newsletter</h1>
    </div>
</div>

<div class="fh-nl-page">
    <div class="fh-nl-inner">

        <!-- LEFT: Gazette image, tilted -->
        <div class="fh-nl-gazette">
            <img src="https://woocommerce-1611979-6343482.cloudwaysapps.com/wp-content/uploads/2026/04/Newpaper.png"
                 alt="The FisHotel Gazette"
                 class="fh-nl-gazette__img">
        </div>

        <!-- RIGHT: Vintage newspaper ad styled box -->
        <div class="fh-nl-ad">
            <div class="fh-nl-ad__inner">

                <!-- Masthead -->
                <div class="fh-nl-ad__masthead">
                    <div class="fh-nl-ad__rule"></div>
                    <span class="fh-nl-ad__masthead-text">✦ THE FISHOTEL GAZETTE ✦</span>
                    <div class="fh-nl-ad__rule"></div>
                </div>

                <!-- Headline -->
                <h2 class="fh-nl-ad__headline">Be First to Know</h2>
                <p class="fh-nl-ad__subhead">When new fish clear quarantine</p>

                <div class="fh-nl-ad__divider"></div>

                <!-- Benefits -->
                <ul class="fh-nl-ad__benefits">
                    <li><span class="fh-nl-ad__diamond">◆</span>24-hour advance notice when new fish go live</li>
                    <li><span class="fh-nl-ad__diamond">◆</span>Exclusive deals, drawings &amp; fish content</li>
                    <li><span class="fh-nl-ad__diamond">◆</span>No spam. No data selling. Ever.</li>
                </ul>

                <div class="fh-nl-ad__divider"></div>

                <!-- Form -->
                <div class="fh-nl-ad__form">
                    <?php echo do_shortcode('[newsletter]'); ?>
                </div>

                <!-- Footer line -->
                <p class="fh-nl-ad__thanks">Thank you for supporting small business!</p>

            </div>
        </div>

    </div>
</div>

<?php get_footer(); ?>
