<?php
/**
 * FisHotel — The Lobby (account dashboard).
 *
 * Welcome block · Recent Reservations card · Quick Actions grid. All copy is
 * pulled from inc/account-relabels.php (admin-editable via FisHotel Settings).
 *
 * @package FisHotel
 */

defined( 'ABSPATH' ) || exit;

$current_user = wp_get_current_user();
$first_name   = $current_user->first_name ? $current_user->first_name : $current_user->display_name;
$user_id      = get_current_user_id();

$order_count = wc_get_customer_order_count( $user_id );
$last_order  = wc_get_customer_last_order( $user_id );

$recent_orders = wc_get_orders( [
	'customer' => $user_id,
	'limit'    => 3,
	'orderby'  => 'date',
	'order'    => 'DESC',
] );

$greeting_prefix = fishotel_account_text( 'fh_account_greeting_prefix' );
?>

<section class="fh-lobby-welcome">
	<div class="fh-account-eyebrow fh-account-eyebrow--sm"><?php esc_html_e( 'WELCOME', 'fishotel' ); ?></div>
	<h2 class="fh-lobby-greeting">
		<?php echo esc_html( $greeting_prefix ); ?>
		<em class="name"><?php echo esc_html( $first_name ); ?>.</em>
	</h2>
	<p class="fh-lobby-subline">
		<?php
		if ( $order_count > 0 && $last_order ) {
			printf(
				/* translators: 1: last order date, 2: order count */
				esc_html__( 'Last stay checked out %1$s · %2$s on file.', 'fishotel' ),
				esc_html( wc_format_datetime( $last_order->get_date_created() ) ),
				esc_html( sprintf( _n( '%d reservation', '%d reservations', $order_count, 'fishotel' ), $order_count ) )
			);
		} else {
			esc_html_e( 'No reservations yet — your reef awaits.', 'fishotel' );
		}
		?>
	</p>
</section>

<section class="fh-card fh-lobby-recent">
	<header class="fh-card__header">
		<h3 class="fh-card__title"><?php esc_html_e( 'Recent', 'fishotel' ); ?> <em><?php esc_html_e( 'Reservations', 'fishotel' ); ?></em></h3>
		<?php if ( $recent_orders ) : ?>
			<a class="fh-card__action" href="<?php echo esc_url( wc_get_account_endpoint_url( 'orders' ) ); ?>">
				<?php esc_html_e( 'VIEW ALL →', 'fishotel' ); ?>
			</a>
		<?php endif; ?>
	</header>

	<?php if ( $recent_orders ) : ?>
		<div class="fh-reslist">
			<?php foreach ( $recent_orders as $order ) :
				$item_count = $order->get_item_count();
				$first_item = '';
				foreach ( $order->get_items() as $item ) {
					$first_item = $item->get_name();
					break;
				}
				$extra      = $item_count > 1 ? $item_count - 1 : 0;
				$summary    = sprintf(
					/* translators: 1: item count, 2: first item name */
					_n( '%1$d ITEM · %2$s', '%1$d ITEMS · %2$s', $item_count, 'fishotel' ),
					$item_count,
					$first_item
				);
				if ( $extra > 0 ) {
					$summary .= ' +' . $extra;
				}
				$view_url = $order->get_view_order_url();
				?>
				<div class="fh-reslist__row">
					<a class="fh-reslist__num" href="<?php echo esc_url( $view_url ); ?>">#<?php echo esc_html( $order->get_order_number() ); ?></a>
					<div class="fh-reslist__meta">
						<span class="fh-reslist__date"><?php echo esc_html( wc_format_datetime( $order->get_date_created() ) ); ?></span>
						<span class="fh-reslist__summary"><?php echo esc_html( $summary ); ?></span>
					</div>
					<?php echo fishotel_account_status_pill( $order ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<span class="fh-reslist__total"><?php echo wp_kses_post( $order->get_formatted_order_total() ); ?></span>
					<a class="fh-reslist__view" href="<?php echo esc_url( $view_url ); ?>"><?php esc_html_e( 'VIEW →', 'fishotel' ); ?></a>
				</div>
			<?php endforeach; ?>
		</div>
	<?php else : ?>
		<div class="fh-empty-state">
			<span class="fh-corner fh-corner--tr" aria-hidden="true"></span>
			<span class="fh-corner fh-corner--bl" aria-hidden="true"></span>
			<p class="fh-empty-state__line"><?php echo esc_html( fishotel_account_text( 'fh_account_empty_lobby_subtitle' ) ); ?></p>
			<a class="fh-btn fh-btn--ghost" href="<?php echo esc_url( wc_get_page_permalink( 'shop' ) ); ?>">
				<?php esc_html_e( 'BROWSE THE LOBBY', 'fishotel' ); ?>
			</a>
		</div>
	<?php endif; ?>
</section>

<section class="fh-lobby-actions">
	<?php
	$quick = [
		[
			'icon'  => 'fishotel_icon_past_stays',
			'title' => __( 'Past Stays', 'fishotel' ),
			'desc'  => fishotel_account_text( 'fh_account_qa_1_desc' ),
			'url'   => wc_get_account_endpoint_url( 'orders' ),
		],
		[
			'icon'  => 'fishotel_icon_guest_profile',
			'title' => __( 'Guest Profile', 'fishotel' ),
			'desc'  => fishotel_account_text( 'fh_account_qa_2_desc' ),
			'url'   => wc_get_account_endpoint_url( 'edit-account' ),
		],
		[
			'icon'  => 'fishotel_icon_forwarding_addresses',
			'title' => __( 'Forwarding Addresses', 'fishotel' ),
			'desc'  => fishotel_account_text( 'fh_account_qa_3_desc' ),
			'url'   => wc_get_account_endpoint_url( 'edit-address' ),
		],
	];
	foreach ( $quick as $action ) : ?>
		<a class="fh-qa-card" href="<?php echo esc_url( $action['url'] ); ?>">
			<?php if ( function_exists( $action['icon'] ) ) {
				echo $action['icon']( 'fh-qa-card__icon' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			} ?>
			<span class="fh-qa-card__title"><?php echo esc_html( $action['title'] ); ?></span>
			<span class="fh-qa-card__desc"><?php echo esc_html( $action['desc'] ); ?></span>
			<span class="fh-qa-card__arrow" aria-hidden="true">→</span>
		</a>
	<?php endforeach; ?>
</section>

<?php
/**
 * Deprecated WooCommerce dashboard hook kept for plugin compatibility.
 */
do_action( 'woocommerce_account_dashboard' );
