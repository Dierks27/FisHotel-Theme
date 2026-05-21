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

$pid   = $product->get_id();
$hotel = function_exists( 'fishotel_get_hotel_data' ) ? fishotel_get_hotel_data( $pid ) : [];
$stage = $hotel['qt_stage'] ?? '';
$days  = $hotel['days_in_qt'] ?? '';

if ( $stage === 'souvenir-shop' ) {
    $status_class = 'fh-fish-card__status--available';
} elseif ( in_array( $stage, array( 'treatment', 'checkin', 'observation' ), true ) ) {
    $status_class = 'fh-fish-card__status--qt';
} else {
    $status_class = '';
}
if ( $stage === 'souvenir-shop' )    { $status_label = 'Available'; }
elseif ( $stage === 'treatment' )    { $status_label = 'In Treatment'; }
elseif ( $stage === 'observation' )  { $status_label = 'In QT'; }
elseif ( $stage === 'checkin' )      { $status_label = 'Checked In'; }
else                                 { $status_label = ''; }

// Comma-separated product_cat slug list — the Medications dual-axis
// filter JS reads this off each card to decide visibility.
$card_cat_slugs = wp_get_post_terms( $pid, 'product_cat', [ 'fields' => 'slugs' ] );
$card_cat_attr  = ( ! is_wp_error( $card_cat_slugs ) && ! empty( $card_cat_slugs ) )
    ? esc_attr( implode( ',', $card_cat_slugs ) )
    : '';

$cs_release_ts = function_exists( 'fishotel_cs_release_ts' ) ? fishotel_cs_release_ts( $pid ) : 0;
?>
<div class="fh-fish-card-wrap product" data-fh-cats="<?php echo $card_cat_attr; ?>">
    <?php do_action( 'woocommerce_before_shop_loop_item' ); ?>
    <a href="<?php the_permalink(); ?>" class="fh-fish-card">
        <div class="fh-fish-card__image">
            <?php fishotel_product_thumbnail( $pid, 'fishotel-product-card' ); ?>
            <?php do_action( 'woocommerce_before_shop_loop_item_title' ); ?>
            <?php fishotel_cs_render_card_badge( $pid ); ?>
            <?php if ( $status_label && ! $cs_release_ts ) : ?>
            <span class="fh-fish-card__status <?php echo esc_attr( $status_class ); ?>">
                <?php echo esc_html( $status_label ); ?>
            </span>
            <?php endif; ?>
            <?php if ( $days && ! $cs_release_ts ) : ?>
            <div class="fh-fish-card__days">
                <span class="fh-fish-card__days-num"><?php echo esc_html( $days ); ?></span>
                <span class="fh-fish-card__days-label">Days QT</span>
            </div>
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
                    <?php if ( $days ) : ?>
                    <span class="fh-fish-card__qt"><?php echo esc_html( $days ); ?> Days QT</span>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </a>
    <?php do_action( 'woocommerce_after_shop_loop_item' ); ?>
</div>
