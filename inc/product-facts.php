<?php
/**
 * Fish facts typed in the description ("old style") — one set of rules
 * shared by the product page, the fish facts box and the Tools migration.
 *
 * Before the fish facts table had its own fields (inc/hotel-data.php), the
 * facts were typed into the product description as labelled lines
 * ("Scientific Name: …", "Maximum Length: …", "Foods and Feeding: …").
 * Three places read those lines:
 *
 *  - The product page hides a labelled line from the "About This Fish"
 *    text — since v1.28.0 ONLY when that fact's field is filled (so the
 *    table shows it instead). A fish whose fields are still empty keeps
 *    its facts readable in the text. "Fun Facts" and whatever follows are
 *    no longer cut off.
 *  - The fish facts box offers "Copy them into these fields" for one
 *    product (fills the empty boxes on screen; Update saves them).
 *  - FisHotel Theme → Tools → "Migrate Product Descriptions" runs the same
 *    copy for every published product. Neither ever overwrites a filled
 *    field.
 *
 * Everything above the "WordPress edge" line is pure PHP (no WordPress)
 * and is covered by tests/product-facts-test.php.
 *
 * Other plugins (the FisHotel Misc plugin's "Product checks" and "Edit
 * screens" sections) call:
 *   fishotel_copy_facts_from_description( $product_id ) → meta keys written
 *   fishotel_fish_facts_old_style( $product_id )         → bool
 *
 * @package FisHotel
 */

defined( 'ABSPATH' ) || exit;

class FisHotel_Product_Facts {

	/** Fact field key → meta key. Region is optional on the page, still a fact. */
	const META = [
		'scientific_name' => '_fh_scientific_name',
		'common_names'    => '_fh_common_names',
		'max_length'      => '_fh_max_length',
		'min_tank_size'   => '_fh_min_tank_size',
		'temperament'     => '_fh_temperament',
		'reef_safe'       => '_fh_reef_safe',
		'region'          => '_fh_region',
		'foods_feeding'   => '_fh_foods_feeding',
		'habitat'         => '_fh_habitat',
	];

	/** Reef safe values a field must hold to count as filled. */
	const REEF_VALUES = [ 'yes', 'no', 'caution' ];

	/**
	 * A labelled line at the start of a line (after optional bullets).
	 * The label may be followed by : - – — | a tab, or end the line
	 * (a heading on its own line). "Habits" is the older spelling the
	 * product page always knew; "Fun Facts" has no field.
	 */
	const LABEL_REGEX = '/^[\s*•\-]*(?<label>Scientific\s+Name|Common\s+Names?|Max(?:imum)?\.?\s+Lengths?|Min(?:imum)?\.?\s+(?:Aquarium|Tank)\s+Sizes?|Temperament|Reef[\s\-]?Safe(?:ty)?|Region|Foods?\s+(?:and|&)\s+Feeding(?:\s+Habits)?|Habitat\s+(?:and|&)\s+Behaviou?r|Habitat|Habits|Fun\s+Facts?)\s*(?:[:\-–—|]|\t|$)/imu';

	// ── Pure rules ─────────────────────────────────────────────────────

	/**
	 * Description HTML → plain text with one line per paragraph / line
	 * break / list item / table cell, tags stripped, entities decoded,
	 * non-breaking spaces made plain.
	 *
	 * @param string $html
	 * @return string
	 */
	public static function normalise( $html ) {
		$text = (string) $html;
		$text = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', $text );
		$text = preg_replace( '~<br\s*/?>|</(?:p|li|div|tr|td|h[1-6])\s*>~i', "\n", $text );
		$text = strip_tags( $text );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = str_replace( [ "\xC2\xA0", "\r\n", "\r" ], [ ' ', "\n", "\n" ], $text );
		return trim( $text );
	}

