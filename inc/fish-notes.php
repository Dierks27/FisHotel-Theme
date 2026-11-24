<?php
/**
 * Staff Notes are private — and a one-time look at the fish whose Notes
 * customers could read until now.
 *
 * Until v1.28.0 the fish facts box said "Notes (not shown on website)", yet
 * the product page printed a Quarantined Fish's Notes on its quarantine
 * certificate. Since v1.28.0 Notes are read nowhere on the storefront. In
 * case some were written FOR customers, the first admin page load after
 * the update writes down which published Quarantined Fish had a note
 * (option `fishotel_notes_were_public`, not autoloaded), and people who can
 * edit products see one notice:
 *
 *   "N fish have Notes that the product page showed to customers until
 *    now — Show them"
 *
 * "Show them" opens the products list filtered to those fish
 * (edit.php?post_type=product&fh_notes=were_public), so text meant for
 * customers can be moved into the description. "Don't show this again"
 * hides the notice for that person; it also stops by itself once none of
 * those fish has a note left. The list filter keeps working afterwards.
 *
 * Pure parts (count, wording, query args) are tested in
 * tests/fish-notes-test.php.
 *
 * @package FisHotel
 */

defined( 'ABSPATH' ) || exit;

class FisHotel_Fish_Notes {

	const OPTION_SNAPSHOT = 'fishotel_notes_were_public';
	const OPTION_DONE     = 'fishotel_notes_notice_done';
	const USER_DISMISSED  = 'fishotel_notes_notice_dismissed';
	const QUERY_VAR       = 'fh_notes';
	const QUERY_VALUE     = 'were_public';
	const DISMISS_ARG     = 'fishotel_notes_notice';
	const NONCE           = 'fishotel_notes_notice';

	public static function init() {
		add_action( 'admin_init',      [ __CLASS__, 'maybe_snapshot' ] );
		add_action( 'admin_init',      [ __CLASS__, 'maybe_dismiss' ] );
		add_action( 'admin_notices',   [ __CLASS__, 'notice' ] );
		add_action( 'pre_get_posts',   [ __CLASS__, 'filter_list' ] );
	}

	// ── Pure ───────────────────────────────────────────────────────────

