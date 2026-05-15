<?php
/**
 * FisHotel — Empty Cart Template
 *
 * Page hero + a single centered card. All copy + the decorative image
 * are pulled from FisHotel Settings → Cart Page → Empty Cart, so no
 * template edits are needed to swap the wording or art.
 *
 * @package FisHotel
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_before_cart' );

$kses_inline = [ 'em' => [], 'strong' => [], 'i' => [], 'b' => [] ];

$eyebrow    = (string) FisHotel_Admin_Settings::get( 'fh_cart_hero_eyebrow' );
$title_html = (string) FisHotel_Admin_Settings::get( 'fh_cart_hero_title_html' );

$image_id  = (int)    FisHotel_Admin_Settings::get( 'fh_empty_cart_image_id' );
$headline  = (string) FisHotel_Admin_Settings::get( 'fh_empty_cart_headline_html' );
$subline   = (string) FisHotel_Admin_Settings::get( 'fh_empty_cart_subline' );
$cta_label = (string) FisHotel_Admin_Settings::get( 'fh_empty_cart_cta_label' );
$cta_url   = (string) FisHotel_Admin_Settings::get( 'fh_empty_cart_cta_url' );
$sec_label = (string) FisHotel_Admin_Settings::get( 'fh_empty_cart_secondary_link_label' );
$sec_url   = (string) FisHotel_Admin_Settings::get( 'fh_empty_cart_secondary_link_url' );

// URL fields fall back to the WC shop permalink when empty so a fresh
// install still produces working buttons without any admin setup.
if ( $cta_url === '' ) {
	$cta_url = (string) wc_get_page_permalink( 'shop' );
}
if ( $sec_url === '' ) {
	$sec_url = (string) wc_get_page_permalink( 'shop' );
}
?>
<div class="page-hero fh-cart-hero">
	<div class="page-hero__inner">
		<nav class="page-hero__breadcrumb">
			<a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Home', 'fishotel' ); ?></a>
			<span>·</span>
			<span><?php esc_html_e( 'Your Cart', 'fishotel' ); ?></span>
		</nav>
		<div class="fh-cart-hero__center">
			<div class="fh-cart-hero__eyebrow"><?php echo esc_html( $eyebrow ); ?></div>
			<h1 class="fh-cart-hero__title"><?php echo wp_kses( $title_html, $kses_inline ); ?></h1>
			<div class="fh-cart-hero__rule"></div>
		</div>
	</div>
</div>

<div class="fh-cart-wrap">
	<div class="fh-cart-card fh-cart-empty">
		<?php if ( $image_id ) : ?>
		<div class="fh-cart-empty__image">
			<?php echo wp_get_attachment_image( $image_id, 'medium', false, [ 'alt' => '' ] ); ?>
		</div>
		<?php endif; ?>

		<?php if ( $headline !== '' ) : ?>
		<h2 class="fh-cart-empty__headline"><?php echo wp_kses( $headline, $kses_inline ); ?></h2>
		<?php endif; ?>

		<?php if ( $subline !== '' ) : ?>
		<p class="fh-cart-empty__subline"><?php echo esc_html( $subline ); ?></p>
		<?php endif; ?>

		<?php if ( $cta_url !== '' && $cta_label !== '' ) : ?>
		<a href="<?php echo esc_url( $cta_url ); ?>" class="fh-btn fh-btn--gold fh-cart-empty__cta">
			<?php echo esc_html( $cta_label ); ?> →
		</a>
		<?php endif; ?>

		<?php if ( $sec_url !== '' && $sec_label !== '' ) : ?>
		<a href="<?php echo esc_url( $sec_url ); ?>" class="fh-cart-empty__secondary">
			<?php echo esc_html( $sec_label ); ?>
		</a>
		<?php endif; ?>

		<?php
		/* Plugin compatibility — let recently-viewed widgets, etc. inject
		   their content here as they would on the default WC template. */
		do_action( 'woocommerce_cart_is_empty' );
		?>
	</div>
</div>

<?php do_action( 'woocommerce_after_cart' ); ?>
