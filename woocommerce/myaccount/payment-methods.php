<?php
/**
 * FisHotel — Billing (saved payment methods).
 *
 * @package FisHotel
 */

defined( 'ABSPATH' ) || exit;

$saved_methods = wc_get_customer_saved_methods_list( get_current_user_id() );
$has_methods   = (bool) $saved_methods;
$types         = wc_get_account_payment_methods_types();
$add_url       = wc_get_endpoint_url( 'add-payment-method' );

do_action( 'woocommerce_before_account_payment_methods', $has_methods );
?>

<?php if ( $has_methods ) : ?>

	<div class="fh-payment-methods">
		<table class="fh-orders-table woocommerce-MyAccount-paymentMethods account-payment-methods-table">
			<thead>
				<tr>
					<th class="col-method"><?php esc_html_e( 'CARD', 'fishotel' ); ?></th>
					<th class="col-expires"><?php esc_html_e( 'EXPIRES', 'fishotel' ); ?></th>
					<th class="col-actions"></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $saved_methods as $type => $methods ) : ?>
					<?php foreach ( $methods as $method ) : ?>
						<tr class="payment-method fh-orders-row<?php echo ! empty( $method['is_default'] ) ? ' is-default' : ''; ?>">
							<td class="col-method" data-title="<?php esc_attr_e( 'Card', 'fishotel' ); ?>">
								<span class="fh-pm-number">
									<?php
									if ( ! empty( $method['method']['last4'] ) ) {
										printf(
											/* translators: 1: card type, 2: last 4 digits */
											esc_html__( '%1$s •••• %2$s', 'fishotel' ),
											esc_html( wc_get_credit_card_type_label( $method['method']['brand'] ) ),
											esc_html( $method['method']['last4'] )
										);
									} else {
										echo esc_html( wc_get_credit_card_type_label( $method['method']['brand'] ) );
									}
									?>
								</span>
								<?php if ( ! empty( $method['is_default'] ) ) : ?>
									<span class="fh-pm-default"><?php esc_html_e( 'DEFAULT', 'fishotel' ); ?></span>
								<?php endif; ?>
							</td>
							<td class="col-expires" data-title="<?php esc_attr_e( 'Expires', 'fishotel' ); ?>">
								<?php
								if ( ! empty( $method['expires'] ) && '&ndash;' !== $method['expires'] ) {
									/* translators: %s: expiry date */
									printf( esc_html__( 'Expires %s', 'fishotel' ), esc_html( $method['expires'] ) );
								}
								?>
							</td>
							<td class="col-actions">
								<?php foreach ( $method['actions'] as $key => $action ) : ?>
									<a href="<?php echo esc_url( $action['url'] ); ?>" class="fh-btn fh-btn--mini-ghost button <?php echo sanitize_html_class( $key ); ?>"><?php echo esc_html( $action['name'] ); ?></a>
								<?php endforeach; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endforeach; ?>
			</tbody>
		</table>

		<div class="fh-form-actions">
			<a class="fh-btn fh-btn--ghost" href="<?php echo esc_url( $add_url ); ?>"><?php esc_html_e( 'ADD PAYMENT METHOD →', 'fishotel' ); ?></a>
		</div>
	</div>

<?php else : ?>

	<div class="fh-card fh-empty-state">
		<span class="fh-corner fh-corner--tr" aria-hidden="true"></span>
		<span class="fh-corner fh-corner--bl" aria-hidden="true"></span>
		<p class="fh-empty-state__line"><?php echo esc_html( fishotel_account_text( 'fh_account_empty_payment_line' ) ); ?></p>
		<a class="fh-btn fh-btn--ghost" href="<?php echo esc_url( $add_url ); ?>"><?php esc_html_e( 'ADD PAYMENT METHOD →', 'fishotel' ); ?></a>
	</div>

<?php endif; ?>

<?php do_action( 'woocommerce_after_account_payment_methods', $has_methods ); ?>
