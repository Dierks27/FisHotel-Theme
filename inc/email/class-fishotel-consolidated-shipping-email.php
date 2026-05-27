<?php
/**
 * FisHotel — Consolidated fulfillment shipping email.
 *
 * When two or more source orders share a fulfillment AND share a tracking
 * number (one physical box), ShipTracker would otherwise fire the same
 * customer-facing email once per source. This module hooks the
 * `fst_send_customer_email` filter (added in ShipTracker v1.9.9), detects
 * the multi-source-same-tracking case, and sends a single consolidated
 * email referencing all source order numbers — then returns false so the
 * default per-source send is suppressed for every sibling.
 *
 * Visual treatment reuses ShipTracker's public-API render helpers
 * (FST_Email::render_progress_bar, render_order_summary) so the
 * consolidated email matches every other ShipTracker email customers
 * receive. Both helpers are wrapped in class_exists/method_exists checks;
 * if ShipTracker is missing or refactored, the email falls back to a
 * minimal text body rather than failing entirely.
 *
 * Dedupe: per-tracking transient set on the first sibling that triggers
 * a consolidated send. Subsequent siblings hit the transient and are
 * suppressed without a second consolidated email. TTL is 1 hour — way
 * more than the milliseconds between sibling email triggers in practice,
 * but short enough that a real later transition (e.g. delivered) on the
 * same tracking gets its own consolidated email.
 *
 * @package FisHotel
 */

defined( 'ABSPATH' ) || exit;

class FisHotel_Consolidated_Shipping_Email {

	const META_FULFILLED       = '_fishotel_fulfilled_by_order';
	const TRANSIENT_PREFIX     = 'fhcse_';   // < 45 chars when joined with status+hash
	const DEDUPE_TTL           = HOUR_IN_SECONDS;

	public static function init() {
		add_filter( 'fst_send_customer_email', [ __CLASS__, 'maybe_consolidate' ], 10, 4 );
	}

	/**
	 * Filter callback. Decide whether to:
	 *   - let ShipTracker's default per-source email send (return $should_send)
	 *   - send our own consolidated email and suppress (return false)
	 *
	 * @param bool     $should_send  ShipTracker's go/no-go for this email.
	 * @param object   $shipment     wp_fst_shipments row.
	 * @param WC_Order $order        Source order the shipment belongs to.
	 * @param string   $new_status   Shipment's new status (e.g. 'in_transit').
	 * @return bool
	 */
	public static function maybe_consolidate( $should_send, $shipment, $order, $new_status ) {
		// Bail if ShipTracker already decided not to send, OR we got handed
		// something we don't understand. Default behavior preserved.
		if ( ! $should_send ) {
			return $should_send;
		}
		if ( ! $order instanceof WC_Order || ! is_object( $shipment ) ) {
			return $should_send;
		}
		$tracking = isset( $shipment->tracking_number ) ? (string) $shipment->tracking_number : '';
		if ( '' === $tracking ) {
			return $should_send;
		}

		// Only act on source orders that belong to a fulfillment.
		$ff_id = (int) $order->get_meta( self::META_FULFILLED );
		if ( $ff_id <= 0 ) {
			return $should_send;
		}
		$fulfillment = wc_get_order( $ff_id );
		if ( ! $fulfillment instanceof WC_Order ) {
			return $should_send;
		}

		// Per-tracking + per-status dedupe key. md5 keeps the transient name
		// well under the 172-char index limit and free of weird chars.
		$transient_key = self::TRANSIENT_PREFIX . md5( $new_status . '|' . $tracking );

		// A sibling already triggered the consolidated send — suppress this one.
		if ( get_transient( $transient_key ) ) {
			return false;
		}

		// Find every sibling source order in this fulfillment that carries a
		// shipment with the same tracking number.
		$sources_with_same_tracking = self::find_siblings_with_tracking( $fulfillment, $tracking );

		// Only one source → nothing to consolidate. Default email is correct.
		if ( count( $sources_with_same_tracking ) <= 1 ) {
			return $should_send;
		}

		// Defensive: only consolidate when every sibling shares the same
		// billing email. Different customers in one fulfillment is a data-
		// integrity edge case, but if it ever happens we shouldn't merge
		// their emails into a single inbox.
		if ( ! self::all_siblings_same_customer( $sources_with_same_tracking ) ) {
			return $should_send;
		}

		// Set the dedupe BEFORE sending so a fast sibling can't double-fire if
		// the mailer takes long. If sending fails, the transient will still
		// expire after DEDUPE_TTL.
		set_transient( $transient_key, time(), self::DEDUPE_TTL );

		self::send_consolidated_email( $order, $shipment, $new_status, $sources_with_same_tracking );

		return false; // suppress ShipTracker's per-source send
	}

