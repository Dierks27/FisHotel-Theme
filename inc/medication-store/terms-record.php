<?php
/**
 * Medication store — terms-acceptance record (pure builder).
 *
 * Everything in this class is plain PHP: no WordPress, no WooCommerce,
 * no globals. The checkout hook, the admin "Record manual
 * acknowledgement" handler and every display surface gather their
 * inputs and hand them here, so the record shape, the terms hash, the
 * extra-acknowledgement required set and the validation rules can be
 * covered by tests/med-terms-test.php without a WordPress install.
 *
 * Record shape (stored as the `_fishotel_med_terms` order meta array):
 *
 *   accepted          bool    always true when a record exists
 *   accepted_at_utc   string  ISO-8601, gmdate('c')
 *   accepted_at_local string  site timezone, 'Y-m-d H:i T'
 *   accepted_at       int     unix timestamp (for sorting / re-formatting)
 *   ip                string  <= 45 chars (IPv6 max)
 *   user_agent        string  <= 255 chars
 *   label             string  the exact checkbox text, tags stripped
 *   terms_version     string  FisHotel_Med_Settings terms version at the time
 *   terms_hash        string  md5 of normalised disclaimer body + label
 *   products          array   [ [ 'id' => int, 'name' => string, 'qty' => int ], ... ]
 *   extra_acks        array   the extra acknowledgement texts accepted
 *   source            string  'checkout' | 'manual'
 *   admin_user_id     int     manual records only
 *   admin_user_name   string  manual records only
 *   note              string  manual records only (one line, optional)
 *
 * @package FisHotel
 */

defined( 'ABSPATH' ) || exit;

class FisHotel_Med_Terms {

	/** Order meta key holding the full record array. */
	const META_KEY = '_fishotel_med_terms';

	/** Legacy order meta keys written since the first disclaimer release. Kept. */
	const LEGACY_ACCEPTED_KEY    = '_fishotel_med_disclaimer_accepted';
	const LEGACY_ACCEPTED_AT_KEY = '_fishotel_med_disclaimer_accepted_at';

	/** Default terms version when the setting is blank. */
	const DEFAULT_VERSION = '2026-10';

	/** Checkout field name for the per-product extra acknowledgements. */
	const EXTRA_FIELD = 'fishotel_med_ack_extra';

	const IP_MAX_LEN      = 45;
	const UA_MAX_LEN      = 255;
	const LABEL_MAX_LEN   = 1000;
	const NOTE_MAX_LEN    = 200;
	const VERSION_MAX_LEN = 32;
	const LOCAL_FORMAT    = 'Y-m-d H:i T';

	/** Record state constants returned by detect_state(). */
	const STATE_RECORDED = 'recorded';
	const STATE_LEGACY   = 'legacy';
	const STATE_MISSING  = 'missing';
	const STATE_NONE     = 'none';

	// ── Builders ─────────────────────────────────────────────────────

