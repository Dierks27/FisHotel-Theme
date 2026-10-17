<?php
/**
 * FisHotel — Invoice products become itemised orders.
 *
 * An "invoice" at FisHotel is a hidden WooCommerce product (product_cat
 * `invoices`, title like "jpreef Invoice (7/3/26)") whose description is a
 * <ul> of lines such as "Blue Tang x2 — $90", "Firefish x1 — $12" and
 * "Credit: lost Cleaner Wrasse — -$82", with the regular price = the total.
 * The customer adds it by link and goes through the normal checkout, so the
 * order used to show ONE line named after the invoice and never the fish.
 *
 * What this module does:
 *
 *   1. Detection — is_invoice_product(): the product (parent for variations)
 *      carries the `invoices` term or "Invoice" in its name (the same rule as
 *      FisHotel_Ship_Day::classify() in the Batch plugin).
 *   2. Parsing — parse_lines() / clean_text() are a port of the Batch plugin's
 *      FisHotel_Ship_Day::parse_invoice_lines() grammar (same regexes, so both
 *      plugins agree on what a line means) except that credits are RETURNED
 *      (kind 'credit', negative amount) instead of skipped. Lines can also be
 *      given explicitly as product meta `_fh_invoice_lines` (JSON) — that wins
 *      over the description and may carry product ids for the fish.
 *   3. Cart + checkout — an "Includes: Blue Tang × 2, …" row under the invoice
 *      and sold-individually (an invoice is never bought twice in one cart).
 *   4. Order creation — on woocommerce_checkout_create_order (priority 20; by
 *      then WC_Checkout::set_data_from_cart() has added the line items, fees,
 *      shipping, taxes and coupons to the still-unsaved order) every invoice
 *      line is either
 *        EXPANDED  into one product-less WC_Order_Item_Product per fish line
 *                  and one negative WC_Order_Item_Fee per credit, the coupon
 *                  discount and every tax amount split pro rata (split_money),
 *      or
 *        kept whole (FALLBACK) with the lines attached as meta
 *      — the rows must balance against the invoice line to the cent or
 *      nothing is touched. What the customer pays never changes: the order
 *      totals, tax lines and coupon lines set from the cart are not recomputed.
 *   5. Lock after purchase — the invoice product goes out of stock and stores
 *      its order id; adding it to a cart again is refused while that order is
 *      live. Cancelling the order releases it.
 *   6. Backfill — a Tools-page button stamps the "Includes" meta + a line
 *      snapshot onto invoice lines of past orders, 100 orders per click.
 *
 * Shared meta contract (the Batch plugin's Ship Day pick list reads it):
 *   fish line  _fh_invoice_fish = 1, _fh_invoice_product_id, _fh_invoice_title
 *   credit fee _fh_invoice_credit = 1, _fh_invoice_product_id
 *   kept line  Includes (visible), _fh_invoice_lines (JSON), _fh_invoice_product_id
 *   product    _fh_invoice_order_id (lock); _fh_invoice_lines (optional JSON)
 *
 * Why the invoice item is converted IN PLACE rather than removed: at the
 * create_order hook every item is unsaved (id 0, keyed `new:line_items0`…).
 * WC_Abstract_Order::remove_item( $key ) does find such an item through
 * get_item( $key, false ), but then runs
 *     unset( $this->items[ $group ][ $item->get_id() ] )
 * with get_id() === 0, so the entry stays in memory and save_items() would
 * still write it (the queued $item->delete() is a no-op for id 0, and the
 * delete loop runs before the save loop). add_item() only appends. Re-using
 * the invoice's WC_Order_Item_Product object as the first fish line needs
 * nothing but public setters and behaves the same for saved and unsaved
 * items, so that is what apply_rows() does.
 *
 * The parser, the EXPAND/FALLBACK decision, the money split and the balance
 * check are pure static methods (no WordPress calls) so
 * tests/invoice-lines-test.php runs them with plain `php`.
 *
 * @package FisHotel
 */

defined( 'ABSPATH' ) || exit;

class FisHotel_Invoice_Lines {

	// ── Shared item-meta contract ──────────────────────────────────────
	const META_FISH       = '_fh_invoice_fish';
	const META_CREDIT     = '_fh_invoice_credit';
	const META_PRODUCT_ID = '_fh_invoice_product_id';
	const META_TITLE      = '_fh_invoice_title';
	const META_LINES      = '_fh_invoice_lines';
	/** Product meta: the order that bought this invoice (set on payment, cleared on cancel). */
	const META_ORDER_ID   = '_fh_invoice_order_id';
	/** Visible item meta on an invoice line kept whole. */
	const META_INCLUDES   = 'Includes';

	const CATEGORY_SLUG = 'invoices';

	/** Sums must agree to the cent; anything looser could move money. */
	const TOLERANCE = 0.01;

	// ── Backfill tool ──────────────────────────────────────────────────
	const OPTION_BACKFILL_PAGE = 'fishotel_invoice_backfill_page';
	const BACKFILL_PER_CLICK   = 100;
	const BACKFILL_ACTION      = 'fishotel_invoice_backfill';
	const BACKFILL_QUERY       = 'fh_invoice_backfill';
	const TOOLS_PAGE           = 'fishotel-tools';

	// ── Grammar (identical to FisHotel_Ship_Day::parse_invoice_lines) ──
	/**
	 * "Name xN — $price": x / X / ×, optional spaces, — / – / - dash-run,
	 * price optional. The price group needs "$digits" right after the
	 * dash-run, so "— -$82", "$-82" and "−$82" can never parse as fish.
	 */
	const RE_FISH = '/^(?<name>.+?)\s*[x\x{00D7}X]\s*(?<qty>\d+)\s*(?:[\x{2014}\x{2013}-]+\s*(?<price>\$\s*\d[\d,]*(?:\.\d+)?)?)?\s*$/u';
	/** A negative amount: the sign sits against the $ after a space, a dash or the start. */
	const RE_NEGATIVE = '/(?:^|(?<=[\s\x{2014}\x{2013}-]))(?:-|\x{2212})\$\s*\d|\$\s*(?:-|\x{2212})\s*\d/u';
	/** First money token: "$82", "$ 1,250.50", "-$82", "$-82", "−$82". */
	const RE_AMOUNT = '/(?:-|\x{2212})?\s*\$\s*(?:-|\x{2212})?\s*(\d[\d,]*(?:\.\d+)?)/u';
	/** A trailing dash-run + money token, e.g. " — -$82". */
	const RE_AMOUNT_AT_END = '/\s*[\x{2014}\x{2013}-]*\s*(?:-|\x{2212})?\s*\$\s*(?:-|\x{2212})?\s*\d[\d,]*(?:\.\d+)?\s*$/u';

	/** Order notes composed at create_order (unsaved order) and written once it has an id. */
	private static $pending_notes = [];

	/** Per-request cache: product id => is it an invoice. */
	private static $is_invoice = [];

