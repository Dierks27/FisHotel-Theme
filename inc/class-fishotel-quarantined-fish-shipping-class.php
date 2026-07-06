<?php
/**
 * FisHotel Quarantined Fish Shipping-Class Safety Net.
 *
 * Background: the US shipping zone's table_rate method is keyed on shipping
 * class. A quarantined fish published with NO shipping class returns no rate,
 * which silently breaks checkout for the whole batch (this happened once when a
 * batch was released classless by the FisHotel Batch Manager plugin).
 *
 * This is one narrow safeguard: when a product in the Quarantined Fish category
 * is saved (or published programmatically) with the shipping class completely
 * unset, assign the Premium class. That is the ONLY thing it does.
 *
 * Hard rule — a manual override always wins. If ANY shipping class is explicitly
 * set (premium, free, quote, standard, pods, worms), the net never touches it;
 * it only fills the empty state. Clearing the class and re-saving legitimately
 * re-triggers the fill.
 *
 * It deliberately does not touch checkout flow, delivery dates, the reorder
 * lock, combined shipping, or anything customer-facing. Variations are left
 * alone — the class lives on the parent and variation-level overrides are
 * intentional.
 *
 * @package FisHotel
 */

defined( 'ABSPATH' ) || exit;

class FisHotel_Quarantined_Fish_Shipping_Class {

	/** Quarantined Fish product category term ID (child terms included too). */
	const CATEGORY_TERM_ID = 73;

	/** Premium shipping-class term ID — the default we assign. */
	const DEFAULT_CLASS_TERM_ID = 54;

	/** Premium shipping-class slug (used for the human-facing notice/log). */
	const DEFAULT_CLASS_SLUG = 'premium';

	/** wc_get_logger() source channel. */
	const LOG_SOURCE = 'fishotel-shipping-diagnostics';

	/** Transient caching the classless-fish tripwire count (1 hour). */
	const TRIPWIRE_TRANSIENT = 'fishotel_qf_classless_count';

	/** Re-entrancy guard: product IDs currently being processed. */
	private static $processing = [];

	/**
	 * Per-request dedupe. A single publish fires both transition_post_status
	 * and save_post_product; once we have filled a product's class this request
	 * we must not log/notice a second time for it.
	 */
	private static $handled = [];

	/** Product IDs queued this request for a shutdown-time class check. */
	private static $queue = [];

	public static function init() {
		// Fires on admin edit-screen saves and on wp_insert_post/wp_update_post.
		add_action( 'save_post_product', [ __CLASS__, 'on_save' ], 20, 1 );

		// Also catch a genuine status transition into 'publish'. Some programmatic
		// publishes (e.g. a scheduled post going live via wp_publish_post) never
		// fire save_post, so this closes that gap.
		add_action( 'transition_post_status', [ __CLASS__, 'on_transition' ], 20, 3 );

		// The actual class check runs on shutdown, after every term/meta write for
		// the request has committed. That way we always read the FINAL shipping
		// class — never a value that is still mid-save (WooCommerce persists the
		// edit-form shipping class on save_post priority 1, after transition and
		// possibly after our own hooks) — so a manual override is never clobbered.
		add_action( 'shutdown', [ __CLASS__, 'process_queue' ], 20 );

		// Best-effort one-time notice on the product edit screen after a fill.
		add_action( 'admin_notices', [ __CLASS__, 'render_assigned_notice' ] );

		// Dashboard tripwire: warn if any published fish is still classless.
		add_action( 'admin_notices', [ __CLASS__, 'render_tripwire_notice' ] );

		// Keep the tripwire cache honest when a fish's class or category changes.
		add_action( 'set_object_terms', [ __CLASS__, 'flush_tripwire_cache' ], 10, 4 );
	}

	/**
	 * save_post_product handler — queue the product for the shutdown check.
	 *
	 * @param int $product_id
	 */
	public static function on_save( $product_id ) {
		if ( wp_is_post_autosave( $product_id ) || wp_is_post_revision( $product_id ) ) {
			return;
		}
		self::enqueue( (int) $product_id );
	}

	/**
	 * transition_post_status handler — queue on a genuine publish event.
	 *
	 * Only a real transition INTO publish (old status differs) is interesting; a
	 * re-save of an already-published product is covered by on_save.
	 *
	 * @param string  $new_status
	 * @param string  $old_status
	 * @param WP_Post $post
	 */
	public static function on_transition( $new_status, $old_status, $post ) {
		if ( ! $post instanceof WP_Post || 'product' !== $post->post_type ) {
			return;
		}
		if ( 'publish' !== $new_status || $new_status === $old_status ) {
			return;
		}
		self::enqueue( (int) $post->ID );
	}

