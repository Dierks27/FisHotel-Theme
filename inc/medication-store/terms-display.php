<?php
/**
 * Medication store — where the terms-acceptance record shows (C2).
 *
 *   - Admin order screen (legacy post screen + HPOS wc-orders): the
 *     "Medication terms" meta box, with the NOT RECORDED state and the
 *     "Record manual acknowledgement" admin-post handler.
 *   - Emails: one line on woocommerce_email_order_meta (HTML + plain text).
 *   - My Account order view: "You accepted the medication terms on …".
 *   - [fishotel_med_terms] shortcode for a public Medication Terms page.
 *   - lines_for_order(): the "Medication terms accepted <date> · IP <ip>
 *     · v<version>" strings the WPO PDF documents and the EA packing slip
 *     print (they call this; the hooks live in their own files).
 *
 * The record itself is built in terms-record.php and written by
 * disclaimer.php; this file only reads it (plus the legacy keys).
 *
 * @package FisHotel
 */

defined( 'ABSPATH' ) || exit;

class FisHotel_Med_Terms_Display {

	const ACTION_MANUAL = 'fishotel_med_terms_manual';
	const META_BOX_ID   = 'fishotel-med-terms';

	/** Email ids that carry the acceptance line. */
	const EMAIL_IDS = [ 'customer_processing_order', 'customer_completed_order', 'customer_invoice', 'new_order' ];

	public static function init() {
		// Admin order screens — screen-specific hooks, the HPOS-safe way
		// (same as inc/order-fulfillment/class-fishotel-order-fulfillment.php).
		add_action( 'add_meta_boxes_shop_order',                  [ __CLASS__, 'register_meta_box' ] );
		add_action( 'add_meta_boxes_woocommerce_page_wc-orders',  [ __CLASS__, 'register_meta_box' ] );
		add_action( 'admin_post_' . self::ACTION_MANUAL,          [ __CLASS__, 'handle_manual_record' ] );
		add_action( 'admin_notices',                              [ __CLASS__, 'manual_record_notice' ] );

		// Emails.
		add_action( 'woocommerce_email_order_meta', [ __CLASS__, 'email_line' ], 20, 4 );

		// My Account order view (also the thank-you page, same template).
		add_action( 'woocommerce_order_details_after_order_table', [ __CLASS__, 'account_line' ], 20, 1 );

		// Public terms page.
		add_shortcode( 'fishotel_med_terms', [ __CLASS__, 'shortcode' ] );
	}

	// ── Record access ────────────────────────────────────────────────