	public static function init() {
		// 3. Cart + checkout.
		add_filter( 'woocommerce_get_item_data',          [ __CLASS__, 'cart_item_data' ], 10, 2 );
		add_filter( 'woocommerce_is_sold_individually',   [ __CLASS__, 'sold_individually' ], 10, 2 );
		add_filter( 'woocommerce_add_to_cart_validation', [ __CLASS__, 'validate_add_to_cart' ], 10, 2 );
		// 4. Order creation (after WC has added items / fees / shipping / taxes / coupons).
		add_action( 'woocommerce_checkout_create_order',  [ __CLASS__, 'itemise_order' ], 20, 2 );
		add_action( 'woocommerce_checkout_order_created', [ __CLASS__, 'flush_notes' ], 10, 1 );
		// A product-less line does not count in WC_Order::needs_processing(), so a
		// paid order made only of itemised fish would auto-complete; keep it processing.
		add_filter( 'woocommerce_payment_complete_order_status', [ __CLASS__, 'keep_processing' ], 10, 3 );
		// 5. Lock after purchase / release on cancel.
		add_action( 'woocommerce_payment_complete',        [ __CLASS__, 'lock_products' ], 10, 1 );
		add_action( 'woocommerce_order_status_processing', [ __CLASS__, 'lock_products' ], 10, 1 );
		add_action( 'woocommerce_order_status_completed',  [ __CLASS__, 'lock_products' ], 10, 1 );
		add_action( 'woocommerce_order_status_cancelled',  [ __CLASS__, 'unlock_products' ], 10, 1 );
		// Keep the raw flags / JSON out of the admin item list (the fish lines speak for themselves).
		add_filter( 'woocommerce_hidden_order_itemmeta',  [ __CLASS__, 'hidden_itemmeta' ] );
		// 6. Backfill tool (button rendered by FisHotel_Hotel_Data::render_tools_panels()).
		add_action( 'admin_post_' . self::BACKFILL_ACTION, [ __CLASS__, 'handle_backfill' ] );
		add_action( 'admin_notices',                       [ __CLASS__, 'backfill_notice' ] );
	}

	/* ─────────────────────────────────────────────
	 *  Pure helpers (no WordPress)
	 * ───────────────────────────────────────────── */

	/**
	 * The Batch plugin's invoice rule: the `invoices` category slug, or
	 * "Invoice" as a whole word in the name.
	 *
	 * @param string[] $category_slugs
	 * @param string   $name
	 * @return bool
	 */
	public static function classify_invoice( array $category_slugs, $name ) {
		foreach ( $category_slugs as $slug ) {
			if ( self::CATEGORY_SLUG === strtolower( trim( (string) $slug ) ) ) {
				return true;
			}
		}
		return (bool) preg_match( '/\bInvoice\b/i', (string) $name );
	}

	/** Strip tags, decode entities, normalise whitespace (incl. nbsp). */
	public static function clean_text( $html ) {
		$text = strip_tags( (string) $html );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = str_replace( [ "\xC2\xA0", "\xE2\x80\xAF" ], ' ', $text );
		$text = preg_replace( '/\s+/u', ' ', $text );
		return trim( (string) $text );
	}

	/**
	 * Expand an invoice description into lines.
	 *
	 * Each <li> is one line (when there is no list, every non-empty text line
	 * is one). A line matching "Name xN — $price" is a fish with the UNIT
	 * price (null when missing). A line starting with "Credit" or carrying a
	 * negative amount is a credit: qty 1, price = the negative amount (a
	 * "Credit…" line with a positive amount is negated — a credit is always a
	 * reduction), name = the text without the amount. Anything else is a
	 * note: qty 1, price null.
	 *
	 * @param string $html
	 * @return array<int,array{name:string,qty:int,price:float|null,kind:string,product_id:int}>
	 */
	public static function parse_lines( $html ) {
		$html = (string) $html;
		if ( '' === trim( $html ) ) {
			return [];
		}

		$raw_lines = [];
		if ( preg_match_all( '/<li\b[^>]*>(.*?)<\/li>/is', $html, $m ) && ! empty( $m[1] ) ) {
			$raw_lines = $m[1];
		} else {
			$text      = preg_replace( '/<\s*(br|\/p|\/div|\/li|\/tr|\/h[1-6])\s*\/?\s*>/i', "\n", $html );
			$raw_lines = preg_split( '/\r\n|\r|\n/', (string) $text );
		}

		$out = [];
		foreach ( (array) $raw_lines as $raw ) {
			$text = self::clean_text( $raw );
			if ( '' === $text ) {
				continue;
			}
			$out[] = self::parse_line( $text );
		}
		return $out;
	}

	/** One cleaned line → fish / credit / note (see parse_lines()). */
	public static function parse_line( $text ) {
		$text = (string) $text;

		// A "Credit…" line is money, not a fish — checked first, like the Batch plugin.
		if ( preg_match( '/^credit\b/iu', $text ) ) {
			return self::credit_line( $text );
		}

		if ( preg_match( self::RE_FISH, $text, $mm ) && '' !== trim( $mm['name'] ) ) {
			$price = null;
			if ( isset( $mm['price'] ) && '' !== $mm['price'] ) {
				$price = (float) str_replace( [ '$', ',', ' ' ], '', $mm['price'] );
			}
			return [
				'name'       => trim( $mm['name'] ),
				'qty'        => max( 1, (int) $mm['qty'] ),
				'price'      => $price,
				'kind'       => 'fish',
				'product_id' => 0,
			];
		}

		// Not a fish line. A negative amount anywhere makes it a credit.
		if ( preg_match( self::RE_NEGATIVE, $text ) ) {
			return self::credit_line( $text );
		}

		return [
			'name'       => $text,
			'qty'        => 1,
			'price'      => null,
			'kind'       => 'note',
			'product_id' => 0,
		];
	}

	/** "Credit: lost Cleaner Wrasse — -$82" → name without the amount, price -82. */
	private static function credit_line( $text ) {
		$amount = null;
		if ( preg_match( self::RE_AMOUNT, $text, $m ) ) {
			$amount = -1 * abs( (float) str_replace( ',', '', $m[1] ) );
		}

		$name = preg_replace( self::RE_AMOUNT_AT_END, '', $text );
		if ( $name === $text && null !== $amount ) {
			// The amount is not at the end: drop the first money token wherever it sits.
			$name = preg_replace( self::RE_AMOUNT, ' ', $text, 1 );
		}
		$name = trim( (string) preg_replace( '/\s+/u', ' ', (string) $name ) );
		$name = (string) preg_replace( '/[\s:\x{2014}\x{2013}-]+$/u', '', $name );
		if ( '' === $name ) {
			$name = $text;
		}

		return [
			'name'       => $name,
			'qty'        => 1,
			'price'      => $amount,
			'kind'       => 'credit',
			'product_id' => 0,
		];
	}

