<?php
/**
 * FisHotel — Coming Soon (Arriving Soon) UI helpers
 *
 * Detection, formatting, and rendering for the "Arriving Soon" UI on
 * archive cards and the PDP. Driven entirely by the FisHotel Misc
 * plugin's `_fh_release_datetime` post meta (UNIX timestamp, UTC).
 *
 * Theme-side restyle: replaces the plugin's red ribbon / red countdown
 * / red Notify Me UI with a turquoise + gold treatment that matches
 * the QT Certificate panel and homepage CTA buttons. Defensive hook
 * removal keeps the plugin's own visual output from rendering inside
 * our wrappers.
 *
 * @package FisHotel
 */

defined( 'ABSPATH' ) || exit;

/**
 * Return the active release timestamp for a product, or 0 if none /
 * already passed. Centralized so card and PDP gating use the same
 * source of truth.
 *
 * @param int $product_id
 * @return int UNIX timestamp (UTC), or 0.
 */
function fishotel_cs_release_ts( $product_id ) {
	$ts = (int) get_post_meta( (int) $product_id, '_fh_release_datetime', true );
	if ( ! $ts ) {
		return 0;
	}
	return $ts > current_time( 'timestamp', true ) ? $ts : 0;
}

/**
 * Format the card-level arrival line shown where the price normally sits.
 *
 *   - ≤ 7 days out: "ARRIVES IN 6D 11H 59M"
 *   - >  7 days out: "ARRIVES MAY 14"
 *
 * @param int $release_ts
 * @return string
 */
function fishotel_cs_format_card_line( $release_ts ) {
	$now  = current_time( 'timestamp', true );
	$diff = max( 0, $release_ts - $now );
	$days = (int) floor( $diff / 86400 );

	if ( $days < 7 ) {
		$h = (int) floor( ( $diff % 86400 ) / 3600 );
		$m = (int) floor( ( $diff % 3600 ) / 60 );
		return sprintf( 'Arrives in %dd %dh %dm', $days, $h, $m );
	}
	// "ARRIVES MAY 14" — site timezone, no year.
	return 'Arrives ' . wp_date( 'M j', $release_ts );
}

/**
 * Render the small turquoise "Arriving Soon" badge in the top-right of
 * the placard image. Echoes nothing if the product isn't on a release.
 */
function fishotel_cs_render_card_badge( $product_id ) {
	if ( ! fishotel_cs_release_ts( $product_id ) ) {
		return;
	}
	echo '<span class="fh-cs-badge">Arriving Soon</span>';
}

/**
 * Render the card-level arrival line (replaces the plugin's red box).
 * Caller decides where in the card body to drop it.
 */
function fishotel_cs_render_card_line( $product_id ) {
	$ts = fishotel_cs_release_ts( $product_id );
	if ( ! $ts ) {
		return;
	}
	echo '<span class="fh-cs-arrival-line">' . esc_html( fishotel_cs_format_card_line( $ts ) ) . '</span>';
}

/**
 * Render the PDP "Arrival Panel" — replaces the QT cert + variation
 * form + ATC + trust strip when a product is on a future release.
 *
 * Mirrors the QT Certificate panel pattern with a turquoise left
 * border accent. Notify button routes to /newsletter/ so all arrival
 * sign-ups funnel into the single newsletter list.
 *
 * @param WC_Product $product
 * @param int        $release_ts
 */