	/**
	 * Queue a product ID for the shutdown-time class check.
	 *
	 * @param int $product_id
	 */
	private static function enqueue( $product_id ) {
		if ( $product_id > 0 ) {
			self::$queue[ $product_id ] = true;
		}
	}

	/**
	 * Run the safety net for every queued product once, on shutdown.
	 */
	public static function process_queue() {
		if ( ! self::$queue ) {
			return;
		}
		$ids          = array_keys( self::$queue );
		self::$queue = [];
		foreach ( $ids as $product_id ) {
			// Only published products can break checkout; a classless draft is
			// still being set up and is out of scope.
			if ( 'publish' !== get_post_status( $product_id ) ) {
				continue;
			}
			self::maybe_assign( (int) $product_id );
		}
	}

	/**
	 * The safety net. Assign Premium only when this is a Quarantined Fish with
	 * a completely unset shipping class.
	 *
	 * @param int $product_id
	 */
	public static function maybe_assign( $product_id ) {
		if ( $product_id <= 0 || isset( self::$processing[ $product_id ] ) || isset( self::$handled[ $product_id ] ) ) {
			return;
		}

		// Non-fish products (medications, foods, gift cards, merchandise,
		// invoices) are completely out of scope.
		if ( ! self::is_quarantined_fish( $product_id ) ) {
			return;
		}

		// Manual override always wins: any explicitly-set class is respected.
		// get_shipping_class_id() returns 0 only when the class is truly unset.
		$product = wc_get_product( $product_id );
		if ( ! $product || $product->get_shipping_class_id() > 0 ) {
			return;
		}

		self::$processing[ $product_id ] = true;

		// Surgical term write on the parent — does not re-enter the product
		// save cycle, and leaves variation-level classes untouched.
		$result = wp_set_object_terms( $product_id, [ self::DEFAULT_CLASS_TERM_ID ], 'product_shipping_class', false );

		unset( self::$processing[ $product_id ] );
		self::$handled[ $product_id ] = true;

		if ( is_wp_error( $result ) ) {
			self::log(
				sprintf(
					'FAILED to auto-assign %s to classless quarantined fish "%s" (ID %d): %s',
					self::DEFAULT_CLASS_SLUG,
					get_the_title( $product_id ),
					$product_id,
					$result->get_error_message()
				)
			);
			return;
		}

		// Flush WC product caches so downstream reads see the new class.
		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients( $product_id );
		}
		delete_transient( self::TRIPWIRE_TRANSIENT );

		self::log(
			sprintf(
				'Auto-assigned "%s" shipping class to classless quarantined fish "%s" (ID %d).',
				self::DEFAULT_CLASS_SLUG,
				get_the_title( $product_id ),
				$product_id
			)
		);

