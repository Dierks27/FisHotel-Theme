<?php
/**
 * Template: Shipments meta box body.
 *
 * Locals (provided by FisHotel_Shipments_Metabox::render):
 *   WC_Order $order
 *   bool     $is_fulfillment
 *   array    $items_by_source   [ source_id => [ 'order'=>WC_Order, 'items'=>WC_Order_Item_Product[] ] ]
 *   array    $shipments         FST_Shipment[] (or stdClass-like rows)
 *   array    $legacy_shipments  see resolve_phase1_legacy_shipments()
 *   int[]    $covered_ids
 */
defined( 'ABSPATH' ) || exit;

$ea_items = FisHotel_Shipments_Metabox::get_ea_items( $items_by_source );
?>
<div class="fishotel-shipments-metabox">

	<?php if ( ! empty( $ea_items ) ) : ?>
		<div class="fhsm-ea-panel">
			<h3><?php esc_html_e( 'Items at EverythingAquatic', 'fishotel' ); ?></h3>
			<table class="widefat striped">
				<thead>
					<tr>
						<th style="width:24px;"><input type="checkbox" id="fhsm-ea-check-all"></th>
						<th><?php esc_html_e( 'Item', 'fishotel' ); ?></th>
						<?php if ( $is_fulfillment ) : ?>
							<th><?php esc_html_e( 'Source', 'fishotel' ); ?></th>
						<?php endif; ?>
						<th><?php esc_html_e( 'Status', 'fishotel' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $ea_items as $entry ) :
						$item      = $entry['item'];
						$item_id   = $entry['item_id'];
						$source_id = $entry['source_id'];
						$status    = (string) $item->get_meta( '_fishotel_ea_status' );
						if ( ! in_array( $status, [ 'not_sent', 'sent', 'confirmed' ], true ) ) {
							$status = 'not_sent';
						}
						$ts = (string) $item->get_meta( '_fishotel_ea_status_at' );
					?>
						<tr data-item-id="<?php echo esc_attr( $item_id ); ?>">
							<td><input type="checkbox" class="fhsm-ea-check"></td>
							<td><?php echo esc_html( $item->get_name() ); ?> &times; <?php echo esc_html( (string) $item->get_quantity() ); ?></td>
							<?php if ( $is_fulfillment ) : ?>
								<td>#<?php echo esc_html( (string) $source_id ); ?></td>
							<?php endif; ?>
							<td>
								<select class="fhsm-ea-status" data-item-id="<?php echo esc_attr( $item_id ); ?>">
									<option value="not_sent" <?php selected( $status, 'not_sent' ); ?>><?php esc_html_e( 'Not Sent', 'fishotel' ); ?></option>
									<option value="sent" <?php selected( $status, 'sent' ); ?>><?php esc_html_e( 'Sent', 'fishotel' ); ?></option>
									<option value="confirmed" <?php selected( $status, 'confirmed' ); ?>><?php esc_html_e( 'Confirmed', 'fishotel' ); ?></option>
								</select>
								<?php if ( '' !== $ts ) :
									$secs = strtotime( $ts );
									if ( $secs ) :
								?>
									<small class="fhsm-ea-ts">(<?php echo esc_html( human_time_diff( $secs ) ); ?> ago)</small>
								<?php endif; endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<div class="fhsm-ea-bulk-actions">
				<button type="button" class="button fhsm-ea-bulk" data-status="sent"><?php esc_html_e( 'Mark Selected as Sent', 'fishotel' ); ?></button>
				<button type="button" class="button fhsm-ea-bulk" data-status="confirmed"><?php esc_html_e( 'Mark Selected as Confirmed', 'fishotel' ); ?></button>
				<span class="fhsm-message fhsm-ea-message" aria-live="polite"></span>
			</div>
		</div>
	<?php endif; ?>

	<div class="fhsm-shipments-list">
		<h3><?php esc_html_e( 'Shipments', 'fishotel' ); ?></h3>

		<?php if ( ! class_exists( 'FST_Shipment' ) ) : ?>
			<p class="fhsm-empty"><em><?php esc_html_e( 'ShipTracker plugin is not active — shipment data is unavailable.', 'fishotel' ); ?></em></p>
		<?php elseif ( empty( $shipments ) && empty( $legacy_shipments ) ) : ?>
			<p class="fhsm-empty"><?php esc_html_e( 'No shipments yet.', 'fishotel' ); ?></p>
		<?php endif; ?>

		<?php foreach ( $shipments as $shipment ) : ?>
			<?php try { ?>
				<?php
				$items_in_shipment = FisHotel_Shipments_Metabox::resolve_shipment_items( $shipment );
				$status            = isset( $shipment->status ) ? (string) $shipment->status : '';
				$carrier           = isset( $shipment->carrier ) ? (string) $shipment->carrier : '';
				$tracking_no       = isset( $shipment->tracking_number ) ? (string) $shipment->tracking_number : '';
				$source_order_id   = isset( $shipment->order_id ) ? (int) $shipment->order_id : 0;
				$ship_id           = isset( $shipment->id ) ? (int) $shipment->id : 0;
				$color             = '#888';
				$label             = ucfirst( $status );
				if ( class_exists( 'FST_Carrier' ) ) {
					if ( method_exists( 'FST_Carrier', 'get_status_color' ) ) {
						$color = FST_Carrier::get_status_color( $status );
					}
					if ( method_exists( 'FST_Carrier', 'get_status_label' ) ) {
						$label = FST_Carrier::get_status_label( $status );
					}
				}
				$tracking_url = FisHotel_Shipments_Metabox::get_tracking_url( $shipment );
				?>
				<div class="fhsm-shipment" data-shipment-id="<?php echo esc_attr( $ship_id ); ?>">
					<div class="fhsm-shipment-header">
						<span class="fhsm-status-badge" style="background:<?php echo esc_attr( $color ); ?>;">
							<?php echo esc_html( $label ); ?>
						</span>
						<span class="fhsm-carrier"><?php echo esc_html( strtoupper( $carrier ) ); ?></span>
						<?php if ( '#' !== $tracking_url ) : ?>
							<a class="fhsm-tracking-number" href="<?php echo esc_url( $tracking_url ); ?>" target="_blank" rel="noopener noreferrer">
								<?php echo esc_html( $tracking_no ); ?>
							</a>
						<?php else : ?>
							<span class="fhsm-tracking-number"><?php echo esc_html( $tracking_no ); ?></span>
						<?php endif; ?>
						<?php if ( $is_fulfillment && $source_order_id ) : ?>
							<span class="fhsm-source-tag">
								<?php
								printf(
									/* translators: %d order ID */
									esc_html__( 'source: #%d', 'fishotel' ),
									(int) $source_order_id
								);
								?>
							</span>
						<?php endif; ?>
					</div>
					<?php if ( ! empty( $items_in_shipment ) ) : ?>
						<ul class="fhsm-shipment-items">
							<?php foreach ( $items_in_shipment as $sitem ) : ?>
								<li><?php echo esc_html( $sitem->get_name() ); ?> &times; <?php echo esc_html( (string) $sitem->get_quantity() ); ?></li>
							<?php endforeach; ?>
						</ul>
					<?php else : ?>
						<p class="fhsm-covers-all"><em><?php esc_html_e( 'Covers all items on this order.', 'fishotel' ); ?></em></p>
					<?php endif; ?>
					<div class="fhsm-shipment-actions">
						<button type="button" class="button fhsm-recheck" data-shipment-id="<?php echo esc_attr( $ship_id ); ?>"><?php esc_html_e( 'Re-check', 'fishotel' ); ?></button>
						<button type="button" class="button fhsm-remove" data-shipment-id="<?php echo esc_attr( $ship_id ); ?>"><?php esc_html_e( 'Remove', 'fishotel' ); ?></button>
					</div>
				</div>
			<?php } catch ( \Throwable $e ) {
				$fail_id = is_object( $shipment ) && isset( $shipment->id ) ? (string) $shipment->id : '?';
				?>
				<div class="fhsm-shipment fhsm-shipment-error">
					<strong><?php esc_html_e( 'Shipment render failed', 'fishotel' ); ?>:</strong>
					<?php
					printf(
						/* translators: 1: shipment id, 2: error message */
						esc_html__( '#%1$s — %2$s', 'fishotel' ),
						esc_html( $fail_id ),
						esc_html( $e->getMessage() )
					);
					?>
				</div>
			<?php } ?>
		<?php endforeach; ?>

		<?php foreach ( $legacy_shipments as $legacy ) : ?>
			<div class="fhsm-shipment fhsm-shipment-legacy">
				<div class="fhsm-shipment-header">
					<span class="fhsm-legacy-badge"><?php esc_html_e( 'LEGACY (Phase 1)', 'fishotel' ); ?></span>
					<span class="fhsm-tracking-number"><?php echo esc_html( $legacy['tracking_number'] ); ?></span>
					<?php if ( $is_fulfillment ) : ?>
						<span class="fhsm-source-tag">
							<?php
							printf(
								/* translators: %d order ID */
								esc_html__( 'source: #%d', 'fishotel' ),
								(int) $legacy['order_id']
							);
							?>
						</span>
					<?php endif; ?>
				</div>
				<ul class="fhsm-shipment-items">
					<?php foreach ( $legacy['items'] as $litem ) : ?>
						<li><?php echo esc_html( $litem->get_name() ); ?> &times; <?php echo esc_html( (string) $litem->get_quantity() ); ?></li>
					<?php endforeach; ?>
				</ul>
				<p class="fhsm-legacy-note">
					<small><?php esc_html_e( 'Created with the old Phase 1 system. These items are covered (excluded from the "Add Shipment" form below).', 'fishotel' ); ?></small>
				</p>
			</div>
		<?php endforeach; ?>
	</div>

	<div class="fhsm-add-shipment">
		<h3><?php esc_html_e( 'Add Shipment', 'fishotel' ); ?></h3>

		<p>
			<label for="fhsm-new-tracking"><strong><?php esc_html_e( 'Tracking number:', 'fishotel' ); ?></strong></label>
			<input type="text" id="fhsm-new-tracking" class="widefat" placeholder="1Z999AA1...">
		</p>
		<p>
			<label for="fhsm-new-carrier"><strong><?php esc_html_e( 'Carrier:', 'fishotel' ); ?></strong></label>
			<select id="fhsm-new-carrier" class="widefat">
				<option value="auto"><?php esc_html_e( 'Auto-detect', 'fishotel' ); ?></option>
				<option value="ups">UPS</option>
				<option value="usps">USPS</option>
				<option value="fedex">FedEx</option>
			</select>
		</p>

		<p><strong><?php esc_html_e( 'Items in this shipment:', 'fishotel' ); ?></strong></p>

		<?php $any_uncovered = false; ?>
		<?php foreach ( $items_by_source as $source_id => $group ) : ?>
			<?php if ( $is_fulfillment ) : ?>
				<h4 class="fhsm-source-heading">
					<?php
					printf(
						/* translators: %d source order ID */
						esc_html__( 'From source order #%d', 'fishotel' ),
						(int) $source_id
					);
					?>
				</h4>
			<?php endif; ?>
			<ul class="fhsm-item-checkboxes">
				<?php foreach ( $group['items'] as $item_id => $item ) :
					if ( ! ( $item instanceof WC_Order_Item_Product ) ) {
						continue;
					}
					$is_covered = in_array( (int) $item_id, $covered_ids, true );
					if ( ! $is_covered ) {
						$any_uncovered = true;
					}
				?>
					<li>
						<label>
							<input type="checkbox"
								class="fhsm-line-item-check"
								value="<?php echo esc_attr( $item_id ); ?>"
								data-source-id="<?php echo esc_attr( $source_id ); ?>"
								<?php disabled( $is_covered ); ?>>
							<?php echo esc_html( $item->get_name() ); ?>
							&times; <?php echo esc_html( (string) $item->get_quantity() ); ?>
							<?php if ( $is_covered ) : ?>
								<em class="fhsm-covered-note">(<?php esc_html_e( 'already in a shipment', 'fishotel' ); ?>)</em>
							<?php endif; ?>
						</label>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endforeach; ?>

		<?php if ( $any_uncovered ) : ?>
			<p>
				<label>
					<input type="checkbox" id="fhsm-select-all-uncovered">
					<?php esc_html_e( 'Select all unshipped items', 'fishotel' ); ?>
				</label>
			</p>
			<p>
				<button type="button" class="button button-primary" id="fhsm-save-shipment">
					<?php esc_html_e( 'Add Shipment', 'fishotel' ); ?>
				</button>
				<span id="fhsm-message" class="fhsm-message" aria-live="polite"></span>
			</p>
		<?php else : ?>
			<p class="fhsm-all-covered"><em><?php esc_html_e( 'All items on this order are covered by shipments.', 'fishotel' ); ?></em></p>
		<?php endif; ?>
	</div>

	<input type="hidden" id="fhsm-order-id" value="<?php echo esc_attr( $order->get_id() ); ?>">
	<?php wp_nonce_field( FisHotel_Shipments_Metabox::NONCE_ACTION, FisHotel_Shipments_Metabox::NONCE_FIELD ); ?>
</div>
