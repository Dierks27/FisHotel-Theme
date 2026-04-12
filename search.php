<?php get_header(); ?>
<div class="page-hero"><div class="page-hero__inner"><h1 class="page-hero__title"><span class="word-1">Search</span>&nbsp;<span class="word-2">Results</span></h1><p style="color:var(--fh-text-3); margin-top:8px; font-size:13px;">Results for: <?php echo esc_html(get_search_query()); ?></p></div></div>
<div style="max-width:var(--fh-max-width); margin:0 auto; padding:48px var(--fh-page-pad);">
    <?php if(have_posts()): ?>
    <div class="fh-product-grid"><?php while(have_posts()):the_post(); ?><a href="<?php the_permalink(); ?>" class="fh-fish-card"><div class="fh-fish-card__image"><?php the_post_thumbnail('fishotel-product-card'); ?></div><div class="fh-fish-card__body"><div class="fh-fish-card__name"><?php the_title(); ?></div></div></a><?php endwhile; ?></div>
    <?php else: ?><p style="color:var(--fh-text-3); text-align:center; padding:80px 0;">No results found for "<?php echo esc_html(get_search_query()); ?>".</p><?php endif; ?>
</div>
<?php get_footer(); ?>