	/**
	 * Lines from the `_fh_invoice_lines` JSON (a list of {name, qty, price,
	 * kind?, product_id?}). Entries without a name are dropped; an unknown
	 * kind is 'credit' for a negative price or a "Credit…" name, else 'fish'.
	 * Credits are qty 1 with a negative price; notes have no price.
	 *
	 * @param string|array|null $json
	 * @return array Same shape as parse_lines(); [] when the JSON is unusable.
	 */
	public static function lines_from_json( $json ) {
		if ( is_array( $json ) ) {
			$data = $json;
		} else {
			if ( ! is_string( $json ) || '' === trim( $json ) ) {
				return [];
			}
			$data = json_decode( $json, true );
		}
		if ( ! is_array( $data ) ) {
			return [];
		}

		$out = [];
		foreach ( $data as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$name = ( isset( $row['name'] ) && is_scalar( $row['name'] ) ) ? self::clean_text( (string) $row['name'] ) : '';
			if ( '' === $name ) {
				continue;
			}
			$price = ( isset( $row['price'] ) && is_numeric( $row['price'] ) ) ? (float) $row['price'] : null;
			$qty   = ( isset( $row['qty'] ) && is_numeric( $row['qty'] ) ) ? max( 1, (int) $row['qty'] ) : 1;
			$kind  = ( isset( $row['kind'] ) && is_string( $row['kind'] ) ) ? strtolower( trim( $row['kind'] ) ) : '';
			if ( ! in_array( $kind, [ 'fish', 'credit', 'note' ], true ) ) {
				$kind = ( ( null !== $price && $price < 0 ) || preg_match( '/^credit\b/iu', $name ) ) ? 'credit' : 'fish';
			}
			$product_id = ( isset( $row['product_id'] ) && is_numeric( $row['product_id'] ) ) ? max( 0, (int) $row['product_id'] ) : 0;

			if ( 'credit' === $kind ) {
				$qty   = 1;
				$price = ( null === $price ) ? null : -1 * abs( $price );
			} elseif ( 'note' === $kind ) {
				$qty   = 1;
				$price = null;
			}

			$out[] = [
				'name'       => $name,
				'qty'        => $qty,
				'price'      => $price,
				'kind'       => $kind,
				'product_id' => $product_id,
			];
		}
		return $out;
	}

	/** "Blue Tang × 2, Firefish × 1, Credit: lost Cleaner Wrasse". */
	public static function summary( array $lines ) {
		$parts = [];
		foreach ( $lines as $line ) {
			$name = isset( $line['name'] ) ? trim( (string) $line['name'] ) : '';
			if ( '' === $name ) {
				continue;
			}
			if ( isset( $line['kind'] ) && 'fish' === $line['kind'] ) {
				$name .= ' × ' . max( 1, (int) ( isset( $line['qty'] ) ? $line['qty'] : 1 ) );
			}
			$parts[] = $name;
		}
		return implode( ', ', $parts );
	}

	/**
	 * EXPAND or FALLBACK?
	 *
	 * EXPAND when the item quantity is 1, there is at least one fish line,
	 * every fish line has a positive unit price, and
	 * sum(fish qty × unit price) + sum(credit amounts) equals the item's
	 * subtotal within a cent. Notes never block; a credit without an amount
	 * counts as 0.
	 *
	 * @return array{expand:bool,reason:string,sum:float,line:string}
	 *         reason '' | 'quantity' | 'no_fish' | 'unpriced' | 'mismatch'
	 */
	public static function decide_expand( array $lines, $quantity, $subtotal ) {
		$out = [ 'expand' => false, 'reason' => '', 'sum' => 0.0, 'line' => '' ];

		if ( 1 !== (int) $quantity ) {
			$out['reason'] = 'quantity';
			return $out;
		}

		$fish = 0;
		$sum  = 0.0;
		foreach ( $lines as $line ) {
			$kind  = isset( $line['kind'] ) ? $line['kind'] : 'note';
			$price = ( isset( $line['price'] ) && is_numeric( $line['price'] ) ) ? (float) $line['price'] : null;
			if ( 'fish' === $kind ) {
				$fish++;
				if ( null === $price || $price <= 0 ) {
					$out['reason'] = 'unpriced';
					$out['line']   = isset( $line['name'] ) ? (string) $line['name'] : '';
					return $out;
				}
				$sum += max( 1, (int) ( isset( $line['qty'] ) ? $line['qty'] : 1 ) ) * $price;
			} elseif ( 'credit' === $kind && null !== $price ) {
				$sum += -1 * abs( $price );
			}
		}
		$out['sum'] = round( $sum, 2 );

		if ( 0 === $fish ) {
			$out['reason'] = 'no_fish';
			return $out;
		}
		if ( ! self::close( $sum, (float) $subtotal ) ) {
			$out['reason'] = 'mismatch';
			return $out;
		}
		$out['expand'] = true;
		return $out;
	}

	/**
	 * Split an amount over weights pro rata, in whole cents (largest-remainder
	 * method): every share is its exact value rounded DOWN to the cent, then
	 * the leftover cents go one each to the shares that lost the most in that
	 * rounding, the last line first on a tie. So no share is ever negative or
	 * more than a cent away from its exact value, and the shares add up to the
	 * amount exactly. (Rounding every share but the last to the nearest cent
	 * and giving the last the remainder can push the last share below zero —
	 * six $100 lines and a $1 line sharing a 10¢ discount round to 2¢ each,
	 * leaving −2¢ — which WC would then write as a line total above its
	 * subtotal.) Any sub-cent part of the amount itself lands on the last
	 * share.
	 *
	 * @param float   $amount
	 * @param float[] $weights
	 * @return float[]
	 */
	public static function split_amount( $amount, array $weights ) {
		$n = count( $weights );
		if ( 0 === $n ) {
			return [];
		}
		$amount = (float) $amount;
		$sign   = ( $amount < 0 ) ? -1 : 1;
		$sum_w  = (float) array_sum( $weights );
		$cents  = (int) floor( abs( $amount ) * 100 + 0.000001 );
		$dust   = max( 0.0, abs( $amount ) - $cents / 100 );

		$shares = [];
		$rest   = [];
		$left   = $cents;
		$i      = 0;
		foreach ( $weights as $w ) {
			$exact        = ( $sum_w > 0 ) ? $cents * (float) $w / $sum_w : 0.0;
			$shares[ $i ] = (int) floor( $exact + 0.000001 );
			$rest[ $i ]   = $exact - $shares[ $i ];
			$left        -= $shares[ $i ];
			$i++;
		}

		// Hand out the leftover cents: biggest remainder first, later lines first on a tie.
		$order = array_keys( $rest );
		usort( $order, function ( $a, $b ) use ( $rest ) {
			if ( abs( $rest[ $a ] - $rest[ $b ] ) > 0.000001 ) {
				return ( $rest[ $a ] > $rest[ $b ] ) ? -1 : 1;
			}
			return $b - $a;
		} );
		for ( $k = 0; $k < $left; $k++ ) {
			$shares[ $order[ $k % $n ] ]++;
		}

		$out = [];
		foreach ( $shares as $i => $share ) {
			$value = $share / 100;
			if ( $i === $n - 1 ) {
				$value += $dust;
			}
			$out[] = self::clean( $sign * $value );
		}
		return $out;
	}

