/**
 * FisHotel — product edit screen (theme v1.28.0).
 *
 *  1. "Medication & food" tab + EA Wholesale / EA Retail on variations:
 *     shown only for a medication or food (or a product with no category
 *     yet, or "Who ships it" / an Amazon ASIN set). Ticking a category
 *     switches them at once by toggling a class on <body>; the server
 *     prints the same class on load (inc/admin-product-screen.php). Hidden
 *     fields stay on the page and are still posted.
 *  2. "Copy them into these fields" in the fish facts box: fills only the
 *     EMPTY fact boxes on screen from the description (the plan is printed
 *     on the button, inc/hotel-data.php). Update saves them. Any fact the
 *     box does not draw (e.g. the scientific name, when another box draws
 *     it) is handed on with the jQuery event `fishotel-copy-facts`
 *     (values) on document, so that box can fill itself. A listener may
 *     say which facts it filled by setting `event.taken[key] = true`; a
 *     listener that says nothing is trusted with all of them. With no
 *     listener at all, the message names the facts that were NOT copied
 *     (before, they were counted as copied and silently dropped).
 *
 * Pure helpers are exported for tests/admin-product.test.js.
 *
 * @package fishotel-theme
 */
( function ( $ ) {
	'use strict';

	/**
	 * Same rule as FisHotel_Admin_Product_Screen::med_fields_shown().
	 *
	 * @param {Array}  termIds    Ticked category ids.
	 * @param {Array}  medTermIds Medication / food category ids.
	 * @param {string} mode       "Who ships it" value.
	 * @param {string} asin       Amazon ASIN.
	 * @return {boolean}
	 */
	function medFieldsShown( termIds, medTermIds, mode, asin ) {
		if ( String( mode || '' ).trim() !== '' || String( asin || '' ).trim() !== '' ) {
			return true;
		}
		var ticked = ( termIds || [] ).map( Number ).filter( function ( n ) { return n > 0; } );
		if ( ! ticked.length ) {
			return true;
		}
		var med = ( medTermIds || [] ).map( Number );
		return ticked.some( function ( id ) { return med.indexOf( id ) !== -1; } );
	}

	/**
	 * Which facts to fill: the planned value for every fact whose box is
	 * on screen and empty. Facts with no box on screen are returned apart.
	 *
	 * @param {Object} plan    fact key → value read from the description.
	 * @param {Object} current fact key → value on screen; a key missing
	 *                         from `current` has no box on screen.
	 * @return {{fill: Object, elsewhere: Object}}
	 */
	function factsToFill( plan, current ) {
		var out = { fill: {}, elsewhere: {} };
		Object.keys( plan || {} ).forEach( function ( key ) {
			var value = String( plan[ key ] == null ? '' : plan[ key ] );
			if ( value.trim() === '' ) {
				return;
			}
			if ( ! current || ! Object.prototype.hasOwnProperty.call( current, key ) ) {
				out.elsewhere[ key ] = value;
				return;
			}
			if ( String( current[ key ] == null ? '' : current[ key ] ).trim() === '' ) {
				out.fill[ key ] = value;
			}
		} );
		return out;
	}

	/**
	 * Which handed-on facts another box took.
	 *
	 * @param {Object}  elsewhere   fact key → value handed on.
	 * @param {Object}  taken       fact key → true, as set by listeners.
	 * @param {boolean} hasListener True when anything listens for the event.
	 * @return {{taken: Array, missed: Array}}
	 */
	function handedOn( elsewhere, taken, hasListener ) {
		var keys = Object.keys( elsewhere || {} );
		var said = taken && typeof taken === 'object' ? Object.keys( taken ).filter( function ( k ) { return !! taken[ k ]; } ) : [];
		if ( ! said.length ) {
			return hasListener ? { taken: keys, missed: [] } : { taken: [], missed: keys };
		}
		return {
			taken:  keys.filter( function ( k ) { return said.indexOf( k ) !== -1; } ),
			missed: keys.filter( function ( k ) { return said.indexOf( k ) === -1; } )
		};
	}

	/**
	 * The line shown after a copy.
	 *
	 * @param {number} filled    Boxes filled here.
	 * @param {number} elsewhere Facts another box took.
	 * @param {Array}  missed    Names of facts nobody could fill (optional).
	 * @return {string}
	 */
	function copyMessage( filled, elsewhere, missed ) {
		var total = filled + elsewhere;
		var names = ( missed || [] ).filter( function ( n ) { return String( n || '' ) !== ''; } );
		var tail  = '';
		if ( names.length ) {
			tail = ' ' + names.join( ', ' ) + ( names.length === 1 ? ' was' : ' were' ) + ' not copied — type ' + ( names.length === 1 ? 'it' : 'them' ) + ' in by hand.';
		}
		if ( total === 0 ) {
			return names.length
				? 'Nothing could be filled here.' + tail
				: 'Nothing new could be read from the description — copy by hand.';
		}
		return 'Copied ' + total + ( total === 1 ? ' fact' : ' facts' ) + ' — press Update to keep them.' + tail;
	}

	if ( typeof module === 'object' && module && module.exports ) {
		module.exports = {
			medFieldsShown: medFieldsShown,
			factsToFill:    factsToFill,
			handedOn:       handedOn,
			copyMessage:    copyMessage
		};
	}

	if ( ! $ ) {
		return;
	}

	$( function () {
		var cfg = window.fishotelAdminProduct || {};
		var bodyClass = cfg.bodyClass || 'fh-hide-med';

		function tickedTermIds() {
			return $( '#product_catchecklist input[type="checkbox"]:checked' ).map( function () {
				return parseInt( this.value, 10 );
			} ).get();
		}

		function applyKind() {
			var shown = medFieldsShown(
				tickedTermIds(),
				cfg.medTermIds || [],
				$( '#_fishotel_fulfillment' ).val(),
				$( '#_fishotel_amazon_asin' ).val()
			);
			var $tab = $( '#woocommerce-product-data ul.product_data_tabs li.fishotel_med_options' );
			$( document.body ).toggleClass( bodyClass, ! shown );
			// The tab was open and is now hidden: open the first tab still shown.
			if ( ! shown && $tab.hasClass( 'active' ) ) {
				$( '#woocommerce-product-data ul.product_data_tabs li:visible' ).eq( 0 ).find( 'a' ).trigger( 'click' );
			}
		}

		if ( $( '#product_catchecklist' ).length ) {
			$( '#product_catdiv' ).on( 'change', 'input[type="checkbox"]', applyKind );
			$( document ).on( 'change', '#_fishotel_fulfillment', applyKind );
			$( document ).on( 'input change', '#_fishotel_amazon_asin', applyKind );
			// A category added through "+ Add new category" arrives ticked.
			$( document ).on( 'ajaxComplete', function ( e, xhr, settings ) {
				if ( settings && typeof settings.data === 'string' && settings.data.indexOf( 'action=add-product_cat' ) !== -1 ) {
					applyKind();
				}
			} );
			applyKind();
		}

		$( document ).on( 'click', '.fh-facts-copy', function () {
			var $btn  = $( this );
			var $box  = $btn.closest( '.inside' );
			var plan  = $btn.data( 'facts' ) || {};
			var current = {};
			Object.keys( plan ).forEach( function ( key ) {
				var $radios = $box.find( 'input[type="radio"][name="fh_' + key + '"]' );
				if ( $radios.length ) {
					current[ key ] = $radios.filter( ':checked' ).val() || '';
					return;
				}
				var $field = $box.find( '[name="fh_' + key + '"]' );
				if ( $field.length ) {
					current[ key ] = $field.val();
				}
			} );
			var todo = factsToFill( plan, current );
			Object.keys( todo.fill ).forEach( function ( key ) {
				var value   = todo.fill[ key ];
				var $radios = $box.find( 'input[type="radio"][name="fh_' + key + '"]' );
				if ( $radios.length ) {
					$radios.filter( function () { return this.value === value; } ).prop( 'checked', true ).trigger( 'change' );
				} else {
					$box.find( '[name="fh_' + key + '"]' ).val( value ).trigger( 'change' );
				}
			} );
			var handed = { taken: [], missed: [] };
			if ( Object.keys( todo.elsewhere ).length ) {
				var ev = $.Event( 'fishotel-copy-facts' );
				ev.taken = {};
				$( document ).trigger( ev, [ todo.elsewhere ] );
				var taken = ( ev.result && typeof ev.result === 'object' ) ? ev.result : ev.taken;
				var bound = $._data ? $._data( document, 'events' ) : null;
				var hasListener = !! ( bound && bound[ 'fishotel-copy-facts' ] && bound[ 'fishotel-copy-facts' ].length );
				handed = handedOn( todo.elsewhere, taken, hasListener );
			}
			var labels = $btn.data( 'labels' ) || {};
			$btn.siblings( '.fh-facts-copy-result' ).text(
				copyMessage(
					Object.keys( todo.fill ).length,
					handed.taken.length,
					handed.missed.map( function ( k ) { return labels[ k ] || k; } )
				)
			);
		} );
	} );
} )( typeof jQuery === 'undefined' ? null : jQuery );
