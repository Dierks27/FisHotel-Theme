/**
 * FisHotel Shipments meta box — admin JS.
 *
 * Drives the unified "Shipments" meta box on every order edit page:
 *   - Add Shipment form → POST to ShipTracker's wp_ajax_fst_add_tracking with
 *     line_item_ids[]. ShipTracker v1.9.1+ handles fan-out + per-source
 *     customer emails.
 *   - Label cost (only rendered when ShipTracker v1.9.17+ records it): the
 *     optional cost / box size / weight / "bought through" inputs ride along
 *     with Add Shipment, and each shipment's inline form saves through
 *     wp_ajax_fst_set_label_cost. A value ShipTracker refuses is reported
 *     next to its field and nothing typed is lost.
 *   - Re-check / Remove → wp_ajax_fst_recheck_tracking / fst_remove_tracking.
 *   - EA Status dropdown + bulk actions → wp_ajax_fishotel_ea_status_update.
 *
 * The helpers above the jQuery block are pure and exported for
 * `node tests/admin-shipments.test.js`.
 */
( function ( $ ) {
	'use strict';

	// ── Label cost helpers (pure) ───────────────────────────────────────

	// localStorage key: the label source of the last shipment saved from
	// this browser, offered as the default the next time.
	var SOURCE_KEY = 'fishotelLastLabelSource';

	// Value of the "Custom…" entry of the box size dropdown.
	var CUSTOM_BOX = 'custom';

	function clean( value ) {
		return ( value === undefined || value === null ) ? '' : String( value ).trim();
	}

	/**
	 * The box size to post: a standard box's size straight from the
	 * dropdown, or the typed LxWxH when "Custom…" is picked. Never the
	 * word "custom" itself.
	 */
	function boxDims( box, dims ) {
		box = clean( box );
		return box === CUSTOM_BOX ? clean( dims ) : box;
	}

	/**
	 * Cost fields to send with fst_add_tracking — only what was filled in,
	 * so an untouched form posts exactly what it always did.
	 *
	 * A source that is merely the remembered default (not picked in this
	 * page view) is sent only together with a cost, size or weight: labels
	 * are bought wherever the rate is best, so the default must never
	 * record itself on a shipment nobody entered a cost for.
	 *
	 * @param {Object} v { cost, box, dims, weight, source, sourceTouched }
	 */
	function addCostFields( v ) {
		v = v || {};
		var out    = {};
		var cost   = clean( v.cost );
		var dims   = boxDims( v.box, v.dims );
		var weight = clean( v.weight );
		var source = clean( v.source );

		if ( cost ) {
			out.label_cost = cost;
		}
		if ( dims ) {
			out.box_dims = dims;
		}
		if ( weight ) {
			out.box_weight = weight;
		}
		if ( source && ( v.sourceTouched || cost || dims || weight ) ) {
			out.label_source = source;
		}
		return out;
	}

	/**
	 * Cost fields to send with fst_set_label_cost — every field of the edit
	 * form, emptied ones included: ShipTracker clears a field that is sent
	 * empty and leaves alone a field that is not sent. label_source is left
	 * out when the form has no source dropdown (v.source null / undefined).
	 *
	 * @param {Object} v { cost, box, dims, weight, source }
	 */
	function editCostFields( v ) {
		v = v || {};
		var out = {
			label_cost: clean( v.cost ),
			box_dims:   boxDims( v.box, v.dims ),
			box_weight: clean( v.weight )
		};
		if ( v.source !== undefined && v.source !== null ) {
			out.label_source = clean( v.source );
		}
		return out;
	}

	/**
	 * Which cost field a ShipTracker error message is about: 'cost', 'box',
	 * 'weight', or '' when it is about something else. ShipTracker's AJAX
	 * errors are a bare message, so the field is told from how its three
	 * validation messages start.
	 */
	function errorField( message ) {
		message = clean( message );
		if ( /^The label cost\b/i.test( message ) ) {
			return 'cost';
		}
		if ( /^The box size\b/i.test( message ) ) {
			return 'box';
		}
		if ( /^The box weight\b/i.test( message ) ) {
			return 'weight';
		}
		return '';
	}

	/** The error text of a failed admin-ajax response (string data, or data.message). */
	function responseError( response, fallback ) {
		var data = response ? response.data : null;
		if ( typeof data === 'string' && data ) {
			return data;
		}
		if ( data && typeof data.message === 'string' && data.message ) {
			return data.message;
		}
		return fallback;
	}

	/**
	 * The remembered label source, when it is still one of the dropdown's
	 * options — else ''. Storage that is missing or blocked reads as ''.
	 */
	function rememberedSource( storage, options ) {
		var last = '';
		try {
			last = clean( storage && storage.getItem( SOURCE_KEY ) );
		} catch ( e ) {
			last = '';
		}
		if ( ! last || ! options || options.indexOf( last ) === -1 ) {
			return '';
		}
		return last;
	}

	/** Remember the label source just used. An empty one keeps the previous. */
	function rememberSource( storage, source ) {
		source = clean( source );
		if ( ! source ) {
			return false;
		}
		try {
			storage.setItem( SOURCE_KEY, source );
			return true;
		} catch ( e ) {
			return false;
		}
	}

	if ( typeof module === 'object' && module && module.exports ) {
		module.exports = {
			SOURCE_KEY:       SOURCE_KEY,
			boxDims:          boxDims,
			addCostFields:    addCostFields,
			editCostFields:   editCostFields,
			errorField:       errorField,
			responseError:    responseError,
			rememberedSource: rememberedSource,
			rememberSource:   rememberSource
		};
	}

	if ( ! $ ) {
		return;
	}

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

		// ── Label cost ──────────────────────────────────────────────────

		// localStorage can throw on access (blocked site data, private mode).
		function browserStorage() {
			try {
				return window.localStorage;
			} catch ( e ) {
				return null;
			}
		}

		// The values of one set of cost inputs (the add form, or an edit form).
		function readCostValues( $fields ) {
			var $source = $fields.find( '.fhsm-cost-source' );
			return {
				cost:          $fields.find( '.fhsm-cost-amount' ).val(),
				box:           $fields.find( '.fhsm-cost-box' ).val(),
				dims:          $fields.find( '.fhsm-cost-dims' ).val(),
				weight:        $fields.find( '.fhsm-cost-weight' ).val(),
				source:        $source.length ? $source.val() : null,
				sourceTouched: $source.data( 'fhsmTouched' ) === true
			};
		}

		function clearCostErrors( $fields ) {
			$fields.find( '.fhsm-cost-field' ).removeClass( 'fhsm-cost-field-invalid' );
			$fields.find( '.fhsm-field-error' ).text( '' );
			$fields.find( '[aria-invalid]' ).removeAttr( 'aria-invalid' );
		}

		// Put ShipTracker's message next to the field it is about. Returns
		// false when the message is not about one of these fields.
		function showCostError( $fields, message ) {
			var field  = errorField( message );
			var $field = field ? $fields.find( '.fhsm-cost-field[data-field="' + field + '"]' ) : $();
			if ( ! $field.length ) {
				return false;
			}
			$field.addClass( 'fhsm-cost-field-invalid' );
			$field.find( '.fhsm-field-error' ).text( message );
			$field.find( 'input, select' ).filter( ':visible' ).last()
				.attr( 'aria-invalid', 'true' ).trigger( 'focus' );
			return true;
		}

		var $newFields = $box.find( '.fhsm-new-cost .fhsm-cost-fields' );
		var $newSource = $newFields.find( '.fhsm-cost-source' );

		// Offer the label source used last time from this browser.
		if ( $newSource.length && ! $newSource.val() ) {
			var lastSource = rememberedSource( browserStorage(), $newSource.find( 'option' ).map( function () {
				return this.value;
			} ).get() );
			if ( lastSource ) {
				$newSource.val( lastSource );
				$newFields.find( '.fhsm-source-hint' ).prop( 'hidden', false );
			}
		}

		$box.on( 'change', '.fhsm-cost-source', function () {
			var $source = $( this );
			$source.data( 'fhsmTouched', true );
			$source.closest( '.fhsm-cost-field' ).find( '.fhsm-source-hint' ).prop( 'hidden', true );
		} );

		// "Custom…" reveals the free LxWxH input.
		$box.on( 'change', '.fhsm-cost-box', function () {
			var $dims = $( this ).closest( '.fhsm-cost-field' ).find( '.fhsm-cost-dims' );
			$dims.toggle( $( this ).val() === CUSTOM_BOX );
			if ( $dims.is( ':visible' ) ) {
				$dims.trigger( 'focus' );
			}
		} );

		// Typing in a flagged field withdraws its message.
		$box.on( 'input change', '.fhsm-cost-field-invalid input, .fhsm-cost-field-invalid select', function () {
			var $field = $( this ).closest( '.fhsm-cost-field' );
			$field.removeClass( 'fhsm-cost-field-invalid' );
			$field.find( '.fhsm-field-error' ).text( '' );
			$field.find( '[aria-invalid]' ).removeAttr( 'aria-invalid' );
		} );

		// Enter in a cost input must never submit the order form around the
		// meta box (and lose what was typed). On an existing shipment it
		// saves the cost; in the Add Shipment form it does nothing — adding
		// a shipment emails the customer, so that stays a deliberate click.
		$box.on( 'keydown', '.fhsm-cost-fields input', function ( e ) {
			if ( e.key !== 'Enter' && e.which !== 13 ) {
				return;
			}
			e.preventDefault();
			$( this ).closest( '.fhsm-cost-form' ).find( '.fhsm-cost-save' ).trigger( 'click' );
		} );

		// Open / close a shipment's label cost form.
		$box.on( 'click', '.fhsm-cost-edit, .fhsm-cost-cancel', function () {
			var $cost  = $( this ).closest( '.fhsm-cost' );
			var $form  = $cost.find( '.fhsm-cost-form' );
			var opened = $( this ).hasClass( 'fhsm-cost-edit' ) && $form.prop( 'hidden' );
			$form.prop( 'hidden', ! opened );
			$cost.find( '.fhsm-cost-edit' ).attr( 'aria-expanded', opened ? 'true' : 'false' );
			if ( opened ) {
				$form.find( '.fhsm-cost-amount' ).trigger( 'focus' );
			}
		} );

		// Save a shipment's label cost. ShipTracker stores it once for the
		// box (tracking number), whichever of its shipments is named.
		$box.on( 'click', '.fhsm-cost-save', function () {
			var $btn    = $( this );
			var $form   = $btn.closest( '.fhsm-cost-form' );
			var $fields = $form.find( '.fhsm-cost-fields' );
			var $msg    = $form.find( '.fhsm-cost-message' );
			var fields  = editCostFields( readCostValues( $fields ) );

			clearCostErrors( $fields );
			$btn.prop( 'disabled', true );
			setMessage( $msg, 'Saving…', '' );

			$.post( window.ajaxurl, $.extend( {
				action:      'fst_set_label_cost',
				nonce:       nonce,
				shipment_id: $btn.data( 'shipment-id' )
			}, fields ) ).done( function ( response ) {
				if ( response && response.success ) {
					rememberSource( browserStorage(), fields.label_source );
					setMessage( $msg, ( response.data && response.data.message ) ? response.data.message : 'Label cost saved.', 'success' );
					setTimeout( function () { window.location.reload(); }, 600 );
				} else {
					var err = responseError( response, 'Could not save the label cost.' );
					setMessage( $msg, showCostError( $fields, err ) ? 'Not saved — see the highlighted field.' : err, 'error' );
					$btn.prop( 'disabled', false );
				}
			} ).fail( function () {
				setMessage( $msg, 'Network error — please retry.', 'error' );
				$btn.prop( 'disabled', false );
			} );
		} );

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
			// Empty when ShipTracker does not record costs (no inputs rendered).
			var costFields = addCostFields( readCostValues( $newFields ) );

			if ( ! tracking ) {
				setMessage( $msg, 'Tracking number is required.', 'error' );
				return;
			}
			if ( ! itemIds.length ) {
				setMessage( $msg, 'Select at least one item.', 'error' );
				return;
			}

			clearCostErrors( $newFields );
			$btn.prop( 'disabled', true );
			setMessage( $msg, 'Saving…', '' );

			// $.ajax with traditional:false explicitly so line_item_ids
			// serializes as line_item_ids[]=12&line_item_ids[]=34 — the form
			// ShipTracker's $_POST['line_item_ids'] reader expects.
			$.ajax( {
				url:        window.ajaxurl,
				method:     'POST',
				traditional: false,
				data:       $.extend( {
					action:          'fst_add_tracking',
					nonce:           nonce,
					order_id:        orderId,
					tracking_number: tracking,
					carrier:         carrier,
					line_item_ids:   itemIds
				}, costFields )
			} ).done( function ( response ) {
				if ( response && response.success ) {
					var msgText = ( response.data && response.data.message ) ? response.data.message : 'Shipment saved.';
					rememberSource( browserStorage(), costFields.label_source );
					setMessage( $msg, msgText, 'success' );
					setTimeout( function () { window.location.reload(); }, 800 );
				} else {
					// Nothing was saved and nothing typed is cleared; a cost
					// value ShipTracker refused is reported next to its field.
					var err = responseError( response, 'Failed to save shipment.' );
					setMessage( $msg, showCostError( $newFields, err ) ? 'Not saved — see the highlighted field above.' : err, 'error' );
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

} )( typeof jQuery === 'undefined' ? null : jQuery );