	/**
	 * Turn an invoice line's money into one row per fish line and one per
	 * credit.
	 *
	 * Fish line i gets subtotal_i = qty × unit price (the last fish line also
	 * absorbs the ≤ 1¢ residual between the lines and the invoice subtotal).
	 * The coupon discount D = subtotal − total is spread over the fish lines
	 * pro rata to subtotal_i in whole cents (split_amount: leftover cents to
	 * the lines that lost most in rounding, the last line first), so
	 * sum(total_i) + sum(credits) == total exactly and no total_i exceeds
	 * subtotal_i; every tax-rate amount is split the same way, and a line's
	 * subtotal_tax / total_tax are the sums of its rate parts (that is what
	 * WC_Order_Item_Product::set_taxes() stores anyway) — only an invoice
	 * line without rate data has its two tax totals split directly. Credits
	 * are fee rows (negative total, no tax).
	 *
	 * @param array $lines        parse_lines() / lines_from_json() output.
	 * @param float $subtotal     Invoice line subtotal (before discounts, ex tax).
	 * @param float $total        Invoice line total (after discounts, ex tax).
	 * @param float $subtotal_tax
	 * @param float $total_tax
	 * @param array $taxes        WC_Order_Item_Product::get_taxes() shape:
	 *                            [ 'subtotal' => [ rate_id => amount ], 'total' => [ rate_id => amount ] ].
	 * @return array[] Rows: kind, name, qty, product_id, subtotal, total,
	 *                 subtotal_tax, total_tax, taxes. [] when the lines cannot
	 *                 account for the money (the caller then falls back).
	 */
	public static function split_money( array $lines, float $subtotal, float $total, float $subtotal_tax, float $total_tax, array $taxes ) {
		$fish       = [];
		$credits    = [];
		$credit_sum = 0.0;

		foreach ( $lines as $line ) {
			$kind  = isset( $line['kind'] ) ? $line['kind'] : 'note';
			$name  = isset( $line['name'] ) ? trim( (string) $line['name'] ) : '';
			$price = ( isset( $line['price'] ) && is_numeric( $line['price'] ) ) ? (float) $line['price'] : null;
			if ( '' === $name ) {
				continue;
			}
			if ( 'fish' === $kind ) {
				if ( null === $price || $price <= 0 ) {
					return [];
				}
				$fish[] = [
					'name'       => $name,
					'qty'        => max( 1, (int) ( isset( $line['qty'] ) ? $line['qty'] : 1 ) ),
					'price'      => $price,
					'product_id' => isset( $line['product_id'] ) ? max( 0, (int) $line['product_id'] ) : 0,
				];
			} elseif ( 'credit' === $kind ) {
				$amount = ( null === $price ) ? 0.0 : round( -1 * abs( $price ), 2 );
				if ( abs( $amount ) < 0.005 ) {
					continue; // an informational credit carries no money
				}
				$credits[]   = [ 'name' => $name, 'total' => $amount ];
				$credit_sum += $amount;
			}
		}
		if ( empty( $fish ) ) {
			return [];
		}

		$n         = count( $fish );
		$subtotals = [];
		$acc       = 0.0;
		foreach ( $fish as $i => $f ) {
			if ( $i === $n - 1 ) {
				// The residual lands here — but only ever a cent of it: a bigger gap
				// means the lines do not describe this invoice line.
				$s = self::clean( $subtotal - $credit_sum - $acc );
				if ( ! self::close( $s, round( $f['qty'] * $f['price'], 2 ) ) ) {
					return [];
				}
			} else {
				$s    = self::clean( round( $f['qty'] * $f['price'], 2 ) );
				$acc += $s;
			}
			if ( $s < -0.005 ) {
				return [];
			}
			$subtotals[] = $s;
		}
		$fish_sum = (float) array_sum( $subtotals );
		if ( $fish_sum <= 0 || ! self::close( $fish_sum + $credit_sum, $subtotal ) ) {
			return [];
		}

		$discounts = self::split_amount( $subtotal - $total, $subtotals );
		$sub_tax   = self::split_amount( $subtotal_tax, $subtotals );
		$tot_tax   = self::split_amount( $total_tax, $subtotals );
		$by_rate   = [ 'subtotal' => [], 'total' => [] ];
		foreach ( [ 'subtotal', 'total' ] as $group ) {
			$rates = ( isset( $taxes[ $group ] ) && is_array( $taxes[ $group ] ) ) ? $taxes[ $group ] : [];
			foreach ( $rates as $rate_id => $amount ) {
				$by_rate[ $group ][ $rate_id ] = self::split_amount( (float) $amount, $subtotals );
			}
		}

		$rows = [];
		foreach ( $fish as $i => $f ) {
			$row_taxes = [ 'subtotal' => [], 'total' => [] ];
			$row_tax   = [ 'subtotal' => $sub_tax[ $i ], 'total' => $tot_tax[ $i ] ];
			foreach ( [ 'subtotal', 'total' ] as $group ) {
				foreach ( $by_rate[ $group ] as $rate_id => $parts ) {
					$row_taxes[ $group ][ $rate_id ] = $parts[ $i ];
				}
				if ( ! empty( $by_rate[ $group ] ) ) {
					$row_tax[ $group ] = self::clean( array_sum( $row_taxes[ $group ] ) );
				}
			}
			$line_total = self::clean( $subtotals[ $i ] - $discounts[ $i ] );
			if ( $line_total < -0.005 ) {
				return [];
			}
			$rows[] = [
				'kind'         => 'fish',
				'name'         => $f['name'],
				'qty'          => $f['qty'],
				'product_id'   => $f['product_id'],
				'subtotal'     => $subtotals[ $i ],
				'total'        => $line_total,
				'subtotal_tax' => $row_tax['subtotal'],
				'total_tax'    => $row_tax['total'],
				'taxes'        => $row_taxes,
			];
		}
		foreach ( $credits as $c ) {
			$rows[] = [
				'kind'         => 'credit',
				'name'         => $c['name'],
				'qty'          => 1,
				'product_id'   => 0,
				'subtotal'     => $c['total'],
				'total'        => $c['total'],
				'subtotal_tax' => 0.0,
				'total_tax'    => 0.0,
				'taxes'        => [ 'subtotal' => [], 'total' => [] ],
			];
		}
		return $rows;
	}

