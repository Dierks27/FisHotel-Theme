<?php
/**
 * Compatibility Guide — admin backfill tool.
 *
 * One-shot button on Products → FisHotel Settings that name-matches every
 * published WC product against assets/data/species.json and assigns
 * _fishotel_compat_category meta where it can. Idempotent (skips products
 * that already have meta) so it's safe to re-run after partial fixes.
 *
 * Match priority:
 *   1. Exact: lower(title) === lower(species.common)
 *   2. Substring: lower(title) contains lower(species.common)
 *   3. Sci match: lower(title + excerpt) contains lower(species.sci)
 *
 * @package FisHotel
 */

defined( 'ABSPATH' ) || exit;

class FisHotel_Compat_Backfill {

	const ACTION            = 'fishotel_compat_backfill';
	const NONCE             = 'fishotel_compat_backfill_nonce';
	const REPORT_TRANSIENT  = 'fh_compat_backfill_report_';
	const SETTINGS_PAGE     = 'product_page_fishotel-settings';

	/**
	 * Genus → matrix-category map for Strategy 4 (genus-from-excerpt).
	 * Driven by what's actually present in the FisHotel catalog excerpts;
	 * extend as new genera show up in the "Unknown genus" report bucket.
	 */
	const GENUS_MAP = [
		// Tangs
		'Acanthurus'         => 'tangs_acanthurus',
		'Zebrasoma'          => 'tangs_zebrasoma',
		'Ctenochaetus'       => 'tangs_ctenochaetus',
		'Naso'               => 'tangs_naso',
		'Paracanthurus'      => 'tangs_paracanthurus',

		// Angels
		'Centropyge'         => 'dwarf_angels_centropyge',
		'Paracentropyge'     => 'dwarf_angels_centropyge',
		'Apolemichthys'      => 'dwarf_angels_centropyge',
		'Pomacanthus'        => 'large_angels_pomacanthus',
		'Pygoplites'         => 'large_angels_pomacanthus', // regal angel
		'Chaetodontoplus'    => 'large_angels_pomacanthus',
		'Holacanthus'        => 'large_angels_holacanthus',
		'Genicanthus'        => 'genicanthus_angels',

		// Wrasses
		'Halichoeres'        => 'wrasses_halichoeres',
		'Thalassoma'         => 'wrasses_halichoeres',
		'Gomphosus'          => 'wrasses_halichoeres',
		'Pseudojuloides'     => 'wrasses_halichoeres',
		'Coris'              => 'wrasses_halichoeres',
		'Hemigymnus'         => 'wrasses_halichoeres',
		'Pteragogus'         => 'wrasses_halichoeres',
		'Labroides'          => 'wrasses_halichoeres', // cleaner wrasses
		'Cheilinus'          => 'wrasses_halichoeres', // Maori-style
		'Choerodon'          => 'wrasses_halichoeres', // tuskfish
		'Pseudodax'          => 'wrasses_halichoeres', // chiseltooth
		'Novaculichthys'     => 'wrasses_halichoeres', // rockmover
		'Cirrhilabrus'       => 'wrasses_cirrhilabrus',
		'Paracheilinus'      => 'wrasses_paracheilinus',
		'Macropharyngodon'   => 'wrasses_macropharyngodon',
		'Iniistius'          => 'wrasses_macropharyngodon',
		'Pseudocheilinus'    => 'wrasses_pseudocheilinus',
		'Anampses'           => 'wrasses_anampses',
		'Wetmorella'         => 'wrasses_anampses',
		'Pseudocoris'        => 'wrasses_anampses',

		// Hogfish
		'Bodianus'           => 'hogfish_bodianus',

		// Damsels & Chromis
		'Chrysiptera'        => 'damsels_chrysiptera',
		'Pomacentrus'        => 'damsels_chrysiptera',
		'Stegastes'          => 'damsels_chrysiptera',
		'Plectroglyphidodon' => 'damsels_chrysiptera',
		'Dischistodus'       => 'damsels_chrysiptera',
		'Neoglyphidodon'     => 'damsels_chrysiptera',
		'Dascyllus'          => 'damsels_chrysiptera',
		'Hypsypops'          => 'damsels_chrysiptera', // garibaldi
		'Paraglyphidodon'    => 'damsels_chrysiptera',
		'Chromis'            => 'chromis',
		'Azurina'            => 'chromis',

		// Clownfish
		'Amphiprion'         => 'clownfish',
		'Premnas'            => 'clownfish',

		// Gobies
		'Cryptocentrus'      => 'gobies_cryptocentrus',
		'Stonogobiops'       => 'gobies_cryptocentrus',
		'Amblyeleotris'      => 'gobies_cryptocentrus',
		'Mahidolia'          => 'gobies_cryptocentrus',
		'Gobiodon'           => 'gobies_gobiodon',
		'Eviota'             => 'gobies_gobiodon',
		'Trimma'             => 'gobies_gobiodon',
		'Elacatinus'         => 'gobies_gobiodon',
		'Priolepis'          => 'gobies_gobiodon',
		'Lythrypnus'         => 'gobies_gobiodon',
		'Coryphopterus'      => 'gobies_gobiodon', // small gobies
		'Nemateleotris'      => 'gobies_nemateleotris',
		'Ptereleotris'       => 'gobies_nemateleotris',
		'Valenciennea'       => 'gobies_valenciennea',
		'Signigobius'        => 'gobies_valenciennea',
		'Amblygobius'        => 'gobies_valenciennea',
		'Koumansetta'        => 'gobies_valenciennea', // sand-sifter

		// Blennies
		'Salarias'           => 'blennies_salarias',
		'Ecsenius'           => 'blennies_salarias',
		'Atrosalarias'       => 'blennies_salarias',
		'Cirripectes'        => 'blennies_salarias',
		'Istiblennius'       => 'blennies_salarias',
		'Meiacanthus'        => 'blennies_meiacanthus',
		'Petroscirtes'       => 'blennies_meiacanthus',
		'Plagiotremus'       => 'blennies_meiacanthus',

		// Cardinalfish (Apogonidae)
		'Pterapogon'         => 'cardinalfish',
		'Sphaeramia'         => 'cardinalfish',
		'Apogon'             => 'cardinalfish',
		'Apogonichthys'      => 'cardinalfish',
		'Cheilodipterus'     => 'cardinalfish',
		'Ostorhinchus'       => 'cardinalfish',

		// Anthias
		'Pseudanthias'       => 'anthias',
		'Serranocirrhitus'   => 'anthias',
		'Mirolabrichthys'    => 'anthias',
		'Plectranthias'      => 'anthias',
		'Holanthias'         => 'anthias',
		'Nemanthias'         => 'anthias',
		'Odontanthias'       => 'anthias',

		// Triggerfish
		'Odonus'             => 'triggerfish_peaceful',
		'Xanthichthys'       => 'triggerfish_peaceful',
		'Sufflamen'          => 'triggerfish_peaceful',
		'Balistoides'        => 'triggerfish_aggressive',
		'Balistapus'         => 'triggerfish_aggressive',
		'Balistes'           => 'triggerfish_aggressive',
		'Rhinecanthus'       => 'triggerfish_aggressive',
		'Pseudobalistes'     => 'triggerfish_aggressive',
		'Melichthys'         => 'triggerfish_aggressive',
		'Canthidermis'       => 'triggerfish_aggressive',

		// Lionfish + venomous predator niche (similar care + behavior)
		'Pterois'            => 'lionfish',
		'Dendrochirus'       => 'lionfish',
		'Parapterois'        => 'lionfish',
		'Pteroidichthys'     => 'lionfish', // ambon scorpionfish
		'Iracundus'          => 'lionfish',
		'Scorpaenopsis'      => 'lionfish',
		'Rhinopias'          => 'lionfish',

		// Eels (Muraenidae)
		'Echidna'            => 'eels',
		'Gymnothorax'        => 'eels',
		'Gymnomuraena'       => 'eels',
		'Muraena'            => 'eels',
		'Rhinomuraena'       => 'eels',
		'Enchelycore'        => 'eels',

		// Groupers
		'Cephalopholis'      => 'groupers',
		'Variola'            => 'groupers',
		'Plectropomus'       => 'groupers',
		'Epinephelus'        => 'groupers',
		'Mycteroperca'       => 'groupers',

		// Hawkfish (Cirrhitidae)
		'Neocirrhites'       => 'hawkfish',
		'Oxycirrhites'       => 'hawkfish',
		'Cirrhitichthys'     => 'hawkfish',
		'Paracirrhites'      => 'hawkfish',
		'Cirrhitops'         => 'hawkfish',
		'Amblycirrhitus'     => 'hawkfish',

		// Puffers
		'Canthigaster'       => 'puffers',
		'Diodon'             => 'puffers',
		'Arothron'           => 'puffers',
		'Chilomycterus'      => 'puffers',
		'Tetraodon'          => 'puffers',

		// Royal Gramma
		'Gramma'             => 'royal_gramma',
		'Lipogramma'         => 'royal_gramma',

		// Dottybacks
		'Pseudochromis'      => 'dottybacks',
		'Pictichromis'       => 'dottybacks',
		'Labracinus'         => 'dottybacks',
		'Manonichthys'       => 'dottybacks',
		'Cypho'              => 'dottybacks',
		'Ogilbyina'          => 'dottybacks',
		'Pholidochromis'     => 'dottybacks',

		// Basslets / soapfish
		'Liopropoma'         => 'basslets',
		'Serranus'           => 'basslets',
		'Hypoplectrus'       => 'basslets',
		'Grammistes'         => 'basslets',

		// Butterflyfish
		'Chelmon'            => 'butterflyfish_reef_safe',
		'Hemitaurichthys'    => 'butterflyfish_reef_safe',
		'Heniochus'          => 'butterflyfish_reef_safe',
		'Forcipiger'         => 'butterflyfish_reef_safe',
		'Prognathodes'       => 'butterflyfish_reef_safe',
		'Chaetodon'          => 'butterflyfish_non_reef_safe',
		'Roa'                => 'butterflyfish_non_reef_safe',

		// Mandarins / Dragonets
		'Synchiropus'        => 'mandarins_dragonets',

		// Rabbitfish / Foxface
		'Siganus'            => 'rabbitfish_siganus',
	];