	/**
	 * Build the checkout acceptance record.
	 *
	 * @param array $input {
	 *   @type int                 $timestamp     Unix time of acceptance (default: now).
	 *   @type DateTimeZone|string $timezone      Site timezone (default: UTC).
	 *   @type string              $ip            Customer IP.
	 *   @type string              $user_agent    Customer UA.
	 *   @type string              $label         Checkbox label as shown (may contain tags).
	 *   @type string              $terms_version Version string from settings.
	 *   @type string              $terms_body    Disclaimer body (HTML allowed) — hashed, not stored.
	 *   @type array               $products      Medication lines: [ id, name, qty ] each.
	 *   @type array               $extra_acks    Extra acknowledgement texts accepted.
	 *   @type string              $source        'checkout' (default) or 'manual'.
	 * }
	 * @return array
	 */
	public static function build_record( array $input ) {
		$ts = isset( $input['timestamp'] ) ? (int) $input['timestamp'] : time();
		if ( $ts <= 0 ) {
			$ts = time();
		}
		$tz = isset( $input['timezone'] ) ? $input['timezone'] : 'UTC';

		$label   = self::clean_text( isset( $input['label'] ) ? $input['label'] : '', self::LABEL_MAX_LEN );
		$body    = isset( $input['terms_body'] ) ? (string) $input['terms_body'] : '';
		$version = self::sanitize_version( isset( $input['terms_version'] ) ? $input['terms_version'] : '' );
		$source  = isset( $input['source'] ) && $input['source'] === 'manual' ? 'manual' : 'checkout';

		return [
			'accepted'          => true,
			'accepted_at_utc'   => gmdate( 'c', $ts ),
			'accepted_at_local' => self::local_time_string( $ts, $tz ),
			'accepted_at'       => $ts,
			'ip'                => self::truncate( self::single_line( isset( $input['ip'] ) ? $input['ip'] : '' ), self::IP_MAX_LEN ),
			'user_agent'        => self::truncate( self::single_line( isset( $input['user_agent'] ) ? $input['user_agent'] : '' ), self::UA_MAX_LEN ),
			'label'             => $label,
			'terms_version'     => $version,
			'terms_hash'        => self::terms_hash( $body, $label ),
			'products'          => self::normalise_products( isset( $input['products'] ) ? $input['products'] : [] ),
			'extra_acks'        => self::normalise_extra_acks( isset( $input['extra_acks'] ) ? $input['extra_acks'] : [] ),
			'source'            => $source,
		];
	}

	/**
	 * Build a record for an acknowledgement taken outside checkout (phone
	 * order, admin-created order, order placed before this version).
	 *
	 * @param array $input Same keys as build_record() plus:
	 *   @type int    $admin_user_id
	 *   @type string $admin_user_name
	 *   @type string $note  Optional one-line note.
	 * @return array
	 */
	public static function build_manual_record( array $input ) {
		$input['source'] = 'manual';
		// No IP / UA for a manual record: the admin's browser is not the customer's.
		unset( $input['ip'], $input['user_agent'] );
		$record = self::build_record( $input );
		$record['admin_user_id']   = isset( $input['admin_user_id'] ) ? (int) $input['admin_user_id'] : 0;
		$record['admin_user_name'] = self::truncate( self::single_line( isset( $input['admin_user_name'] ) ? $input['admin_user_name'] : '' ), 100 );
		$record['note']            = self::truncate( self::single_line( isset( $input['note'] ) ? $input['note'] : '' ), self::NOTE_MAX_LEN );
		return $record;
	}

	// ── Terms hash ───────────────────────────────────────────────────

	/**
	 * Stable fingerprint of the terms text the customer saw. Tags are
	 * stripped and whitespace collapsed first, so a re-saved body with
	 * different line breaks or <p> wrapping hashes the same, while any
	 * wording change does not.
	 */
	public static function terms_hash( $body, $label ) {
		return md5( self::normalise_text( $body ) . "\n" . self::normalise_text( $label ) );
	}

	/** Strip tags, decode entities, collapse whitespace, lowercase-insensitive trim. */
	public static function normalise_text( $text ) {
		$text = (string) $text;
		$text = preg_replace( '#<(script|style)[^>]*>.*?</\1>#is', ' ', $text );
		$text = strip_tags( $text );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = str_replace( "\xC2\xA0", ' ', $text ); // nbsp
		$text = preg_replace( '/\s+/u', ' ', $text );
		return trim( (string) $text );
	}

	// ── Extra acknowledgements ───────────────────────────────────────