	/**
	 * Snapshot ids that still have a note, given id => current note.
	 *
	 * @param int[] $snapshot
	 * @param array $notes_by_id
	 * @return int[]
	 */
	public static function still_noted( array $snapshot, array $notes_by_id ) {
		$out = [];
		foreach ( $snapshot as $id ) {
			$id = (int) $id;
			if ( $id && isset( $notes_by_id[ $id ] ) && '' !== trim( (string) $notes_by_id[ $id ] ) ) {
				$out[] = $id;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * The notice sentence for a count.
	 *
	 * @param int $count
	 * @return string Plain text.
	 */
	public static function notice_text( $count ) {
		$count = (int) $count;
		return 1 === $count
			? '1 fish has Notes that the product page showed to customers until now'
			: $count . ' fish have Notes that the product page showed to customers until now';
	}

	/**
	 * Query vars for the filtered products list.
	 *
	 * @param int[] $snapshot
	 * @return array
	 */
	public static function list_query_vars( array $snapshot ) {
		$ids = array_values( array_filter( array_map( 'intval', $snapshot ) ) );
		return [
			'post__in'   => $ids ? $ids : [ 0 ],
			'meta_query' => [
				[
					'key'     => '_fh_notes',
					'value'   => '',
					'compare' => '!=',
				],
			],
		];
	}

	// ── WordPress edge ─────────────────────────────────────────────────

	/** @return int[] */
	public static function snapshot() {
		$ids = get_option( self::OPTION_SNAPSHOT, [] );
		return is_array( $ids ) ? array_map( 'intval', $ids ) : [];
	}

	/** Published Quarantined Fish with a non-empty note, right now. */
	private static function query_noted_fish( array $only_ids = [] ) {
		$args = [
			'post_type'        => 'product',
			'post_status'      => 'publish',
			'fields'           => 'ids',
			'posts_per_page'   => -1,
			'no_found_rows'    => true,
			'suppress_filters' => true,
			'meta_query'       => [
				[
					'key'     => '_fh_notes',
					'value'   => '',
					'compare' => '!=',
				],
			],
		];
		if ( $only_ids ) {
			$args['post__in'] = $only_ids;
		} else {
			$args['tax_query'] = [
				[
					'taxonomy'         => 'product_cat',
					'field'            => 'slug',
					'terms'            => [ 'quarantined-fish' ],
					'include_children' => false,
				],
			];
		}
		return array_map( 'intval', (array) get_posts( $args ) );
	}

	/** First admin load after the update: write down which fish had a shown note. */
	public static function maybe_snapshot() {
		if ( wp_doing_ajax() || wp_doing_cron() ) return;
		if ( false !== get_option( self::OPTION_SNAPSHOT, false ) ) return;
		add_option( self::OPTION_SNAPSHOT, self::query_noted_fish(), '', 'no' );
	}

	/** Fish of the snapshot that still have a note. */
	public static function current_ids() {
		$snapshot = self::snapshot();
		if ( ! $snapshot ) return [];
		return self::query_noted_fish( $snapshot );
	}

	public static function list_url() {
		return add_query_arg(
			[ 'post_type' => 'product', self::QUERY_VAR => self::QUERY_VALUE ],
			admin_url( 'edit.php' )
		);
	}

	public static function maybe_dismiss() {
		if ( ! isset( $_GET[ self::DISMISS_ARG ] ) || 'dismiss' !== $_GET[ self::DISMISS_ARG ] ) return;
		check_admin_referer( self::NONCE );
		update_user_meta( get_current_user_id(), self::USER_DISMISSED, '1' );
		wp_safe_redirect( remove_query_arg( [ self::DISMISS_ARG, '_wpnonce' ] ) );
		exit;
	}

	private static function on_filtered_list() {
		return isset( $_GET[ self::QUERY_VAR ] ) && self::QUERY_VALUE === $_GET[ self::QUERY_VAR ];
	}

	public static function notice() {
		if ( ! current_user_can( 'edit_products' ) ) return;

		if ( self::on_filtered_list() ) {
			echo '<div class="notice notice-info"><p>'
				. esc_html__( 'These fish had Notes that the product page showed to customers until the theme update. Notes are now for the team only and never shown on the website. If a note was written for customers, move that text into the description.', 'fishotel' )
				. ' <a href="' . esc_url( admin_url( 'edit.php?post_type=product' ) ) . '">' . esc_html__( 'Show all products', 'fishotel' ) . '</a></p></div>';
			return;
		}

		if ( get_option( self::OPTION_DONE ) ) return;
		if ( get_user_meta( get_current_user_id(), self::USER_DISMISSED, true ) ) return;

		$ids = self::current_ids();
		if ( ! $ids ) {
			update_option( self::OPTION_DONE, '1', false );
			return;
		}
		$dismiss = wp_nonce_url( add_query_arg( self::DISMISS_ARG, 'dismiss' ), self::NONCE );
		echo '<div class="notice notice-warning"><p><strong>'
			. esc_html( self::notice_text( count( $ids ) ) ) . '</strong> — '
			. '<a href="' . esc_url( self::list_url() ) . '">' . esc_html__( 'Show them', 'fishotel' ) . '</a>'
			. ' · <a href="' . esc_url( $dismiss ) . '">' . esc_html__( 'Don\'t show this again', 'fishotel' ) . '</a></p>'
			. '<p>' . esc_html__( 'Notes are now for the team only. If a note was written for customers, move that text into the description.', 'fishotel' ) . '</p></div>';
	}

	/** The products-list filter behind "Show them". */
	public static function filter_list( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() || ! self::on_filtered_list() ) return;
		if ( 'product' !== $query->get( 'post_type' ) ) return;
		foreach ( self::list_query_vars( self::snapshot() ) as $key => $value ) {
			$query->set( $key, $value );
		}
	}
}

FisHotel_Fish_Notes::init();