	/** The stored record array, or null when the order has none / it is malformed. */
	public static function get_record( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return null;
		}
		$record = $order->get_meta( FisHotel_Med_Terms::META_KEY, true );
		return FisHotel_Med_Terms::is_record( $record ) ? $record : null;
	}

	/** One of the FisHotel_Med_Terms::STATE_* values for an order. */
	public static function get_state( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return FisHotel_Med_Terms::STATE_NONE;
		}
		return FisHotel_Med_Terms::detect_state(
			$order->get_meta( FisHotel_Med_Terms::META_KEY, true ),
			$order->get_meta( FisHotel_Med_Terms::LEGACY_ACCEPTED_KEY, true ),
			! empty( fishotel_med_order_med_lines( $order ) )
		);
	}

	/** Legacy timestamp formatted in the site timezone, '' when none. */
	public static function legacy_local_time( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return '';
		}
		$ts = (int) $order->get_meta( FisHotel_Med_Terms::LEGACY_ACCEPTED_AT_KEY, true );
		if ( $ts <= 0 ) {
			return '';
		}
		return FisHotel_Med_Terms::local_time_string( $ts, wp_timezone() );
	}

	/**
	 * Plain-text acceptance lines for a document. A regular order yields
	 * at most one line; a fulfillment (combined box) yields one per source
	 * order that has a record, prefixed with its order number. Legacy-only
	 * orders yield a short "accepted (legacy record)" line. Nothing for
	 * orders without medication lines.
	 *
	 * @param WC_Order $order
	 * @param bool     $with_ip
	 * @return string[]
	 */
	public static function lines_for_order( $order, $with_ip = false ) {
		if ( ! $order instanceof WC_Order ) {
			return [];
		}
		$orders = [ $order ];
		$prefix = false;
		if ( self::is_fulfillment( $order ) ) {
			$orders = self::fulfillment_sources( $order );
			$prefix = count( $orders ) > 1;
		}

		$lines = [];
		foreach ( $orders as $o ) {
			$line = self::line_for_single_order( $o, $with_ip );
			if ( $line === '' ) {
				continue;
			}
			/* translators: %s = order number */
			$lines[] = $prefix ? sprintf( __( 'Order #%s: ', 'fishotel' ), $o->get_order_number() ) . $line : $line;
		}
		return $lines;
	}

	/**
	 * True for a combined-box fulfillment wrapper (Phase 2.5). Such an order
	 * only carries snapshot copies of its sources' line items; the terms
	 * records live on the source orders, so every surface maps it to them.
	 */
	public static function is_fulfillment( $order ) {
		return $order instanceof WC_Order
			&& function_exists( 'fishotel_is_fulfillment' )
			&& function_exists( 'fishotel_fulfillment_get_sources' )
			&& fishotel_is_fulfillment( $order );
	}

	/**
	 * The loaded source orders of a fulfillment wrapper ([] for anything
	 * else), in the order the fulfillment lists them.
	 *
	 * @return WC_Order[]
	 */
	public static function fulfillment_sources( $order ) {
		$sources = [];
		if ( ! self::is_fulfillment( $order ) ) {
			return $sources;
		}
		foreach ( (array) fishotel_fulfillment_get_sources( $order ) as $sid ) {
			$src = wc_get_order( (int) $sid );
			if ( $src instanceof WC_Order ) {
				$sources[] = $src;
			}
		}
		return $sources;
	}

	/** Acceptance line for one order, '' when there is nothing to say. */
	protected static function line_for_single_order( WC_Order $order, $with_ip ) {
		$record = self::get_record( $order );
		if ( $record ) {
			return FisHotel_Med_Terms::format_line( $record, $with_ip );
		}
		if ( self::get_state( $order ) === FisHotel_Med_Terms::STATE_LEGACY ) {
			$when = self::legacy_local_time( $order );
			return $when !== ''
				/* translators: %s = local date/time */
				? sprintf( __( 'Medication terms accepted %s (legacy record)', 'fishotel' ), $when )
				: __( 'Medication terms accepted (legacy record, no details)', 'fishotel' );
		}
		return '';
	}

	// ── Admin meta box ───────────────────────────────────────────────

	/** Register the box on whichever order screen fired the hook. */
	public static function register_meta_box( $screen_or_post = null ) {
		$order = self::order_from( $screen_or_post );
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		// Only orders that carry medication lines or already have a record
		// get the box — a live-fish-only order has nothing to show. A
		// fulfillment wrapper is judged by its source orders (the records
		// live there), never by its own copied line items.
		if ( self::is_fulfillment( $order ) ) {
			$show = false;
			foreach ( self::fulfillment_sources( $order ) as $src ) {
				if ( self::get_state( $src ) !== FisHotel_Med_Terms::STATE_NONE ) {
					$show = true;
					break;
				}
			}
			if ( ! $show ) {
				return;
			}
		} elseif ( self::get_state( $order ) === FisHotel_Med_Terms::STATE_NONE ) {
			return;
		}
		$screen = self::on_hpos_screen() && function_exists( 'wc_get_page_screen_id' )
			? wc_get_page_screen_id( 'shop-order' )
			: 'shop_order';
		add_meta_box(
			self::META_BOX_ID,
			__( 'Medication terms', 'fishotel' ),
			[ __CLASS__, 'render_meta_box' ],
			$screen,
			'side',
			'default'
		);
	}

	private static function on_hpos_screen() {
		if ( ! function_exists( 'get_current_screen' ) || ! function_exists( 'wc_get_page_screen_id' ) ) {
			return false;
		}
		$screen = get_current_screen();
		return $screen && $screen->id === wc_get_page_screen_id( 'shop-order' );
	}

	/** WP_Post (legacy) or WC_Order (HPOS) → WC_Order|null. */
	private static function order_from( $post_or_order ) {
		if ( $post_or_order instanceof WC_Order ) {
			return $post_or_order;
		}
		if ( $post_or_order instanceof WP_Post && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( $post_or_order->ID );
			return $order instanceof WC_Order ? $order : null;
		}
		return null;
	}

	public static function render_meta_box( $post_or_order ) {
		$order = self::order_from( $post_or_order );
		if ( ! $order instanceof WC_Order ) {
			echo '<p style="color:#666;">' . esc_html__( 'Order not loaded yet.', 'fishotel' ) . '</p>';
			return;
		}
		// A fulfillment (combined box) only carries snapshot copies of its
		// sources' line items; the records live on the source orders, so
		// list theirs instead of a false NOT RECORDED (and never offer the
		// manual form here — handle_manual_record() refuses a wrapper too).
		if ( self::is_fulfillment( $order ) ) {
			echo '<div class="fishotel-med-terms-box">';
			self::render_fulfillment( $order );
			echo '</div>';
			return;
		}
		$state = self::get_state( $order );
		echo '<div class="fishotel-med-terms-box">';
		switch ( $state ) {
			case FisHotel_Med_Terms::STATE_RECORDED:
				self::render_record( self::get_record( $order ) );
				break;
			case FisHotel_Med_Terms::STATE_LEGACY:
				self::render_legacy( $order );
				break;
			case FisHotel_Med_Terms::STATE_MISSING:
				self::render_missing( $order );
				break;
			default:
				echo '<p style="margin:0;color:#666;font-style:italic;">' . esc_html__( 'No medication lines on this order.', 'fishotel' ) . '</p>';
		}
		echo '</div>';
	}

	protected static function render_record( array $record ) {
		$is_manual = isset( $record['source'] ) && $record['source'] === 'manual';
		$rows      = [];
		$rows[]    = [ __( 'Accepted at', 'fishotel' ), isset( $record['accepted_at_local'] ) ? $record['accepted_at_local'] : '' ];
		if ( $is_manual ) {
			$by = isset( $record['admin_user_name'] ) ? (string) $record['admin_user_name'] : '';
			if ( ! empty( $record['admin_user_id'] ) ) {
				$by .= ' (#' . (int) $record['admin_user_id'] . ')';
			}
			$rows[] = [ __( 'Recorded by', 'fishotel' ), trim( $by ) ];
			if ( ! empty( $record['note'] ) ) {
				$rows[] = [ __( 'Note', 'fishotel' ), (string) $record['note'] ];
			}
		} else {
			$rows[] = [ __( 'IP', 'fishotel' ), isset( $record['ip'] ) && $record['ip'] !== '' ? $record['ip'] : '—' ];
		}
		$rows[] = [ __( 'Terms version', 'fishotel' ), isset( $record['terms_version'] ) ? $record['terms_version'] : '' ];
		?>
		<p class="fishotel-med-terms-box__state fishotel-med-terms-box__state--ok">
			<?php echo $is_manual ? esc_html__( 'Acknowledged (manual record)', 'fishotel' ) : esc_html__( 'Accepted at checkout', 'fishotel' ); ?>
		</p>
		<table class="fishotel-med-terms-box__table">
			<?php foreach ( $rows as $row ) : ?>
				<tr>
					<th scope="row"><?php echo esc_html( $row[0] ); ?></th>
					<td><?php echo esc_html( (string) $row[1] ); ?></td>
				</tr>
			<?php endforeach; ?>
		</table>
		<?php if ( ! empty( $record['label'] ) ) : ?>
			<p class="fishotel-med-terms-box__label"><strong><?php esc_html_e( 'Checkbox text', 'fishotel' ); ?></strong><br><?php echo esc_html( (string) $record['label'] ); ?></p>
		<?php endif; ?>
		<?php if ( ! empty( $record['extra_acks'] ) && is_array( $record['extra_acks'] ) ) : ?>
			<p class="fishotel-med-terms-box__label"><strong><?php esc_html_e( 'Extra acknowledgements', 'fishotel' ); ?></strong></p>
			<ul class="fishotel-med-terms-box__list">
				<?php foreach ( $record['extra_acks'] as $ack ) : ?>
					<li><?php echo esc_html( (string) $ack ); ?></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
		<?php if ( ! empty( $record['products'] ) && is_array( $record['products'] ) ) : ?>
			<p class="fishotel-med-terms-box__label"><strong><?php esc_html_e( 'Medications in the cart', 'fishotel' ); ?></strong></p>
			<ul class="fishotel-med-terms-box__list">
				<?php foreach ( $record['products'] as $p ) : ?>
					<?php if ( ! is_array( $p ) ) continue; ?>
					<li>
						<?php echo esc_html( isset( $p['qty'] ) ? (int) $p['qty'] : 0 ); ?> ×
						<?php echo esc_html( isset( $p['name'] ) ? (string) $p['name'] : '' ); ?>
						<?php if ( ! empty( $p['id'] ) ) : ?><span style="color:#888;">(#<?php echo esc_html( (int) $p['id'] ); ?>)</span><?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
		<?php if ( ! empty( $record['user_agent'] ) ) : ?>
			<p class="fishotel-med-terms-box__ua"><?php echo esc_html( (string) $record['user_agent'] ); ?></p>
		<?php endif; ?>
		<?php
	}

	protected static function render_legacy( WC_Order $order ) {
		$when = self::legacy_local_time( $order );
		?>
		<p class="fishotel-med-terms-box__state fishotel-med-terms-box__state--legacy">
			<?php esc_html_e( 'Accepted (legacy record, no details)', 'fishotel' ); ?>
		</p>
		<?php if ( $when !== '' ) : ?>
			<p style="margin:0 0 8px;"><?php echo esc_html( sprintf( /* translators: %s = local date/time */ __( 'Accepted at %s', 'fishotel' ), $when ) ); ?></p>
		<?php endif; ?>
		<p class="description" style="margin:0;"><?php esc_html_e( 'This order was placed before the full terms record existed; only the acceptance flag and timestamp were stored.', 'fishotel' ); ?></p>
		<?php
	}

	/**
	 * Fulfillment wrapper: one read-only row per source order that carries
	 * medication lines — its number linked to its own edit screen plus its
	 * state (record details, legacy, or NOT RECORDED pointing at the source).
	 * No manual form: a record is only ever written on a source order.
	 */
	protected static function render_fulfillment( WC_Order $order ) {
		$rows = [];
		foreach ( self::fulfillment_sources( $order ) as $src ) {
			$state = self::get_state( $src );
			if ( $state === FisHotel_Med_Terms::STATE_NONE ) {
				continue;
			}
			$number = (string) $src->get_order_number();
			$class  = 'fishotel-med-terms-box__state--missing';
			/* translators: %s = order number */
			$text   = sprintf( __( 'NOT RECORDED — record it on order #%s', 'fishotel' ), $number );
			if ( $state === FisHotel_Med_Terms::STATE_RECORDED ) {
				$record = self::get_record( $src );
				$when   = isset( $record['accepted_at_local'] ) ? (string) $record['accepted_at_local'] : '';
				$ver    = isset( $record['terms_version'] ) ? (string) $record['terms_version'] : '';
				$class  = 'fishotel-med-terms-box__state--ok';
				$text   = isset( $record['source'] ) && $record['source'] === 'manual'
					/* translators: 1: local date/time, 2: terms version */
					? sprintf( __( 'Acknowledged (manual record) %1$s · v%2$s', 'fishotel' ), $when, $ver )
					/* translators: 1: local date/time, 2: terms version */
					: sprintf( __( 'Accepted at checkout %1$s · v%2$s', 'fishotel' ), $when, $ver );
			} elseif ( $state === FisHotel_Med_Terms::STATE_LEGACY ) {
				$when  = self::legacy_local_time( $src );
				$class = 'fishotel-med-terms-box__state--legacy';
				$text  = $when !== ''
					/* translators: %s = local date/time */
					? sprintf( __( 'Accepted %s (legacy record)', 'fishotel' ), $when )
					: __( 'Accepted (legacy record, no details)', 'fishotel' );
			}
			$rows[] = [ 'url' => $src->get_edit_order_url(), 'number' => $number, 'class' => $class, 'text' => $text ];
		}
		if ( empty( $rows ) ) {
			echo '<p style="margin:0;color:#666;font-style:italic;">' . esc_html__( 'No medication lines on the source orders.', 'fishotel' ) . '</p>';
			return;
		}
		echo '<p class="description" style="margin:0 0 6px;">' . esc_html__( 'Combined box — the records live on the source orders:', 'fishotel' ) . '</p>';
		echo '<ul class="fishotel-med-terms-box__list" style="list-style:none;margin-left:0;">';
		foreach ( $rows as $row ) {
			echo '<li style="margin:0 0 6px;">';
			/* translators: %s = order number */
			echo '<a href="' . esc_url( $row['url'] ) . '"><strong>' . esc_html( sprintf( __( 'Order #%s', 'fishotel' ), $row['number'] ) ) . '</strong></a><br>';
			echo '<span class="fishotel-med-terms-box__state ' . esc_attr( $row['class'] ) . '" style="display:block;">' . esc_html( $row['text'] ) . '</span>';
			echo '</li>';
		}
		echo '</ul>';
	}

	protected static function render_missing( WC_Order $order ) {
		$order_id = (int) $order->get_id();
		?>
		<p class="fishotel-med-terms-box__state fishotel-med-terms-box__state--missing">
			<?php esc_html_e( 'NOT RECORDED', 'fishotel' ); ?>
		</p>
		<p class="description" style="margin:0 0 8px;"><?php esc_html_e( 'This order has medication lines but no terms acceptance on file (placed before this version, created in admin, or taken by phone). Record the customer\'s acknowledgement once you have it.', 'fishotel' ); ?></p>
		<!-- NOT a <form>: this meta box renders inside the order-edit form and
		     the browser flattens nested forms — the button would submit the
		     order screen instead (and a hidden "action" field here would hijack
		     its Update button). The detached form built below posts cleanly to
		     admin-post.php, same pattern as the Fulfillment boxes. -->
		<div class="fishotel-med-terms-box__form" data-fishotel-med-terms-manual>
			<p style="margin:0 0 6px;">
				<label for="fishotel-med-terms-note" class="screen-reader-text"><?php esc_html_e( 'Note', 'fishotel' ); ?></label>
				<input type="text" id="fishotel-med-terms-note" maxlength="200" class="widefat" placeholder="<?php esc_attr_e( 'Optional note, e.g. "confirmed by phone 10/17"', 'fishotel' ); ?>">
			</p>
			<p style="margin:0;">
				<button type="button" class="button button-primary" data-fishotel-med-terms-submit><?php esc_html_e( 'Record manual acknowledgement', 'fishotel' ); ?></button>
			</p>
		</div>
		<script>
		( function () {
			var box = document.querySelector( '[data-fishotel-med-terms-manual]' );
			if ( ! box ) { return; }
			var btn  = box.querySelector( '[data-fishotel-med-terms-submit]' );
			var note = box.querySelector( '#fishotel-med-terms-note' );
			if ( ! btn ) { return; }
			if ( note ) {
				// Enter in the note field would submit the surrounding order
				// form (Update); route it to the manual record instead.
				note.addEventListener( 'keydown', function ( e ) {
					if ( e.key === 'Enter' || e.keyCode === 13 ) {
						e.preventDefault();
						btn.click();
					}
				} );
			}
			btn.addEventListener( 'click', function () {
				if ( ! window.confirm( <?php echo wp_json_encode( __( 'Record that the customer acknowledged the medication terms? This is stored on the order with your name and the time.', 'fishotel' ) ); ?> ) ) {
					return;
				}
				var f = document.createElement( 'form' );
				f.method = 'post';
				f.action = <?php echo wp_json_encode( admin_url( 'admin-post.php' ) ); ?>;
				f.style.display = 'none';
				function add( n, v ) { var i = document.createElement( 'input' ); i.type = 'hidden'; i.name = n; i.value = v; f.appendChild( i ); }
				add( 'action', <?php echo wp_json_encode( self::ACTION_MANUAL ); ?> );
				add( 'order_id', <?php echo (int) $order_id; ?> );
				add( 'note', note ? note.value : '' );
				add( '_fishotel_med_terms_nonce', <?php echo wp_json_encode( wp_create_nonce( self::ACTION_MANUAL . '_' . $order_id ) ); ?> );
				document.body.appendChild( f );
				f.submit();
			} );
		} )();
		</script>
		<?php
	}

	/** admin-post: store a manual record (nonce + manage_woocommerce). */
	public static function handle_manual_record() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'fishotel' ), '', [ 'response' => 403 ] );
		}
		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
		$nonce    = isset( $_POST['_fishotel_med_terms_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_fishotel_med_terms_nonce'] ) ) : '';
		if ( ! $order_id || ! wp_verify_nonce( $nonce, self::ACTION_MANUAL . '_' . $order_id ) ) {
			wp_die( esc_html__( 'Invalid request.', 'fishotel' ), '', [ 'response' => 403 ] );
		}
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		if ( ! $order instanceof WC_Order ) {
			wp_die( esc_html__( 'Order not found.', 'fishotel' ) );
		}
		// Never write a record onto a combined-box wrapper: every document
		// reads the records from the source orders, so one stored here would
		// be invisible everywhere but this box.
		if ( self::is_fulfillment( $order ) ) {
			wp_safe_redirect( add_query_arg( 'fishotel_med_terms', 'fulfillment', $order->get_edit_order_url() ) );
			exit;
		}

		$result = 'exists';
		if ( ! self::get_record( $order ) ) {
			$user   = wp_get_current_user();
			$note   = isset( $_POST['note'] ) ? sanitize_text_field( wp_unslash( $_POST['note'] ) ) : '';
			$label  = FisHotel_Med_Settings::get_checkout_label();
			$record = FisHotel_Med_Terms::build_manual_record( [
				'timestamp'       => time(),
				'timezone'        => wp_timezone(),
				'label'           => wp_strip_all_tags( $label ),
				'terms_version'   => FisHotel_Med_Settings::get_terms_version(),
				'terms_body'      => FisHotel_Med_Settings::get_disclaimer_body(),
				'products'        => fishotel_med_order_med_lines( $order ),
				'extra_acks'      => [],
				'admin_user_id'   => $user ? (int) $user->ID : 0,
				'admin_user_name' => $user ? (string) $user->display_name : '',
				'note'            => $note,
			] );
			$order->update_meta_data( FisHotel_Med_Terms::META_KEY, $record );
			$order->update_meta_data( FisHotel_Med_Terms::LEGACY_ACCEPTED_KEY, '1' );
			$order->update_meta_data( FisHotel_Med_Terms::LEGACY_ACCEPTED_AT_KEY, $record['accepted_at'] );
			$order->add_order_note( trim( sprintf(
				/* translators: 1: admin display name, 2: terms version, 3: note */
				__( 'Medication terms acknowledgement recorded manually by %1$s (terms v%2$s). %3$s', 'fishotel' ),
				$record['admin_user_name'],
				$record['terms_version'],
				$record['note']
			) ) );
			$order->save();
			$result = 'saved';
		}

		$redirect = add_query_arg( 'fishotel_med_terms', $result, $order->get_edit_order_url() );
		wp_safe_redirect( $redirect );
		exit;
	}

	/** Success notice after the redirect back to the order screen. */
	public static function manual_record_notice() {
		if ( ! isset( $_GET['fishotel_med_terms'] ) || ! current_user_can( 'manage_woocommerce' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag.
			return;
		}
		$flag = sanitize_key( wp_unslash( $_GET['fishotel_med_terms'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $flag === 'saved' ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Medication terms acknowledgement recorded.', 'fishotel' ) . '</p></div>';
		} elseif ( $flag === 'exists' ) {
			echo '<div class="notice notice-info is-dismissible"><p>' . esc_html__( 'This order already has a medication terms record — nothing changed.', 'fishotel' ) . '</p></div>';
		} elseif ( $flag === 'fulfillment' ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'This is a combined-box fulfillment order — record the medication terms acknowledgement on the source order instead. Nothing changed.', 'fishotel' ) . '</p></div>';
		}
	}

	// ── Emails ───────────────────────────────────────────────────────

	/**
	 * One line in the order emails when a record exists.
	 *
	 * @param WC_Order $order
	 * @param bool     $sent_to_admin
	 * @param bool     $plain_text
	 * @param object   $email
	 */
	public static function email_line( $order, $sent_to_admin = false, $plain_text = false, $email = null ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$email_id = is_object( $email ) && isset( $email->id ) ? (string) $email->id : '';
		if ( $email_id !== '' && ! in_array( $email_id, self::EMAIL_IDS, true ) ) {
			return;
		}
		$record = self::get_record( $order );
		if ( ! $record ) {
			return;
		}
		if ( ! $sent_to_admin && isset( $record['source'] ) && $record['source'] === 'manual' ) {
			// Customer copy of a manual record: neutral wording (same as the
			// My Account line) — no staff name, and no claim that the
			// customer "accepted" at a time only the admin clicked.
			$line = sprintf(
				/* translators: 1: local date/time, 2: terms version */
				__( 'Medication terms acknowledgement recorded %1$s · terms v%2$s', 'fishotel' ),
				isset( $record['accepted_at_local'] ) ? (string) $record['accepted_at_local'] : '',
				isset( $record['terms_version'] ) ? (string) $record['terms_version'] : ''
			);
		} else {
			$line = FisHotel_Med_Terms::format_line( $record, false );
		}
		if ( $plain_text ) {
			echo "\n" . esc_html( $line ) . "\n";
			return;
		}
		echo '<p class="fishotel-med-terms-email" style="margin:0 0 16px;font-size:13px;color:#555;">' . esc_html( $line ) . '</p>';
	}

	// ── My Account ───────────────────────────────────────────────────

	/** "You accepted the medication terms on <date>." under the order table. */
	public static function account_line( $order ) {
		if ( ! $order instanceof WC_Order || is_admin() ) {
			return;
		}
		$record = self::get_record( $order );
		if ( ! $record ) {
			return;
		}
		$date = isset( $record['accepted_at_local'] ) ? (string) $record['accepted_at_local'] : '';
		$text = isset( $record['source'] ) && $record['source'] === 'manual'
			/* translators: %s = local date/time */
			? sprintf( __( 'Your medication terms acknowledgement was recorded on %s.', 'fishotel' ), $date )
			/* translators: %s = local date/time */
			: sprintf( __( 'You accepted the medication terms on %s.', 'fishotel' ), $date );
		echo '<p class="fishotel-med-terms-account">' . esc_html( $text ) . ' ' . FisHotel_Med_Disclaimer::terms_link_html() . '</p>'; // Link escaped inside.
	}

	// ── Shortcode ────────────────────────────────────────────────────

	/** [fishotel_med_terms] — body, version and checkbox label. */
	public static function shortcode( $atts = [] ) {
		$body    = FisHotel_Med_Settings::get_disclaimer_body();
		$label   = FisHotel_Med_Settings::get_checkout_label();
		$version = FisHotel_Med_Settings::get_terms_version();

		$out  = '<div class="fishotel-med-terms">';
		$out .= '<div class="fishotel-med-terms__body">' . wp_kses_post( wpautop( $body ) ) . '</div>';
		$out .= '<p class="fishotel-med-terms__label"><strong>' . esc_html__( 'At checkout you confirm:', 'fishotel' ) . '</strong> ' . esc_html( wp_strip_all_tags( $label ) ) . '</p>';
		$out .= '<p class="fishotel-med-terms__version">' . esc_html( sprintf( /* translators: %s = terms version */ __( 'Terms version %s', 'fishotel' ), $version ) ) . '</p>';
		$out .= '</div>';
		return $out;
	}
}