	/**
	 * Compute the required extra-acknowledgement set from the medication
	 * lines in the cart. Each distinct non-empty text appears once, keyed by
	 * the md5 of its normalised form, with the product names it applies to.
	 *
	 * @param array $lines [ [ 'name' => string, 'extra_ack' => string ], ... ]
	 * @return array hash => [ 'hash' => string, 'text' => string, 'products' => string[] ]
	 */
	public static function extra_ack_required_set( array $lines ) {
		$set = [];
		foreach ( $lines as $line ) {
			$raw  = isset( $line['extra_ack'] ) ? (string) $line['extra_ack'] : '';
			$text = self::normalise_text( $raw );
			if ( $text === '' ) {
				continue;
			}
			$hash = md5( $text );
			$name = self::normalise_text( isset( $line['name'] ) ? $line['name'] : '' );
			if ( ! isset( $set[ $hash ] ) ) {
				$set[ $hash ] = [
					'hash'     => $hash,
					'text'     => $text,
					'products' => [],
				];
			}
			if ( $name !== '' && ! in_array( $name, $set[ $hash ]['products'], true ) ) {
				$set[ $hash ]['products'][] = $name;
			}
		}
		return $set;
	}

	/**
	 * Validate the posted extra acknowledgements against the live required
	 * set. Posted keys are never trusted: the required set is recomputed
	 * from the cart and every hash in it must be present AND checked. Keys
	 * that are not in the required set (forged indexes) are ignored.
	 *
	 * @param array $required Output of extra_ack_required_set().
	 * @param mixed $posted   $_POST[ self::EXTRA_FIELD ] — hash => '1'.
	 * @return array Hashes that are missing or unchecked ([] = valid).
	 */
	public static function validate_extra_acks( array $required, $posted ) {
		$posted  = is_array( $posted ) ? $posted : [];
		$missing = [];
		foreach ( $required as $hash => $ack ) {
			$key = isset( $ack['hash'] ) ? (string) $ack['hash'] : (string) $hash;
			if ( ! isset( $posted[ $key ] ) || (string) $posted[ $key ] !== '1' ) {
				$missing[] = $key;
			}
		}
		return $missing;
	}

	/** Label for one extra acknowledgement: "Product A, Product B: <text>". */
	public static function extra_ack_label( array $ack ) {
		$text     = isset( $ack['text'] ) ? (string) $ack['text'] : '';
		$products = isset( $ack['products'] ) && is_array( $ack['products'] ) ? $ack['products'] : [];
		$products = array_values( array_filter( array_map( 'strval', $products ), 'strlen' ) );
		if ( empty( $products ) ) {
			return $text;
		}
		return implode( ', ', $products ) . ': ' . $text;
	}

	// ── State detection ──────────────────────────────────────────────

	/** True when the value looks like a record this class built. */
	public static function is_record( $value ) {
		return is_array( $value ) && ! empty( $value['accepted'] ) && isset( $value['accepted_at_utc'] );
	}

	/**
	 * Decide what an order screen / document should show.
	 *
	 * @param mixed  $record          Value of the `_fishotel_med_terms` meta.
	 * @param mixed  $legacy_accepted Value of `_fishotel_med_disclaimer_accepted`.
	 * @param bool   $has_med_lines   Whether the order contains medication lines.
	 * @return string One of the STATE_* constants.
	 */
	public static function detect_state( $record, $legacy_accepted, $has_med_lines ) {
		if ( self::is_record( $record ) ) {
			return self::STATE_RECORDED;
		}
		if ( (string) $legacy_accepted === '1' ) {
			return self::STATE_LEGACY;
		}
		return $has_med_lines ? self::STATE_MISSING : self::STATE_NONE;
	}

	// ── Display formatters ───────────────────────────────────────────

