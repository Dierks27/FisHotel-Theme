<?php get_header(); ?>
<?php
/**
 * The cart + checkout + My Account pages render their own hero + full-width
 * layout (woocommerce/cart/*, woocommerce/checkout/*, woocommerce/myaccount/*).
 * Skip the generic page wrapper here so we don't get a duplicate page hero
 * heading AND the 800px content cap stomping on those WC pages' wider grids.
 */
$is_full_bleed_wc = ( function_exists( 'is_cart' ) && is_cart() )
	|| ( function_exists( 'is_checkout' ) && is_checkout() )
	|| ( function_exists( 'is_account_page' ) && is_account_page() );
if ( $is_full_bleed_wc ) :
	while ( have_posts() ) :
		the_post();
		the_content();
	endwhile;
else : ?>
<div class="page-hero"><div class="page-hero__inner"><nav class="page-hero__breadcrumb"><a href="<?php echo esc_url(home_url('/')); ?>">Home</a><span>/</span><span style="color:var(--fh-text-2)"><?php the_title(); ?></span></nav><h1 class="page-hero__title"><span class="word-1"><?php echo esc_html(explode(' ', get_the_title(), 2)[0]); ?></span><?php $words=explode(' ',get_the_title(),2); if(!empty($words[1])) echo '&nbsp;<span class="word-2">'.esc_html($words[1]).'</span>'; ?></h1></div></div>
<div style="max-width:var(--fh-max-width); margin:0 auto; padding:60px var(--fh-page-pad);">
    <?php while(have_posts()):the_post(); ?>
    <div class="fh-page-content" style="max-width:800px; font-size:14px; line-height:1.8; color:var(--fh-text-2);">
        <?php the_content(); ?>
    </div>
    <?php endwhile; ?>
</div>
<?php endif; ?>
<?php get_footer(); ?>