	/**
	 * Money invariant: do the rows add up to the invoice line (within a
	 * cent) on subtotal, total, both tax totals and every tax rate — with no
	 * fish row below zero or discounted below its own subtotal (WC's
	 * set_total() would silently raise such a subtotal to match)?
	 */
	public static function rows_balance( array $rows, float $subtotal, float $total, float $subtotal_tax, float $total_tax, array $taxes ) {
		if ( empty( $rows ) ) {
			return false;
		}
		$sum   = [ 'subtotal' => 0.0, 'total' => 0.0, 'subtotal_tax' => 0.0, 'total_tax' => 0.0 ];
		$rates = [ 'subtotal' => [], 'total' => [] ];
		$fish  = 0;

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['kind'], $row['name'], $row['qty'], $row['subtotal'], $row['total'] ) ) {
				return false;
			}
			if ( '' === trim( (string) $row['name'] ) || (int) $row['qty'] < 1 ) {
				return false;
			}
			if ( 'fish' === $row['kind'] ) {
				$fish++;
				if ( (float) $row['subtotal'] < -0.005 || (float) $row['total'] < -0.005 || (float) $row['total'] > (float) $row['subtotal'] + 0.005 ) {
					return false;
				}
			} elseif ( 'credit' !== $row['kind'] ) {
				return false;
			}
			$sum['subtotal']     += (float) $row['subtotal'];
			$sum['total']        += (float) $row['total'];
			$sum['subtotal_tax'] += isset( $row['subtotal_tax'] ) ? (float) $row['subtotal_tax'] : 0.0;
			$sum['total_tax']    += isset( $row['total_tax'] ) ? (float) $row['total_tax'] : 0.0;
			foreach ( [ 'subtotal', 'total' ] as $group ) {
				$row_rates = ( isset( $row['taxes'][ $group ] ) && is_array( $row['taxes'][ $group ] ) ) ? $row['taxes'][ $group ] : [];
				foreach ( $row_rates as $rate_id => $amount ) {
					$rates[ $group ][ $rate_id ] = ( isset( $rates[ $group ][ $rate_id ] ) ? $rates[ $group ][ $rate_id ] : 0.0 ) + (float) $amount;
				}
			}
		}

		if ( 0 === $fish ) {
			return false;
		}
		if ( ! self::close( $sum['subtotal'], $subtotal ) || ! self::close( $sum['total'], $total )
			|| ! self::close( $sum['subtotal_tax'], $subtotal_tax ) || ! self::close( $sum['total_tax'], $total_tax ) ) {
			return false;
		}
		foreach ( [ 'subtotal', 'total' ] as $group ) {
			$expected = ( isset( $taxes[ $group ] ) && is_array( $taxes[ $group ] ) ) ? $taxes[ $group ] : [];
			foreach ( $expected as $rate_id => $amount ) {
				$got = isset( $rates[ $group ][ $rate_id ] ) ? $rates[ $group ][ $rate_id ] : 0.0;
				if ( ! self::close( $got, (float) $amount ) ) {
					return false;
				}
			}
			foreach ( $rates[ $group ] as $rate_id => $amount ) {
				if ( ! isset( $expected[ $rate_id ] ) && ! self::close( $amount, 0.0 ) ) {
					return false;
				}
			}
		}
		return true;
	}

	/** Within a cent (plus float noise). */
	private static function close( $a, $b ) {
		return abs( (float) $a - (float) $b ) <= self::TOLERANCE + 0.000001;
	}

	/** Kill float noise and negative zero. */
	private static function clean( $value ) {
		$value = round( (float) $value, 6 );
		return ( 0.0 == $value ) ? 0.0 : $value; // phpcs:ignore Universal.Operators.StrictComparisons -- -0.0 == 0.0 on purpose
	}

	/* ─────────────────────────────────────────────
	 *  Detection + lines (WordPress)
	 * ───────────────────────────────────────────── */

	/** The product that carries the invoice data: the parent of a variation, else itself. */
	public static function invoice_parent( $product ) {
		if ( ! $product instanceof WC_Product ) {
			return null;
		}
		if ( $product->is_type( 'variation' ) && $product->get_parent_id() ) {
			$parent = wc_get_product( $product->get_parent_id() );
			if ( $parent instanceof WC_Product ) {
				return $parent;
			}
		}
		return $product;
	}

	/**
	 * Is this an invoice product? `invoices` term on the product (parent for
	 * variations), or "Invoice" in its name. Cached per request.
	 *
	 * @param WC_Product|int $product
	 * @return bool
	 */
	public static function is_invoice_product( $product ) {
		if ( is_numeric( $product ) && function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( (int) $product );
		}
		$product = self::invoice_parent( $product );
		if ( ! $product ) {
			return false;
		}
		$id = (int) $product->get_id();
		if ( isset( self::$is_invoice[ $id ] ) ) {
			return self::$is_invoice[ $id ];
		}
		$slugs = [];
		$terms = get_the_terms( $id, 'product_cat' );
		if ( is_array( $terms ) ) {
			foreach ( $terms as $term ) {
				if ( isset( $term->slug ) ) {
					$slugs[] = (string) $term->slug;
				}
			}
		}
		self::$is_invoice[ $id ] = self::classify_invoice( $slugs, $product->get_name() );
		return self::$is_invoice[ $id ];
	}

	/**
	 * The invoice's lines: product meta `_fh_invoice_lines` (JSON) when it
	 * is present and usable, else the parsed description.
	 *
	 * @param WC_Product $product
	 * @return array
	 */
	public static function get_lines( $product ) {
		$product = self::invoice_parent( $product );
		if ( ! $product ) {
			return [];
		}
		$lines = self::lines_from_json( $product->get_meta( self::META_LINES ) );
		if ( ! empty( $lines ) ) {
			return $lines;
		}
		return self::parse_lines( $product->get_description() );
	}

	/**
	 * The invoice product an order item came from (via _fh_invoice_product_id),
	 * for code that needs product data — e.g. the shipping class — from a
	 * product-less fish line.
	 *
	 * @param WC_Order_Item $item
	 * @return WC_Product|null
	 */
	public static function item_invoice_product( $item ) {
		if ( ! $item instanceof WC_Order_Item ) {
			return null;
		}
		$pid = (int) $item->get_meta( self::META_PRODUCT_ID );
		if ( $pid <= 0 ) {
			return null;
		}
		$product = wc_get_product( $pid );
		return $product instanceof WC_Product ? $product : null;
	}

	/** Hidden item meta a fulfillment copy of a line must keep (the pick list reads it). */
	public static function copied_item_meta_keys() {
		return [ self::META_FISH, self::META_PRODUCT_ID, self::META_TITLE, self::META_LINES ];
	}

	/* ─────────────────────────────────────────────
	 *  Cart + checkout
	 * ───────────────────────────────────────────── */

	/** "Includes: Blue Tang × 2, …" under an invoice in the cart / order review. */
	public static function cart_item_data( $item_data, $cart_item ) {
		$product = ( is_array( $cart_item ) && isset( $cart_item['data'] ) ) ? $cart_item['data'] : null;
		if ( ! $product instanceof WC_Product || ! self::is_invoice_product( $product ) ) {
			return $item_data;
		}
		$summary = self::summary( self::get_lines( $product ) );
		if ( '' === $summary ) {
			return $item_data;
		}
		$item_data   = is_array( $item_data ) ? $item_data : [];
		$item_data[] = [
			'key'   => __( 'Includes', 'fishotel' ),
			'value' => esc_html( $summary ),
		];
		return $item_data;
	}

	/** An invoice is never bought twice in one cart. */
	public static function sold_individually( $sold_individually, $product ) {
		return self::is_invoice_product( $product ) ? true : $sold_individually;
	}

	/** Refuse an invoice that a live order already paid for. */
	public static function validate_add_to_cart( $passed, $product_id ) {
		if ( ! $passed ) {
			return $passed;
		}
		$product = wc_get_product( (int) $product_id );
		if ( ! self::is_invoice_product( $product ) ) {
			return $passed;
		}
		$order_id = (int) self::invoice_parent( $product )->get_meta( self::META_ORDER_ID );
		if ( $order_id <= 0 || ! self::order_holds_lock( $order_id ) ) {
			return $passed;
		}
		$order = wc_get_order( $order_id );
		wc_add_notice(
			sprintf(
				/* translators: %s: order number */
				__( 'This invoice was already paid on order #%s. Contact us if you need a new one.', 'fishotel' ),
				$order->get_order_number()
			),
			'error'
		);
		return false;
	}

	/* ─────────────────────────────────────────────
	 *  Order creation
	 * ───────────────────────────────────────────── */

	/**
	 * woocommerce_checkout_create_order, priority 20: every invoice line on
	 * the unsaved order is expanded or kept whole (see the file header).
	 *
	 * @param WC_Order $order
	 * @param array    $data  Posted checkout data (unused).
	 */
	public static function itemise_order( $order, $data = [] ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			if ( '' !== (string) $item->get_meta( self::META_FISH ) || '' !== (string) $item->get_meta( self::META_LINES ) ) {
				continue; // already handled
			}
			$product = $item->get_product();
			if ( ! $product instanceof WC_Product || ! self::is_invoice_product( $product ) ) {
				continue;
			}
			try {
				self::itemise_item( $order, $item, self::invoice_parent( $product ) );
			} catch ( \Throwable $e ) {
				if ( function_exists( 'wc_get_logger' ) ) {
					wc_get_logger()->error( 'Invoice itemisation failed: ' . $e->getMessage(), [ 'source' => 'fishotel-invoice-lines' ] );
				}
			}
		}
	}

	/** Decide, split, apply — or fall back. Queues the order note. */
	private static function itemise_item( WC_Order $order, WC_Order_Item_Product $item, WC_Product $invoice ) {
		$lines      = self::get_lines( $invoice );
		$title      = $invoice->get_name();
		$invoice_id = (int) $invoice->get_id();
		$subtotal   = (float) $item->get_subtotal();
		$decision   = self::decide_expand( $lines, (int) $item->get_quantity(), $subtotal );
		$reason     = $decision['reason'];

		if ( '' === $reason ) {
			$total        = (float) $item->get_total();
			$subtotal_tax = (float) $item->get_subtotal_tax();
			$total_tax    = (float) $item->get_total_tax();
			$taxes        = self::normalise_taxes( $item->get_taxes() );
			$rows         = self::split_money( $lines, $subtotal, $total, $subtotal_tax, $total_tax, $taxes );

			if ( ! empty( $rows ) && self::rows_balance( $rows, $subtotal, $total, $subtotal_tax, $total_tax, $taxes ) ) {
				$counts = self::apply_rows( $order, $item, $rows, $invoice_id, $title );
				/* translators: %d: number of fish lines */
				$what = sprintf( _n( '%d fish line', '%d fish lines', $counts['fish'], 'fishotel' ), $counts['fish'] );
				if ( $counts['credits'] > 0 ) {
					/* translators: %d: number of credits */
					$what .= ' ' . sprintf( _n( 'and %d credit', 'and %d credits', $counts['credits'], 'fishotel' ), $counts['credits'] );
				}
				$note = sprintf(
					/* translators: 1: invoice product name, 2: product id, 3: "2 fish lines and 1 credit" */
					__( 'Invoice %1$s (#%2$d) itemised into %3$s.', 'fishotel' ),
					$title,
					$invoice_id,
					$what
				);
				$notes = [];
				foreach ( $lines as $line ) {
					if ( isset( $line['kind'] ) && 'note' === $line['kind'] ) {
						$notes[] = (string) $line['name'];
					}
				}
				if ( $notes ) {
					/* translators: %s: comma-separated description lines that were neither fish nor credits */
					$note .= ' ' . sprintf( __( 'Other lines: %s.', 'fishotel' ), implode( ', ', $notes ) );
				}
				self::$pending_notes[] = $note;
				return;
			}
			$reason = 'unbalanced';
		}

		self::attach_fallback( $item, $lines, $invoice_id );
		self::$pending_notes[] = sprintf(
			/* translators: 1: invoice product name, 2: why it was not itemised */
			__( 'Invoice %1$s kept as one line: %2$s.', 'fishotel' ),
			$title,
			self::reason_text( $reason, $decision, (int) $item->get_quantity(), $subtotal )
		);
	}

	/** Human reason for the "kept as one line" note. */
	private static function reason_text( $reason, array $decision, $quantity, $subtotal ) {
		switch ( $reason ) {
			case 'quantity':
				/* translators: %d: line item quantity */
				return sprintf( __( 'quantity is %d, expected 1', 'fishotel' ), (int) $quantity );
			case 'no_fish':
				return __( 'no fish lines found in the description', 'fishotel' );
			case 'unpriced':
				/* translators: %s: the fish line */
				return sprintf( __( '"%s" has no price', 'fishotel' ), $decision['line'] );
			case 'mismatch':
				/* translators: 1: sum of the lines, 2: line item subtotal */
				return sprintf( __( 'lines add up to %1$s but the item subtotal is %2$s', 'fishotel' ), number_format( (float) $decision['sum'], 2, '.', '' ), number_format( (float) $subtotal, 2, '.', '' ) );
			case 'unbalanced':
				return __( 'the split rows did not balance, nothing changed', 'fishotel' );
		}
		return (string) $reason;
	}

	/**
	 * Write the rows onto the order. The invoice item object becomes the
	 * first fish line (see the file header for why it is not removed);
	 * further fish lines and the credit fees are added with add_item().
	 *
	 * @return array{fish:int,credits:int}
	 */
	private static function apply_rows( WC_Order $order, WC_Order_Item_Product $item, array $rows, $invoice_id, $invoice_title ) {
		// Resolve optional product ids first: set_product_id() throws on an unknown id.
		foreach ( $rows as $i => $row ) {
			$rows[ $i ]['ids'] = ( 'fish' === $row['kind'] ) ? self::resolve_line_product( $row['product_id'] ) : [ 'product_id' => 0, 'variation_id' => 0 ];
		}

		$tax_class = $item->get_tax_class();
		$fish      = 0;
		$credits   = 0;
		$first     = true;

		foreach ( $rows as $row ) {
			if ( 'credit' === $row['kind'] ) {
				$fee = new WC_Order_Item_Fee();
				$fee->set_name( $row['name'] );
				$fee->set_tax_class( '' );
				$fee->set_tax_status( 'none' );
				$fee->set_amount( $row['total'] );
				$fee->set_total( $row['total'] );
				$fee->set_total_tax( 0 );
				$fee->set_taxes( [ 'total' => [] ] );
				$fee->add_meta_data( self::META_CREDIT, 1, true );
				$fee->add_meta_data( self::META_PRODUCT_ID, $invoice_id, true );
				$order->add_item( $fee );
				$credits++;
				continue;
			}

			if ( $first ) {
				$line  = $item;
				$first = false;
				self::reset_item( $line );
			} else {
				$line = new WC_Order_Item_Product();
			}
			$line->set_name( $row['name'] );
			$line->set_product_id( $row['ids']['product_id'] );
			$line->set_variation_id( $row['ids']['variation_id'] );
			$line->set_quantity( $row['qty'] );
			$line->set_tax_class( $tax_class );
			$line->set_subtotal( $row['subtotal'] );
			$line->set_total( $row['total'] );
			$line->set_subtotal_tax( $row['subtotal_tax'] );
			$line->set_total_tax( $row['total_tax'] );
			// Last, like WC_Checkout: set_taxes() re-derives the two tax totals from the rate amounts.
			$line->set_taxes( $row['taxes'] );
			$line->add_meta_data( self::META_FISH, 1, true );
			$line->add_meta_data( self::META_PRODUCT_ID, $invoice_id, true );
			$line->add_meta_data( self::META_TITLE, $invoice_title, true );
			if ( $line !== $item ) {
				$order->add_item( $line );
			}
			$fish++;
		}

		return [ 'fish' => $fish, 'credits' => $credits ];
	}

	/** Strip the invoice item of what described the invoice before it becomes a fish line. */
	private static function reset_item( WC_Order_Item_Product $item ) {
		foreach ( $item->get_meta_data() as $meta ) {
			if ( isset( $meta->key ) ) {
				$item->delete_meta_data( $meta->key );
			}
		}
		// Cart references WC_Checkout attaches for the deprecated
		// woocommerce_add_order_item_meta action — they describe the invoice
		// cart line, not this fish.
		unset( $item->legacy_values, $item->legacy_cart_item_key );
	}

	/** [ product_id, variation_id ] for a line's optional product id; zeros when it is not a usable product. */
	private static function resolve_line_product( $product_id ) {
		$none = [ 'product_id' => 0, 'variation_id' => 0 ];
		$pid  = (int) $product_id;
		if ( $pid <= 0 ) {
			return $none;
		}
		$product = wc_get_product( $pid );
		if ( ! $product instanceof WC_Product || self::is_invoice_product( $product ) ) {
			return $none;
		}
		if ( $product->is_type( 'variation' ) ) {
			return [ 'product_id' => (int) $product->get_parent_id(), 'variation_id' => $pid ];
		}
		return [ 'product_id' => $pid, 'variation_id' => 0 ];
	}

	/** FALLBACK: keep the invoice line, attach the lines as meta. */
	private static function attach_fallback( WC_Order_Item_Product $item, array $lines, $invoice_id ) {
		$summary = self::summary( $lines );
		if ( '' !== $summary ) {
			$item->update_meta_data( self::META_INCLUDES, $summary );
		}
		if ( ! empty( $lines ) ) {
			$item->update_meta_data( self::META_LINES, wp_json_encode( array_values( $lines ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
		}
		$item->update_meta_data( self::META_PRODUCT_ID, (int) $invoice_id );
	}

	/** get_taxes() → [ 'subtotal' => [ rate => float ], 'total' => [ rate => float ] ]. */
	private static function normalise_taxes( $taxes ) {
		$out = [ 'subtotal' => [], 'total' => [] ];
		if ( ! is_array( $taxes ) ) {
			return $out;
		}
		foreach ( [ 'subtotal', 'total' ] as $group ) {
			if ( isset( $taxes[ $group ] ) && is_array( $taxes[ $group ] ) ) {
				foreach ( $taxes[ $group ] as $rate_id => $amount ) {
					$out[ $group ][ $rate_id ] = (float) $amount;
				}
			}
		}
		return $out;
	}

	/** woocommerce_checkout_order_created: the order has an id now, write the queued notes. */
	public static function flush_notes( $order ) {
		if ( empty( self::$pending_notes ) ) {
			return;
		}
		if ( $order instanceof WC_Order ) {
			foreach ( self::$pending_notes as $note ) {
				$order->add_order_note( $note );
			}
		}
		self::$pending_notes = [];
	}

	/** An order with itemised fish lines must ship — never auto-complete it on payment. */
	public static function keep_processing( $status, $order_id, $order = null ) {
		if ( 'completed' !== $status ) {
			return $status;
		}
		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order_id );
		}
		if ( ! $order instanceof WC_Order ) {
			return $status;
		}
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( '' !== (string) $item->get_meta( self::META_FISH ) ) {
				return 'processing';
			}
		}
		return $status;
	}

	/* ─────────────────────────────────────────────
	 *  Lock after purchase
	 * ───────────────────────────────────────────── */

	/** woocommerce_payment_complete / order_status_processing / order_status_completed. */
	public static function lock_products( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order || self::is_fulfillment( $order ) ) {
			return;
		}
		$order_id = (int) $order->get_id();
		foreach ( self::referenced_invoice_products( $order ) as $product ) {
			$current = (int) $product->get_meta( self::META_ORDER_ID );
			// Another live order already owns the record (the invoice was put back
			// in stock by hand and bought again): keep that record, but the product
			// still goes out of stock — a paid invoice is never purchasable.
			$owner = ( $current > 0 && $current !== $order_id && self::order_holds_lock( $current ) ) ? $current : $order_id;
			if ( $owner === $current && 'outofstock' === $product->get_stock_status() ) {
				continue; // already locked
			}
			$product->update_meta_data( self::META_ORDER_ID, $owner );
			if ( $product->get_manage_stock() ) {
				$product->set_stock_quantity( 0 );
			}
			$product->set_stock_status( 'outofstock' );
			$product->save();
		}
	}

	/** woocommerce_order_status_cancelled: release the invoices this order held. */
	public static function unlock_products( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order || self::is_fulfillment( $order ) ) {
			return;
		}
		$order_id = (int) $order->get_id();
		foreach ( self::referenced_invoice_products( $order ) as $product ) {
			if ( (int) $product->get_meta( self::META_ORDER_ID ) !== $order_id ) {
				continue;
			}
			$product->delete_meta_data( self::META_ORDER_ID );
			if ( $product->get_manage_stock() ) {
				$product->set_stock_quantity( 1 );
			}
			$product->set_stock_status( 'instock' );
			$product->save();
		}
	}

	/**
	 * Invoice products an order references: items or fees carrying
	 * _fh_invoice_product_id, or a line item whose product is an invoice.
	 *
	 * @return WC_Product[] keyed by product id
	 */
	private static function referenced_invoice_products( WC_Order $order ) {
		$ids = [];
		foreach ( $order->get_items( [ 'line_item', 'fee' ] ) as $item ) {
			$pid = (int) $item->get_meta( self::META_PRODUCT_ID );
			if ( $pid <= 0 && $item instanceof WC_Order_Item_Product ) {
				$product = $item->get_product();
				if ( $product instanceof WC_Product && self::is_invoice_product( $product ) ) {
					$pid = (int) self::invoice_parent( $product )->get_id();
				}
			}
			if ( $pid > 0 ) {
				$ids[ $pid ] = true;
			}
		}
		$out = [];
		foreach ( array_keys( $ids ) as $pid ) {
			$product = wc_get_product( $pid );
			if ( $product instanceof WC_Product ) {
				$out[ $pid ] = $product;
			}
		}
		return $out;
	}

	/** Does this order still hold an invoice lock? (exists and is not cancelled / failed / refunded / trashed) */
	private static function order_holds_lock( $order_id ) {
		$order = wc_get_order( (int) $order_id );
		if ( ! $order instanceof WC_Order ) {
			return false;
		}
		return ! $order->has_status( [ 'cancelled', 'failed', 'refunded', 'trash' ] );
	}

	/** True for a Phase 2.5 fulfillment (combined-box) order — those mirror source orders and never own a lock. */
	private static function is_fulfillment( $order ) {
		return class_exists( 'FisHotel_Fulfillment' )
			&& method_exists( 'FisHotel_Fulfillment', 'is_fulfillment' )
			&& FisHotel_Fulfillment::is_fulfillment( $order );
	}

	/** woocommerce_hidden_order_itemmeta: keep the flags / JSON out of the admin item list. */
	public static function hidden_itemmeta( $keys ) {
		return array_merge( (array) $keys, [ self::META_FISH, self::META_CREDIT, self::META_PRODUCT_ID, self::META_TITLE, self::META_LINES ] );
	}

	/* ─────────────────────────────────────────────
	 *  Backfill for past orders (Tools page)
	 * ───────────────────────────────────────────── */

	/** Tools-page panel: the button + where the cursor stands. */
	public static function render_backfill_panel() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$page = max( 1, (int) get_option( self::OPTION_BACKFILL_PAGE, 1 ) );
		?>
			<div style="background:#fff; border:1px solid #ccd0d4; border-left:4px solid #c9963a; padding:20px 24px; margin:20px 0; max-width:600px;">
				<h2 style="margin-top:0; font-size:16px;"><?php esc_html_e( 'Add fish lines to past invoice orders', 'fishotel' ); ?></h2>
				<p style="color:#666;"><?php esc_html_e( 'Orders placed before the theme itemised invoices show one line named after the invoice. Each click scans the next 100 orders (newest first) and adds an "Includes" line listing the fish, plus the line snapshot the Ship Day pick list reads, to every invoice line that has none. Money and statuses are never touched; safe to run again.', 'fishotel' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::BACKFILL_ACTION ); ?>">
					<?php wp_nonce_field( self::BACKFILL_ACTION ); ?>
					<button type="submit" class="button button-primary" style="background:#c9963a; border-color:#b8862f; font-size:13px; padding:6px 20px;">
						<?php esc_html_e( 'Scan the next 100 orders', 'fishotel' ); ?>
					</button>
				</form>
				<?php if ( $page > 1 ) : ?>
					<p style="margin:12px 0 0; color:#666;">
						<?php
						/* translators: %d: page number of the next 100-order batch */
						echo esc_html( sprintf( __( 'Next click continues with page %d.', 'fishotel' ), $page ) );
						?>
					</p>
				<?php endif; ?>
			</div>
		<?php
	}

	/** admin-post handler: one page of 100 orders per click. */
	public static function handle_backfill() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'fishotel' ), '', [ 'response' => 403 ] );
		}
		check_admin_referer( self::BACKFILL_ACTION );

		$back = admin_url( 'admin.php?page=' . self::TOOLS_PAGE );
		if ( ! function_exists( 'wc_get_orders' ) ) {
			wp_safe_redirect( add_query_arg( self::BACKFILL_QUERY, 'error', $back ) );
			exit;
		}

		@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		$result = self::backfill_page( max( 1, (int) get_option( self::OPTION_BACKFILL_PAGE, 1 ) ) );
		if ( $result['done'] ) {
			delete_option( self::OPTION_BACKFILL_PAGE );
		} else {
			update_option( self::OPTION_BACKFILL_PAGE, $result['next_page'], false );
		}

		wp_safe_redirect( add_query_arg( [
			self::BACKFILL_QUERY => $result['done'] ? 'done' : 'more',
			'scanned'            => (int) $result['scanned'],
			'updated'            => (int) $result['updated'],
			'left'               => (int) $result['pages_left'],
		], $back ) );
		exit;
	}

	/**
	 * Scan one page of orders (newest first, wc_get_orders() so it works on
	 * HPOS and classic) and stamp every untouched invoice line.
	 *
	 * @return array{scanned:int,updated:int,pages_left:int,next_page:int,done:bool}
	 */
	public static function backfill_page( $page ) {
		$page  = max( 1, (int) $page );
		$query = wc_get_orders( [
			'type'     => 'shop_order',
			'limit'    => self::BACKFILL_PER_CLICK,
			'page'     => $page,
			'orderby'  => 'date',
			'order'    => 'DESC',
			'paginate' => true,
			'return'   => 'objects',
		] );
		$orders    = ( is_object( $query ) && isset( $query->orders ) ) ? (array) $query->orders : [];
		$max_pages = ( is_object( $query ) && isset( $query->max_num_pages ) ) ? (int) $query->max_num_pages : $page;

		$updated = 0;
		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order ) {
				continue;
			}
			foreach ( $order->get_items( 'line_item' ) as $item ) {
				if ( self::backfill_item( $item ) ) {
					$updated++;
				}
			}
		}

		$done = empty( $orders ) || $page >= $max_pages;
		return [
			'scanned'    => count( $orders ),
			'updated'    => $updated,
			'pages_left' => $done ? 0 : max( 0, $max_pages - $page ),
			'next_page'  => $page + 1,
			'done'       => $done,
		];
	}

	/** Stamp one line item when it is an untouched invoice line with lines to show. */
	private static function backfill_item( $item ) {
		if ( ! $item instanceof WC_Order_Item_Product ) {
			return false;
		}
		if ( '' !== (string) $item->get_meta( self::META_FISH ) || '' !== (string) $item->get_meta( self::META_LINES ) ) {
			return false;
		}
		$product = $item->get_product();
		if ( ! $product instanceof WC_Product || ! self::is_invoice_product( $product ) ) {
			return false;
		}
		$invoice = self::invoice_parent( $product );
		$lines   = self::get_lines( $invoice );
		if ( empty( $lines ) ) {
			return false;
		}
		self::attach_fallback( $item, $lines, (int) $invoice->get_id() );
		$item->save();
		return true;
	}

	/** Result notice on the Tools page after the redirect. */
	public static function backfill_notice() {
		if ( ! isset( $_GET[ self::BACKFILL_QUERY ], $_GET['page'] ) || self::TOOLS_PAGE !== sanitize_key( wp_unslash( $_GET['page'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display of our own redirect args, nothing is changed
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$state   = sanitize_key( wp_unslash( $_GET[ self::BACKFILL_QUERY ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$scanned = isset( $_GET['scanned'] ) ? absint( $_GET['scanned'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$updated = isset( $_GET['updated'] ) ? absint( $_GET['updated'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$left    = isset( $_GET['left'] ) ? absint( $_GET['left'] ) : 0;       // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		switch ( $state ) {
			case 'more':
				$class   = 'notice-info';
				/* translators: 1: orders scanned, 2: invoice lines updated, 3: pages left */
				$message = sprintf( __( 'Scanned %1$d orders, updated %2$d invoice lines; %3$d pages left, click again.', 'fishotel' ), $scanned, $updated, $left );
				break;
			case 'done':
				$class   = 'notice-success';
				/* translators: 1: orders scanned, 2: invoice lines updated */
				$message = sprintf( __( 'Done. Scanned %1$d orders, updated %2$d invoice lines; every order has been checked.', 'fishotel' ), $scanned, $updated );
				break;
			case 'error':
				$class   = 'notice-error';
				$message = __( 'WooCommerce is not active, nothing was scanned.', 'fishotel' );
				break;
			default:
				return;
		}
		echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
	}
}

FisHotel_Invoice_Lines::init();