	/**
	 * Returns the source order IDs (within this fulfillment) that have at
	 * least one shipment matching the given tracking number.
	 *
	 * @return int[]
	 */
	private static function find_siblings_with_tracking( WC_Order $fulfillment, $tracking ) {
		$source_ids = function_exists( 'fishotel_fulfillment_get_sources' )
			? fishotel_fulfillment_get_sources( $fulfillment )
			: [];
		if ( empty( $source_ids ) ) {
			return [];
		}
		if ( ! class_exists( 'FST_Shipment' ) || ! method_exists( 'FST_Shipment', 'get_by_order' ) ) {
			return [];
		}

		$out = [];
		foreach ( $source_ids as $sid ) {
			$rows = FST_Shipment::get_by_order( (int) $sid );
			if ( ! is_array( $rows ) ) {
				continue;
			}
			foreach ( $rows as $row ) {
				$rt = isset( $row->tracking_number ) ? (string) $row->tracking_number : '';
				if ( $rt === $tracking ) {
					$out[] = (int) $sid;
					break;
				}
			}
		}
		return array_values( array_unique( $out ) );
	}

	/** Lowercase-trimmed billing emails match across every sibling. */
	private static function all_siblings_same_customer( array $source_ids ) {
		$emails = [];
		foreach ( $source_ids as $sid ) {
			$o = wc_get_order( (int) $sid );
			if ( ! $o instanceof WC_Order ) {
				continue;
			}
			$emails[] = strtolower( trim( (string) $o->get_billing_email() ) );
		}
		$emails = array_filter( array_unique( $emails ) );
		return 1 === count( $emails );
	}

	/**
	 * Build and send the consolidated email. Reuses ShipTracker's public
	 * render helpers (FST_Email::render_progress_bar, render_order_summary)
	 * so the visual matches every other ShipTracker email.
	 *
	 * @param WC_Order $primary_order  Source order whose ShipTracker event triggered this filter.
	 * @param object   $shipment       The wp_fst_shipments row.
	 * @param string   $new_status     Shipment status, e.g. 'in_transit'.
	 * @param int[]    $source_ids     All source orders sharing this tracking.
	 */
	private static function send_consolidated_email( WC_Order $primary_order, $shipment, $new_status, array $source_ids ) {
		$source_orders = [];
		foreach ( $source_ids as $sid ) {
			$o = wc_get_order( (int) $sid );
			if ( $o instanceof WC_Order ) {
				$source_orders[] = $o;
			}
		}
		if ( empty( $source_orders ) ) {
			return;
		}

		$to = $primary_order->get_billing_email();
		if ( empty( $to ) ) {
			return;
		}

		$numbers_phrase = self::format_order_numbers( $source_orders );
		$verb           = self::status_verb_phrase( $new_status );
		$subject        = sprintf(
			/* translators: 1: comma-joined order numbers (e.g. "#34159 and #34168"), 2: status verb phrase ("have shipped", "are out for delivery") */
			__( 'Items from your orders %1$s %2$s!', 'fishotel' ),
			$numbers_phrase,
			$verb
		);

		$body = self::render_body( $primary_order, $source_orders, $shipment, $new_status, $numbers_phrase, $verb );

		wp_mail(
			$to,
			$subject,
			$body,
			[ 'Content-Type: text/html; charset=UTF-8' ]
		);
	}

	/**
	 * "Items from your orders <X> <verb>!" — natural-language join.
	 *  1 order:  "#A"  (callers usually skip this since count<=1 short-circuits)
	 *  2 orders: "#A and #B"
	 *  3+ :      "#A, #B, and #C"
	 *
	 * @param WC_Order[] $orders
	 */
	private static function format_order_numbers( array $orders ) {
		$labels = array_map( static function ( WC_Order $o ) {
			return '#' . $o->get_order_number();
		}, $orders );
		$count = count( $labels );
		if ( 1 === $count ) {
			return $labels[0];
		}
		if ( 2 === $count ) {
			/* translators: 1: order #A, 2: order #B — joiner for two-order list */
			return sprintf( __( '%1$s and %2$s', 'fishotel' ), $labels[0], $labels[1] );
		}
		$last = array_pop( $labels );
		/* translators: 1: comma-joined order numbers, 2: final order number — Oxford-comma join */
		return sprintf( __( '%1$s, and %2$s', 'fishotel' ), implode( ', ', $labels ), $last );
	}

	private static function status_verb_phrase( $status ) {
		$status = strtolower( str_replace( [ '-', ' ' ], '_', (string) $status ) );
		switch ( $status ) {
			case 'in_transit':
			case 'shipped':
				return __( 'have shipped', 'fishotel' );
			case 'out_for_delivery':
				return __( 'are out for delivery', 'fishotel' );
			case 'delivered':
				return __( 'have been delivered', 'fishotel' );
			default:
				return __( 'have a shipment update', 'fishotel' );
		}
	}