		// Queue a one-time admin notice keyed to whoever triggered the save.
		self::queue_assigned_notice( $product_id );
	}

	/**
	 * True when the product is in the Quarantined Fish category (or any of its
	 * child categories).
	 *
	 * @param int $product_id
	 * @return bool
	 */
	private static function is_quarantined_fish( $product_id ) {
		$term_ids = self::category_term_ids();
		return has_term( $term_ids, 'product_cat', $product_id );
	}

	/**
	 * Quarantined Fish term ID plus any descendant term IDs. has_term() does not
	 * walk the hierarchy on its own, so we expand it here.
	 *
	 * @return int[]
	 */
	private static function category_term_ids() {
		$ids      = [ self::CATEGORY_TERM_ID ];
		$children = get_term_children( self::CATEGORY_TERM_ID, 'product_cat' );
		if ( ! is_wp_error( $children ) && $children ) {
			$ids = array_merge( $ids, array_map( 'intval', $children ) );
		}
		return $ids;
	}

	/**
	 * Write to the fishotel-shipping-diagnostics log channel.
	 *
	 * @param string $message
	 */
	private static function log( $message ) {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->info( $message, [ 'source' => self::LOG_SOURCE ] );
		}
	}

	// ─────────────────────────────────────────
	// One-time "assigned" admin notice
	// ─────────────────────────────────────────

	/**
	 * Remember that we just filled the class for this product, so the next
	 * admin page load for its editor can show a notice.
	 *
	 * @param int $product_id
	 */
	private static function queue_assigned_notice( $product_id ) {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return; // Programmatic publish with no logged-in admin — log only.
		}
		$key     = self::notice_transient_key( $user_id );
		$pending = get_transient( $key );
		$pending = is_array( $pending ) ? $pending : [];
		$pending[ (int) $product_id ] = true;
		set_transient( $key, $pending, HOUR_IN_SECONDS );
	}

	/**
	 * Show the one-time "Shipping class auto-assigned: Premium" notice on the
	 * product edit screen, then clear it.
	 */
	public static function render_assigned_notice() {
		$user_id = get_current_user_id();
		if ( ! $user_id || ! function_exists( 'get_current_screen' ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || 'product' !== $screen->id ) {
			return;
		}

		$key     = self::notice_transient_key( $user_id );
		$pending = get_transient( $key );
		if ( ! is_array( $pending ) || ! $pending ) {
			return;
		}

		$post_id = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $post_id && isset( $pending[ $post_id ] ) ) {
			unset( $pending[ $post_id ] );
			if ( $pending ) {
				set_transient( $key, $pending, HOUR_IN_SECONDS );
			} else {
				delete_transient( $key );
			}
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html__( 'Shipping class auto-assigned: Premium', 'fishotel' )
			);
		}
	}

	/**
	 * Per-user transient key for pending "assigned" notices.
	 *
	 * @param int $user_id
	 * @return string
	 */
	private static function notice_transient_key( $user_id ) {
		return 'fishotel_qf_class_notice_' . (int) $user_id;
	}

	// ─────────────────────────────────────────
	// Dashboard tripwire
	// ─────────────────────────────────────────

	/**
	 * Warn on the dashboard if any published Quarantined Fish is still classless
	 * — the exact state that broke checkout. Count is cached for an hour.
	 */
	public static function render_tripwire_notice() {
		if ( ! function_exists( 'get_current_screen' ) || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || 'dashboard' !== $screen->id ) {
			return;
		}

		$count = self::classless_fish_count();
		if ( $count < 1 ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
			esc_html__( 'FisHotel shipping safety net:', 'fishotel' ),
			esc_html(
				sprintf(
					/* translators: %d: number of published quarantined fish with no shipping class. */
					_n(
						'%d published Quarantined Fish product has no shipping class and will return no shipping rate at checkout. Assign a shipping class now.',
						'%d published Quarantined Fish products have no shipping class and will return no shipping rate at checkout. Assign a shipping class now.',
						$count,
						'fishotel'
					),
					$count
				)
			)
		);
	}

	/**
	 * Number of published Quarantined Fish products with no shipping class.
	 * Cached in a 1-hour transient.
	 *
	 * @return int
	 */
	private static function classless_fish_count() {
		$cached = get_transient( self::TRIPWIRE_TRANSIENT );
		if ( false !== $cached ) {
			return (int) $cached;
		}

		$query = new WP_Query(
			[
				'post_type'              => 'product',
				'post_status'            => 'publish',
				'fields'                 => 'ids',
				'nopaging'               => true,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'tax_query'              => [
					'relation' => 'AND',
					[
						'taxonomy'         => 'product_cat',
						'field'            => 'term_id',
						'terms'            => self::category_term_ids(),
						'include_children' => true,
					],
					[
						'taxonomy' => 'product_shipping_class',
						'operator' => 'NOT EXISTS',
					],
				],
			]
		);

		$count = (int) $query->post_count;
		set_transient( self::TRIPWIRE_TRANSIENT, $count, HOUR_IN_SECONDS );
		return $count;
	}

	/**
	 * Invalidate the tripwire cache whenever a shipping-class or category
	 * relationship changes, so the dashboard count is not stale after a manual
	 * fix. Scoped to the two taxonomies we care about to avoid needless churn.
	 *
	 * @param int    $object_id
	 * @param array  $terms
	 * @param array  $tt_ids
	 * @param string $taxonomy
	 */
	public static function flush_tripwire_cache( $object_id, $terms = [], $tt_ids = [], $taxonomy = '' ) {
		if ( '' !== $taxonomy && ! in_array( $taxonomy, [ 'product_shipping_class', 'product_cat' ], true ) ) {
			return;
		}
		delete_transient( self::TRIPWIRE_TRANSIENT );
	}
}
