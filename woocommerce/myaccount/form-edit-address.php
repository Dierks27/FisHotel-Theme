<?php
/**
 * FisHotel — Forwarding Address edit form.
 *
 * @package FisHotel
 * @var string $load_address billing|shipping
 * @var array  $address      Address fields for the chosen type.
 */

defined( 'ABSPATH' ) || exit;

$page_title = ( 'billing' === $load_address ) ? __( 'Billing address', 'woocommerce' ) : __( 'Shipping address', 'woocommerce' );

do_action( 'woocommerce_before_edit_account_address_form' );

if ( ! $load_address ) {
	// No address type chosen — fall back to the addresses listing.
	wc_get_template( 'myaccount/my-address.php' );
	return;
}
?>

<form class="fh-account-form edit-address" method="post">

	<div class="fh-form-grid woocommerce-address-fields__field-wrapper">
		<?php
		foreach ( $address as $key => $field ) {
			// Half-width for paired name fields; full-width otherwise.
			$is_half = in_array( $key, [ $load_address . '_first_name', $load_address . '_last_name', $load_address . '_city', $load_address . '_state', $load_address . '_postcode', $load_address . '_phone' ], true );
			$field['class'] = array_merge(
				isset( $field['class'] ) ? (array) $field['class'] : [],
				[ 'fh-field', $is_half ? 'fh-field--half' : 'fh-field--full' ]
			);
			$field['input_class'] = array_merge(
				isset( $field['input_class'] ) ? (array) $field['input_class'] : [],
				[ 'fh-input' ]
			);
			woocommerce_form_field( $key, $field, wc_get_post_data_by_key( $key, $field['value'] ) );
		}
		?>
	</div>

	<?php do_action( "woocommerce_after_edit_address_form_{$load_address}" ); ?>

	<div class="fh-form-actions">
		<?php wp_nonce_field( 'woocommerce-edit_address', 'woocommerce-edit-address-nonce' ); ?>
		<button type="submit" class="fh-btn fh-btn--primary" name="save_address" value="<?php esc_attr_e( 'Save address', 'fishotel' ); ?>">
			<?php esc_html_e( 'SAVE ADDRESS', 'fishotel' ); ?>
		</button>
		<input type="hidden" name="action" value="edit_address" />
	</div>
</form>

<?php do_action( 'woocommerce_after_edit_account_address_form' ); ?>