	/**
	 * Which fact a label names ('fun_facts' for Fun Facts).
	 *
	 * @param string $label The matched label text.
	 * @return string Field key.
	 */
	public static function field_for_label( $label ) {
		$l = strtolower( preg_replace( '/[\s\-]+/u', ' ', trim( (string) $label ) ) );
		if ( 0 === strpos( $l, 'scientific' ) ) return 'scientific_name';
		if ( 0 === strpos( $l, 'common' ) )     return 'common_names';
		if ( 0 === strpos( $l, 'max' ) )        return 'max_length';
		if ( 0 === strpos( $l, 'min' ) )        return 'min_tank_size';
		if ( 0 === strpos( $l, 'temperament' ) ) return 'temperament';
		if ( 0 === strpos( $l, 'reef' ) )       return 'reef_safe';
		if ( 0 === strpos( $l, 'region' ) )     return 'region';
		if ( 0 === strpos( $l, 'food' ) )       return 'foods_feeding';
		if ( 0 === strpos( $l, 'fun' ) )        return 'fun_facts';
		return 'habitat'; // Habitat, Habitat & Behaviour, Habits.
	}

	/**
	 * The field a single line is labelled with, or '' for a plain line.
	 *
	 * @param string $line
	 * @return string
	 */
	public static function line_field( $line ) {
		if ( preg_match( self::LABEL_REGEX, (string) $line, $m ) ) {
			return self::field_for_label( $m['label'] );
		}
		return '';
	}

	/**
	 * Distinct facts named by labelled lines in the text (in first-seen
	 * order), 'fun_facts' included.
	 *
	 * @param string $text Normalised text.
	 * @return string[]
	 */
	public static function labels_found( $text ) {
		$found = [];
		foreach ( explode( "\n", (string) $text ) as $line ) {
			$field = self::line_field( $line );
			if ( '' !== $field && ! in_array( $field, $found, true ) ) {
				$found[] = $field;
			}
		}
		return $found;
	}

	/**
	 * Is a stored value "filled" for this fact?
	 *
	 * @param string $field
	 * @param mixed  $value
	 * @return bool
	 */
	public static function is_filled( $field, $value ) {
		$value = trim( (string) $value );
		if ( 'reef_safe' === $field ) {
			return in_array( strtolower( $value ), self::REEF_VALUES, true );
		}
		return '' !== $value;
	}

	/**
	 * Field keys whose value is filled, from a field => value map.
	 *
	 * @param array $values
	 * @return string[]
	 */
	public static function filled_fields( array $values ) {
		$filled = [];
		foreach ( $values as $field => $value ) {
			if ( self::is_filled( $field, $value ) ) {
				$filled[] = $field;
			}
		}
		return $filled;
	}

	/**
	 * Facts the product page shows outside the description text, so their
	 * typed line can go: the table rows (fish only — the table is printed
	 * for the Quarantined Fish category alone) and the Fish Dossier blocks
	 * (Foods & feeding, Habitat & behaviour — printed for every product).
	 * The Region row also shows when a region tag is set.
	 *
	 * @param array $values     Field key => stored value.
	 * @param bool  $table      True when the facts table is printed.
	 * @param bool  $region_tag True when a region tag gives the Region row.
	 * @return string[]
	 */
	public static function shown_elsewhere( array $values, $table, $region_tag ) {
		$filled = self::filled_fields( $values );
		if ( ! $table ) {
			return array_values( array_intersect( $filled, [ 'foods_feeding', 'habitat' ] ) );
		}
		if ( $region_tag && ! in_array( 'region', $filled, true ) ) {
			$filled[] = 'region';
		}
		return $filled;
	}

