<?php
/**
 * Product page layout decisions (v1.30.0) — which fact goes where, and
 * what is printed when a field is empty.
 *
 * The product page (woocommerce/single-product.php) shows, under the photo
 * and the buy box, a row of short facts ("at a glance"); then one band with
 * the description (and its Fun Facts card) on the left and a plain list of
 * longer entries (the Fish Dossier) on the right. Every piece is optional.
 * The rules that keep the page tidy with 0, 1, 2 or all pieces filled live
 * here, away from the markup, so they can be tested:
 *
 *   split()       the fact rows → glance cells / entries before the care
 *                 notes / entries after them; a row that only repeats the
 *                 line under the name is dropped
 *   care_notes()  Foods & Feeding, Habitat & Behavior (never on a
 *                 medication page)
 *   link_tags()   a tag pill that says the same as a glance cell is not
 *                 printed; the cell links to the tag instead
 *   top_modifier() where the glance row sits (under the photo and buy box,
 *                 or beside the photo when there is no buy form)
 *   body()        which columns and headings the band below prints
 *   crumb_order() the order of the category links above the name (every
 *                 category of the product is one link, the widest first)
 *
 * Pure PHP — no WordPress, no database, nothing stored. Covered by
 * tests/product-layout-test.php.
 *
 * @package FisHotel
 */

defined( 'ABSPATH' ) || exit;

class FisHotel_Product_Layout {

	/** A fact this short (characters of plain text) fits the glance row. */
	const GLANCE_MAX = 28;

	/** Fewer short facts than this: no glance row (one cell alone looks lost). */
	const GLANCE_MIN_CELLS = 2;

	/**
	 * A food's or medication's rows, when there are this many or fewer,
	 * are printed above the description instead of in a column of their
	 * own (one "Food Source" alone behind a dividing line looks lost).
	 */
	const LEDE_MAX = 2;

	/** Tags stripped, entities decoded, spaces tidied, trimmed. */
	public static function plain( $html ) {
		$text = html_entity_decode( strip_tags( (string) $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = preg_replace( '/\s+/u', ' ', str_replace( "\xC2\xA0", ' ', $text ) );
		return trim( (string) $text );
	}

	/** Characters (not bytes) in a text. */
	public static function length( $text ) {
		$n = preg_match_all( '/./su', (string) $text );
		return false === $n ? strlen( (string) $text ) : $n;
	}

	/** Same words, capitals and spaces aside. */
	private static function same( $a, $b ) {
		$fold = function ( $t ) {
			$t = self::plain( $t );
			return function_exists( 'mb_strtolower' ) ? mb_strtolower( $t, 'UTF-8' ) : strtolower( $t );
		};
		return $fold( $a ) === $fold( $b );
	}

	/** Short enough for the glance row? The Reef Safe badge always is. */
	public static function is_short( array $row ) {
		if ( false !== strpos( (string) $row[1], 'fh-spec-badge' ) ) {
			return true;
		}
		$text = self::plain( $row[1] );
		return '' !== $text && self::length( $text ) <= self::GLANCE_MAX;
	}

	/**
	 * The fact rows of a product → where each one is printed.
	 *
	 *  - A row that says the same as the line under the name is dropped
	 *    (Scientific Name on a fish, Food Source on a food).
	 *  - Scientific Name (when it differs) is an entry, first.
	 *  - Common Names is an entry, last, labelled "Also Known As".
	 *  - Short rows are the glance cells — when there are at least two.
	 *    A single short row is an entry instead.
	 *  - Every other row is an entry, in row order.
	 *
	 * @param array[] $rows     [ label, html ] pairs (html already escaped).
	 * @param string  $subtitle The line under the product name ('' = none).
	 * @return array{ glance: array[], before: array[], after: array[] }
	 *               before / after = entries above / below the care notes.
	 */
	public static function split( array $rows, $subtitle = '' ) {
		$subtitle = self::plain( $subtitle );
		$first    = [];
		$last     = [];
		$rest     = [];
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || ! isset( $row[0], $row[1] ) || '' === trim( (string) $row[1] ) ) {
				continue;
			}
			$row = [ (string) $row[0], (string) $row[1] ];
			if ( '' !== $subtitle && self::same( $row[1], $subtitle ) ) {
				continue;
			}
			if ( 'Scientific Name' === $row[0] ) {
				$first[] = $row;
			} elseif ( 'Common Names' === $row[0] ) {
				$last[] = [ 'Also Known As', $row[1] ];
			} else {
				$rest[] = $row;
			}
		}
		$short = array_values( array_filter( $rest, [ __CLASS__, 'is_short' ] ) );
		if ( count( $short ) < self::GLANCE_MIN_CELLS ) {
			return [ 'glance' => [], 'before' => array_merge( $first, $rest ), 'after' => $last ];
		}
		$long = array_values( array_filter( $rest, function ( $row ) {
			return ! self::is_short( $row );
		} ) );
		return [ 'glance' => $short, 'before' => array_merge( $first, $long ), 'after' => $last ];
	}

