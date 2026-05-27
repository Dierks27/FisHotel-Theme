/**
 * FisHotel Shipments meta box — admin JS.
 *
 * Drives the unified "Shipments" meta box on every order edit page:
 *   - Add Shipment form → POST to ShipTracker's wp_ajax_fst_add_tracking with
 *     line_item_ids[]. ShipTracker v1.9.1+ handles fan-out + per-source
 *     customer emails.
 *   - Re-check / Remove → wp_ajax_fst_recheck_tracking / fst_remove_tracking.
 *   - EA Status dropdown + bulk actions → wp_ajax_fishotel_ea_status_update.
 */
( function ( $ ) {
	'use strict';

	$( function () {
		var $box = $( '#fishotel-shipments .fishotel-shipments-metabox' );
		if ( ! $box.length ) {
			return;
		}

		var nonce   = $box.find( '#fhsm_nonce' ).val();
		var orderId = $box.find( '#fhsm-order-id' ).val();

		function setMessage( $el, text, kind ) {
			$el.removeClass( 'fhsm-message-error fhsm-message-success' )
				.text( text || '' );
			if ( kind === 'error' ) {
				$el.addClass( 'fhsm-message-error' );
			} else if ( kind === 'success' ) {
				$el.addClass( 'fhsm-message-success' );
			}
		}

		// ── Add Shipment ────────────────────────────────────────────────

		$box.on( 'change', '#fhsm-select-all-uncovered', function () {
			$box.find( '.fhsm-line-item-check:not(:disabled)' ).prop( 'checked', this.checked );
		} );

		$box.on( 'click', '#fhsm-save-shipment', function () {
			var $btn      = $( this );
			var $msg      = $box.find( '#fhsm-message' );
			var tracking  = String( $box.find( '#fhsm-new-tracking' ).val() || '' ).trim();
			var carrier   = String( $box.find( '#fhsm-new-carrier' ).val() || 'auto' );
			// Coerce to int so a bad cast on the server can't silently fall
			// through to "covers all" because of a string/int mismatch.
			var itemIds   = $box.find( '.fhsm-line-item-check:checked' ).map( function () {
				return parseInt( $( this ).val(), 10 );
			} ).get().filter( function ( n ) { return n > 0; } );

			if ( ! tracking ) {
				setMessage( $msg, 'Tracking number is required.', 'error' );
				return;
			}
			if ( ! itemIds.length ) {
				setMessage( $msg, 'Select at least one item.', 'error' );
				return;
			}

			$btn.prop( 'disabled', true );
			setMessage( $msg, 'Saving…', '' );

			// $.ajax with traditional:false explicitly so line_item_ids
			// serializes as line_item_ids[]=12&line_item_ids[]=34 — the form
			// ShipTracker's $_POST['line_item_ids'] reader expects.
			$.ajax( {
				url:        window.ajaxurl,
				method:     'POST',
				traditional: false,
				data:       {
					action:          'fst_add_tracking',
					nonce:           nonce,
					order_id:        orderId,
					tracking_number: tracking,
					carrier:         carrier,
					line_item_ids:   itemIds
				}
			} ).done( function ( response ) {
				if ( response && response.success ) {
					var msgText = ( response.data && response.data.message ) ? response.data.message : 'Shipment saved.';
					setMessage( $msg, msgText, 'success' );
					setTimeout( function () { window.location.reload(); }, 800 );
				} else {
					var err = ( response && response.data ) ? ( typeof response.data === 'string' ? response.data : ( response.data.message || 'Failed to save shipment.' ) ) : 'Failed to save shipment.';
					setMessage( $msg, err, 'error' );
					$btn.prop( 'disabled', false );
				}
			} ).fail( function () {
				setMessage( $msg, 'Network error — please retry.', 'error' );
				$btn.prop( 'disabled', false );
			} );
		} );

		// ── Re-check ────────────────────────────────────────────────────

		$box.on( 'click', '.fhsm-recheck', function () {
			var $btn = $( this );
			var id   = $btn.data( 'shipment-id' );
			$btn.prop( 'disabled', true ).text( 'Re-checking…' );
			$.post( window.ajaxurl, {
				action:      'fst_recheck_tracking',
				nonce:       nonce,
				shipment_id: id
			} ).always( function () {
				window.location.reload();
			} );
		} );

		// ── Remove ──────────────────────────────────────────────────────

		$box.on( 'click', '.fhsm-remove', function () {
			if ( ! window.confirm( 'Remove this shipment? This cannot be undone.' ) ) {
				return;
			}
			var $btn = $( this );
			var id   = $btn.data( 'shipment-id' );
			$btn.prop( 'disabled', true ).text( 'Removing…' );
			$.post( window.ajaxurl, {
				action:      'fst_remove_tracking',
				nonce:       nonce,
				shipment_id: id
			} ).always( function () {
				window.location.reload();
			} );
		} );

		// ── EA Status ───────────────────────────────────────────────────

		$box.on( 'change', '#fhsm-ea-check-all', function () {
			$box.find( '.fhsm-ea-check' ).prop( 'checked', this.checked );
		} );

		$box.on( 'change', '.fhsm-ea-status', function () {
			var $sel   = $( this );
			var itemId = $sel.data( 'item-id' );
			var status = String( $sel.val() );
			var $msg   = $box.find( '.fhsm-ea-message' );
			$sel.prop( 'disabled', true );
			$.post( window.ajaxurl, {
				action:  'fishotel_ea_status_update',
				nonce:   nonce,
				item_id: itemId,
				status:  status
			} ).done( function ( response ) {
				if ( response && response.success ) {
					var $row = $sel.closest( 'tr' );
					$row.find( '.fhsm-ea-ts' ).text( '(just now)' );
					if ( ! $row.find( '.fhsm-ea-ts' ).length ) {
						$sel.after( ' <small class="fhsm-ea-ts">(just now)</small>' );
					}
					setMessage( $msg, 'Saved.', 'success' );
				} else {
					setMessage( $msg, 'Could not save EA status.', 'error' );
				}
			} ).fail( function () {
				setMessage( $msg, 'Network error — please retry.', 'error' );
			} ).always( function () {
				$sel.prop( 'disabled', false );
			} );
		} );

		$box.on( 'click', '.fhsm-ea-bulk', function () {
			var newStatus = String( $( this ).data( 'status' ) );
			var $checked  = $box.find( '.fhsm-ea-check:checked' );
			if ( ! $checked.length ) {
				setMessage( $box.find( '.fhsm-ea-message' ), 'Select at least one row.', 'error' );
				return;
			}
			$checked.each( function () {
				var $row = $( this ).closest( 'tr' );
				$row.find( '.fhsm-ea-status' ).val( newStatus ).trigger( 'change' );
			} );
		} );
	} );

} )( jQuery );