	/**
	 * Still "old style": at least two different facts are typed as
	 * labelled lines AND at least one of those facts has an empty field
	 * that a copy can fill (so the table is missing something the text
	 * has). Labels found but every field filled is harmless — the product
	 * page hides the lines. A label with nothing readable after it
	 * ("Minimum Tank Size:" and no size) is not a fact to copy, so it never
	 * keeps a fish flagged.
	 *
	 * @param string   $text   Normalised description (+ short description).
	 * @param string[] $filled Filled field keys.
	 * @return bool
	 */
	public static function is_old_style( $text, array $filled ) {
		$found = self::labels_found( $text );
		if ( count( $found ) < 2 ) {
			return false;
		}
		$readable = self::extract( $text );
		foreach ( $found as $field ) {
			if ( 'fun_facts' !== $field && ! in_array( $field, $filled, true ) && isset( $readable[ $field ] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The "About This Fish" text: the description with each labelled line
	 * removed ONLY when its fact is filled (the table shows it). A leading
	 * "Description:" is dropped; runs of blank lines shrink to one.
	 *
	 * @param string   $html   Raw description.
	 * @param string[] $filled Filled field keys (region also when a region tag is set).
	 * @return string Plain text (escape before printing).
	 */
	public static function prose( $html, array $filled ) {
		$keep = [];
		foreach ( explode( "\n", self::normalise( $html ) ) as $line ) {
			$field = self::line_field( $line );
			if ( '' !== $field && in_array( $field, $filled, true ) ) {
				continue;
			}
			$keep[] = rtrim( $line );
		}
		$prose = trim( implode( "\n", $keep ) );
		$prose = preg_replace( '/^Description:\s*/i', '', $prose );
		$prose = preg_replace( "/\n{3,}/", "\n\n", $prose );
		return trim( $prose );
	}

	/**
	 * Read the facts out of old-style text — the same reading the Tools
	 * migration has always done (inc/hotel-data.php before v1.28.0).
	 *
	 * @param string $text Normalised text.
	 * @return array<string,string> Field key => value, only facts found.
	 */
	public static function extract( $text ) {
		$text = (string) $text;
		$out  = [];

		$extract = function ( $patterns, $text ) {
			foreach ( $patterns as $pattern ) {
				if ( preg_match( $pattern, $text, $m ) ) {
					$val = trim( $m[1] );
					$val = preg_replace( '/\s+(Scientific|Common|Maximum|Minimum|Reef|Temperament|Foods|Habitat|Fun\s+Facts?).*$/is', '', $val );
					// "Scientific Name:" with nothing after it: the pattern
					// ran on to the next line, which is another fact's label.
					if ( '' !== self::line_field( $val ) ) {
						continue;
					}
					return $val;
				}
			}
			return '';
		};

		// Single-line fields — safe regex (no backtracking risk).
		$fields_map = [
			'scientific_name' => [ '/Scientific Name\s*[:-]\s*([^\n]+)/i' ],
			'common_names'    => [ '/Common Names?\s*[:-]\s*([^\n]+)/i' ],
			'max_length'      => [ '/Maximum?\s*Lengths?\s*[:-]\s*([^\n]+)/i', '/Max\.?\s*Lengths?\s*[:-]\s*([^\n]+)/i' ],
			'min_tank_size'   => [ '/Minimum\s*Aquarium\s*Sizes?\s*[:-]\s*([^\n]+)/i', '/Min\.?\s*Tank\s*Sizes?\s*[:-]\s*([^\n]+)/i' ],
			'temperament'     => [ '/Temperament\s*[:-]\s*([^\n]+)/i' ],
			'region'          => [ '/Region\s*[:-]\s*([^\n]+)/i' ],
		];
		foreach ( $fields_map as $field => $patterns ) {
			$value = $extract( $patterns, $text );
			if ( '' !== (string) $value ) {
				$out[ $field ] = $value;
			}
		}

		// Multi-line fields: the label line, then following lines until the
		// next known label.
		$multiline_map = [
			'foods_feeding' => [ 'Foods and Feeding Habits', 'Foods and Feeding', 'Feeding' ],
			'habitat'       => [ 'Habitat and Behavior', 'Habitat & Behavior', 'Habitat', 'Habits' ],
		];
		$stop_at = [ 'Scientific Name', 'Common Names', 'Maximum', 'Minimum Aquarium',
		             'Temperament', 'Reef Safety', 'Reef Safe', 'Description', 'Fun Facts',
		             'Region', 'Foods and Feeding', 'Habitat', 'Habits', 'Feeding' ];
		$lines = explode( "\n", $text );
		foreach ( $multiline_map as $field => $labels ) {
			$collecting = false;
			$collected  = [];
			foreach ( $lines as $line ) {
				$line = trim( $line );
				if ( ! $collecting ) {
					foreach ( $labels as $label ) {
						if ( stripos( $line, $label . ':' ) === 0 || stripos( $line, $label . ' -' ) === 0 ) {
							$collecting = true;
							$after      = trim( preg_replace( '/^' . preg_quote( $label, '/' ) . '\s*[:\-]\s*/i', '', $line ) );
							if ( $after ) $collected[] = $after;
							break;
						}
					}
				} else {
					// Stop at the next labelled line. Before v1.28.0 only the
					// short list below stopped it, so "Habitat and Behavior: …"
					// right after a feeding block ran into Foods & feeding.
					$stop = '' !== self::line_field( $line );
					foreach ( $stop_at as $s ) {
						if ( stripos( $line, $s . ':' ) === 0 || stripos( $line, $s . ' -' ) === 0 ) {
							$stop = true;
							break;
						}
					}
					if ( $stop ) break;
					if ( $line ) $collected[] = $line;
				}
			}
			if ( ! empty( $collected ) ) {
				$out[ $field ] = implode( ' ', $collected );
			}
		}

		if ( preg_match( '/Reef[\s\-]?Safe(?:ty)?\s*[:-]\s*([^\n]+)/i', $text, $m ) && '' === self::line_field( $m[1] ) ) {
			$out['reef_safe'] = self::reef_value( $m[1] );
		}

		// Every other labelled line the old-style test recognises
		// ("Minimum Tank Size:", "Foods & Feeding:", "Habitat and
		// Behaviour:", "Temperament – calm", a heading on its own line…):
		// read it the same way the test sees it, so a fish flagged "old
		// style" can always be copied (v1.28.0).
		foreach ( self::labelled_values( $text ) as $field => $value ) {
			if ( isset( $out[ $field ] ) ) {
				continue;
			}
			$out[ $field ] = 'reef_safe' === $field ? self::reef_value( $value ) : $value;
		}

		// Field order, not discovery order.
		$ordered = [];
		foreach ( array_keys( self::META ) as $field ) {
			if ( isset( $out[ $field ] ) ) {
				$ordered[ $field ] = $out[ $field ];
			}
		}
		return $ordered;
	}

	/** A typed reef-safe answer → yes / no / caution (yes unless it says otherwise). */
	public static function reef_value( $typed ) {
		$val = strtolower( trim( (string) $typed ) );
		if ( strpos( $val, 'caution' ) !== false ) return 'caution';
		if ( strpos( $val, 'no' ) !== false )      return 'no';
		return 'yes';
	}

	/**
	 * The value of each labelled line, read exactly as LABEL_REGEX sees
	 * the label: the text after the label on the same line; when that is
	 * empty (a heading on its own line), the lines below it up to the next
	 * labelled line — one line for a short fact, all of them for Foods &
	 * feeding and Habitat. A label with nothing after it gives nothing.
	 * First occurrence wins. Fun Facts is never a value.
	 *
	 * @param string $text Normalised text.
	 * @return array<string,string> Field key => value.
	 */
	public static function labelled_values( $text ) {
		$lines = explode( "\n", (string) $text );
		$out   = [];
		$n     = count( $lines );
		for ( $i = 0; $i < $n; $i++ ) {
			if ( ! preg_match( self::LABEL_REGEX, $lines[ $i ], $m ) ) {
				continue;
			}
			$field = self::field_for_label( $m['label'] );
			if ( 'fun_facts' === $field || isset( $out[ $field ] ) ) {
				continue;
			}
			$multi = in_array( $field, [ 'foods_feeding', 'habitat' ], true );
			$parts = [];
			$after = trim( (string) substr( $lines[ $i ], strlen( $m[0] ) ) );
			if ( '' !== $after ) {
				$parts[] = $after;
			}
			if ( $multi || ! $parts ) {
				for ( $k = $i + 1; $k < $n; $k++ ) {
					$line = trim( $lines[ $k ] );
					if ( '' !== self::line_field( $line ) || preg_match( '/^Description\s*:/i', $line ) ) {
						break;
					}
					if ( '' === $line ) {
						if ( $parts ) {
							// A blank line ends a short fact; a block goes on.
							if ( ! $multi ) break;
						}
						continue;
					}
					$parts[] = $line;
					if ( ! $multi ) {
						break;
					}
				}
			}
			$value = trim( implode( ' ', $parts ) );
			if ( '' !== $value ) {
				$out[ $field ] = $value;
			}
		}
		return $out;
	}

	/**
	 * What a copy would write: the facts read from the text for fields
	 * that are empty now. A field holding anything at all (even a value
	 * the table cannot show) is never in the result.
	 *
	 * @param string $text    Normalised text.
	 * @param array  $current Field key => stored value.
	 * @return array<string,string>
	 */
	public static function copy_plan( $text, array $current ) {
		$plan = [];
		foreach ( self::extract( $text ) as $field => $value ) {
			if ( '' !== trim( (string) ( $current[ $field ] ?? '' ) ) ) {
				continue;
			}
			$plan[ $field ] = $value;
		}
		return $plan;
	}

	// ── WordPress edge ─────────────────────────────────────────────────

	/** Stored fact values of a product, field key => value. */
	public static function current( $product_id ) {
		$values = [];
		foreach ( self::META as $field => $meta_key ) {
			$values[ $field ] = (string) get_post_meta( (int) $product_id, $meta_key, true );
		}
		return $values;
	}

	/** Description + short description of a product, normalised. */
	public static function product_text( $product_id ) {
		$product_id = (int) $product_id;
		return self::normalise(
			(string) get_post_field( 'post_content', $product_id ) . "\n" . (string) get_post_field( 'post_excerpt', $product_id )
		);
	}

	/** Copy plan for one product (nothing written). */
	public static function plan_for( $product_id ) {
		return self::copy_plan( self::product_text( $product_id ), self::current( $product_id ) );
	}

	/**
	 * Medications and foods never get fish facts: their descriptions often
	 * hold lines such as "Reef Safe: Yes" or "Region: …" that are about the
	 * product, not a fish. Same rule as the fish facts box's yellow line.
	 */
	public static function is_med_or_food( $product_id ) {
		return ( function_exists( 'fishotel_is_medication_product' ) && fishotel_is_medication_product( $product_id ) )
			|| ( function_exists( 'fishotel_is_food_product' ) && fishotel_is_food_product( $product_id ) );
	}

	/**
	 * Copy the facts typed in one product's description into its empty
	 * fields. Never overwrites a filled field. A medication or food is
	 * never touched (also when the Tools migration or another plugin's bulk
	 * action asks).
	 *
	 * @param int $product_id
	 * @return string[] Meta keys written (empty when nothing was copied).
	 */
	public static function copy_from_description( $product_id ) {
		$product_id = (int) $product_id;
		$written    = [];
		if ( ! $product_id || self::is_med_or_food( $product_id ) ) {
			return $written;
		}
		foreach ( self::plan_for( $product_id ) as $field => $value ) {
			$meta_key = self::META[ $field ];
			$value    = 'reef_safe' === $field ? $value : sanitize_textarea_field( $value );
			if ( '' === trim( (string) $value ) ) {
				continue;
			}
			update_post_meta( $product_id, $meta_key, $value );
			$written[] = $meta_key;
		}
		return $written;
	}

	/** Old-style test for one product. */
	public static function product_is_old_style( $product_id ) {
		$product_id = (int) $product_id;
		if ( ! $product_id || self::is_med_or_food( $product_id ) ) {
			return false;
		}
		return self::is_old_style(
			self::product_text( $product_id ),
			self::filled_fields( self::current( $product_id ) )
		);
	}
}

/**
 * Copy the facts typed in a product's description into its empty fish
 * facts fields (never overwrites; a medication or food is never touched).
 * For other FisHotel plugins.
 *
 * @param int $product_id
 * @return string[] Meta keys written.
 */
function fishotel_copy_facts_from_description( $product_id ) {
	return FisHotel_Product_Facts::copy_from_description( $product_id );
}

/**
 * True when a product's facts are still typed in its description and at
 * least one of them is missing from the fields and can be copied. Never
 * true for a medication or food.
 *
 * @param int $product_id
 * @return bool
 */
function fishotel_fish_facts_old_style( $product_id ) {
	return FisHotel_Product_Facts::product_is_old_style( $product_id );
}
