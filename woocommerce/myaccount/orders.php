<?php
/**
 * FisHotel — Past Stays (orders table).
 *
 * @package FisHotel
 * @var bool   $has_orders
 * @var object $customer_orders
 * @var int    $current_page
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_before_account_orders', $has_orders );
?>

<?php if ( $has_orders ) : ?>

	<table class="fh-orders-table">
		<thead>
			<tr>
				<th class="col-number"><?php esc_html_e( 'RESERVATION #', 'fishotel' ); ?></th>
				<th class="col-date"><?php esc_html_e( 'DATE', 'fishotel' ); ?></th>
				<th class="col-status"><?php esc_html_e( 'STATUS', 'fishotel' ); ?></th>
				<th class="col-total"><?php esc_html_e( 'TOTAL', 'fishotel' ); ?></th>
				<th class="col-actions"></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $customer_orders->orders as $order ) : ?>
				<tr class="fh-orders-row">
					<td class="col-number" data-title="<?php esc_attr_e( 'Reservation #', 'fishotel' ); ?>">
						<a href="<?php echo esc_url( $order->get_view_order_url() ); ?>">#<?php echo esc_html( $order->get_order_number() ); ?></a>
					</td>
					<td class="col-date" data-title="<?php esc_attr_e( 'Date', 'fishotel' ); ?>">
						<?php echo esc_html( wc_format_datetime( $order->get_date_created() ) ); ?>
					</td>
					<td class="col-status" data-title="<?php esc_attr_e( 'Status', 'fishotel' ); ?>">
						<?php echo fishotel_account_status_pill( $order ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</td>
					<td class="col-total" data-title="<?php esc_attr_e( 'Total', 'fishotel' ); ?>">
						<?php echo wp_kses_post( $order->get_formatted_order_total() ); ?>
					</td>
					<td class="col-actions">
						<a class="fh-orders-row__view" href="<?php echo esc_url( $order->get_view_order_url() ); ?>">
							<?php esc_html_e( 'VIEW →', 'fishotel' ); ?>
						</a>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<?php if ( 1 < $customer_orders->max_num_pages ) : ?>
		<nav class="fh-orders-pagination" aria-label="<?php esc_attr_e( 'Orders pagination', 'fishotel' ); ?>">
			<?php if ( 1 !== $current_page ) : ?>
				<a class="fh-orders-pagination__link" href="<?php echo esc_url( wc_get_endpoint_url( 'orders', $current_page - 1 ) ); ?>"><?php esc_html_e( '← PREVIOUS', 'fishotel' ); ?></a>
			<?php endif; ?>

			<?php for ( $i = 1; $i <= $customer_orders->max_num_pages; $i++ ) : ?>
				<a class="fh-orders-pagination__num<?php echo $i === $current_page ? ' is-active' : ''; ?>"
				   href="<?php echo esc_url( wc_get_endpoint_url( 'orders', $i ) ); ?>"><?php echo esc_html( $i ); ?></a>
			<?php endfor; ?>

			<?php if ( $current_page !== (int) $customer_orders->max_num_pages ) : ?>
				<a class="fh-orders-pagination__link" href="<?php echo esc_url( wc_get_endpoint_url( 'orders', $current_page + 1 ) ); ?>"><?php esc_html_e( 'NEXT →', 'fishotel' ); ?></a>
			<?php endif; ?>
		</nav>
	<?php endif; ?>

<?php else : ?>

	<div class="fh-card fh-empty-state">
		<span class="fh-corner fh-corner--tr" aria-hidden="true"></span>
		<span class="fh-corner fh-corner--bl" aria-hidden="true"></span>
		<h3 class="fh-empty-state__title"><?php echo esc_html( fishotel_account_text( 'fh_account_empty_orders_title' ) ); ?></h3>
		<p class="fh-empty-state__line"><?php echo esc_html( fishotel_account_text( 'fh_account_empty_orders_subtitle' ) ); ?></p>
		<a class="fh-btn fh-btn--primary" href="<?php echo esc_url( wc_get_page_permalink( 'shop' ) ); ?>">
			<?php esc_html_e( 'BROWSE THE LOBBY', 'fishotel' ); ?>
		</a>
	</div>

<?php endif; ?>

<?php do_action( 'woocommerce_after_account_orders', $has_orders ); ?>