	public static function init() {
		add_action( 'admin_post_' . self::ACTION, [ __CLASS__, 'handle' ] );
		add_action( 'admin_notices',              [ __CLASS__, 'render_report' ] );
	}

	/**
	 * POST handler. Runs the backfill, stores the report in a per-user
	 * transient, and redirects back to the settings page so the report
	 * surfaces via the admin_notices hook.
	 */
	public static function handle() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Insufficient permissions.' );
		}
		check_admin_referer( self::ACTION, self::NONCE );

		$report = self::run();

		set_transient(
			self::REPORT_TRANSIENT . get_current_user_id(),
			$report,
			5 * MINUTE_IN_SECONDS
		);

		wp_safe_redirect(
			add_query_arg(
				[ 'post_type' => 'product', 'page' => 'fishotel-settings' ],
				admin_url( 'edit.php' )
			) . '#fh-backfill-report'
		);
		exit;
	}

	/**
	 * Walk all published products and resolve a category. Returns:
	 *   [
	 *     matched   => [ { id, title, category, matched_on } ... ],
	 *     skipped   => [ { id, title, category } ... ],   // already had meta
	 *     ambiguous => [ { id, title, matches: [...] } ... ],
	 *     unmatched => [ { id, title } ... ],
	 *     totals    => [ matched, skipped, ambiguous, unmatched ],
	 *     ran_at    => timestamp,
	 *   ]
	 */
	public static function run() {
		$species = function_exists( 'fishotel_compat_load_data' )
			? (array) fishotel_compat_load_data( 'species' )
			: [];
		if ( empty( $species ) ) {
			return [ 'error' => 'species.json not found or empty.' ];
		}

		// Build lookups; keep duplicates so we can detect ambiguity later.
		$by_common_lower = []; // common => list of species rows
		$by_sci_lower    = []; // sci    => list of species rows
		foreach ( $species as $sp ) {
			if ( ! empty( $sp['common'] ) ) {
				$k = strtolower( $sp['common'] );
				$by_common_lower[ $k ][] = $sp;
			}
			if ( ! empty( $sp['sci'] ) ) {
				$k = strtolower( $sp['sci'] );
				$by_sci_lower[ $k ][] = $sp;
			}
		}

		// Order common-name keys longest-first so substring matching prefers
		// "Powder Blue Tang" over the shorter "Powder Blue" if both exist.
		$common_keys_sorted = array_keys( $by_common_lower );
		usort( $common_keys_sorted, function ( $a, $b ) { return strlen( $b ) - strlen( $a ); } );

		$matched        = [];
		$skipped        = [];
		$ambiguous      = [];
		$unknown_genus  = [];
		$unmatched      = [];

		$query = new WP_Query( [
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'orderby'        => 'title',
			'order'          => 'ASC',
		] );

		foreach ( $query->posts as $product_id ) {
			$title    = get_the_title( $product_id );
			$existing = (string) get_post_meta( $product_id, '_fishotel_compat_category', true );

			if ( $existing !== '' ) {
				$skipped[] = [ 'id' => $product_id, 'title' => $title, 'category' => $existing ];
				continue;
			}

			$title_lower = strtolower( $title );
			$hits        = []; // species rows that match
			$matched_on  = '';

			// 1. Exact title === common
			if ( isset( $by_common_lower[ $title_lower ] ) ) {
				$hits       = $by_common_lower[ $title_lower ];
				$matched_on = 'exact';
			}

			// 2. Substring — title contains common name (longest match wins,
			//    but collect every match so we can detect ambiguity properly)
			if ( empty( $hits ) ) {
				foreach ( $common_keys_sorted as $common ) {
					if ( strpos( $title_lower, $common ) !== false ) {
						foreach ( $by_common_lower[ $common ] as $sp ) {
							$hits[] = $sp;
						}
					}
				}
				if ( ! empty( $hits ) ) $matched_on = 'substring';
			}

			// 3. Sci match — title + excerpt
			if ( empty( $hits ) ) {
				$excerpt = (string) get_post_field( 'post_excerpt', $product_id );
				$haystack = strtolower( $title . ' ' . $excerpt );
				foreach ( $by_sci_lower as $sci => $rows ) {
					if ( strpos( $haystack, $sci ) !== false ) {
						foreach ( $rows as $sp ) {
							$hits[] = $sp;
						}
					}
				}
				if ( ! empty( $hits ) ) $matched_on = 'sci';
			}

			// 4. Genus-from-excerpt — pull `Genus species` tokens out of the
			//    short description and look the genus up in GENUS_MAP.
			//    Scans every token (not just the first) so an opening
			//    common-name phrase like "The Adorned" doesn't mask the real
			//    sci binomial later in the excerpt. Falls into the
			//    unknown_genus bucket if a token parses but no genus matches.
			if ( empty( $hits ) ) {
				$genus_result = self::resolve_genus_from_excerpt( $product_id );
				if ( $genus_result['category'] !== '' ) {
					update_post_meta( $product_id, '_fishotel_compat_category', $genus_result['category'] );
					$matched[] = [
						'id'         => $product_id,
						'title'      => $title,
						'category'   => $genus_result['category'],
						'matched_on' => 'genus',
						'species'    => $genus_result['binomial'],
					];
					continue;
				}
				if ( $genus_result['genus'] !== '' ) {
					$unknown_genus[] = [
						'id'    => $product_id,
						'title' => $title,
						'genus' => $genus_result['genus'],
					];
					continue;
				}
				$unmatched[] = [ 'id' => $product_id, 'title' => $title ];
				continue;
			}

			// Resolve hits: if all hits share one category, treat as
			// unambiguous even if multiple species matched.
			$unique_cats = array_values( array_unique( array_filter( array_map(
				function ( $h ) { return isset( $h['category'] ) ? $h['category'] : ''; },
				$hits
			) ) ) );

			if ( count( $unique_cats ) === 1 ) {
				$cat = $unique_cats[0];
				update_post_meta( $product_id, '_fishotel_compat_category', $cat );
				$matched[] = [
					'id'         => $product_id,
					'title'      => $title,
					'category'   => $cat,
					'matched_on' => $matched_on,
					'species'    => self::label_for( $hits[0] ),
				];
			} else {
				$ambiguous[] = [
					'id'      => $product_id,
					'title'   => $title,
					'matches' => array_map( function ( $h ) {
						return [
							'label'    => self::label_for( $h ),
							'category' => isset( $h['category'] ) ? $h['category'] : '',
						];
					}, $hits ),
				];
			}
		}

		return [
			'matched'        => $matched,
			'skipped'        => $skipped,
			'ambiguous'      => $ambiguous,
			'unknown_genus'  => $unknown_genus,
			'unmatched'      => $unmatched,
			'totals'         => [
				'matched'        => count( $matched ),
				'skipped'        => count( $skipped ),
				'ambiguous'      => count( $ambiguous ),
				'unknown_genus'  => count( $unknown_genus ),
				'unmatched'      => count( $unmatched ),
			],
			'ran_at'         => time(),
		];
	}

	/**
	 * Strategy 4 helper. Scans the product's short description for
	 * `Genus species` tokens via /\b([A-Z][a-z]+)\s+[a-z]+\b/ and returns:
	 *   [ category => '', genus => '', binomial => '' ]
	 *
	 * - category: matrix-category key if any token's genus is in GENUS_MAP
	 * - genus:    first token's genus if no map hit (for the unknown bucket)
	 * - binomial: the matched "Genus species" string for the report
	 */
	protected static function resolve_genus_from_excerpt( $product_id ) {
		$out = [ 'category' => '', 'genus' => '', 'binomial' => '' ];
		$excerpt = (string) get_post_field( 'post_excerpt', $product_id );
		if ( $excerpt === '' ) {
			return $out;
		}
		$plain = wp_strip_all_tags( $excerpt );
		if ( ! preg_match_all( '/\b([A-Z][a-z]+)\s+([a-z]+)\b/', $plain, $matches, PREG_SET_ORDER ) ) {
			return $out;
		}
		foreach ( $matches as $m ) {
			$genus = $m[1];
			if ( isset( self::GENUS_MAP[ $genus ] ) ) {
				$out['category'] = self::GENUS_MAP[ $genus ];
				$out['genus']    = $genus;
				$out['binomial'] = $genus . ' ' . $m[2];
				return $out;
			}
		}
		// No map hit — record the FIRST parseable genus for the unknown bucket.
		$out['genus']    = $matches[0][1];
		$out['binomial'] = $matches[0][1] . ' ' . $matches[0][2];
		return $out;
	}

	protected static function label_for( $sp ) {
		if ( ! empty( $sp['common'] ) ) return $sp['common'];
		if ( ! empty( $sp['sci'] ) )    return $sp['sci'];
		return '(unnamed)';
	}

	/** Print the trigger button on the settings page (called from render_page). */
	public static function render_button() {
		?>
		<h2 style="margin-top:48px; padding-top:24px; border-top:1px solid #ddd;">Compatibility Guide — Backfill</h2>
		<p style="max-width:760px;">
			Match every published product against the compatibility category list by name.
			Idempotent — products that already have a category assigned are skipped.
			Stock status doesn't matter; out-of-stock products are matched too so future
			restocks just work.
		</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0 0 8px;">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
			<?php wp_nonce_field( self::ACTION, self::NONCE ); ?>
			<button type="submit" class="button button-secondary">Backfill Compat Categories</button>
		</form>
		<p class="description">Allow a few seconds for catalogs over a hundred products. The page will reload with a report.</p>
		<?php
	}

	/** Render the report block (admin_notices) above the settings form. */
	public static function render_report() {
		$screen = get_current_screen();
		if ( ! $screen || $screen->id !== self::SETTINGS_PAGE ) return;

		$key    = self::REPORT_TRANSIENT . get_current_user_id();
		$report = get_transient( $key );
		if ( ! $report ) return;
		delete_transient( $key );

		if ( isset( $report['error'] ) ) {
			echo '<div class="notice notice-error"><p>Backfill failed: ' . esc_html( $report['error'] ) . '</p></div>';
			return;
		}

		$t = $report['totals'];
		?>
		<div id="fh-backfill-report" class="notice notice-success" style="padding:18px 20px;">
			<h2 style="margin-top:0;">Backfill Report</h2>
			<ul style="font-size:14px; line-height:1.7;">
				<li><strong>Matched:</strong> <?php echo (int) $t['matched']; ?> products</li>
				<li><strong>Already had meta set:</strong> <?php echo (int) $t['skipped']; ?> products (skipped)</li>
				<li><strong>Ambiguous</strong> (multiple matches — please assign manually): <?php echo (int) $t['ambiguous']; ?> products</li>
				<li><strong>Unknown genus</strong> (consider new category): <?php echo (int) ( isset( $t['unknown_genus'] ) ? $t['unknown_genus'] : 0 ); ?> products</li>
				<li><strong>No match</strong> (please assign manually): <?php echo (int) $t['unmatched']; ?> products</li>
			</ul>

			<?php if ( ! empty( $report['matched'] ) ) : ?>
				<details style="margin-top:14px;">
					<summary style="cursor:pointer; font-weight:600;">Matched (<?php echo (int) $t['matched']; ?>)</summary>
					<ul style="margin:10px 0 0 18px;">
						<?php foreach ( $report['matched'] as $row ) : ?>
							<li>
								<a href="<?php echo esc_url( get_edit_post_link( $row['id'] ) ); ?>"><?php echo esc_html( $row['title'] ); ?></a>
								→ <code><?php echo esc_html( $row['category'] ); ?></code>
								<small style="color:#666;">via <?php echo esc_html( $row['matched_on'] ); ?> — <em><?php echo esc_html( $row['species'] ); ?></em></small>
							</li>
						<?php endforeach; ?>
					</ul>
				</details>
			<?php endif; ?>

			<?php if ( ! empty( $report['ambiguous'] ) ) : ?>
				<details open style="margin-top:14px;">
					<summary style="cursor:pointer; font-weight:600; color:#946800;">Ambiguous — please assign manually (<?php echo (int) $t['ambiguous']; ?>)</summary>
					<ul style="margin:10px 0 0 18px;">
						<?php foreach ( $report['ambiguous'] as $row ) : ?>
							<li>
								<a href="<?php echo esc_url( get_edit_post_link( $row['id'] ) ); ?>"><?php echo esc_html( $row['title'] ); ?></a>
								— matched:
								<?php
								$labels = array_map(
									function ( $m ) { return $m['label'] . ' (' . $m['category'] . ')'; },
									$row['matches']
								);
								echo esc_html( implode( ', ', $labels ) );
								?>
							</li>
						<?php endforeach; ?>
					</ul>
				</details>
			<?php endif; ?>

			<?php if ( ! empty( $report['unknown_genus'] ) ) : ?>
				<details open style="margin-top:14px;">
					<summary style="cursor:pointer; font-weight:600; color:#3858a3;">Unknown genus — consider new category (<?php echo (int) $t['unknown_genus']; ?>)</summary>
					<p class="description" style="margin:8px 0 6px;">
						Sci binomial parsed but the genus isn't in the FisHotel category map yet.
						Use this list to decide whether to add new categories in the next matrix release.
					</p>
					<ul style="margin:10px 0 0 18px;">
						<?php foreach ( $report['unknown_genus'] as $row ) : ?>
							<li>
								<a href="<?php echo esc_url( get_edit_post_link( $row['id'] ) ); ?>"><?php echo esc_html( $row['title'] ); ?></a>
								— genus: <code><?php echo esc_html( $row['genus'] ); ?></code>
							</li>
						<?php endforeach; ?>
					</ul>
				</details>
			<?php endif; ?>

			<?php if ( ! empty( $report['unmatched'] ) ) : ?>
				<details open style="margin-top:14px;">
					<summary style="cursor:pointer; font-weight:600; color:#a00;">No match — please assign manually (<?php echo (int) $t['unmatched']; ?>)</summary>
					<ul style="margin:10px 0 0 18px;">
						<?php foreach ( $report['unmatched'] as $row ) : ?>
							<li><a href="<?php echo esc_url( get_edit_post_link( $row['id'] ) ); ?>"><?php echo esc_html( $row['title'] ); ?></a></li>
						<?php endforeach; ?>
					</ul>
				</details>
			<?php endif; ?>
		</div>
		<?php
	}
}

FisHotel_Compat_Backfill::init();
