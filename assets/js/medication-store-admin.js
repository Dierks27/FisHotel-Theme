/*
 * FisHotel Medication Store — admin JS
 *
 * Wires three pieces of admin UI:
 *   1. Mode-driven field visibility on the product editor's
 *      FisHotel Medication tab: EA fields show in EA mode, Amazon
 *      ASIN row shows in Amazon mode, both hide in Self mode.
 *   2. The manual "Sync stock from EA" button (AJAX → admin-ajax).
 *   3. The "Replace" button next to each masked secret on the
 *      Medication Store Settings page — hides the mask and reveals
 *      the password input so the admin can paste a new value.
 */
( function ( $ ) {
	'use strict';

	$( function () {
		bindModeVisibility();
		bindSyncButton();
		bindSecretReplace();
	} );

	function bindModeVisibility() {
		var $select = $( '.fishotel-med-mode' );
		if ( ! $select.length ) return;
		var $ea     = $( '.fishotel-med-fields-ea' );
		var $amazon = $( '.fishotel-med-fields-amazon' );

		function apply() {
			var mode = $select.val();
			$ea.attr( 'data-fishotel-hidden',     mode === 'ea'     ? '0' : '1' );
			$amazon.attr( 'data-fishotel-hidden', mode === 'amazon' ? '0' : '1' );
		}
		apply();
		$select.on( 'change', apply );
	}

	function bindSyncButton() {
		$( document ).on( 'click', '.fishotel-med-sync-btn', function ( e ) {
			e.preventDefault();
			var $btn    = $( this );
			var $status = $btn.siblings( '.fishotel-med-sync-status' ).first();
			var pid     = parseInt( $btn.data( 'product-id' ), 10 );
			if ( ! pid ) return;
			if ( typeof window.fishotelMedAdmin === 'undefined' ) return;

			$btn.prop( 'disabled', true );
			$status.text( 'Syncing…' ).attr( 'data-state', 'busy' );

			$.post( window.fishotelMedAdmin.ajaxUrl, {
				action:     'fishotel_med_sync_stock',
				nonce:      window.fishotelMedAdmin.nonceSync,
				product_id: pid
			} )
				.done( function ( res ) {
					if ( res && res.success && res.data ) {
						var d = res.data;
						var msg = 'Stock: ' + d.status;
						if ( d.quantity !== null && typeof d.quantity !== 'undefined' ) {
							msg += ' (' + d.quantity + ')';
						}
						if ( d.synced_at_label ) {
							msg += ' — ' + d.synced_at_label;
						}
						$status.text( msg ).attr( 'data-state', 'ok' );
					} else {
						var err = ( res && res.data && res.data.message ) ? res.data.message : 'Sync failed.';
						$status.text( err ).attr( 'data-state', 'error' );
					}
				} )
				.fail( function () {
					$status.text( 'Sync failed (network).' ).attr( 'data-state', 'error' );
				} )
				.always( function () {
					$btn.prop( 'disabled', false );
				} );
		} );
	}

	function bindSecretReplace() {
		$( document ).on( 'click', '.fishotel-med-secret__replace', function ( e ) {
			e.preventDefault();
			var $wrap  = $( this ).closest( '.fishotel-med-secret' );
			var $mask  = $wrap.find( '.fishotel-med-secret__mask' );
			var $input = $wrap.find( '.fishotel-med-secret__input' );
			$mask.hide();
			$( this ).hide();
			$input.prop( 'hidden', false ).attr( 'placeholder', 'Paste new value' ).focus();
		} );
	}
} )( jQuery );
