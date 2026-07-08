<?php
/**
 * Gift Cards
 *
 * Shows orders on the account page.
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/myaccount/giftcards.php.
 *
 * HOWEVER, on occasion WooCommerce will need to update template files and you
 * (the theme developer) will need to copy the new files to your theme to
 * maintain compatibility. We try to do this as little as possible, but it does
 * happen. When this occurs the version of the template file will be bumped and
 * the readme will list any important changes.
 *
 * @see https://woocommerce.com/document/template-structure/
 * @package WooCommerce Gift Cards
 * @version 1.16.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

do_action( 'woocommerce_gc_before_account_giftcards', $has_giftcards ); ?>

	<h2><?php esc_html_e( 'Your Balance', 'woocommerce-gift-cards' ); ?></h2>
	<div class="woocommerce-Giftcards woocommerce-MyAccount-Giftcards-balance-amount"><?php echo wp_kses_post( wc_price( $balance ) ); ?></div>

	<form method="POST">
		<?php wp_nonce_field( 'customer_redeems_gift_card' ); ?>
		<h4><?php esc_html_e( 'Add a gift card?', 'woocommerce-gift-cards' ); ?></h4>
		<div class="woocommerce-Giftcards woocommerce-MyAccount-Giftcards-form">
			<input type="text"  class="input-text" name="wc_gc_redeem_code" placeholder="<?php esc_attr_e( 'Enter code&hellip;', 'woocommerce-gift-cards' ); ?>"/>
			<button name="wc_gc_redeem_save" value="wc_gc_redeem_save" class="<?php echo isset( $button_class ) ? esc_attr( $button_class ) : 'woocommerce-Button button'; ?>"><?php esc_html_e( 'Add to your account', 'woocommerce-gift-cards' ); ?></button>
		</div>
	</form>

	<h2><?php esc_html_e( 'Active Gift Cards', 'woocommerce-gift-cards' ); ?></h2>

	<table class="woocommerce-orders-table woocommerce-giftcards-table woocommerce-MyAccount-orders shop_table shop_table_responsive my_account_orders account-orders-table">
		<thead>
			<tr>
				<th>
					<?php esc_html_e( 'Date', 'woocommerce-gift-cards' ); ?>
				</th>
				<th>
					<?php esc_html_e( 'Code', 'woocommerce-gift-cards' ); ?>
				</th>
				<th>
					<?php esc_html_e( 'Available Balance', 'woocommerce-gift-cards' ); ?>
				</th>
				<th>
					<?php esc_html_e( 'Expires', 'woocommerce-gift-cards' ); ?>
				</th>
			</tr>
		</thead>
		<tbody>

			<?php if ( $has_giftcards ) : ?>

				<?php foreach ( $giftcards as $giftcard ) : ?>
					<tr>
						<td data-title="<?php esc_html_e( 'Date', 'woocommerce-gift-cards' ); ?>">
							<?php echo esc_html( date_i18n( wc_date_format(), $giftcard->get_date_redeemed() ) ); ?>
						</td>
						<td data-title="<?php esc_html_e( 'Code', 'woocommerce-gift-cards' ); ?>">
							<?php echo wc_gc_mask_codes( 'account' ) ? esc_html( wc_gc_mask_code( $giftcard->get_code() ) ) : esc_html( $giftcard->get_code() ); ?>
						</td>
						<td data-title="<?php esc_html_e( 'Available Balance', 'woocommerce-gift-cards' ); ?>">
							<?php echo wp_kses_post( wc_price( $giftcard->get_balance() ) ); ?>

							<?php if ( $giftcard->get_pending_balance() > 0 ) { ?>
								<small class="woocommerce-MyAccount-Giftcards-pending-amount">
									<?php echo wc_gc_get_pending_balance_resolution( $giftcard ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
									<span class="warning-icon"></span>
								</small>
							<?php } ?>

						</td>
						<td data-title="<?php esc_html_e( 'Expires', 'woocommerce-gift-cards' ); ?>">
							<?php echo ! empty( $giftcard->get_expire_date() ) ? esc_html( date_i18n( wc_date_format(), $giftcard->get_expire_date() ) ) : esc_html__( 'Never', 'woocommerce-gift-cards' ); ?>
						</td>
					</tr>
				<?php endforeach; ?>

			<?php else : ?>

				<td colspan="4"><?php esc_html_e( 'You have no active gift cards at the moment', 'woocommerce-gift-cards' ); ?></td>

			<?php endif; ?>
		</tbody>
	</table>

	<h2><?php esc_html_e( 'Activity', 'woocommerce-gift-cards' ); ?></h2>

	<table class="woocommerce-orders-table woocommerce-giftcards-activity-table woocommerce-MyAccount-orders shop_table shop_table_responsive my_account_orders account-orders-table">
		<thead>
			<tr>
				<th>
					<?php esc_html_e( 'Date', 'woocommerce-gift-cards' ); ?>
				</th>
				<th>
					<?php esc_html_e( 'Description', 'woocommerce-gift-cards' ); ?>
				</th>
				<th>
					<?php esc_html_e( 'Amount', 'woocommerce-gift-cards' ); ?>
				</th>
			</tr>
		</thead>
		<tbody>
			<?php if ( $has_activities ) : ?>

				<?php foreach ( $activities as $activity ) : ?>
					<tr>
						<td data-title="<?php esc_html_e( 'Date', 'woocommerce-gift-cards' ); ?>">
							<?php echo esc_html( date_i18n( wc_date_format(), $activity->get_date() ) ); ?>
						</td>
						<td data-title="<?php esc_html_e( 'Description', 'woocommerce-gift-cards' ); ?>">
							<?php echo wp_kses_post( wc_gc_get_activity_description( $activity ) ); ?>
						</td>
						<td data-title="<?php esc_html_e( 'Amount', 'woocommerce-gift-cards' ); ?>">
							<?php echo wp_kses_post( wc_price( $activity->get_amount() ) ); ?>
						</td>
					</tr>
				<?php endforeach; ?>

			<?php else : ?>

				<td colspan="3"><?php esc_html_e( 'No activity recorded just yet', 'woocommerce-gift-cards' ); ?></td>

			<?php endif; ?>
		</tbody>
</table>

<?php if ( 1 < $total_pages ) : ?>
	<div class="woocommerce-pagination woocommerce-pagination--without-numbers woocommerce-Pagination">
		<?php if ( 1 !== $current_page ) : ?>
			<a class="<?php echo isset( $button_class ) ? esc_attr( $button_class ) : 'woocommerce-Button button'; ?> woocommerce-button--previous woocommerce-Button--previous" href="<?php echo esc_url( wc_get_endpoint_url( 'giftcards', $current_page - 1 ) ); ?>"><?php esc_html_e( 'Previous', 'woocommerce' ); ?></a>
		<?php endif; ?>

		<?php if ( intval( $total_pages ) !== $current_page ) : ?>
			<a class="<?php echo isset( $button_class ) ? esc_attr( $button_class ) : 'woocommerce-Button button'; ?> woocommerce-button--next woocommerce-Button--next" href="<?php echo esc_url( wc_get_endpoint_url( 'giftcards', $current_page + 1 ) ); ?>"><?php esc_html_e( 'Next', 'woocommerce' ); ?></a>
		<?php endif; ?>
	</div>
<?php endif; ?>

<?php do_action( 'woocommerce_gc_after_account_giftcards', $has_giftcards ); ?>