	/**
	 * The two care notes as entries. Never on a medication page: the
	 * site-wide default text would print a feeding schedule under a
	 * medicated food (v1.29.0).
	 *
	 * @param string $foods   Foods & Feeding text ('' = none).
	 * @param string $habitat Habitat & Behavior text ('' = none).
	 * @param bool   $is_med  Medication, medicated food or Amazon product.
	 * @return array[] [ label, plain text ] pairs.
	 */
	public static function care_notes( $foods, $habitat, $is_med ) {
		$notes = [];
		if ( $is_med ) {
			return $notes;
		}
		if ( '' !== trim( (string) $foods ) ) {
			$notes[] = [ 'Foods & Feeding', (string) $foods ];
		}
		if ( '' !== trim( (string) $habitat ) ) {
			$notes[] = [ 'Habitat & Behavior', (string) $habitat ];
		}
		return $notes;
	}

	/**
	 * Tag pills that only repeat a glance cell. The pill is not printed
	 * and the cell's value links to the tag's page instead, so nothing is
	 * said twice and no link is lost.
	 *
	 *  - Temperament and Region: a tag named exactly as the cell reads.
	 *  - Reef Safe showing "Yes": a tag named "Reef Safe".
	 *
	 * @param array[]  $glance Glance cells from split().
	 * @param string[] $tags   Tag key => tag name (the pills about to be printed).
	 * @return array{ links: array<int, int|string>, drop: array<int, int|string> }
	 *               links = cell index => tag key; drop = tag keys not to print.
	 */
	public static function link_tags( array $glance, array $tags ) {
		$links = [];
		$drop  = [];
		foreach ( array_values( $glance ) as $i => $cell ) {
			$label = (string) $cell[0];
			$text  = self::plain( $cell[1] );
			if ( 'Temperament' === $label || 'Region' === $label ) {
				$want = $text;
			} elseif ( 'Reef Safe' === $label && preg_match( '/(?:^|\s)yes$/i', $text ) ) {
				$want = 'Reef Safe';
			} else {
				continue;
			}
			foreach ( $tags as $key => $name ) {
				if ( ! in_array( $key, $drop, true ) && self::same( $name, $want ) ) {
					$links[ $i ] = $key;
					$drop[]      = $key;
					break;
				}
			}
		}
		return [ 'links' => $links, 'drop' => $drop ];
	}

	/**
	 * Is the page printed without a buy form? Coming Soon (the arrival
	 * panel stands in its place) or a product that cannot be bought. A gift
	 * card and an Amazon medication print their own form or button.
	 */
	public static function no_buy_form( $is_coming_soon, $is_unavailable, $is_gift_card, $is_amazon_med ) {
		return (bool) $is_coming_soon || ( $is_unavailable && ! $is_gift_card && ! $is_amazon_med );
	}

	/**
	 * Class added to .fh-product-layout: where the glance row sits.
	 * '' when there is no glance row.
	 */
	public static function top_modifier( $glance_cells, $no_buy_form ) {
		if ( $glance_cells < self::GLANCE_MIN_CELLS ) {
			return '';
		}
		return $no_buy_form ? 'fh-product-layout--facts-beside' : 'fh-product-layout--facts-below';
	}

	/**
	 * Last word of the story heading: "Fish", "Product", "Food", or ''
	 * (the heading is then "Description").
	 *
	 * @param bool   $is_fish
	 * @param bool   $is_med     Medication / Amazon product, or in the Medications category.
	 * @param string $about_word FisHotel_Med_Listing::about_word().
	 * @param bool   $is_food    In a food category.
	 */
	public static function story_word( $is_fish, $is_med, $about_word, $is_food ) {
		if ( $is_fish ) {
			return 'Fish';
		}
		if ( $is_med ) {
			return '' !== (string) $about_word ? (string) $about_word : 'Product';
		}
		return $is_food ? 'Food' : '';
	}

