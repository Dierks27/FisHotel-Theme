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
 *    facts beside the photo show it instead). A fish whose fields are
 *    still empty keeps its facts readable in the text. "Fun Facts" and
 *    whatever follows are no longer cut off.
 *  - Since v1.30.0 the product page prints the description WITH its
 *    formatting through rich(): paragraphs, lists, bold, italics, links
 *    and quotes are kept (nothing else), and the "Fun Facts" block
 *    becomes a card of its own. prose() — the plain text — is what a
 *    medication page still prints, and what rich() falls back to.
 *  - The fish facts box offers "Copy them into these fields" for one
 *    product (fills the empty boxes on screen; Update saves them).
 *  - FisHotel Theme → Tools → "Migrate Product Descriptions" runs the same
 *    copy for every published product. Neither ever overwrites a filled
 *    field.
 *
 * Everything above the "WordPress edge" line is pure PHP (no WordPress)
 * and is covered by tests/product-facts-test.php and, for rich(),
 * tests/product-rich-test.php.
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

	// ── The description with its formatting (v1.30.0) ──────────────────

	/** A fun fact's lead-in ("Night Owl: …") is at most this long. */
	const LEAD_MAX_CHARS = 40;
	const LEAD_MAX_WORDS = 6;

	/** Inline tags a line may keep; block tags that start a new line. */
	const RICH_INLINE = [ 'strong' => 1, 'b' => 1, 'em' => 1, 'i' => 1, 'a' => 1 ];
	const RICH_BLOCK  = [
		'p' => 1, 'div' => 1, 'section' => 1, 'article' => 1, 'ul' => 1, 'ol' => 1, 'li' => 1,
		'blockquote' => 1, 'table' => 1, 'tr' => 1, 'td' => 1, 'th' => 1, 'hr' => 1, 'br' => 1,
		'h1' => 1, 'h2' => 1, 'h3' => 1, 'h4' => 1, 'h5' => 1, 'h6' => 1,
	];

	/**
	 * Every tag rich() can write, in wp_kses() form. The product page
	 * prints rich()'s HTML through wp_kses() with exactly this list.
	 *
	 * @return array
	 */
	public static function rich_allowed_html() {
		return [
			'p' => [], 'br' => [], 'strong' => [], 'b' => [], 'em' => [], 'i' => [],
			'ul' => [], 'ol' => [], 'li' => [], 'blockquote' => [],
			'a' => [ 'href' => true ],
		];
	}

	/**
	 * The description for the product page WITH its formatting: paragraphs,
	 * line breaks, lists, bold, italics, links and quotes are kept; every
	 * other tag and every attribute except a link's href is dropped (its
	 * words stay). The "Fun Facts" block — a blockquote that starts with
	 * the label, or the label typed on its own — is taken out of the text
	 * and returned as rows, each with its lead-in (the words before the
	 * first colon). A labelled line whose fact is filled is hidden exactly
	 * as in prose(). Text is never dropped by any other rule.
	 *
	 * Shortcodes are not run: "[gallery]" is printed as the typed words,
	 * as the page has always done.
	 *
	 * When the markup is beyond these rules (text would be lost, a tag left
	 * open, a broken character) the answer is the plain text of prose() in
	 * one paragraph — what the page printed before v1.30.0 — and 'plain'
	 * is true.
	 *
	 * @param string   $html   Raw description.
	 * @param string[] $filled Filled field keys, as for prose().
	 * @return array{ html: string, fun_title: string, fun_facts: array<int, array{ lead: string, html: string }>, plain: bool }
	 *               html and each fact's html hold only the tags of
	 *               rich_allowed_html(); each lead is PLAIN TEXT (escape it).
	 */
	public static function rich( $html, array $filled ) {
		try {
			$out = self::rich_build( (string) $html, $filled );
			if ( null !== $out ) {
				return $out;
			}
		} catch ( \Throwable $e ) {
			// Fall through to the plain text.
		}
		$prose = self::prose( $html, $filled );
		return [
			'html'      => '' === $prose ? '' : '<p>' . nl2br( htmlspecialchars( $prose, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) ) . '</p>',
			'fun_title' => '',
			'fun_facts' => [],
			'plain'     => true,
		];
	}

	/** Tags stripped, entities decoded, spaces tidied: the text a reader sees. */
	private static function rich_plain( $html ) {
		$text = html_entity_decode( preg_replace( '/<[^>]*>/', '', (string) $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = preg_replace( '/\s+/u', ' ', str_replace( "\xC2\xA0", ' ', $text ) );
		if ( null === $text ) {
			throw new \RuntimeException( 'text' );
		}
		return trim( $text );
	}

	/** Typed text → safe text for HTML (entities read once, written once). */
	private static function rich_esc( $text ) {
		$text = html_entity_decode( (string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return htmlspecialchars( str_replace( "\xC2\xA0", ' ', $text ), ENT_NOQUOTES, 'UTF-8' );
	}

	/** The safe target of a link's attributes, or '' (the words stay, the link goes). */
	private static function rich_href( $attrs ) {
		if ( ! preg_match( '/\bhref\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', (string) $attrs, $m ) ) {
			return '';
		}
		$href = $m[1] ?? '';
		if ( '' === $href ) $href = $m[2] ?? '';
		if ( '' === $href ) $href = $m[3] ?? '';
		$href = trim( html_entity_decode( $href, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		if ( ! preg_match( '~^(?:https?://|mailto:|tel:|/|#)~i', $href ) || preg_match( '/[\x00-\x1F\x7F<>`"\'\\\\]/', $href ) ) {
			return '';
		}
		return str_replace( ' ', '%20', htmlspecialchars( $href, ENT_QUOTES, 'UTF-8' ) );
	}

	/** Removes inline tags left with nothing inside, tidies the spaces. */
	private static function rich_tidy( $html ) {
		do {
			$html = preg_replace( '~<(strong|b|em|i|a)(?: href="[^"]*")?>(\s*)</\1>~', '$2', (string) $html, -1, $n );
		} while ( $n > 0 );
		return trim( preg_replace( '/\s+/u', ' ', $html ) );
	}

	/** True when every tag in the fragment opens and closes in order. */
	private static function rich_balanced( $html ) {
		$stack = [];
		preg_match_all( '~<(/?)([a-z]+)[^>]*>~', (string) $html, $tags, PREG_SET_ORDER );
		foreach ( $tags as $t ) {
			if ( 'br' === $t[2] ) {
				continue;
			}
			if ( '' === $t[1] ) {
				$stack[] = $t[2];
			} elseif ( array_pop( $stack ) !== $t[2] ) {
				return false;
			}
		}
		return ! $stack;
	}

	/** Last check before anything is returned: only the allowed tags, each written our way, all closed. */
	private static function rich_valid( $html, $inline_only ) {
		$names = $inline_only ? 'strong|b|em|i' : 'p|strong|b|em|i|ul|ol|li|blockquote';
		$left  = preg_replace( '~</?(?:' . $names . ')>|</a>|<a href="[^"<>]*">' . ( $inline_only ? '' : '|<br>' ) . '~', '', (string) $html );
		return null !== $left && false === strpos( $left, '<' ) && false === strpos( $left, '>' ) && self::rich_balanced( $html );
	}

	/**
	 * Step 1 — the description as lines: one per paragraph line, line
	 * break, list item, table cell or heading (the idea of normalise()),
	 * each keeping its safe inline tags and knowing where it came from.
	 *
	 * @return array[]|null Lines, or null when the text does not survive whole.
	 */
	private static function rich_lines( $raw ) {
		$html = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', $raw );
		$html = null === $html ? null : preg_replace( '/<!--.*?-->/s', '', $html );
		if ( null === $html ) {
			return null;
		}
		$html = str_replace( [ "\r\n", "\r" ], "\n", $html );
		if ( ! preg_match_all( '~<(/?)([a-zA-Z][a-zA-Z0-9]*)\b([^>]*)>|[^<]+|<~u', $html, $tokens, PREG_SET_ORDER ) ) {
			return '' === trim( $html ) ? [] : null;
		}

		$lines     = [];
		$cur       = null;
		$para      = 1;
		$quote     = 0;   // id of the outermost open blockquote, 0 = none
		$quote_in  = 0;   // how many are open
		$quote_seq = 0;
		$lists     = [];  // open lists: type, id, root (outermost list's id), li (an item is open)
		$list_seq  = 0;
		$heading   = false;
		$open      = [];  // open inline tags: [ name, opening tag ]

		$begin = function () use ( &$cur, &$open, &$quote, &$quote_in, &$lists, &$para, &$heading ) {
			if ( null === $cur ) {
				$cur = [
					'html'    => implode( '', array_column( $open, 1 ) ),
					'quote'   => $quote_in ? $quote : 0,
					'list'    => $lists ? [ 'type' => $lists[0]['type'], 'id' => $lists[0]['id'] ] : null,
					'para'    => $para,
					'heading' => $heading,
				];
			}
		};
		$flush = function () use ( &$cur, &$open, &$lines ) {
			if ( null !== $cur ) {
				foreach ( array_reverse( $open ) as $o ) {
					$cur['html'] .= '</' . $o[0] . '>';
				}
				$cur['html'] = self::rich_tidy( $cur['html'] );
				$cur['text'] = self::rich_plain( $cur['html'] );
				if ( '' !== $cur['text'] ) {
					$lines[] = $cur;
				}
			}
			$cur = null;
		};

		foreach ( $tokens as $tok ) {
			if ( isset( $tok[2] ) && '' !== $tok[2] ) {
				$closing = '/' === $tok[1];
				$name    = strtolower( $tok[2] );

				if ( isset( self::RICH_INLINE[ $name ] ) ) {
					if ( ! $closing ) {
						$tag = '<' . $name . '>';
						if ( 'a' === $name ) {
							$href = in_array( 'a', array_column( $open, 0 ), true ) ? '' : self::rich_href( $tok[3] );
							if ( '' === $href ) {
								continue; // no safe target: the words stay, the link goes
							}
							$tag = '<a href="' . $href . '">';
						}
						$open[] = [ $name, $tag ];
						if ( null !== $cur ) {
							$cur['html'] .= $tag;
						}
					} else {
						$idx = -1;
						for ( $k = count( $open ) - 1; $k >= 0; $k-- ) {
							if ( $open[ $k ][0] === $name ) {
								$idx = $k;
								break;
							}
						}
						if ( $idx < 0 ) {
							continue; // nothing to close (or the end of a dropped link)
						}
						$tail = array_splice( $open, $idx );
						if ( null !== $cur ) {
							foreach ( array_reverse( $tail ) as $o ) {
								$cur['html'] .= '</' . $o[0] . '>';
							}
						}
						foreach ( array_slice( $tail, 1 ) as $o ) {
							$open[] = $o;
							if ( null !== $cur ) {
								$cur['html'] .= $o[1];
							}
						}
					}
					continue;
				}

				if ( ! isset( self::RICH_BLOCK[ $name ] ) ) {
					continue; // span, u, font, img, iframe, input …: the tag goes, its words stay
				}
				$flush();
				if ( 'br' === $name ) {
					continue;
				}
				$para++;
				if ( 'blockquote' === $name ) {
					if ( ! $closing ) {
						if ( 0 === $quote_in ) {
							$quote = ++$quote_seq;
						}
						$quote_in++;
					} elseif ( $quote_in > 0 ) {
						$quote_in--;
					}
				} elseif ( 'ul' === $name || 'ol' === $name ) {
					if ( ! $closing ) {
						$lists[] = [ 'type' => $name, 'id' => ++$list_seq, 'li' => false ];
					} else {
						array_pop( $lists );
					}
				} elseif ( 'li' === $name ) {
					if ( $lists ) {
						$lists[ count( $lists ) - 1 ]['li'] = ! $closing;
					}
				} elseif ( 'h' === $name[0] && 'hr' !== $name ) {
					$heading = ! $closing;
				}
				continue;
			}

			if ( '<' === $tok[0] ) { // a loose "<" in the text
				$begin();
				$cur['html'] .= '&lt;';
				continue;
			}

			$text = $tok[0];
			if ( in_array( true, array_column( $lists, 'li' ), true ) ) {
				$text = preg_replace( '/\s*\n\s*/', ' ', $text ); // a list item is one line
			}
			foreach ( preg_split( '/\n[ \t]*\n\s*/', $text ) as $pi => $ptxt ) {
				if ( $pi > 0 ) {
					$flush();
					$para++;
				}
				foreach ( explode( "\n", $ptxt ) as $li => $ltxt ) {
					if ( $li > 0 ) {
						$flush();
					}
					if ( null === $cur && '' === trim( str_replace( '&nbsp;', ' ', $ltxt ) ) ) {
						continue;
					}
					$begin();
					$cur['html'] .= self::rich_esc( $ltxt );
				}
			}
		}
		$flush();

		// Nothing typed may go missing: the lines must hold exactly the
		// text normalise() reads (spaces aside).
		$mine = preg_replace( '/\s+/u', '', implode( '', array_column( $lines, 'text' ) ) );
		$all  = preg_replace( '/\s+/u', '', self::normalise( $raw ) );
		if ( null === $mine || null === $all || $mine !== $all ) {
			return null;
		}
		return $lines;
	}

	/**
	 * One fun-fact line → lead-in + sentence. The lead-in is the words
	 * before the first colon when the colon is followed by a space, there
	 * is text on both sides and it is short; otherwise the whole line is
	 * one row with no lead-in ("Feeds at 10:30 sharp").
	 *
	 * @param string $line_html A line as rich_lines() wrote it.
	 * @return array{ lead: string, html: string }
	 */
	public static function split_fact( $line_html ) {
		$html = preg_replace( '~^((?:\s|<[^>]+>)*)[*•\-–—]\s+~u', '$1', (string) $line_html ); // a typed bullet
		$html = self::rich_tidy( $html );
		$len  = strlen( $html );
		$pos  = -1;
		for ( $i = 0, $in_tag = false; $i < $len; $i++ ) {
			$c = $html[ $i ];
			if ( '<' === $c ) {
				$in_tag = true;
			} elseif ( '>' === $c ) {
				$in_tag = false;
			} elseif ( ':' === $c && ! $in_tag ) {
				$pos = $i;
				break;
			}
		}
		$whole = [ 'lead' => '', 'html' => self::rich_balanced( $html ) ? $html : self::rich_esc( self::rich_plain( $html ) ) ];
		if ( $pos < 0 ) {
			return $whole;
		}
		$lead      = self::rich_plain( substr( $html, 0, $pos ) );
		$after_raw = (string) substr( $html, $pos + 1 );
		$after     = self::rich_tidy( preg_replace( '~^(?:\s|</(?:strong|b|em|i|a)>)+~', '', $after_raw ) );
		if ( '' === $lead || '' === self::rich_plain( $after ) || ! preg_match( '~^(?:<[^>]+>)*\s~', $after_raw ) ) {
			return $whole; // "10:30", nothing before or after the colon
		}
		if ( preg_match_all( '/./su', $lead ) > self::LEAD_MAX_CHARS || count( preg_split( '/\s+/u', $lead ) ) > self::LEAD_MAX_WORDS ) {
			return $whole;
		}
		return [ 'lead' => $lead, 'html' => self::rich_balanced( $after ) ? $after : self::rich_esc( self::rich_plain( $after ) ) ];
	}

	/** rich() itself; null when the markup is beyond the rules. */
	private static function rich_build( $raw, array $filled ) {
		$lines = self::rich_lines( $raw );
		if ( null === $lines ) {
			return null;
		}
		$n         = count( $lines );
		$fun_title = '';
		$fun_facts = [];

		// Step 2 — the Fun Facts block (the first "Fun Facts" label only).
		$at = -1;
		foreach ( $lines as $i => $line ) {
			if ( 'fun_facts' === self::line_field( $line['text'] ) ) {
				$at = $i;
				break;
			}
		}
		if ( $at >= 0 ) {
			$label = $lines[ $at ];
			preg_match( self::LABEL_REGEX, $label['text'], $m );
			$rest = trim( (string) substr( $label['text'], strlen( $m[0] ) ) );
			$take = [];
			$j    = $at + 1;
			if ( $label['quote'] ) {
				// Inside a blockquote: every later line of that quote.
				while ( $j < $n && $lines[ $j ]['quote'] === $label['quote'] ) {
					$take[] = $j++;
				}
			} else {
				// More lines in the label's own paragraph.
				while ( $j < $n && $lines[ $j ]['para'] === $label['para'] && ! $lines[ $j ]['list'] && ! $lines[ $j ]['quote'] ) {
					$take[] = $j++;
				}
			}
			if ( ! $take && '' === $rest && $j < $n ) {
				$next = $lines[ $j ];
				if ( $next['quote'] && $next['quote'] !== $label['quote'] ) {
					// The label on its own, the facts in the blockquote under it.
					while ( $j < $n && $lines[ $j ]['quote'] === $next['quote'] ) {
						$take[] = $j++;
					}
				} elseif ( $next['list'] && ! $next['quote'] ) {
					// The list right under the label (nested items too).
					while ( $j < $n && $lines[ $j ]['list'] && ! $lines[ $j ]['quote'] && $lines[ $j ]['list']['id'] === $next['list']['id'] ) {
						$take[] = $j++;
					}
				} elseif ( ! $next['quote'] ) {
					// Paragraphs shaped "Lead-in: sentence", up to the first that is not.
					while ( $j < $n && ! $lines[ $j ]['list'] && ! $lines[ $j ]['quote'] && ! $lines[ $j ]['heading']
						&& '' !== self::split_fact( $lines[ $j ]['html'] )['lead'] ) {
						$take[] = $j++;
					}
				}
			}

			$facts = [];
			if ( '' !== $rest ) {
				// Text typed on the label's own line is the first fact.
				$rest_html = self::rich_esc( $rest );
				if ( preg_match( '~Fun\s+Facts?\s*(?:</[a-z]+>\s*)*[:\-–—|]?~iu', $label['html'], $mm, PREG_OFFSET_CAPTURE ) ) {
					$tail = self::rich_tidy( preg_replace( '~^(?:\s|</(?:strong|b|em|i|a)>)+~', '', (string) substr( $label['html'], $mm[0][1] + strlen( $mm[0][0] ) ) ) );
					if ( self::rich_balanced( $tail ) && self::rich_plain( $tail ) === $rest ) {
						$rest_html = $tail;
					}
				}
				$facts[] = self::split_fact( $rest_html );
			}
			foreach ( $take as $i ) {
				$facts[] = self::split_fact( $lines[ $i ]['html'] );
			}

			if ( $facts ) {
				$fun_title = preg_match( '/s$/i', trim( $m['label'] ) ) ? 'Fun Facts' : 'Fun Fact';
				$fun_facts = $facts;
				foreach ( array_merge( [ $at ], $take ) as $i ) {
					unset( $lines[ $i ] );
				}
			} elseif ( $at === $n - 1 ) {
				unset( $lines[ $at ] ); // the label as the very last line: no heading over nothing
			} // otherwise the label stays in the text as typed
			$lines = array_values( $lines );
		}

		// Step 3 — an old-style labelled line is hidden when its fact is
		// filled (the same test, line by line, as prose()).
		$kept = [];
		foreach ( $lines as $line ) {
			$field = self::line_field( $line['text'] );
			if ( '' !== $field && in_array( $field, $filled, true ) ) {
				continue;
			}
			$kept[] = $line;
		}
		if ( $kept ) {
			$kept[0]['html'] = self::rich_tidy( preg_replace( '~^((?:<[^>]+>)*)Description:\s*~i', '$1', $kept[0]['html'] ) );
			if ( '' === self::rich_plain( $kept[0]['html'] ) ) {
				array_shift( $kept );
			}
		}

		// Step 4 — rebuild. Only the tags of rich_allowed_html() are written.
		$out   = '';
		$count = count( $kept );
		$i     = 0;
		while ( $i < $count ) {
			$q     = $kept[ $i ]['quote'];
			$block = '';
			while ( $i < $count && $kept[ $i ]['quote'] === $q ) {
				$line = $kept[ $i ];
				if ( $line['list'] ) {
					$tag    = $line['list']['type'];
					$block .= '<' . $tag . '>';
					while ( $i < $count && $kept[ $i ]['quote'] === $q && $kept[ $i ]['list'] && $kept[ $i ]['list']['id'] === $line['list']['id'] ) {
						$block .= '<li>' . $kept[ $i ]['html'] . '</li>';
						$i++;
					}
					$block .= '</' . $tag . '>';
				} else {
					$parts = [];
					while ( $i < $count && $kept[ $i ]['quote'] === $q && ! $kept[ $i ]['list'] && $kept[ $i ]['para'] === $line['para'] ) {
						$parts[] = $kept[ $i ]['heading']
							? '<strong>' . preg_replace( '~</?(?:strong|b)>~', '', $kept[ $i ]['html'] ) . '</strong>'
							: $kept[ $i ]['html'];
						$i++;
					}
					$block .= '<p>' . implode( '<br>', $parts ) . '</p>';
				}
			}
			$out .= $q ? '<blockquote>' . $block . '</blockquote>' : $block;
		}

		if ( ! self::rich_valid( $out, false ) ) {
			return null;
		}
		foreach ( $fun_facts as $fact ) {
			if ( ! self::rich_valid( $fact['html'], true ) ) {
				return null;
			}
		}
		return [ 'html' => $out, 'fun_title' => $fun_title, 'fun_facts' => $fun_facts, 'plain' => false ];
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
