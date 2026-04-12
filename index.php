<?php
/**
 * FisHotel Theme — index.php
 * Fallback template. WordPress requires this file.
 * All routing is handled by more specific templates.
 *
 * @package FisHotel
 */
get_header(); ?>

<main class="fh-container" style="padding: 60px 48px;">
    <?php if ( have_posts() ) :
        while ( have_posts() ) : the_post();
            the_content();
        endwhile;
    endif; ?>
</main>

<?php get_footer();