	/**
	 * What the band under the top area prints.
	 *
	 * Three places can hold the entries (the longer facts and the care
	 * notes):
	 *  - the details column, right of the story — the Fish Dossier;
	 *  - the same column printed BEFORE the story in the HTML when its rows
	 *    have no heading (a food's or a medication's rows): on a phone
	 *    "Contains" is then above the description, not the last thing on
	 *    the page. On a wide screen it is still the right-hand column;
	 *  - a short list above the description ("lede") when there are only
	 *    one or two such rows: no second column for them.
	 *
	 * @param array $in has_text (bool: description text), facts (int: fun
	 *                  facts), before / care / after (int: entries),
	 *                  has_tools (bool: quarantine buttons), is_fish (bool).
	 * @return array{
	 *   band: bool, story: bool, prose: bool, card: bool, lede: bool,
	 *   details: bool, details_first: bool, entries: int,
	 *   band_class: string, dossier_class: string, dossier_title: bool,
	 *   entry_tag: string, plan_tag: string
	 * }
	 */
	public static function body( array $in ) {
		$has_text = ! empty( $in['has_text'] );
		$facts    = (int) ( $in['facts'] ?? 0 );
		$care     = (int) ( $in['care'] ?? 0 );
		$entries  = (int) ( $in['before'] ?? 0 ) + $care + (int) ( $in['after'] ?? 0 );
		$tools    = ! empty( $in['has_tools'] );
		$is_fish  = ! empty( $in['is_fish'] );

		$story = $has_text || $facts > 0;

		// "Fish Dossier" only over entries, and only on a fish or over a
		// care note; a food's or medication's rows stand as a plain list.
		$title = $entries > 0 && ( $is_fish || $care > 0 );

		$lede    = $story && $entries > 0 && $entries <= self::LEDE_MAX && ! $title && ! $tools;
		$details = ( $entries > 0 || $tools ) && ! $lede;
		$first   = $details && $story && $entries > 0 && ! $title && ! $tools;

		$band_class = 'fh-pdp-body';
		if ( $story xor $details ) {
			$band_class .= ' fh-pdp-body--solo';
			if ( $details ) {
				$band_class .= ' fh-pdp-body--care-only';
			}
		} elseif ( $first ) {
			$band_class .= ' fh-pdp-body--details-first';
		}
		$dossier_class = 'fh-dossier';
		if ( 0 === $entries ) {
			$dossier_class .= ' fh-dossier--tools-only';
		} elseif ( ! $title ) {
			$dossier_class .= ' fh-dossier--untitled';
		}

		return [
			'band'          => $story || $details,
			'story'         => $story,
			'prose'         => $has_text,
			'card'          => $facts > 0,
			'lede'          => $lede,
			'details'       => $details,
			'details_first' => $first,
			'entries'       => $entries,
			'band_class'    => $band_class,
			'dossier_class' => $dossier_class,
			'dossier_title' => $title,
			// Headings never skip a level: an entry is an h3 under the
			// column's or the story's h2, an h2 when it has neither above
			// it (rows printed before the story come before its h2).
			'entry_tag'     => ( $title || ( $story && ! $first ) ) ? 'h3' : 'h2',
			'plan_tag'      => $entries > 0 ? 'h3' : 'h2',
		];
	}

	/**
	 * The order of a product's categories in the breadcrumb above its
	 * name. Every category is one link there (the "Category" row under the
	 * buy box is gone), so a fish filed under "Quarantined Fish" and
	 * "Wrasses" keeps a link to both.
	 *
	 *  - a parent comes before its child, the child right after it;
	 *  - otherwise the category holding the most products comes first
	 *    ("Quarantined Fish" before "Wrasses", "Medications" before
	 *    "Medicated Food"), whichever name sorts first;
	 *  - then by name, then by id, so the order never flips between visits.
	 *
	 * @param array[] $cats key => [ id, parent, count, name ].
	 * @return array Keys of $cats, in print order.
	 */
	public static function crumb_order( array $cats ) {
		$ids = [];
		foreach ( $cats as $key => $cat ) {
			$ids[ (int) ( $cat['id'] ?? 0 ) ] = $key;
		}
		$by_parent = [];
		foreach ( $cats as $key => $cat ) {
			$parent = (int) ( $cat['parent'] ?? 0 );
			// A parent that is not one of this product's categories (or a
			// category named as its own parent) counts as none.
			if ( ! isset( $ids[ $parent ] ) || $ids[ $parent ] === $key ) {
				$parent = 0;
			}
			$by_parent[ $parent ][] = $key;
		}
		$wide_first = function ( $a, $b ) use ( $cats ) {
			$by = ( (int) ( $cats[ $b ]['count'] ?? 0 ) ) <=> ( (int) ( $cats[ $a ]['count'] ?? 0 ) );
			if ( 0 === $by ) {
				$by = strcasecmp( (string) ( $cats[ $a ]['name'] ?? '' ), (string) ( $cats[ $b ]['name'] ?? '' ) );
			}
			return 0 !== $by ? $by : ( (int) ( $cats[ $a ]['id'] ?? 0 ) ) <=> ( (int) ( $cats[ $b ]['id'] ?? 0 ) );
		};
		$order = [];
		$walk  = function ( $parent ) use ( &$walk, &$order, &$by_parent, $cats, $wide_first ) {
			$keys = $by_parent[ $parent ] ?? [];
			unset( $by_parent[ $parent ] ); // each level once: a loop of parents cannot run on
			usort( $keys, $wide_first );
			foreach ( $keys as $key ) {
				$order[] = $key;
				$walk( (int) ( $cats[ $key ]['id'] ?? 0 ) );
			}
		};
		$walk( 0 );
		// Categories caught in a loop of parents (A under B under A): still printed.
		foreach ( array_keys( $cats ) as $key ) {
			if ( ! in_array( $key, $order, true ) ) {
				$order[] = $key;
			}
		}
		return $order;
	}
}
