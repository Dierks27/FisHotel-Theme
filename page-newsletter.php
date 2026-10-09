<?php
/**
 * Template Name: Newsletter
 * The FisHotel Newsletter signup page
 */
get_header();
if ( have_posts() ) : the_post(); endif;

// Gazette hero image. Default is the live upload, built from the uploads
// base URL so a relocated uploads dir / filtered baseurl still resolves
// (the old markup hot-linked the Cloudways staging domain).
// Optional override from FisHotel Settings: an attachment ID or a URL.
$nl_upload   = wp_get_upload_dir();
$nl_hero_src = trailingslashit( $nl_upload['baseurl'] ) . '2026/04/Newpaper.png';
$nl_hero_opt = class_exists( 'FisHotel_Admin_Settings' )
    ? FisHotel_Admin_Settings::get( 'fh_newsletter_hero_image' )
    : '';
$nl_hero_opt = is_scalar( $nl_hero_opt ) ? trim( (string) $nl_hero_opt ) : '';
if ( is_numeric( $nl_hero_opt ) ) {
    // Numeric = attachment ID. '' / '0' / non-positive means "unset" (the
    // image picker posts "0" when nothing is chosen) — keep the default
    // rather than treating it as a URL.
    if ( (int) $nl_hero_opt > 0 ) {
        $nl_hero_att = wp_get_attachment_image_url( (int) $nl_hero_opt, 'large' );
        if ( $nl_hero_att ) {
            $nl_hero_src = $nl_hero_att;
        }
    }
} elseif ( $nl_hero_opt !== '' ) {
    $nl_hero_src = $nl_hero_opt;
}
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
            <img src="<?php echo esc_url( $nl_hero_src ); ?>"
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
