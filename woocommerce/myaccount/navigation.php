<?php
/**
 * FisHotel — Concierge directory (account sidebar navigation).
 *
 * Rebuilds the WooCommerce account nav as grouped sections plus a standalone
 * Check Out, each item carrying an inline-SVG icon. Endpoint items come from
 * wc_get_account_menu_items() (already relabeled by inc/account-relabels.php),
 * so plugin endpoints appear only when registered. Gift Cards is a manual link
 * to the gift-card product page (not a WC account endpoint), so it's rendered
 * directly rather than through the endpoint loop.
 *
 * @package FisHotel
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_before_account_navigation' );

$menu_items = wc_get_account_menu_items();          // slug => relabeled label
$current    = function_exists( 'fishotel_account_current_endpoint' ) ? fishotel_account_current_endpoint() : 'dashboard';

// Icon function per canonical slug.
$icons = [
	'dashboard'       => 'fishotel_icon_lobby',
	'orders'          => 'fishotel_icon_past_stays',
	'edit-account'    => 'fishotel_icon_guest_profile',
	'edit-address'    => 'fishotel_icon_forwarding_addresses',
	'payment-methods' => 'fishotel_icon_billing',
	'wallet'          => 'fishotel_icon_house_account',
	'customer-logout' => 'fishotel_icon_check_out',
];

// Grouped layout. `slugs` are WC endpoints (rendered via the endpoint loop);
// `manual` are static links (e.g. Gift Cards → a product page) rendered first.
$groups = [
	[ 'label' => __( 'YOUR STAYS', 'fishotel' ),   'slugs' => [ 'dashboard', 'orders' ] ],
	[ 'label' => __( 'YOUR PROFILE', 'fishotel' ), 'slugs' => [ 'edit-account', 'edit-address', 'payment-methods' ] ],
	[ 'label' => __( 'PERKS', 'fishotel' ),        'slugs' => [ 'wallet' ], 'manual' => [ 'gift-cards' ] ],
];

// Index registered items by canonical slug, retaining the raw slug for URLs.
$by_canonical = [];
foreach ( $menu_items as $raw_slug => $label ) {
	$canonical = fishotel_account_normalize_slug( $raw_slug );
	$by_canonical[ $canonical ] = [ 'raw' => $raw_slug, 'label' => $label ];
}

$current_user = wp_get_current_user();
$first_name   = $current_user->first_name ? $current_user->first_name : $current_user->display_name;

/**
 * Render one endpoint nav item. $canonical drives the icon + active state; the
 * raw slug (from the registered menu) builds the endpoint URL.
 */
$render_item = function ( $canonical, $entry ) use ( $icons, $current ) {
	$is_active = ( $canonical === $current ) || ( $current === 'view-order' && $canonical === 'orders' );
	$classes   = 'fh-nav-item' . ( $is_active ? ' is-active' : '' );
	$icon_fn   = $icons[ $canonical ] ?? '';
	?>
	<a href="<?php echo esc_url( wc_get_account_endpoint_url( $entry['raw'] ) ); ?>"
	   class="<?php echo esc_attr( $classes ); ?>"
	   <?php echo $is_active ? 'aria-current="page"' : ''; ?>>
		<?php if ( $icon_fn && function_exists( $icon_fn ) ) {
			echo $icon_fn(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- trusted inline SVG.
		} ?>
		<span><?php echo esc_html( $entry['label'] ); ?></span>
	</a>
	<?php
};

/**
 * Render a manual (non-endpoint) nav item. Never shows an active state because
 * the link leaves the account layout entirely.
 */
$render_manual = function ( $key ) {
	if ( $key !== 'gift-cards' ) {
		return;
	}
	$url = function_exists( 'fishotel_account_gift_cards_url' ) ? fishotel_account_gift_cards_url() : home_url( '/my-account/gift-cards/' );
	?>
	<a href="<?php echo esc_url( $url ); ?>" class="fh-nav-item">
		<?php echo fishotel_icon_gift_cards(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- trusted inline SVG. ?>
		<span><?php esc_html_e( 'Gift Cards', 'fishotel' ); ?></span>
	</a>
	<?php
};
?>
<nav class="fh-account-nav woocommerce-MyAccount-navigation" aria-label="<?php esc_attr_e( 'Account', 'fishotel' ); ?>">

	<button type="button" class="fh-account-nav__mobile-toggle" aria-expanded="false">
		<?php esc_html_e( 'CONCIERGE DIRECTORY', 'fishotel' ); ?>
		<span class="fh-account-nav__chev" aria-hidden="true"></span>
	</button>

	<div class="fh-account-nav__inner">

		<div class="fh-account-nav__head">
			<div class="fh-account-nav__eyebrow"><?php esc_html_e( 'CONCIERGE', 'fishotel' ); ?></div>
			<div class="fh-account-nav__greeting">
				<?php
				printf(
					/* translators: %s: customer first name */
					esc_html__( 'Welcome, %s', 'fishotel' ),
					'<em>' . esc_html( $first_name ) . '</em>'
				);
				?>
			</div>
		</div>

		<?php $first_group = true; ?>
		<?php foreach ( $groups as $group ) :
			$slugs   = $group['slugs'] ?? [];
			$manual  = $group['manual'] ?? [];
			$present = array_filter( $slugs, function ( $s ) use ( $by_canonical ) {
				return isset( $by_canonical[ $s ] );
			} );
			// Skip a group only when it has neither a present endpoint nor a
			// manual link.
			if ( empty( $present ) && empty( $manual ) ) {
				continue;
			}
			?>
			<?php if ( ! $first_group ) : ?>
				<div class="fh-account-nav__divider" aria-hidden="true"></div>
			<?php endif; ?>
			<?php $first_group = false; ?>

			<div class="fh-account-nav__group-label"><?php echo esc_html( $group['label'] ); ?></div>

			<?php foreach ( $manual as $key ) {
				$render_manual( $key );
			} ?>

			<?php foreach ( $slugs as $canonical ) :
				if ( ! isset( $by_canonical[ $canonical ] ) ) {
					continue;
				}
				$render_item( $canonical, $by_canonical[ $canonical ] );
			endforeach; ?>

		<?php endforeach; ?>

		<?php if ( isset( $by_canonical['customer-logout'] ) ) : ?>
			<div class="fh-account-nav__divider is-strong" aria-hidden="true"></div>
			<?php $render_item( 'customer-logout', $by_canonical['customer-logout'] ); ?>
		<?php endif; ?>

	</div>
</nav>
<?php

do_action( 'woocommerce_after_account_navigation' );