	/**
	 * Call a ShipTracker static render helper if available, capturing both
	 * its return value and any echoed output. Used so we work whether the
	 * helper echoes or returns its HTML.
	 *
	 * @return string '' when the method isn't callable.
	 */
	private static function call_fst_render( $method, array $args ) {
		if ( ! class_exists( 'FST_Email' ) || ! method_exists( 'FST_Email', $method ) ) {
			return '';
		}
		ob_start();
		try {
			$ret = call_user_func_array( [ 'FST_Email', $method ], $args );
		} catch ( \Throwable $e ) {
			ob_end_clean();
			return '';
		}
		$echoed = ob_get_clean();
		if ( is_string( $ret ) && '' !== $ret ) {
			return $ret;
		}
		return is_string( $echoed ) ? $echoed : '';
	}

	private static function render_body( WC_Order $primary_order, array $source_orders, $shipment, $new_status, $numbers_phrase, $verb ) {
		$first_name = (string) $primary_order->get_billing_first_name();
		$carrier    = isset( $shipment->carrier ) ? strtoupper( (string) $shipment->carrier ) : '';
		$tracking   = isset( $shipment->tracking_number ) ? (string) $shipment->tracking_number : '';
		$track_url  = class_exists( 'FisHotel_Shipments_Metabox' )
			? FisHotel_Shipments_Metabox::get_tracking_url( $shipment )
			: '#';

		$progress = self::call_fst_render( 'render_progress_bar', [ $shipment ] );

		ob_start();
		?>
		<div style="font-family: 'Playfair Display', Georgia, serif; max-width: 600px; margin: 0 auto; padding: 24px; color: #2b2b2b;">
			<h2 style="margin: 0 0 12px; font-size: 22px;">
				<?php
				printf(
					/* translators: 1: customer first name */
					esc_html__( 'Hi %s,', 'fishotel' ),
					esc_html( '' !== $first_name ? $first_name : __( 'there', 'fishotel' ) )
				);
				?>
			</h2>
			<p style="font-size: 16px; line-height: 1.45; margin: 0 0 16px;">
				<?php
				printf(
					/* translators: 1: "#X and #Y" phrase, 2: "have shipped" / "are out for delivery" / etc */
					esc_html__( 'Items from your orders %1$s %2$s — combined into one shipment.', 'fishotel' ),
					esc_html( $numbers_phrase ),
					esc_html( $verb )
				);
				?>
			</p>

			<?php if ( '' !== $progress ) : ?>
				<div style="margin: 20px 0;"><?php echo $progress; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — ShipTracker-rendered HTML ?></div>
			<?php endif; ?>

			<div style="background: rgba(212,165,116,0.10); border-left: 4px solid #d4a574; padding: 14px 18px; margin: 16px 0;">
				<div style="font-size: 11px; letter-spacing: 0.22em; text-transform: uppercase; color: #d4a574; margin-bottom: 4px;">
					<?php esc_html_e( 'Tracking', 'fishotel' ); ?>
				</div>
				<div style="font-size: 16px;">
					<?php if ( '' !== $carrier ) : ?>
						<strong><?php echo esc_html( $carrier ); ?></strong> &middot;
					<?php endif; ?>
					<?php if ( '#' !== $track_url && '' !== $track_url ) : ?>
						<a href="<?php echo esc_url( $track_url ); ?>" style="color: #2b2b2b;"><?php echo esc_html( $tracking ); ?></a>
					<?php else : ?>
						<?php echo esc_html( $tracking ); ?>
					<?php endif; ?>
				</div>
			</div>

			<?php foreach ( $source_orders as $src ) :
				$summary = self::call_fst_render( 'render_order_summary', [ $src ] );
			?>
				<div style="margin-top: 18px;">
					<h3 style="margin: 0 0 8px; font-size: 16px;">
						<?php
						printf(
							/* translators: %s order number */
							esc_html__( 'Order #%s', 'fishotel' ),
							esc_html( $src->get_order_number() )
						);
						?>
					</h3>
					<?php if ( '' !== $summary ) : ?>
						<?php echo $summary; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — ShipTracker-rendered HTML ?>
					<?php else : ?>
						<ul style="margin: 0; padding-left: 20px;">
							<?php foreach ( $src->get_items( 'line_item' ) as $item ) : ?>
								<li style="margin: 2px 0;"><?php echo esc_html( $item->get_name() ); ?> &times; <?php echo esc_html( (string) $item->get_quantity() ); ?></li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>

			<p style="margin-top: 24px; font-size: 13px; color: #666;">
				<?php esc_html_e( 'Questions about your shipment? Reply to this email or visit your account on fishotel.com.', 'fishotel' ); ?>
			</p>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