	/**
	 * One-line summary used in emails, PDFs and the EA slip.
	 *
	 *   Medication terms accepted 2026-10-17 14:03 EDT · terms v2026-10
	 *   Medication terms accepted 2026-10-17 14:03 EDT · IP 203.0.113.9 · v2026-10
	 *
	 * A manual record is marked as such so nobody mistakes it for a
	 * checkout click. Plain text — callers escape for their context.
	 */
	public static function format_line( array $record, $with_ip = false ) {
		$date    = isset( $record['accepted_at_local'] ) ? (string) $record['accepted_at_local'] : '';
		$version = isset( $record['terms_version'] ) ? (string) $record['terms_version'] : '';
		$ip      = isset( $record['ip'] ) ? (string) $record['ip'] : '';
		$source  = isset( $record['source'] ) ? (string) $record['source'] : 'checkout';

		$line = 'Medication terms accepted ' . $date;
		if ( $with_ip ) {
			$line .= ' · IP ' . ( $ip !== '' ? $ip : 'n/a' );
			$line .= ' · v' . $version;
		} else {
			$line .= ' · terms v' . $version;
		}
		if ( $source === 'manual' ) {
			$by    = isset( $record['admin_user_name'] ) ? (string) $record['admin_user_name'] : '';
			$line .= ' (recorded manually' . ( $by !== '' ? ' by ' . $by : '' ) . ')';
		}
		return $line;
	}

	/** Local time string for a legacy record that only stored a timestamp. */
	public static function local_time_string( $timestamp, $timezone = 'UTC' ) {
		$timestamp = (int) $timestamp;
		try {
			$tz = $timezone instanceof DateTimeZone ? $timezone : new DateTimeZone( (string) $timezone );
		} catch ( Exception $e ) {
			$tz = new DateTimeZone( 'UTC' );
		}
		$dt = new DateTime( '@' . $timestamp );
		$dt->setTimezone( $tz );
		return $dt->format( self::LOCAL_FORMAT );
	}

	// ── Normalisers ──────────────────────────────────────────────────

	/** Keep a version string short and printable; fall back to the default. */
	public static function sanitize_version( $version ) {
		$version = self::normalise_text( $version );
		$version = preg_replace( '/[^A-Za-z0-9._\-]/', '', $version );
		$version = self::truncate( (string) $version, self::VERSION_MAX_LEN );
		return $version !== '' ? $version : self::DEFAULT_VERSION;
	}

	/** [ id, name, qty ] rows only, ints cast, empty names dropped. */
	public static function normalise_products( $products ) {
		$out = [];
		if ( ! is_array( $products ) ) {
			return $out;
		}
		foreach ( $products as $p ) {
			if ( ! is_array( $p ) ) {
				continue;
			}
			$id   = isset( $p['id'] ) ? (int) $p['id'] : 0;
			$name = self::clean_text( isset( $p['name'] ) ? $p['name'] : '', 200 );
			$qty  = isset( $p['qty'] ) ? (int) $p['qty'] : 0;
			if ( $id <= 0 && $name === '' ) {
				continue;
			}
			$out[] = [ 'id' => $id, 'name' => $name, 'qty' => max( 0, $qty ) ];
		}
		return $out;
	}

	/** Distinct, non-empty, tag-free texts in input order. */
	public static function normalise_extra_acks( $acks ) {
		$out = [];
		if ( ! is_array( $acks ) ) {
			return $out;
		}
		foreach ( $acks as $ack ) {
			$text = self::normalise_text( is_array( $ack ) && isset( $ack['text'] ) ? $ack['text'] : $ack );
			if ( $text !== '' && ! in_array( $text, $out, true ) ) {
				$out[] = $text;
			}
		}
		return $out;
	}

	/** Tags stripped + whitespace collapsed + bounded. */
	public static function clean_text( $text, $max ) {
		return self::truncate( self::normalise_text( $text ), $max );
	}

	/** Control characters and line breaks removed, trimmed. */
	public static function single_line( $text ) {
		$text = preg_replace( '/[\x00-\x1F\x7F]+/', ' ', (string) $text );
		return trim( (string) $text );
	}

	/** Byte-safe truncate that never splits a UTF-8 sequence. */
	public static function truncate( $text, $max ) {
		$text = (string) $text;
		$max  = (int) $max;
		if ( $max <= 0 || strlen( $text ) <= $max ) {
			return $text;
		}
		if ( function_exists( 'mb_strcut' ) ) {
			return mb_strcut( $text, 0, $max, 'UTF-8' );
		}
		return substr( $text, 0, $max );
	}
}