function fishotel_cs_render_arrival_panel( $product, $release_ts ) {
	$date_line = wp_date( 'l, F j · g:i A T', $release_ts );

	$now = current_time( 'timestamp', true );
	$diff = max( 0, $release_ts - $now );
	$days = (int) floor( $diff / 86400 );
	$hours = (int) floor( ( $diff % 86400 ) / 3600 );
	$mins = (int) floor( ( $diff % 3600 ) / 60 );

	$price_html = $product->get_price_html();
	$stock_qty  = $product->get_stock_quantity();
	$avail_line = $price_html;
	if ( is_numeric( $stock_qty ) && (int) $stock_qty > 0 ) {
		$avail_line .= ' <span class="fh-arrival-panel__avail-sep">·</span> ' . sprintf(
			/* translators: %d is stock count */
			esc_html( _n( '%d available at release', '%d available at release', (int) $stock_qty, 'fishotel' ) ),
			(int) $stock_qty
		);
	}

	$notify_url = home_url( '/newsletter/' );
	?>
	<div class="fh-arrival-panel" data-fh-countdown="<?php echo esc_attr( $release_ts ); ?>">
		<div class="fh-arrival-panel__eyebrow">Arriving Soon</div>
		<div class="fh-arrival-panel__date"><?php echo esc_html( $date_line ); ?></div>
		<div class="fh-arrival-panel__countdown">
			<span class="fh-arrival-panel__seg"><span data-d><?php echo (int) $days; ?></span> Days</span>
			<span class="fh-arrival-panel__bullet" aria-hidden="true">·</span>
			<span class="fh-arrival-panel__seg"><span data-h><?php echo (int) $hours; ?></span> Hours</span>
			<span class="fh-arrival-panel__bullet" aria-hidden="true">·</span>
			<span class="fh-arrival-panel__seg"><span data-m><?php echo (int) $mins; ?></span> Min</span>
		</div>
		<div class="fh-arrival-panel__avail"><?php echo wp_kses_post( $avail_line ); ?></div>
		<a href="<?php echo esc_url( $notify_url ); ?>" class="fh-btn fh-btn--ghost fh-btn--full fh-arrival-panel__btn">
			<?php esc_html_e( 'Notify Me on Arrival', 'fishotel' ); ?>
		</a>
	</div>
	<?php
}

/**
 * Best-effort suppression of the plugin's own Coming Soon UI. Walks
 * the WC hook callbacks and unsets any whose class/method signals
 * Coming Soon rendering. Runs late on init so the plugin has finished
 * registering its hooks first. The CSS in woocommerce.css is the
 * belt-and-braces fallback if a callback uses a name we don't catch.
 */
add_action( 'init', function () {
	$hooks = [
		'woocommerce_single_product_summary',
		'woocommerce_before_single_product_summary',
		'woocommerce_after_single_product_summary',
		'woocommerce_before_shop_loop_item',
		'woocommerce_before_shop_loop_item_title',
		'woocommerce_shop_loop_item_title',
		'woocommerce_after_shop_loop_item_title',
		'woocommerce_after_shop_loop_item',
	];
	$pattern = '/coming[\s_-]?soon|notify[\s_-]?me|arrival[\s_-]?panel|cs[\s_-]?(countdown|ribbon|badge|notify|panel)|release[\s_-]?date|fhmisc|fh[\s_-]?cs/i';

	foreach ( $hooks as $hook_name ) {
		if ( empty( $GLOBALS['wp_filter'][ $hook_name ] ) ) {
			continue;
		}
		$hook = $GLOBALS['wp_filter'][ $hook_name ];
		if ( ! is_object( $hook ) || empty( $hook->callbacks ) ) {
			continue;
		}
		foreach ( $hook->callbacks as $priority => $cbs ) {
			foreach ( $cbs as $key => $cb ) {
				$fn = isset( $cb['function'] ) ? $cb['function'] : null;
				$class = '';
				$method = '';
				if ( is_array( $fn ) ) {
					$class  = is_object( $fn[0] ) ? get_class( $fn[0] ) : (string) $fn[0];
					$method = isset( $fn[1] ) ? (string) $fn[1] : '';
				} elseif ( is_string( $fn ) ) {
					$method = $fn;
				}
				if ( preg_match( $pattern, $class . ' ' . $method ) ) {
					unset( $hook->callbacks[ $priority ][ $key ] );
				}
			}
		}
	}
}, 30 );
