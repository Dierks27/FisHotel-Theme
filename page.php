<?php get_header(); ?>
<?php
/**
 * The cart page renders its own hero + full-width layout via
 * woocommerce/cart/cart.php (and cart-empty.php). Skip the generic page
 * wrapper here so we don't get a duplicate <h1>CART</h1> heading AND
 * the 800px content cap stomping on the cart's 1300px grid.
 */
if ( function_exists( 'is_cart' ) && is_cart() ) :
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
