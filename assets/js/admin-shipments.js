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
 *   - Packing (only rendered when ShipTracker v1.9.18+ records it): picking
 *     a box opens a block of lines — supply + quantity — with the foam and
 *     paper cost and a running total, filled from ShipTracker's usual
 *     packing for that box and this order. Changing the box or the ticked
 *     items re-fills only the lines that were not touched. It is sent as
 *     `materials` (a JSON string) with Add Shipment and with a shipment's
 *     inline form.
 *   - How it went out (an order with no shipment): local pickup, with its
 *     own packing block, or "went in another order's box" with a lookup of
 *     that order → wp_ajax_fst_set_ship_mode.
 *   - Re-check / Remove → wp_ajax_fst_recheck_tracking / fst_remove_tracking.
 *   - EA Status dropdown + bulk actions → wp_ajax_fishotel_ea_status_update.
 *
 * The helpers above the jQuery block are pure and exported for
 * `node tests/admin-shipments.test.js` and
 * `node tests/admin-shipments-packing.test.js`.
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
	 * Which field a ShipTracker error message is about: 'cost', 'box',
	 * 'weight', the packing block's 'foam', 'paper' or 'packing' (one of its
	 * lines), or '' when it is about something else. ShipTracker's AJAX
	 * errors are a bare message, so the field is told from how its
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
		if ( /^The foam cost\b/i.test( message ) ) {
			return 'foam';
		}
		if ( /^The paper cost\b/i.test( message ) ) {
			return 'paper';
		}
		if ( /^(?:The quantity of|The unit cost of|Unknown supply\b|Every materials line\b|materials must\b)/i.test( message ) ) {
			return 'packing';
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

	// ── Packing helpers (pure) ──────────────────────────────────────────

	// Supply roles that always have a line in a packing block, in this
	// order: the box itself, fish bags, heat packs, ice packs.
	var PACK_ROLES = [ 'box', 'fish_bag', 'heat_pack', 'ice_pack' ];

	function lower( value ) {
		return clean( value ).toLowerCase();
	}

	/**
	 * A quantity as whole hundredths, read the way ShipTracker reads it: 0
	 * or more with at most two decimals. '' is 0; anything else is null.
	 */
	function toHundredths( value ) {
		value = clean( value );
		if ( value === '' ) {
			return 0;
		}
		var m = /^(\d{0,6})(?:\.(\d{0,2}))?$/.exec( value );
		if ( ! m || ( m[1] === '' && ! m[2] ) ) {
			return null;
		}
		return parseInt( m[1] || '0', 10 ) * 100 + parseInt( ( ( m[2] || '' ) + '00' ).slice( 0, 2 ), 10 );
	}

	/** Whole hundredths as a short number: 600 is "6", 250 is "2.5", 5 is "0.05". */
	function qtyText( hundredths ) {
		hundredths = Math.max( 0, Math.round( hundredths ) || 0 );
		var whole = Math.floor( hundredths / 100 );
		var frac  = hundredths % 100;
		if ( ! frac ) {
			return String( whole );
		}
		return whole + '.' + ( frac % 10 ? ( frac < 10 ? '0' : '' ) + frac : frac / 10 );
	}

	/** A unit cost ("0.1100") as whole ten-thousandths of a dollar; 0 when it is not one. */
	function toRate( value ) {
		var m = /^(\d{0,6})(?:\.(\d{0,4}))?$/.exec( clean( value ) );
		if ( ! m || ( m[1] === '' && ! m[2] ) ) {
			return 0;
		}
		return parseInt( m[1] || '0', 10 ) * 10000 + parseInt( ( ( m[2] || '' ) + '0000' ).slice( 0, 4 ), 10 );
	}

	/**
	 * An amount as typed ("1.80", "$1,018.4") in whole cents, rounded half
	 * up. '' is 0; anything that is not an amount of 0 or more is null.
	 * Only for the running total — ShipTracker is the one validator.
	 */
	function toCents( value ) {
		var typed = clean( value );
		if ( typed === '' ) {
			return 0;
		}
		var m = /^(\d*)(?:\.(\d*))?$/.exec( typed.replace( /^\$\s*/, '' ).replace( /,/g, '' ) );
		if ( ! m || ( m[1] === '' && ! m[2] ) ) {
			return null;
		}
		var frac = ( ( m[2] || '' ) + '000' ).slice( 0, 3 );
		return parseInt( m[1] || '0', 10 ) * 100 + parseInt( frac.slice( 0, 2 ), 10 ) + ( frac.charAt( 2 ) >= '5' ? 1 : 0 );
	}

	/** Whole cents as an amount: 610 is "6.10", 5 is "0.05". */
	function centsText( cents ) {
		cents = Math.round( cents ) || 0;
		var abs = Math.abs( cents );
		return ( cents < 0 ? '-' : '' ) + Math.floor( abs / 100 ) + '.' + ( abs % 100 < 10 ? '0' : '' ) + ( abs % 100 );
	}

	/** A unit cost for reading: "0.1100" is "0.11", "0.1319" stays as it is. */
	function rateText( value ) {
		var rate = toRate( value );
		return ( Math.floor( rate / 10000 ) + '.' + ( '0000' + ( rate % 10000 ) ).slice( -4 ) ).replace( /0{1,2}$/, '' );
	}

	/** Quantity × unit cost in whole cents, rounded half up — ShipTracker's own sum. */
	function lineCents( qty, rate ) {
		return Math.floor( ( qty * rate * 2 + 10000 ) / 20000 );
	}

	function findSupply( supplies, id ) {
		id = parseInt( id, 10 );
		supplies = supplies || [];
		for ( var i = 0; i < supplies.length; i++ ) {
			if ( supplies[ i ] && parseInt( supplies[ i ].id, 10 ) === id ) {
				return supplies[ i ];
			}
		}
		return null;
	}

	/** The supply that plays a role — for 'box', the one tied to that standard box. */
	function supplyForRole( supplies, role, boxName ) {
		supplies = supplies || [];
		for ( var i = 0; i < supplies.length; i++ ) {
			var supply = supplies[ i ];
			if ( ! supply || supply.role !== role ) {
				continue;
			}
			if ( role === 'box' && lower( supply.box_name ) !== lower( boxName ) ) {
				continue;
			}
			return supply;
		}
		return null;
	}

	/**
	 * ShipTracker's usual packing for a box: that of the standard box with
	 * this name, else — a custom size, or no box — the custom one.
	 *
	 * @param {Object} defaults { boxes: [ { name, dims, materials } ], custom: materials }
	 */
	function usualFor( defaults, boxName ) {
		defaults = defaults || {};
		var name  = lower( boxName );
		var boxes = defaults.boxes || [];
		for ( var i = 0; name && i < boxes.length; i++ ) {
			if ( boxes[ i ] && lower( boxes[ i ].name ) === name ) {
				return boxes[ i ].materials || null;
			}
		}
		return defaults.custom || null;
	}

	/** The lines of a recorded packing as rows — kept as recorded, so "touched". */
	function recordRows( record ) {
		return ( ( record && record.lines ) || [] ).map( function ( line ) {
			return {
				id:      parseInt( line.supply_id, 10 ),
				name:    clean( line.name ),
				unit:    clean( line.unit ),
				qty:     clean( line.qty ),
				rate:    clean( line.unit_cost ),
				touched: true
			};
		} );
	}

	/**
	 * The lines of a packing block, in order:
	 *  1. the supplies every block has a line for — the picked standard
	 *     box's own supply, fish bags, heat packs, ice packs — at 0;
	 *  2. the usual packing of the box (ShipTracker's defaults), when given;
	 *  3. a bag per fish for the items ticked, when a fish count is given;
	 *  4. every line the owner touched (or that is on record), as it is.
	 * An untouched line that is in none of these is dropped — the previous
	 * box's own supply, for one.
	 *
	 * @param {Object} o { supplies, usual, boxName, fish (hundredths or null), prev (rows) }
	 * @return {Array} rows { id, name, unit, qty, rate, touched }
	 */
	function packingRows( o ) {
		o = o || {};
		var supplies = o.supplies || [];
		var rows     = [];
		var at       = {};

		function put( id, qty, rate, touched, name, unit ) {
			id = parseInt( id, 10 );
			if ( ! ( id > 0 ) ) {
				return;
			}
			var supply = findSupply( supplies, id );
			var row    = {
				id:      id,
				name:    supply ? supply.name : ( clean( name ) || '#' + id ),
				unit:    supply ? supply.unit : ( clean( unit ) || 'each' ),
				qty:     clean( qty ),
				rate:    clean( rate ) || ( supply ? clean( supply.price_each ) : '' ),
				touched: !! touched
			};
			if ( at[ id ] === undefined ) {
				at[ id ] = rows.length;
				rows.push( row );
			} else {
				rows[ at[ id ] ] = row;
			}
		}

		PACK_ROLES.forEach( function ( role ) {
			var supply = role === 'box' && ! clean( o.boxName ) ? null : supplyForRole( supplies, role, o.boxName );
			if ( supply ) {
				put( supply.id, '0', '', false );
			}
		} );

		( ( o.usual && o.usual.lines ) || [] ).forEach( function ( line ) {
			put( line.supply_id, qtyText( toHundredths( line.qty ) || 0 ), line.unit_cost, false, line.name, line.unit );
		} );

		if ( o.fish !== null && o.fish !== undefined ) {
			var bag = supplyForRole( supplies, 'fish_bag' );
			if ( bag ) {
				put( bag.id, qtyText( o.fish ), '', false );
			}
		}

		( o.prev || [] ).forEach( function ( row ) {
			if ( row && row.touched ) {
				put( row.id, row.qty, row.rate, true, row.name, row.unit );
			}
		} );

		return rows;
	}

	/** Lines + foam + paper in whole cents. What cannot be read counts as 0. */
	function packingTotal( rows, foam, paper ) {
		var cents = 0;
		( rows || [] ).forEach( function ( row ) {
			var qty = toHundredths( row.qty );
			if ( qty ) {
				cents += lineCents( qty, toRate( row.rate ) );
			}
		} );
		return cents + ( toCents( foam ) || 0 ) + ( toCents( paper ) || 0 );
	}

	/**
	 * The `materials` to send: every line with a quantity and the two flat
	 * amounts, as typed. A line at 0 is left out (ShipTracker removes a
	 * line sent with 0, and drops one that is not sent); a quantity that is
	 * not a number is sent as it is, so ShipTracker refuses it by name.
	 */
	function packingPayload( rows, foam, paper ) {
		var lines = [];
		( rows || [] ).forEach( function ( row ) {
			if ( toHundredths( row.qty ) !== 0 ) {
				lines.push( { supply_id: row.id, qty: clean( row.qty ) } );
			}
		} );
		return {
			lines:      lines,
			foam_cost:  clean( foam ),
			paper_cost: clean( paper )
		};
	}

	/** True when a payload records nothing at all: no line, no foam, no paper. */
	function packingIsEmpty( payload ) {
		return ! payload || ( ! payload.lines.length && toCents( payload.foam_cost ) === 0 && toCents( payload.paper_cost ) === 0 );
	}

	/** What one line costs, for reading: "× $0.11 = $0.66". */
	function costText( row, currency ) {
		var qty  = toHundredths( row && row.qty );
		var rate = toRate( row && row.rate );
		currency = clean( currency ) || '$';
		if ( qty === null ) {
			return '';
		}
		if ( ! rate ) {
			return qty ? 'no price yet' : '';
		}
		return '× ' + currency + rateText( row.rate ) + ( qty ? ' = ' + currency + centsText( lineCents( qty, rate ) ) : '' );
	}

	/** How many of a counted supply are left, for reading; '' when it is not counted. */
	function stockText( supply ) {
		if ( ! supply || supply.on_hand === null || supply.on_hand === undefined ) {
			return '';
		}
		if ( supply.recount_needed ) {
			return '0 on hand — recount needed';
		}
		return clean( supply.on_hand ) + ' on hand' + ( supply.low ? ' — time to reorder' : '' );
	}

	/** The fish of the ticked order lines, in whole hundredths. */
	function sumFish( values ) {
		var total = 0;
		( values || [] ).forEach( function ( value ) {
			total += toHundredths( value ) || 0;
		} );
		return total;
	}

	/**
	 * What to say about the weather check in a packing block. Only the two
	 * actions that put a pack in the box say anything: 'heat_pack' and
	 * 'cooling' (ice packs).
	 *
	 * @param {Object} o { action, label, rows, supplies }
	 * @return {Array} sentences
	 */
	function weatherNotes( o ) {
		o = o || {};
		var role  = o.action === 'heat_pack' ? 'heat_pack' : ( o.action === 'cooling' ? 'ice_pack' : '' );
		var label = clean( o.label );
		if ( ! role || ! label ) {
			return [];
		}

		var supply = supplyForRole( o.supplies, role );
		if ( ! supply ) {
			return [ 'The weather check says ' + label + ', but no supply is set as the ' + ( role === 'heat_pack' ? 'heat pack' : 'ice pack' ) + ' yet.' ];
		}

		var row = null;
		( o.rows || [] ).forEach( function ( candidate ) {
			if ( candidate.id === parseInt( supply.id, 10 ) ) {
				row = candidate;
			}
		} );
		var qty = row ? toHundredths( row.qty ) : 0;

		if ( row && row.touched ) {
			return [ 'The weather check says ' + label + '.' ];
		}
		if ( qty ) {
			return [ supply.name + ' added' + ( qty === 100 ? '' : ' (' + qtyText( qty ) + ')' ) + ': the weather check says ' + label + '.' ];
		}
		return [ 'The weather check says ' + label + ' — enter the quantity of ' + supply.name + ' for this box.' ];
	}

	/**
	 * Whether the Add Shipment form must ask before it adds: the tracking
	 * number is already a box with its packing recorded, on other orders
	 * only. (On this very order ShipTracker refuses the duplicate anyway.)
	 *
	 * @param {Object} found   { exists, order_ids, has_materials } or null
	 * @param {*}      orderId The order on screen.
	 */
	function sharesRecordedBox( found, orderId ) {
		if ( ! found || ! found.exists || ! found.has_materials ) {
			return false;
		}
		var own = parseInt( orderId, 10 );
		return ! ( found.order_ids || [] ).some( function ( id ) {
			return parseInt( id, 10 ) === own;
		} );
	}

	/** The question asked then: the packing on record stays as it is. */
	function sharedBoxQuestion( orderIds ) {
		var refs = ( orderIds || [] ).map( function ( id ) {
			return '#' + parseInt( id, 10 );
		} ).join( ', ' );
		return 'This tracking number is already a box' + ( refs ? ' on order ' + refs : '' ) + ', and its packing is recorded.\n\n' +
			'Adding it here puts this order in that same box. The packing on record is left as it is — ' +
			'if this order put more into the box (its bags, for one), change it on the shipment afterwards.\n\n' +
			'Add this order to that box?';
	}

	/**
	 * What is posted to fst_set_ship_mode. `with_order_id` goes with "went
	 * in another order's box" only; `materials` (a JSON string) with a
	 * local pickup only, and only when its packing block was read.
	 *
	 * @param {Object} v { mode, withOrder, materials }
	 */
	function shipModeFields( v ) {
		v = v || {};
		var mode = clean( v.mode );
		var out  = { mode: mode === 'local' || mode === 'with_order' ? mode : 'label' };
		if ( out.mode === 'with_order' ) {
			out.with_order_id = clean( v.withOrder ).replace( /^#\s*/, '' );
		}
		if ( out.mode === 'local' && v.materials ) {
			out.materials = JSON.stringify( v.materials );
		}
		return out;
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
			rememberSource:   rememberSource,
			toHundredths:     toHundredths,
			qtyText:          qtyText,
			toRate:           toRate,
			toCents:          toCents,
			centsText:        centsText,
			rateText:         rateText,
			lineCents:        lineCents,
			supplyForRole:    supplyForRole,
			usualFor:         usualFor,
			recordRows:       recordRows,
			packingRows:      packingRows,
			packingTotal:     packingTotal,
			packingPayload:   packingPayload,
			packingIsEmpty:   packingIsEmpty,
			costText:         costText,
			stockText:        stockText,
			sumFish:          sumFish,
			weatherNotes:     weatherNotes,
			sharesRecordedBox: sharesRecordedBox,
			sharedBoxQuestion: sharedBoxQuestion,
			shipModeFields:   shipModeFields
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

		// $scope: the form the fields sit in — its cost inputs and, when it
		// has one, its packing block.
		function clearCostErrors( $scope ) {
			$scope.find( '.fhsm-cost-field' ).removeClass( 'fhsm-cost-field-invalid' );
			$scope.find( '.fhsm-field-error' ).text( '' );
			$scope.find( '[aria-invalid]' ).removeAttr( 'aria-invalid' );
		}

		// Put ShipTracker's message next to the field it is about. Returns
		// false when the message is not about one of these fields.
		function showCostError( $scope, message ) {
			var field  = errorField( message );
			var $field = field ? $scope.find( '.fhsm-cost-field[data-field="' + field + '"]' ).filter( ':visible' ) : $();
			if ( ! $field.length ) {
				return false;
			}
			$field.addClass( 'fhsm-cost-field-invalid' );
			$field.find( '.fhsm-field-error' ).text( message );
			// A packing line: the first quantity; else the field's last input
			// (the typed size, when the box dropdown is on "Custom…").
			var $inputs = $field.find( 'input, select' ).filter( ':visible' );
			( field === 'packing' ? $inputs.first() : $inputs.last() )
				.attr( 'aria-invalid', 'true' ).trigger( 'focus' );
			return true;
		}

		var $newCost   = $box.find( '.fhsm-new-cost' );
		var $newFields = $newCost.find( '.fhsm-cost-fields' );
		var $newSource = $newFields.find( '.fhsm-cost-source' );

		// ── Packing ─────────────────────────────────────────────────────

		// What the packing blocks are filled from: ShipTracker's supplies
		// and its usual packing per box. Absent = it does not record
		// packing, no block is rendered and every function below is idle.
		var packingData = null;
		try {
			packingData = JSON.parse( $box.attr( 'data-packing' ) || 'null' );
		} catch ( e ) {
			packingData = null;
		}

		function packingState( $p ) {
			return $p.length ? $p.data( 'fhsmPacking' ) : null;
		}

		// The standard box picked in the form a block sits in: its name, or
		// '' for a custom size, no box, or a block with no box (a pickup).
		function pickedBox( $p ) {
			var $select = $p.closest( '.fhsm-new-cost, .fhsm-cost-form' ).find( '.fhsm-cost-box' );
			var value   = $select.length ? clean( $select.val() ) : '';
			return {
				chosen: value !== '',
				name:   value && value !== CUSTOM_BOX ? clean( $select.find( 'option:selected' ).attr( 'data-name' ) ) : ''
			};
		}

		// The fish of the items ticked in the Add Shipment form — of every
		// item still to ship while none is ticked.
		function tickedFish() {
			var $items  = $box.find( '.fhsm-line-item-check:not(:disabled)' );
			var $ticked = $items.filter( ':checked' );
			return sumFish( ( $ticked.length ? $ticked : $items ).map( function () {
				return $( this ).attr( 'data-fish' );
			} ).get() );
		}

		// A block starts from its record when it has one — kept as recorded,
		// whatever the box — and otherwise follows the box picked.
		function packingStart( $p ) {
			var record = null;
			try {
				record = JSON.parse( $p.attr( 'data-record' ) || 'null' );
			} catch ( e ) {
				record = null;
			}
			var state = { rows: [], follow: ! record, foamTouched: !! record, paperTouched: !! record };
			$p.data( 'fhsmPacking', state );
			if ( record ) {
				state.rows = recordRows( record );
				$p.find( '.fhsm-packing-foam' ).val( record.foam_cost );
				$p.find( '.fhsm-packing-paper' ).val( record.paper_cost );
			}
			return state;
		}

		// (Re-)fill a block. While it follows the box, the lines, foam and
		// paper nobody touched take the usual amounts of the box picked;
		// what was touched stays. `reset` drops everything for the usual.
		function packingFill( $p, reset ) {
			var state = packingState( $p );
			if ( ! packingData || ! state ) {
				return;
			}
			if ( reset ) {
				state.follow       = true;
				state.foamTouched  = false;
				state.paperTouched = false;
			}

			var picked = pickedBox( $p );
			var usual  = state.follow ? usualFor( ( packingData.defaults || {} )[ $p.attr( 'data-defaults' ) ], picked.name ) : null;

			state.rows = packingRows( {
				supplies: packingData.supplies,
				usual:    usual,
				boxName:  picked.name,
				fish:     state.follow && $p.attr( 'data-kind' ) === 'add' ? tickedFish() : null,
				prev:     reset ? null : state.rows
			} );
			if ( usual && ! state.foamTouched ) {
				$p.find( '.fhsm-packing-foam' ).val( usual.foam_cost );
			}
			if ( usual && ! state.paperTouched ) {
				$p.find( '.fhsm-packing-paper' ).val( usual.paper_cost );
			}
			packingDraw( $p );
		}

		// Build the lines of a block and its "Add supply" picker.
		function packingDraw( $p ) {
			var state  = packingState( $p );
			var prefix = clean( $p.attr( 'data-prefix' ) );
			var $tbody = $p.find( '.fhsm-packing-lines tbody' ).empty();
			var shown  = {};

			$.each( state.rows, function ( i, row ) {
				var supply  = findSupply( packingData.supplies, row.id );
				var stock   = stockText( supply );
				var inputId = prefix + '-qty-' + row.id;
				var $name   = $( '<td class="fhsm-packing-name"></td>' )
					.append( $( '<label></label>' ).attr( 'for', inputId ).text( row.name ) );
				if ( stock ) {
					$name.append( $( '<small class="fhsm-packing-stock"></small>' )
						.toggleClass( 'fhsm-packing-stock-low', !! ( supply.low || supply.recount_needed ) )
						.text( stock ) );
				}
				$( '<tr class="fhsm-packing-row"></tr>' ).attr( 'data-supply-id', row.id ).append(
					$name,
					$( '<td class="fhsm-packing-qty-cell"></td>' ).append(
						$( '<input type="text" class="fhsm-packing-qty" inputmode="decimal" autocomplete="off">' ).attr( 'id', inputId ).val( row.qty ),
						$( '<span class="fhsm-packing-unit"></span>' ).text( row.unit )
					),
					$( '<td class="fhsm-packing-cost"></td>' )
				).appendTo( $tbody );
				shown[ row.id ] = true;
			} );
			$p.find( '.fhsm-packing-lines' ).prop( 'hidden', ! state.rows.length );

			// Every other supply can be added — except the ones kept as foam
			// and as paper: those are the two amounts below the lines.
			var $picker = $p.find( '.fhsm-packing-supply' );
			$picker.find( 'option' ).not( ':first' ).remove();
			$.each( packingData.supplies || [], function ( i, supply ) {
				if ( shown[ supply.id ] || supply.role === 'foam' || supply.role === 'paper' ) {
					return;
				}
				$( '<option></option>' ).val( supply.id ).text( supply.name + ' (' + supply.unit + ')' ).appendTo( $picker );
			} );
			$picker.val( '' );
			$p.find( '.fhsm-packing-add' ).prop( 'hidden', $picker.find( 'option' ).length < 2 );

			packingSums( $p );
		}

		// What each line costs, the weather note and the running total.
		function packingSums( $p ) {
			var state    = packingState( $p );
			var currency = packingData.currency;
			var byId     = {};
			$.each( state.rows, function ( i, row ) {
				byId[ row.id ] = row;
			} );

			$p.find( '.fhsm-packing-row' ).each( function () {
				var row = byId[ $( this ).attr( 'data-supply-id' ) ];
				$( this ).find( '.fhsm-packing-cost' ).text( row ? costText( row, currency ) : '' );
			} );

			// History has no weather note: only a block that follows the box.
			var notes = state.follow ? weatherNotes( {
				action:   $p.attr( 'data-action' ),
				label:    $p.attr( 'data-action-label' ),
				rows:     state.rows,
				supplies: packingData.supplies
			} ) : [];
			$p.find( '.fhsm-packing-notes' ).text( notes.join( ' ' ) ).prop( 'hidden', ! notes.length );

			$p.find( '.fhsm-packing-total strong' ).text( ( clean( currency ) || '$' ) + centsText( packingTotal(
				state.rows,
				$p.find( '.fhsm-packing-foam' ).val(),
				$p.find( '.fhsm-packing-paper' ).val()
			) ) );
		}

		// The `materials` of a block, or null when it is not in play: no
		// block, hidden (no box picked), never opened — or, unless `always`,
		// nothing in it and nothing touched (a box nothing is known about).
		function packingRead( $p, always ) {
			var state = packingState( $p );
			if ( ! packingData || ! state || $p.prop( 'hidden' ) || $p.find( '.fhsm-packing-body' ).prop( 'hidden' ) ) {
				return null;
			}
			var payload = packingPayload( state.rows, $p.find( '.fhsm-packing-foam' ).val(), $p.find( '.fhsm-packing-paper' ).val() );
			var touched = state.foamTouched || state.paperTouched || state.rows.some( function ( row ) {
				return row.touched;
			} );
			return ! always && ! touched && packingIsEmpty( payload ) ? null : payload;
		}

		// The box of a form changed: the Add Shipment block opens once a box
		// is picked; an open block re-fills what was not touched.
		function packingFollowBox( $p ) {
			if ( ! packingState( $p ) ) {
				return;
			}
			if ( $p.attr( 'data-kind' ) === 'add' ) {
				$p.prop( 'hidden', ! pickedBox( $p ).chosen );
			}
			if ( ! $p.prop( 'hidden' ) && ! $p.find( '.fhsm-packing-body' ).prop( 'hidden' ) ) {
				packingFill( $p );
			}
		}

		// The items ticked changed: the fish bags of the Add Shipment block follow.
		function packingFollowItems() {
			var $p = $newCost.find( '.fhsm-packing' );
			if ( packingState( $p ) && ! $p.prop( 'hidden' ) ) {
				packingFill( $p );
			}
		}

		if ( packingData ) {
			$box.find( '.fhsm-packing' ).each( function () {
				var $p    = $( this );
				var state = packingStart( $p );
				if ( $p.attr( 'data-kind' ) === 'add' ) {
					// No standard boxes saved: the size is typed, "Custom…" is
					// already picked and the block is there from the start.
					packingFollowBox( $p );
				} else if ( ! state.follow || $p.attr( 'data-kind' ) === 'pickup' ) {
					packingFill( $p );
				}
			} );
		}

		// A quantity typed: that line is the owner's from now on.
		$box.on( 'input change', '.fhsm-packing-qty', function () {
			var $p    = $( this ).closest( '.fhsm-packing' );
			var state = packingState( $p );
			var id    = parseInt( $( this ).closest( '.fhsm-packing-row' ).attr( 'data-supply-id' ), 10 );
			var value = $( this ).val();
			$.each( state ? state.rows : [], function ( i, row ) {
				if ( row.id === id && ( row.touched || clean( row.qty ) !== clean( value ) ) ) {
					row.qty     = value;
					row.touched = true;
				}
			} );
			if ( state ) {
				packingSums( $p );
			}
		} );

		$box.on( 'input change', '.fhsm-packing-foam, .fhsm-packing-paper', function () {
			var $p    = $( this ).closest( '.fhsm-packing' );
			var state = packingState( $p );
			if ( ! state ) {
				return;
			}
			state[ $( this ).hasClass( 'fhsm-packing-foam' ) ? 'foamTouched' : 'paperTouched' ] = true;
			packingSums( $p );
		} );

		// "Add supply…": one of it, ready to be changed.
		$box.on( 'change', '.fhsm-packing-supply', function () {
			var $p     = $( this ).closest( '.fhsm-packing' );
			var state  = packingState( $p );
			var supply = state ? findSupply( packingData.supplies, $( this ).val() ) : null;
			if ( ! supply ) {
				return;
			}
			state.rows.push( { id: parseInt( supply.id, 10 ), name: supply.name, unit: supply.unit, qty: '1', rate: clean( supply.price_each ), touched: true } );
			packingDraw( $p );
			$p.find( '.fhsm-packing-row' ).last().find( '.fhsm-packing-qty' ).trigger( 'focus' ).trigger( 'select' );
		} );

		// "Use the usual for this box": drop what is there for the defaults.
		$box.on( 'click', '.fhsm-packing-usual', function () {
			packingFill( $( this ).closest( '.fhsm-packing' ), true );
		} );

		// "Add packing" on a box that has none recorded.
		$box.on( 'click', '.fhsm-packing-open', function () {
			var $p = $( this ).closest( '.fhsm-packing' );
			$p.find( '.fhsm-packing-closed' ).prop( 'hidden', true );
			$p.find( '.fhsm-packing-body' ).prop( 'hidden', false );
			packingFill( $p );
			$p.find( '.fhsm-packing-qty, .fhsm-packing-foam' ).first().trigger( 'focus' );
		} );

		// ── How it went out ─────────────────────────────────────────────

		var $mode = $box.find( '.fhsm-ship-mode' );

		// Show what the picked way needs. Save is there once something other
		// than "with a label" is picked — or stored, so it can be cleared.
		function shipModeShow() {
			var picked = clean( $mode.find( '.fhsm-ship-mode-select' ).val() );
			$mode.find( '.fhsm-ship-mode-local' ).prop( 'hidden', picked !== 'local' );
			$mode.find( '.fhsm-ship-mode-with' ).prop( 'hidden', picked !== 'with_order' );
			$mode.find( '.fhsm-ship-mode-actions' ).prop( 'hidden', picked === 'label' && $mode.attr( 'data-mode' ) === 'label' );
		}

		$box.on( 'change', '.fhsm-ship-mode-select', function () {
			shipModeShow();
			setMessage( $mode.find( '.fhsm-ship-mode-message' ), '', '' );
			if ( $( this ).val() === 'with_order' ) {
				$mode.find( '.fhsm-ship-with-order' ).trigger( 'focus' );
			}
		} );

		// Look the other order up: who it is for and the box found on it.
		$box.on( 'click', '.fhsm-ship-with-find', function () {
			var $btn    = $( this );
			var $result = $mode.find( '.fhsm-ship-with-result' );
			var typed   = clean( $mode.find( '.fhsm-ship-with-order' ).val() );

			function show( text, problem ) {
				$result.toggleClass( 'fhsm-ship-with-problem', !! problem ).text( text );
			}
			if ( ! typed ) {
				show( 'Enter the order number of the box it went in.', true );
				return;
			}

			$btn.prop( 'disabled', true );
			show( 'Looking…', false );
			$.post( window.ajaxurl, {
				action:     'fishotel_ship_with_lookup',
				nonce:      nonce,
				order_id:   orderId,
				with_order: typed
			} ).done( function ( response ) {
				if ( response && response.success && response.data ) {
					show( response.data.summary, false );
				} else {
					show( responseError( response, 'Could not look that order up.' ), true );
				}
			} ).fail( function ( xhr ) {
				show( responseError( xhr ? xhr.responseJSON : null, 'Network error — please retry.' ), true );
			} ).always( function () {
				$btn.prop( 'disabled', false );
			} );
		} );

		// Save how the order went out. ShipTracker stores it on the order —
		// no shipment, no e-mail to the customer, no status change.
		$box.on( 'click', '.fhsm-ship-mode-save', function () {
			var $btn   = $( this );
			var $msg   = $mode.find( '.fhsm-ship-mode-message' );
			var picked = clean( $mode.find( '.fhsm-ship-mode-select' ).val() );
			var fields = shipModeFields( {
				mode:      picked,
				withOrder: $mode.find( '.fhsm-ship-with-order' ).val(),
				materials: picked === 'local' ? packingRead( $mode.find( '.fhsm-packing' ), true ) : null
			} );

			if ( fields.mode === 'with_order' && ! fields.with_order_id ) {
				setMessage( $msg, 'Enter the order number of the box it went in.', 'error' );
				return;
			}

			clearCostErrors( $mode );
			$btn.prop( 'disabled', true );
			setMessage( $msg, 'Saving…', '' );

			$.post( window.ajaxurl, $.extend( {
				action:   'fst_set_ship_mode',
				nonce:    nonce,
				order_id: orderId
			}, fields ) ).done( function ( response ) {
				if ( response && response.success ) {
					setMessage( $msg, ( response.data && response.data.message ) ? response.data.message : 'Saved.', 'success' );
					setTimeout( function () { window.location.reload(); }, 600 );
				} else {
					var err = responseError( response, 'Could not save how it went out.' );
					setMessage( $msg, showCostError( $mode, err ) ? 'Not saved — see the highlighted field.' : err, 'error' );
					$btn.prop( 'disabled', false );
				}
			} ).fail( function () {
				setMessage( $msg, 'Network error — please retry.', 'error' );
				$btn.prop( 'disabled', false );
			} );
		} );

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

		// "Custom…" reveals the free LxWxH input; the packing block of the
		// same form follows the box.
		$box.on( 'change', '.fhsm-cost-box', function () {
			var $dims = $( this ).closest( '.fhsm-cost-field' ).find( '.fhsm-cost-dims' );
			$dims.toggle( $( this ).val() === CUSTOM_BOX );
			if ( $dims.is( ':visible' ) ) {
				$dims.trigger( 'focus' );
			}
			packingFollowBox( $( this ).closest( '.fhsm-new-cost, .fhsm-cost-form' ).find( '.fhsm-packing' ) );
		} );

		// Typing in a flagged field withdraws its message.
		$box.on( 'input change', '.fhsm-cost-field-invalid input, .fhsm-cost-field-invalid select', function () {
			var $field = $( this ).closest( '.fhsm-cost-field' );
			$field.removeClass( 'fhsm-cost-field-invalid' );
			$field.find( '.fhsm-field-error' ).text( '' );
			$field.find( '[aria-invalid]' ).removeAttr( 'aria-invalid' );
		} );

		// Enter in a cost or packing input must never submit the order form
		// around the meta box (and lose what was typed). On an existing
		// shipment it saves the cost; in the Add Shipment form it does
		// nothing — adding a shipment emails the customer, so that stays a
		// deliberate click. In "How it went out" it looks the order up.
		$box.on( 'keydown', '.fhsm-cost-fields input, .fhsm-packing input, .fhsm-ship-mode input', function ( e ) {
			if ( e.key !== 'Enter' && e.which !== 13 ) {
				return;
			}
			e.preventDefault();
			if ( $( this ).hasClass( 'fhsm-ship-with-order' ) ) {
				$box.find( '.fhsm-ship-with-find' ).trigger( 'click' );
				return;
			}
			$( this ).closest( '.fhsm-cost-form' ).find( '.fhsm-cost-save' ).trigger( 'click' );
		} );

		// Open / close a shipment's label cost form.
		$box.on( 'click', '.fhsm-cost-edit, .fhsm-cost-cancel', function () {
			var $cost  = $( this ).closest( '.fhsm-cost' );
			var $form  = $cost.find( '.fhsm-cost-form' );
			var opened = $( this ).hasClass( 'fhsm-cost-edit' ) && $form.prop( 'hidden' );
			$form.prop( 'hidden', ! opened );
			$cost.find( '.fhsm-cost-edit' ).attr( 'aria-expanded', opened ? 'true' : 'false' );
			// Closing the form also closes a packing block that was only
			// opened to look ("Add packing" on a box with none recorded), so
			// a later save of the label cost does not record it unseen.
			if ( ! opened && $form.find( '.fhsm-packing-closed' ).length ) {
				$form.find( '.fhsm-packing-body' ).prop( 'hidden', true );
				$form.find( '.fhsm-packing-closed' ).prop( 'hidden', false );
			}
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
			// The packing goes along once its block is open: left closed,
			// `materials` is not sent and ShipTracker leaves it alone.
			var packing = packingRead( $form.find( '.fhsm-packing' ), true );
			if ( packing ) {
				fields.materials = JSON.stringify( packing );
			}

			clearCostErrors( $form );
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
					setMessage( $msg, showCostError( $form, err ) ? 'Not saved — see the highlighted field.' : err, 'error' );
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
			packingFollowItems();
		} );

		// The fish bags of the packing block follow the items ticked.
		$box.on( 'change', '.fhsm-line-item-check', packingFollowItems );

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
			// The packing of the box picked — null while no box is picked (or
			// ShipTracker does not record packing), and then nothing is sent.
			var packing = packingRead( $newCost.find( '.fhsm-packing' ), false );
			if ( packing ) {
				costFields.materials = JSON.stringify( packing );
			}

			if ( ! tracking ) {
				setMessage( $msg, 'Tracking number is required.', 'error' );
				return;
			}
			if ( ! itemIds.length ) {
				setMessage( $msg, 'Select at least one item.', 'error' );
				return;
			}

			clearCostErrors( $newCost );
			$btn.prop( 'disabled', true );

			function send() {
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
						setMessage( $msg, showCostError( $newCost, err ) ? 'Not saved — see the highlighted field above.' : err, 'error' );
						$btn.prop( 'disabled', false );
					}
				} ).fail( function () {
					setMessage( $msg, 'Network error — please retry.', 'error' );
					$btn.prop( 'disabled', false );
				} );
			}

			if ( ! costFields.materials ) {
				send();
				return;
			}

			// ShipTracker takes `materials` as sent. When the tracking number
			// is already a box with its packing recorded — another order went
			// out in it — this order joins that box and the record is kept:
			// asked first, and then the packing is not sent.
			setMessage( $msg, 'Checking…', '' );
			$.post( window.ajaxurl, {
				action:          'fishotel_box_lookup',
				nonce:           nonce,
				tracking_number: tracking
			} ).done( function ( response ) {
				var found = response && response.success ? response.data : null;
				if ( sharesRecordedBox( found, orderId ) ) {
					if ( ! window.confirm( sharedBoxQuestion( found.order_ids ) ) ) {
						setMessage( $msg, '', '' );
						$btn.prop( 'disabled', false );
						return;
					}
					delete costFields.materials;
				}
				send();
			} ).fail( send );
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
