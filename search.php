<?php get_header(); ?>
<div class="page-hero"><div class="page-hero__inner"><h1 class="page-hero__title"><span class="word-1">Search</span>&nbsp;<span class="word-2">Results</span></h1><p style="color:var(--fh-text-3); margin-top:8px; font-size:13px;">Results for: <?php echo esc_html(get_search_query()); ?></p></div></div>
<div style="max-width:var(--fh-max-width); margin:0 auto; padding:48px var(--fh-page-pad);">
    <?php if(have_posts()): ?>
    <div class="fh-product-grid">
        <?php while(have_posts()): the_post();
            // Only products get the fish card (which needs WC's $product
            // global and falls back to the placeholder library). Pages and
            // posts render as a plain result — title, excerpt and the real
            // featured image only — so no placeholder meta is ever written
            // to a non-product row.
            if ( 'product' === get_post_type() && function_exists( 'wc_get_product' ) ) :
                $GLOBALS['product'] = wc_get_product( get_the_ID() );
                get_template_part( 'template-parts/product/fish-card' );
            else : ?>
            <article class="fh-search-result" style="display:flex; flex-direction:column; gap:10px;">
                <?php if ( has_post_thumbnail() ) : ?>
                <a href="<?php the_permalink(); ?>" style="display:block;"><?php the_post_thumbnail( 'medium', [ 'style' => 'display:block; width:100%; height:auto;' ] ); ?></a>
                <?php endif; ?>
                <h2 style="font-family:var(--fh-serif); font-size:20px; margin:0;"><a href="<?php the_permalink(); ?>" style="color:var(--fh-text-1); text-decoration:none;"><?php the_title(); ?></a></h2>
                <div style="color:var(--fh-text-3); font-size:13px; line-height:1.6;"><?php the_excerpt(); ?></div>
            </article>
            <?php endif;
        endwhile; ?>
    </div>
    <?php else: ?><p style="color:var(--fh-text-3); text-align:center; padding:80px 0;">No results found for "<?php echo esc_html(get_search_query()); ?>".</p><?php endif; ?>
</div>
<?php get_footer(); ?>
