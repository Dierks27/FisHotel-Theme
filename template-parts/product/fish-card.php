<?php
/**
 * FisHotel — shop loop product card.
 *
 * Single source of truth for the archive product card. Rendered inside a
 * have_posts() loop (archive-product.php) AND by the Load More AJAX handler
 * (inc/shop-load-more.php) so appended cards are byte-identical to the
 * server-rendered grid. Relies on the loop globals ($post + WC's $product)
 * that the_post() sets up.
 *
 * @package FisHotel
 */
defined( 'ABSPATH' ) || exit;

global $product;
if ( ! $product instanceof WC_Product ) {
    $product = wc_get_product( get_the_ID() );
}
if ( ! $product instanceof WC_Product ) {
    return;
}

$pid = $product->get_id();

// Stock badge — same states + copy as the PDP badge (fishotel_stock_badge_label)
// so a card and its product page never disagree. This replaces the old
// qt_stage status pill + "Days QT" chip, which read hotel-data keys that
// FisHotel_Hotel_Data no longer defines and therefore never rendered.
// Variable parents only use WC's synced stock status (in stock / sold
// out): summing the variations would wc_get_product() every child of
// every card (the Medications archive is all-variable, 50 per page) and
// "Only 3 left" spread across three sizes misleads anyway. The PDP shows
// the per-size count once a size is picked.
$card_in_stock = $product->is_in_stock();
if ( $product->is_type( 'variable' ) ) {
    $card_max = $card_in_stock ? -1 : 0;
} else {
    $card_max = (int) $product->get_max_purchase_quantity();
}
$stock_badge = function_exists( 'fishotel_stock_badge_label' )
    ? fishotel_stock_badge_label( $card_in_stock, $card_max )
    : [ 'state' => '', 'text' => '' ];
// Not purchasable (no price, or an Amazon-mode med — amazon_is_not_purchasable
// filters it false) → no badge. The PDP shows "Currently unavailable" or the
// Amazon panel for these, so a green "Ready to Ship" pill would contradict it.
if ( ! $product->is_purchasable() ) {
    $stock_badge = [ 'state' => '', 'text' => '' ];
}

// Comma-separated product_cat slug list — the Medications dual-axis
// filter JS reads this off each card to decide visibility. get_the_terms()
// reads the object-term cache WP_Query already primed for the loop;
// wp_get_post_terms() ran a fresh query per card (N+1).
$card_terms     = get_the_terms( $pid, 'product_cat' );
$card_cat_slugs = ( $card_terms && ! is_wp_error( $card_terms ) ) ? wp_list_pluck( $card_terms, 'slug' ) : [];
$card_cat_attr  = $card_cat_slugs ? esc_attr( implode( ',', $card_cat_slugs ) ) : '';

$cs_release_ts = function_exists( 'fishotel_cs_release_ts' ) ? fishotel_cs_release_ts( $pid ) : 0;
?>
<div class="fh-fish-card-wrap product" data-product-id="<?php echo esc_attr( $pid ); ?>" data-fh-cats="<?php echo $card_cat_attr; ?>">
    <?php do_action( 'woocommerce_before_shop_loop_item' ); ?>
    <a href="<?php the_permalink(); ?>" class="fh-fish-card">
        <div class="fh-fish-card__image">
            <?php fishotel_product_thumbnail( $pid, 'fishotel-product-card' ); ?>
            <?php do_action( 'woocommerce_before_shop_loop_item_title' ); ?>
            <?php fishotel_cs_render_card_badge( $pid ); ?>
            <?php if ( $stock_badge['text'] !== '' && ! $cs_release_ts ) : ?>
            <span class="fh-fish-card__stock" data-state="<?php echo esc_attr( $stock_badge['state'] ); ?>">
                <?php echo esc_html( $stock_badge['text'] ); ?>
            </span>
            <?php endif; ?>
        </div>
        <div class="fh-fish-card__body">
            <div class="fh-fish-card__name"><?php the_title(); ?></div>
            <?php do_action( 'woocommerce_after_shop_loop_item_title' ); ?>
            <div class="fh-fish-card__latin"><?php echo wp_kses_post( $product->get_short_description() ); ?></div>
            <div class="fh-fish-card__footer">
                <?php if ( $cs_release_ts ) : ?>
                    <?php fishotel_cs_render_card_line( $pid ); ?>
                <?php else : ?>
                    <span class="fh-fish-card__price"><?php echo $product->get_price_html(); ?></span>
                <?php endif; ?>
            </div>
        </div>
    </a>
    <?php do_action( 'woocommerce_after_shop_loop_item' ); ?>
</div>
