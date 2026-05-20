<?php
/**
 * FisHotel — My Account outer wrapper.
 *
 * Renders the shared page header on every endpoint, then the two-column
 * account shell (Concierge sidebar + endpoint content). Logged-out visitors
 * never reach this template — WooCommerce loads form-login.php directly.
 *
 * @package FisHotel
 */

defined( 'ABSPATH' ) || exit;

if ( function_exists( 'fishotel_account_render_page_header' ) ) {
	fishotel_account_render_page_header();
}
?>
<div class="fh-account-shell">

	<?php
	/**
	 * Concierge sidebar (myaccount/navigation.php).
	 */
	do_action( 'woocommerce_account_navigation' );
	?>

	<div class="woocommerce-MyAccount-content fh-account-content">
		<?php
		/**
		 * Endpoint content (dashboard.php, orders.php, form-edit-account.php, …).
		 */
		do_action( 'woocommerce_account_content' );
		?>
	</div>

</div>
