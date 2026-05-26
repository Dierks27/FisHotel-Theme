/**
 * FisHotel — Self-serve delivery date change (My Account).
 *
 * Wires up the YOUR FOLIO "Change Delivery Date" inline form. The PHP side
 * lives in inc/customer/class-fishotel-self-serve-date.php.
 */
( function ( $ ) {
	'use strict';

	$( function () {
		var $root = $( '.fh-view-order__delivery' );
		if ( ! $root.length ) {
			return;
		}

		function setMessage( $msg, text, kind ) {
			$msg.removeClass( 'is-error is-success' ).text( text || '' );
			if ( kind === 'error' ) {
				$msg.addClass( 'is-error' );
			} else if ( kind === 'success' ) {
				$msg.addClass( 'is-success' );
			}
		}

		function openForm( $delivery ) {
			var $btn    = $delivery.find( '.fh-change-date-btn' );
			var $form   = $delivery.find( '.fh-change-date-form' );
			var $select = $form.find( '.fh-date-select' );
			var $msg    = $form.find( '.fh-date-message' );
			var $save   = $form.find( '.fh-save-date-btn' );

			if ( ! $btn.length ) { return; }

			$btn.hide();
			$form.show();
			setMessage( $msg, 'Loading available dates…', '' );
			$save.prop( 'disabled', true );
			$select.empty().append( '<option>Loading…</option>' );

			$.post( window.fishotelDateChange.ajaxurl, {
				action:   'fishotel_get_available_dates',
				nonce:    $delivery.find( '[name="' + 'fishotel_change_date_nonce' + '"]' ).val(),
				order_id: $delivery.data( 'order-id' )
			} ).done( function ( res ) {
				$select.empty();
				if ( ! res || ! res.success ) {
					setMessage( $msg, ( res && res.data ) ? res.data : 'Could not load dates.', 'error' );
					return;
				}
				var dates = res.data.dates || [];
				if ( ! dates.length ) {
					setMessage( $msg, 'No alternate dates are available right now. Please contact us.', 'error' );
					return;
				}
				dates.forEach( function ( d ) {
					var label = d.label + ( d.is_current ? ' (current)' : '' );
					var $opt  = $( '<option/>' ).val( d.date ).text( label );
					if ( d.is_current ) {
						$opt.prop( 'disabled', true ).prop( 'selected', true );
					}
					$select.append( $opt );
				} );
				setMessage( $msg, '', '' );
				$save.prop( 'disabled', false );
			} ).fail( function () {
				setMessage( $msg, 'Network error — please retry.', 'error' );
			} );
		}

		$root.on( 'click', '.fh-change-date-btn', function () {
			openForm( $( this ).closest( '.fh-view-order__delivery' ) );
		} );

		$root.on( 'click', '.fh-cancel-date-btn', function () {
			var $delivery = $( this ).closest( '.fh-view-order__delivery' );
			$delivery.find( '.fh-change-date-form' ).hide();
			$delivery.find( '.fh-change-date-btn' ).show();
		} );

		$root.on( 'click', '.fh-save-date-btn', function () {
			var $btn      = $( this );
			var $delivery = $btn.closest( '.fh-view-order__delivery' );
			var $form     = $btn.closest( '.fh-change-date-form' );
			var $select   = $form.find( '.fh-date-select' );
			var $msg      = $form.find( '.fh-date-message' );
			var newDate   = String( $select.val() || '' );
			var orderId   = $delivery.data( 'order-id' );

			if ( ! newDate ) {
				setMessage( $msg, 'Please select a date.', 'error' );
				return;
			}
			$btn.prop( 'disabled', true );
			setMessage( $msg, 'Saving…', '' );

			$.post( window.fishotelDateChange.ajaxurl, {
				action:   'fishotel_change_delivery_date',
				nonce:    $delivery.find( '[name="' + 'fishotel_change_date_nonce' + '"]' ).val(),
				order_id: orderId,
				new_date: newDate
			} ).done( function ( res ) {
				if ( res && res.success ) {
					setMessage( $msg, 'Updated — refreshing…', 'success' );
					setTimeout( function () { window.location.reload(); }, 900 );
				} else {
					setMessage( $msg, ( res && res.data ) ? res.data : 'Could not save.', 'error' );
					$btn.prop( 'disabled', false );
				}
			} ).fail( function () {
				setMessage( $msg, 'Network error — please retry.', 'error' );
				$btn.prop( 'disabled', false );
			} );
		} );

		// Auto-open when hash present (deep link from Past Stays "change" link).
		if ( window.location.hash === '#fh-change-date' ) {
			var $delivery = $root.first();
			if ( $delivery.find( '.fh-change-date-btn' ).length ) {
				openForm( $delivery );
				setTimeout( function () {
					var node = $delivery.get( 0 );
					if ( node && node.scrollIntoView ) {
						node.scrollIntoView( { behavior: 'smooth', block: 'center' } );
					}
				}, 250 );
			}
		}
	} );

} )( jQuery );
