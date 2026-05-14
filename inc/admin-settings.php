<?php
/**
 * FisHotel Admin Settings Page
 *
 * Exposes all hardcoded theme values as editable options.
 * Lives under Products → FisHotel Settings.
 *
 * @package FisHotel
 */

defined( 'ABSPATH' ) || exit;

class FisHotel_Admin_Settings {

	const OPTION_GROUP = 'fishotel_settings';

	/**
	 * Per-sub-page settings group. Each subpage's <form> submits to
	 * options.php with this group name; WordPress then only iterates +
	 * sanitizes the options registered to that group. Without per-page
	 * groups, a save on the Product Page would also iterate every
	 * Homepage / About / Contacts option — fields not present in the
	 * POST body get sanitized as empty and silently overwrite stored
	 * values. (See Phase 3.5.1 root cause.)
	 *
	 * @param string $page_slug e.g. `fishotel-product`
	 * @return string e.g. `fishotel_product`
	 */
	public static function group_for_page( $page_slug ) {
		return str_replace( '-', '_', (string) $page_slug );
	}

	/** FAQ item categories whitelist */
	public static function faq_categories() {
		return [
			'General'    => 'General',
			'Shipping'   => 'Shipping',
			'Guarantees' => 'Guarantees',
		];
	}

	/** Default 5 quarantine stages (word-for-word per spec). */
	public static function default_quarantine_stages() {
		return [
			[
				'label'       => 'Check-In',
				'sublabel'    => 'Upon Receiving the Fish',
				'duration'    => 'Day 1',
				'description' => "When fish arrive from the wholesaler, we look each one over for anything that needs immediate attention. We then float the bags to match the tank temperature and confirm the salinity matches before anyone checks in.",
			],
			[
				'label'       => 'The Spa',
				'sublabel'    => 'Into Medication',
				'duration'    => 'Days 1–14',
				'description' => "Fish that look healthy and ready to be treated begin a 14-day treatment in either Copper Power (held at 2.35–2.50) or Chloroquine (held at 60 mg/gal). Fish on Copper Power also receive 10 days of formalin treatments, along with 2 Praziquantel and/or Fenbendazole treatments spaced 6 days apart.",
			],
			[
				'label'       => 'Room Upgrade',
				'sublabel'    => 'Moving Day',
				'duration'    => 'Days 15–28',
				'description' => "After the 14-day treatment wraps, every fish moves into a clean observation tank designed to feel like their natural environment — rocks and sand in every tank so they can settle in. They stay here for 14 days of observation while we watch their health and feed them well so they can put weight back on.",
			],
			[
				'label'       => 'Room Service',
				'sublabel'    => 'Medicated Food',
				'duration'    => 'Days 15–28',
				'if_needed'   => true,
				'description' => "For any fish that isn't putting on weight during observation, we provide medicated food with Praziquantel or Fenbendazole — once a day for 3 treatments. We keep watching and won't let them head to check-out until we're confident they're healthy and gaining weight.",
			],
			[
				'label'       => 'Check-Out',
				'sublabel'    => 'Ready to Go',
				'duration'    => 'Day 29+',
				'description' => "After 4 weeks with us — 2 in the spa, 2 under observation — each fish is ready to head home. We'll miss these little guys, but we know we can't keep them all. That's where you come in: adopt one and give them a long, healthy life in your slice of the ocean.",
			],
		];
	}

	/** Default 4 FAQ items (word-for-word per spec). */
	public static function default_faq_items() {
		return [
			[
				'question' => 'How much is shipping?',
				'category' => 'Shipping',
				'answer'   => "<p><strong>Product Shipping</strong><br>Ground shipping starts at \$2.99. Receive free shipping on orders over \$79.99.</p>\n<p><strong>Livestock Shipping</strong><br>Overnight shipping is \$49.99. Receive \$19 shipping on orders over \$500.</p>",
			],
			[
				'question' => 'Can I pick my shipment date?',
				'category' => 'Shipping',
				'answer'   => "<p>We ship all critters every Tuesday &amp; Wednesdays. At checkout simply select the date that you would like your fish to be delivered. We expect you to be home to collect the delivery, but if you are unable to please make sure you get the fish dropped at the nearest UPS location to your home.</p>",
			],
			[
				'question' => 'What if my fish arrives DOA?',
				'category' => 'Guarantees',
				'answer'   => "<p>At the FisHotel we take great pride in the health of our live animals. We believe in giving people the healthiest animals to ensure a positive experience in the aquarium hobby. For that reason, we fully guarantee that the animals we ship will arrive healthy and alive. In the unfortunate event that you have a D.O.A claim, please see below:</p>\n<ul>\n<li>Take a picture of your un-opened bag of livestock</li>\n<li>Once opened, please remove them and take a photo of the deceased livestock on a white background next to our packing slip</li>\n<li>Do NOT dispose of deceased livestock until you get the approval from FisHotel (In case we need more photos or information, we suggest freezing the animal in a Ziploc bag)</li>\n<li>Send an email with pictures to Jeff@FisHotel.com</li>\n<li>D.O.A. claims must be sent within 24 hours of receiving the package</li>\n<li>We do not issue credit for shipping costs</li>\n<li>Any claims due to packages being left outside for extended periods of time in extreme weather are void, although we know mistakes do happen. For this reason, we reserve the right to handle this on a case to case basis.</li>\n<li>Any D.O.A less than \$15 in value will be shipped with a future orders</li>\n</ul>",
			],
			[
				'question' => "What's your health guarantee?",
				'category' => 'Guarantees',
				'answer'   => "<p>We also guarantee the health of our animals for 14 days from the day they are received. If you have any issues with sick livestock, please contact us within 14 days of receiving your order. Please allow one to two business days for a FisHotel to review your claim and notify you of approval or denial. Livestock credit is valid for one year from the date it is issued. (To use your credit, enter the credit code in the Coupons field on the checkout page. The dollar amount will be deducted from your livestock purchase total.)</p>",
			],
		];
	}

	/** Default body for the About page (rendered as the article body). */
	public static function default_about_body() {
		return "<h2>How a closet became a 1,000-gallon shop</h2>\n"
			. "<p>I'm Jeff. I run FisHotel out of Blaine, Minnesota — though the mail still goes to Champlin.</p>\n"
			. "<p>Reefkeeping pulled me in for a stack of reasons that all happen to live in the same hobby: I'm a gadget guy, an ocean guy, a SCUBA guy, and an animal guy. Living in Minnesota means I can't just go diving on a Tuesday — so I brought a piece of the ocean home. A reef calms me down, gives me something to work on, and is never actually \"done.\" Just when I think I know how the tank's going to behave tomorrow, it surprises me.</p>\n"
			. "<p>In 2019 I went deep on fish disease — how to keep it out of my system, how to actually treat it. That's when I met Bobby (Humblefish to most). He took me under his wing, let me bounce ideas off him, and over time helped me figure out what real quarantine looked like.</p>\n"
			. "<p>I started QT'ing every fish that went into my own reef. Then a few buddies asked me to QT theirs. That turned into four 10-gallon tanks in my walkout closet. The closet outgrew itself fast, so I built a bigger system in the laundry room. Around then Bobby got out of selling quarantined fish and started pointing people my way — that's when FisHotel really became a thing.</p>\n"
			. "<p>The laundry room lasted about a year. I'm now in a dedicated shop in Blaine running over 1,000 gallons of water, and FisHotel is my full-time job.</p>\n"
			. "<h2>What's broken in this hobby</h2>\n"
			. "<p>Most fish in the trade are flipped. They get bought, listed, and shipped out the door as fast as possible. A lot of sellers act like they've cared for these fish for weeks — the truth is they get them in and out as quickly as they can. By the time the fish actually arrives at a hobbyist's house, it's stressed, starving, and has about a 50/50 shot at surviving.</p>\n"
			. "<p>That's before you even talk about disease. Plenty of sources say \"we QT\" but what they actually do is hold fish in low-copper systems just long enough to keep them looking alive until shipping. That's not quarantine — that's stalling. The disease shows up in <em>your</em> tank instead of theirs. It's a big reason so many people cycle in and out of this hobby.</p>\n"
			. "<h2>How FisHotel does it differently</h2>\n"
			. "<p>Every fish that comes through this shop spends real time here. Two full weeks of active treatment in a dedicated medication system. Then two more weeks of observation in a clean tank — eating well, gaining weight, monitored daily. Nothing gets listed for sale until that whole cycle is done.</p>\n"
			. "<p>I'm one person, running this myself, with one standard: every fish gets the same protocol regardless of price tag. If you want to ask questions about a fish before you buy, ask. If you want to see its treatment history, you'll get it with the fish.</p>\n"
			. "<h2>Where to find me</h2>\n"
			. "<p>You'll regularly catch me on <strong><a href=\"http://Humble.Fish\">Humble.Fish</a></strong> if you want to talk reef. The shop is in Blaine, MN; mail goes to Champlin, MN 55316. For anything else, <strong><a href=\"mailto:Jeff@FisHotel.com\">Jeff@FisHotel.com</a></strong>.</p>";
	}

	/** All settings with defaults */
	public static function defaults() {
		return [
			// Shop
			'fh_shop_display'             => 'categories',
			'fh_shop_hide_empty'          => '1',
			'fh_shop_hidden_cats'         => [],
			// Homepage
			'fh_home_available_count'     => 8,
			'fh_home_testimonials'        => [], // seeded via get_home_testimonials()
			// Placeholder libraries (default empty arrays)
			'fh_placeholder_library'      => [],
			'fh_cs_placeholder_library'   => [],
			// QT Certificate
			'fh_qt_line_1'                => '14 days observation',
			'fh_qt_line_2'                => '+ 14 days treatment',
			// Trust Strip — fish (quarantined-fish PDP)
			'fh_trust_1'                  => '28-day QT protocol',
			'fh_trust_2'                  => 'Live arrival guarantee',
			'fh_trust_3'                  => 'Ships Mon–Tue',
			// Trust Strip — medications (Phase 3.5)
			'fh_med_trust_1'              => 'The same meds we use in QT',
			'fh_med_trust_2'              => 'Trusted partner fulfillment',
			'fh_med_trust_3'              => 'Ships in 1-2 business days',
			// Trust Strip — foods (Phase 3.5)
			'fh_food_trust_1'             => 'The same foods we feed in-house',
			'fh_food_trust_2'             => 'Trusted partner fulfillment',
			'fh_food_trust_3'             => 'Ships in 1-2 business days',
			// Trust Strip — tier 2 / Amazon-affiliate medications (Phase 3.5.1)
			'fh_tier2_trust_1'            => 'The same meds we use in QT',
			'fh_tier2_trust_2'            => 'Sourced via Amazon for best pricing',
			'fh_tier2_trust_3'            => 'Reef-keeper favorite for decades',
			// Pharmacy / Pantry badge subtitles (Phase 3.6) — mirror the QT
			// certificate panel for med + food products.
			'fh_pharmacy_badge_subtitle'  => 'Sourced direct · Therapeutic-grade',
			'fh_pantry_badge_subtitle'    => 'Sourced direct · Premium grade',
			// Branding
			'fh_tagline'                  => 'We quarantine. You reef.',
			// Care Guide Defaults
			'fh_default_foods'            => '',
			'fh_default_habitat'          => '',
			// FAQ Page — hero fields
			'faq_concierge_label'         => 'The Concierge Desk',
			'faq_page_title'              => 'Frequently Asked Questions',
			'faq_page_intro'              => '',
			'quarantine_section_title'    => 'A Stay at The FisHotel',
			'quarantine_section_subtitle' => 'How does a stay at The FisHotel work?',
			// FAQ Page — structured content
			'fh_quarantine_stages'        => [], // seeded below via get_quarantine_stages()
			'fh_faq_items'                => [], // seeded below via get_faq_items()
			// About Page — Founder's Edition newspaper
			'about_masthead'              => 'THE FISHOTEL GAZETTE',
			'about_edition_line'          => "FOUNDER'S EDITION · EST. 1923 · ONE FISH CENT",
			'about_dateline'              => 'BLAINE, MINNESOTA',
			'about_headline'              => 'From a Walkout Closet to 1,000 Gallons',
			'about_dek'                   => 'How one stubborn Minnesotan brought the ocean home — and why your reef should care.',
			'about_byline'                => 'By Jeff Dierks · Founder, The FisHotel',
			'about_pull_quote'            => "That's not quarantine — that's stalling. The disease shows up in your tank instead of theirs.",
			'about_body'                  => '', // seeded via get_about_body()
			'about_signoff'               => '— J.D.',
			'about_footer_line'           => 'Published by The FisHotel · Est. 2019 · Champlin & Blaine, MN',
			// About Page — images (attachment IDs + caption + credit per slot)
			'about_hero_image'              => 0,
			'about_hero_caption'            => 'Jeff at the FisHotel quarantine shop',
			'about_hero_credit'             => 'STAFF PHOTO',
			'about_inline_image_1'          => 0,
			'about_inline_image_1_caption'  => '',
			'about_inline_image_1_credit'   => 'ILLUSTRATION',
			'about_inline_image_2'          => 0,
			'about_inline_image_2_caption'  => '',
			'about_inline_image_2_credit'   => 'ILLUSTRATION',
			// Contacts page
			'contacts_eyebrow'              => 'FRONT DESK',
			'contacts_heading'              => 'Contact',
			'contacts_location_text'        => 'Champlin, MN 55316',
			'contacts_location_map_image'   => 0,
			'contacts_email'                => 'Jeff@FisHotel.com',
			'contacts_email_label'          => 'Write us',
			'contacts_forum_url'            => 'https://humble.fish/community/forums/fishotel.41/',
			'contacts_forum_label'          => 'Visit our Humble.Fish forum',
		];
	}

	/** Get the About body, falling back to default if empty. */
	public static function get_about_body() {
		$saved = get_option( 'about_body', '' );
		if ( $saved === '' ) {
			return self::default_about_body();
		}
		return $saved;
	}

	/** Get quarantine stages, filling missing slots from defaults so we always have exactly 5. */
	public static function get_quarantine_stages() {
		$saved    = get_option( 'fh_quarantine_stages', [] );
		$defaults = self::default_quarantine_stages();
		$out      = [];
		for ( $i = 0; $i < 5; $i++ ) {
			$row = isset( $saved[ $i ] ) && is_array( $saved[ $i ] ) ? $saved[ $i ] : [];
			// Fall back to default if_needed only when the saved row is missing
			// the key entirely (legacy data); an explicit "0" stays off.
			$default_if_needed = ! empty( $defaults[ $i ]['if_needed'] );
			$if_needed = array_key_exists( 'if_needed', $row ) ? ! empty( $row['if_needed'] ) : $default_if_needed;
			$out[ $i ] = [
				'label'       => isset( $row['label'] )       && $row['label']       !== '' ? $row['label']       : $defaults[ $i ]['label'],
				'sublabel'    => isset( $row['sublabel'] )    && $row['sublabel']    !== '' ? $row['sublabel']    : $defaults[ $i ]['sublabel'],
				'duration'    => isset( $row['duration'] )    && $row['duration']    !== '' ? $row['duration']    : $defaults[ $i ]['duration'],
				'description' => isset( $row['description'] ) && $row['description'] !== '' ? $row['description'] : $defaults[ $i ]['description'],
				'if_needed'   => $if_needed,
			];
		}
		return $out;
	}

	/** Default homepage testimonial seeded from the original hardcoded quote. */
	public static function default_home_testimonials() {
		return [
			[
				'quote'  => 'Some of the healthiest, happiest, most well-adjusted quarantined fish on the market.',
				'author' => '',
				'source' => '',
			],
		];
	}

	/** Get homepage testimonials. Empty array means "hide section". */
	public static function get_home_testimonials() {
		$saved = get_option( 'fh_home_testimonials', null );
		if ( $saved === null ) {
			// First load — seed with the legacy quote so the homepage doesn't go blank.
			return self::default_home_testimonials();
		}
		if ( ! is_array( $saved ) ) {
			return [];
		}
		return $saved;
	}

	/** Get FAQ items, falling back to defaults if nothing saved yet. */
	public static function get_faq_items() {
		$saved = get_option( 'fh_faq_items', null );
		if ( ! is_array( $saved ) || empty( $saved ) ) {
			return self::default_faq_items();
		}
		return $saved;
	}

	/** Get a setting with its default fallback */
	public static function get( $key ) {
		$defaults = self::defaults();
		$default  = $defaults[ $key ] ?? '';
		$value    = get_option( $key, $default );
		// If a wipe (or any legacy state) left an empty string in the DB
		// for a key that has a declared default, prefer the default so
		// callers don't need their own ?: fallback ladder. Only applies
		// to scalar string defaults — array/object types pass through.
		if ( is_string( $value ) && trim( $value ) === '' && is_string( $default ) && $default !== '' ) {
			return $default;
		}
		return $value;
	}

	/**
	 * Top-level menu slug. The Dashboard sub-page reuses this slug so the
	 * first child entry shows "Dashboard" instead of the parent's title.
	 */
	const PARENT_SLUG = 'fishotel-theme';

	/**
	 * Map of sub-page slug → page config. Drives both menu registration
	 * and the rendering callback dispatch. Order here = sidebar order.
	 */
	public static function pages() {
		return [
			'fishotel-theme'         => [
				'page_title' => 'FisHotel Theme',
				'menu_title' => 'Dashboard',
				'render'     => 'render_dashboard',
			],
			'fishotel-homepage'      => [ 'page_title' => 'Homepage',      'menu_title' => 'Homepage' ],
			'fishotel-shop'          => [ 'page_title' => 'Shop Page',     'menu_title' => 'Shop Page' ],
			'fishotel-product'       => [ 'page_title' => 'Product Page',  'menu_title' => 'Product Page' ],
			'fishotel-faq'           => [ 'page_title' => 'FAQ Page',      'menu_title' => 'FAQ Page' ],
			'fishotel-about'         => [ 'page_title' => 'About Page',    'menu_title' => 'About Page' ],
			'fishotel-contacts'      => [ 'page_title' => 'Contacts Page', 'menu_title' => 'Contacts Page' ],
			'fishotel-placeholders'  => [ 'page_title' => 'Placeholders',  'menu_title' => 'Placeholders' ],
			'fishotel-branding'      => [ 'page_title' => 'Branding',      'menu_title' => 'Branding' ],
			'fishotel-tools'         => [
				'page_title' => 'Tools',
				'menu_title' => 'Tools',
				'render'     => 'render_tools',
			],
		];
	}

	public static function init() {
		add_action( 'admin_menu', [ __CLASS__, 'add_page' ] );
		add_action( 'admin_init', [ __CLASS__, 'register_settings' ] );
		add_action( 'admin_init', [ __CLASS__, 'redirect_legacy_urls' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_admin_assets' ] );
	}

	/**
	 * The Settings and Tools pages used to live under
	 * Products → FisHotel Settings / FisHotel Tools. After the reorg the
	 * old URLs would 404; redirect them to the new top-level menu so any
	 * bookmarked links still resolve.
	 */
	public static function redirect_legacy_urls() {
		if ( empty( $_GET['page'] ) || empty( $_GET['post_type'] ) ) {
			return;
		}
		if ( $_GET['post_type'] !== 'product' ) {
			return;
		}
		$old = sanitize_key( wp_unslash( $_GET['page'] ) );
		$map = [
			'fishotel-settings' => self::PARENT_SLUG,
			'fishotel-tools'    => 'fishotel-tools',
		];
		if ( ! isset( $map[ $old ] ) ) {
			return;
		}
		wp_safe_redirect( admin_url( 'admin.php?page=' . $map[ $old ] ) );
		exit;
	}

	public static function enqueue_admin_assets( $hook ) {
		if ( $hook !== 'toplevel_page_' . self::PARENT_SLUG
			&& strpos( $hook, self::PARENT_SLUG . '_page_' ) !== 0 ) {
			return;
		}
		// Ensure TinyMCE / Quicktags are available so wp_editor() instances init reliably
		// and so we can add editors dynamically for new repeater rows.
		wp_enqueue_editor();
		wp_enqueue_script( 'jquery-ui-sortable' );
		wp_enqueue_script(
			'fishotel-admin-faq',
			FISHOTEL_THEME_URI . '/assets/js/admin-faq.js',
			[ 'jquery', 'jquery-ui-sortable', 'editor', 'quicktags' ],
			FISHOTEL_THEME_VERSION,
			true
		);
		wp_enqueue_script(
			'fishotel-admin-testimonials',
			FISHOTEL_THEME_URI . '/assets/js/admin-testimonials.js',
			[ 'jquery', 'jquery-ui-sortable' ],
			FISHOTEL_THEME_VERSION,
			true
		);
		wp_enqueue_media();
		wp_add_inline_script( 'jquery-core', self::image_picker_js() );
		wp_add_inline_style( 'wp-admin', self::admin_inline_css() );
	}

	public static function image_picker_js() {
		return <<<'JS'
(function($){
	function fhPhLibrarySync($lib){
		var ids = [];
		$lib.find('.fh-ph-library__item').each(function(){
			var id = parseInt($(this).attr('data-id'), 10);
			if (id) ids.push(id);
		});
		$lib.find('.fh-ph-library__input').val(ids.join(','));
	}
	$(function(){
		$('.fh-ph-library__grid').each(function(){
			var $grid = $(this);
			if ($.fn.sortable) {
				$grid.sortable({
					items: '> li',
					placeholder: 'fh-ph-library__placeholder',
					tolerance: 'pointer',
					update: function(){ fhPhLibrarySync($grid.closest('.fh-ph-library')); }
				});
			}
		});
	});
	$(document).on('click', '.fh-ph-library__add', function(e){
		e.preventDefault();
		var $lib = $(this).closest('.fh-ph-library');
		var frame = wp.media({
			title: 'Select placeholder images',
			button: { text: 'Add to library' },
			multiple: 'add',
			library: { type: 'image' }
		});
		frame.on('select', function(){
			var sel = frame.state().get('selection').toJSON();
			var existing = {};
			$lib.find('.fh-ph-library__item').each(function(){
				existing[$(this).attr('data-id')] = true;
			});
			sel.forEach(function(att){
				if (existing[att.id]) return;
				var url = (att.sizes && att.sizes.thumbnail) ? att.sizes.thumbnail.url : att.url;
				var $item = $('<li class="fh-ph-library__item"></li>')
					.attr('data-id', att.id)
					.append($('<img>').attr('src', url).attr('alt', ''))
					.append('<button type="button" class="fh-ph-library__remove" aria-label="Remove">&times;</button>');
				$lib.find('.fh-ph-library__grid').append($item);
			});
			fhPhLibrarySync($lib);
		});
		frame.open();
	});
	$(document).on('click', '.fh-ph-library__remove', function(e){
		e.preventDefault();
		var $lib = $(this).closest('.fh-ph-library');
		$(this).closest('.fh-ph-library__item').remove();
		fhPhLibrarySync($lib);
	});
	$(document).on('click', '.fh-image-picker__choose', function(e){
		e.preventDefault();
		var $picker = $(this).closest('.fh-image-picker');
		var frame = wp.media({
			title: 'Select an image',
			button: { text: 'Use this image' },
			multiple: false,
			library: { type: 'image' }
		});
		frame.on('select', function(){
			var att = frame.state().get('selection').first().toJSON();
			var url = (att.sizes && att.sizes.medium) ? att.sizes.medium.url : att.url;
			$picker.find('.fh-image-picker__id').val(att.id);
			$picker.find('.fh-image-picker__preview img').attr('src', url);
			$picker.find('.fh-image-picker__preview').prop('hidden', false);
			$picker.find('.fh-image-picker__remove').prop('hidden', false);
			$picker.find('.fh-image-picker__choose').text('Replace Image');
		});
		frame.open();
	});
	$(document).on('click', '.fh-image-picker__remove', function(e){
		e.preventDefault();
		var $picker = $(this).closest('.fh-image-picker');
		$picker.find('.fh-image-picker__id').val('');
		$picker.find('.fh-image-picker__preview img').attr('src', '');
		$picker.find('.fh-image-picker__preview').prop('hidden', true);
		$picker.find('.fh-image-picker__choose').text('Choose Image');
		$(this).prop('hidden', true);
	});
})(jQuery);
JS;
	}

	public static function admin_inline_css() {
		return '
		.fh-faq-stages { display: flex; flex-direction: column; gap: 16px; max-width: 860px; }
		.fh-faq-stage { background: #fff; border: 1px solid #ddd; border-left: 3px solid #c9963a; padding: 14px 16px; border-radius: 3px; }
		.fh-faq-stage h4 { margin: 0 0 10px; font-size: 13px; text-transform: uppercase; letter-spacing: 1px; color: #555; }
		.fh-faq-stage .fh-row { display: grid; grid-template-columns: 1fr 1fr; gap: 10px 16px; margin-bottom: 10px; }
		.fh-faq-stage label { display: block; font-weight: 600; font-size: 12px; color: #444; margin-bottom: 4px; }
		.fh-faq-stage input[type=text] { width: 100%; }
		.fh-faq-stage textarea { width: 100%; min-height: 90px; }
		.fh-faq-repeater { max-width: 920px; }
		.fh-faq-list { list-style: none; margin: 0; padding: 0; }
		.fh-faq-row { background: #fff; border: 1px solid #ddd; border-left: 3px solid #c9963a; border-radius: 3px; padding: 12px 14px 14px; margin: 0 0 14px; }
		.fh-faq-row.ui-sortable-helper { box-shadow: 0 6px 18px rgba(0,0,0,.15); }
		.fh-faq-row-head { display: flex; gap: 8px; align-items: center; margin-bottom: 10px; flex-wrap: wrap; }
		.fh-faq-row-head .fh-faq-handle { cursor: move; color: #888; font-size: 18px; line-height: 1; user-select: none; padding: 4px 6px; }
		.fh-faq-row-head .fh-faq-q { flex: 1 1 260px; min-width: 200px; }
		.fh-faq-row-head select { min-width: 130px; }
		.fh-faq-row-head button { cursor: pointer; }
		.fh-faq-row .fh-faq-answer-label { display: block; font-weight: 600; font-size: 12px; color: #444; margin: 6px 0 4px; }
		.fh-faq-actions { margin-top: 10px; }
		.fh-image-picker { max-width: 320px; }
		.fh-image-picker__preview { margin: 0 0 10px; padding: 6px; background: #fff; border: 1px solid #ddd; border-radius: 3px; max-width: 320px; }
		.fh-image-picker__preview img { display: block; max-width: 100%; height: auto; }
		.fh-image-picker__buttons { margin: 0; display: flex; gap: 10px; align-items: center; }
		.fh-ph-library { max-width: 920px; }
		.fh-ph-library__grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(110px, 1fr)); gap: 10px; list-style: none; margin: 0 0 12px; padding: 0; max-width: 760px; }
		.fh-ph-library__item { position: relative; background: #fff; border: 1px solid #ddd; border-radius: 3px; padding: 4px; cursor: move; aspect-ratio: 1 / 1; display: flex; align-items: center; justify-content: center; overflow: hidden; }
		.fh-ph-library__item img { display: block; width: 100%; height: 100%; object-fit: cover; }
		.fh-ph-library__remove { position: absolute; top: 2px; right: 2px; width: 22px; height: 22px; line-height: 20px; padding: 0; border: 1px solid #ccc; background: #fff; border-radius: 50%; cursor: pointer; font-size: 16px; color: #b32d2e; }
		.fh-ph-library__remove:hover { background: #b32d2e; color: #fff; border-color: #b32d2e; }
		.fh-ph-library__placeholder { background: #f3f0e8; border: 1px dashed #c9963a; border-radius: 3px; aspect-ratio: 1 / 1; }
		.fh-tm-repeater { max-width: 920px; }
		.fh-tm-list { list-style: none; margin: 0; padding: 0; }
		.fh-tm-row { background: #fff; border: 1px solid #ddd; border-left: 3px solid #c9963a; border-radius: 3px; padding: 12px 14px 14px; margin: 0 0 14px; }
		.fh-tm-row.ui-sortable-helper { box-shadow: 0 6px 18px rgba(0,0,0,.15); }
		.fh-tm-row-head { display: flex; gap: 8px; align-items: center; margin-bottom: 10px; flex-wrap: wrap; }
		.fh-tm-row-head .fh-tm-handle { cursor: move; color: #888; font-size: 18px; line-height: 1; user-select: none; padding: 4px 6px; }
		.fh-tm-row-head strong { flex: 1 1 auto; font-size: 12px; text-transform: uppercase; letter-spacing: 1px; color: #555; }
		.fh-tm-label { display: block; font-weight: 600; font-size: 12px; color: #444; margin: 6px 0 10px; }
		.fh-tm-optional { font-weight: 400; color: #999; }
		.fh-tm-row textarea { width: 100%; }
		.fh-tm-row input[type=text] { width: 100%; }
		.fh-tm-meta { display: grid; grid-template-columns: 1fr 1fr; gap: 10px 16px; }
		.fh-dash-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 14px; max-width: 980px; margin-top: 18px; }
		.fh-dash-card { display: flex; flex-direction: column; gap: 6px; padding: 16px 18px; background: #fff; border: 1px solid #ddd; border-left: 3px solid #c9963a; border-radius: 3px; text-decoration: none; color: inherit; transition: box-shadow .15s ease, transform .15s ease; }
		.fh-dash-card:hover { box-shadow: 0 4px 14px rgba(0,0,0,.08); transform: translateY(-1px); }
		.fh-dash-card strong { font-size: 14px; color: #1d2327; }
		.fh-dash-card span { font-size: 12px; color: #666; line-height: 1.45; }
		';
	}

	public static function add_page() {
		$pages = self::pages();

		// Top-level menu — uses the same slug as the Dashboard sub-page so
		// add_submenu_page() with that slug renames the first child entry
		// from "FisHotel Theme" to "Dashboard".
		add_menu_page(
			'FisHotel Theme',
			'FisHotel Theme',
			'manage_options',
			self::PARENT_SLUG,
			[ __CLASS__, 'render_dashboard' ],
			'dashicons-admin-customizer',
			57 // Just below WooCommerce / Products.
		);

		foreach ( $pages as $slug => $config ) {
			if ( isset( $config['render'] ) ) {
				$callback = [ __CLASS__, $config['render'] ];
			} else {
				$callback = function () use ( $slug, $config ) {
					self::render_settings_subpage( $slug, $config['page_title'] );
				};
			}
			add_submenu_page(
				self::PARENT_SLUG,
				$config['page_title'],
				$config['menu_title'],
				'manage_options',
				$slug,
				$callback
			);
		}
	}

	/**
	 * Section definitions, keyed by section id, each tagged with the
	 * sub-page slug it belongs to. register_settings() and the sub-page
	 * renderer share this map so adding a field only touches one place.
	 */
	public static function sections() {
		return [
			// Homepage page
			'home' => [
				'page'   => 'fishotel-homepage',
				'title'  => 'Homepage',
				'fields' => [
					'fh_home_available_count' => [
						'label'       => 'Available Now — products to show',
						'type'        => 'number',
						'min'         => 1,
						'max'         => 24,
						'placeholder' => '8',
						'description' => "How many fish to display in the homepage \"Available Now\" section. Set higher to show more of the catalog up front, lower for a curated teaser.",
					],
				],
			],
			'home_testimonials' => [
				'page'   => 'fishotel-homepage',
				'title'  => 'Homepage Testimonials',
				'fields' => [
					'fh_home_testimonials' => [
						'label'       => 'Testimonials',
						'type'        => 'testimonials_repeater',
						'description' => "Quotes that appear in the homepage testimonial banner. With one entry, it displays static. With two or more, they auto-rotate.",
					],
				],
			],
			// Shop page
			'shop' => [
				'page'   => 'fishotel-shop',
				'title'  => 'Shop Page',
				'fields' => [
					'fh_shop_display'     => [ 'label' => 'Shop page display',    'type' => 'select', 'options' => [ 'categories' => 'Categories', 'products' => 'Products', 'both' => 'Both' ] ],
					'fh_shop_hide_empty'  => [ 'label' => 'Hide empty categories', 'type' => 'checkbox' ],
					'fh_shop_hidden_cats' => [ 'label' => 'Hidden categories',     'type' => 'multicheck', 'description' => 'Checked categories will never appear on the shop page.' ],
				],
			],
			// Product page
			'qt' => [
				'page'   => 'fishotel-product',
				'title'  => 'QT Certificate',
				'fields' => [
					'fh_qt_line_1' => [ 'label' => 'Protocol line 1', 'type' => 'text', 'placeholder' => '14 days observation' ],
					'fh_qt_line_2' => [ 'label' => 'Protocol line 2', 'type' => 'text', 'placeholder' => '+ 14 days treatment' ],
				],
			],
			'trust' => [
				'page'   => 'fishotel-product',
				'title'  => 'Fish Trust Strip (below Add to Cart)',
				'fields' => [
					'fh_trust_1' => [ 'label' => 'Trust item 1', 'type' => 'text', 'placeholder' => '28-day QT protocol' ],
					'fh_trust_2' => [ 'label' => 'Trust item 2', 'type' => 'text', 'placeholder' => 'Live arrival guarantee' ],
					'fh_trust_3' => [ 'label' => 'Trust item 3', 'type' => 'text', 'placeholder' => 'Ships Mon–Tue' ],
				],
			],
			'med_trust' => [
				'page'   => 'fishotel-product',
				'title'  => 'Medication Trust Strip (below Add to Cart)',
				'fields' => [
					'fh_med_trust_1' => [ 'label' => 'Trust item 1', 'type' => 'text', 'placeholder' => 'The same meds we use in QT' ],
					'fh_med_trust_2' => [ 'label' => 'Trust item 2', 'type' => 'text', 'placeholder' => 'Trusted partner fulfillment' ],
					'fh_med_trust_3' => [ 'label' => 'Trust item 3', 'type' => 'text', 'placeholder' => 'Ships in 1-2 business days' ],
				],
			],
			'food_trust' => [
				'page'   => 'fishotel-product',
				'title'  => 'Food Trust Strip (below Add to Cart)',
				'fields' => [
					'fh_food_trust_1' => [ 'label' => 'Trust item 1', 'type' => 'text', 'placeholder' => 'The same foods we feed in-house' ],
					'fh_food_trust_2' => [ 'label' => 'Trust item 2', 'type' => 'text', 'placeholder' => 'Trusted partner fulfillment' ],
					'fh_food_trust_3' => [ 'label' => 'Trust item 3', 'type' => 'text', 'placeholder' => 'Ships in 1-2 business days' ],
				],
			],
			'tier2_trust' => [
				'page'   => 'fishotel-product',
				'title'  => 'Tier 2 / Amazon-Affiliate Trust Strip (below Buy on Amazon)',
				'fields' => [
					'fh_tier2_trust_1' => [ 'label' => 'Trust item 1', 'type' => 'text', 'placeholder' => 'The same meds we use in QT' ],
					'fh_tier2_trust_2' => [ 'label' => 'Trust item 2', 'type' => 'text', 'placeholder' => 'Sourced via Amazon for best pricing' ],
					'fh_tier2_trust_3' => [ 'label' => 'Trust item 3', 'type' => 'text', 'placeholder' => 'Reef-keeper favorite for decades' ],
				],
			],
			'pharmacy_pantry_badge' => [
				'page'   => 'fishotel-product',
				'title'  => 'Pharmacy / Pantry Badge (above Price)',
				'fields' => [
					'fh_pharmacy_badge_subtitle' => [ 'label' => 'Pharmacy badge subtitle', 'type' => 'text', 'placeholder' => 'Sourced direct · Therapeutic-grade', 'description' => 'Shown beneath "FROM THE PHARMACY" on medication + Amazon-affiliate product pages.' ],
					'fh_pantry_badge_subtitle'   => [ 'label' => 'Pantry badge subtitle',   'type' => 'text', 'placeholder' => 'Sourced direct · Premium grade',     'description' => 'Shown beneath "FROM THE PANTRY" on food product pages.' ],
				],
			],
			'care' => [
				'page'   => 'fishotel-product',
				'title'  => 'Care Guide Defaults',
				'fields' => [
					'fh_default_foods'   => [ 'label' => 'Default Foods & Feeding text',    'type' => 'textarea', 'description' => 'Shown when a product has no Foods & Feeding custom field.' ],
					'fh_default_habitat' => [ 'label' => 'Default Habitat & Behavior text', 'type' => 'textarea', 'description' => 'Shown when a product has no Habitat & Behavior custom field.' ],
				],
			],
			// FAQ page
			'faq' => [
				'page'   => 'fishotel-faq',
				'title'  => 'FAQ Page',
				'fields' => [
					'faq_concierge_label'         => [ 'label' => 'Concierge eyebrow',         'type' => 'text',     'placeholder' => 'The Concierge Desk' ],
					'faq_page_title'              => [ 'label' => 'Page title',                'type' => 'text',     'placeholder' => 'Frequently Asked Questions' ],
					'faq_page_intro'              => [ 'label' => 'Page intro (optional)',     'type' => 'textarea', 'placeholder' => 'Optional short paragraph under the title.' ],
					'quarantine_section_title'    => [ 'label' => 'Quarantine section title',  'type' => 'text',     'placeholder' => 'A Stay at The FisHotel' ],
					'quarantine_section_subtitle' => [ 'label' => 'Quarantine section subtitle','type' => 'text',    'placeholder' => 'How does a stay at The FisHotel work?' ],
					'fh_quarantine_stages'        => [ 'label' => 'Quarantine stages (5)',     'type' => 'stages_fixed', 'description' => 'Five fixed stages of the quarantine timeline. Blank fields revert to the default copy.' ],
					'fh_faq_items'                => [ 'label' => 'FAQ items',                 'type' => 'repeater_wysiwyg', 'description' => 'Drag rows to reorder. Answers support bullet lists, bold, and links.' ],
				],
			],
			// About page
			'about' => [
				'page'   => 'fishotel-about',
				'title'  => "About Page (Founder's Edition)",
				'fields' => [
					'about_masthead'      => [ 'label' => 'Masthead',         'type' => 'text',     'placeholder' => 'THE FISHOTEL GAZETTE' ],
					'about_edition_line'  => [ 'label' => 'Edition line',     'type' => 'text',     'placeholder' => "FOUNDER'S EDITION · EST. 1923 · ONE FISH CENT" ],
					'about_dateline'      => [ 'label' => 'Dateline',         'type' => 'text',     'placeholder' => 'BLAINE, MINNESOTA' ],
					'about_headline'      => [ 'label' => 'Headline',         'type' => 'text',     'placeholder' => 'From a Walkout Closet to 1,000 Gallons' ],
					'about_dek'           => [ 'label' => 'Dek (subhead)',    'type' => 'textarea', 'placeholder' => 'How one stubborn Minnesotan brought the ocean home…' ],
					'about_byline'        => [ 'label' => 'Byline',           'type' => 'text',     'placeholder' => 'By Jeff Dierks · Founder, The FisHotel' ],
					'about_pull_quote'    => [ 'label' => 'Pull quote',       'type' => 'textarea', 'description' => 'Large centered quotation that breaks the columns.' ],
					'about_body'          => [ 'label' => 'Article body',     'type' => 'wysiwyg',  'description' => 'Use H2 for section heads. Each paragraph in <p> tags. Links and emphasis welcome.' ],
					'about_signoff'       => [ 'label' => 'Sign-off',         'type' => 'text',     'placeholder' => '— J.D.' ],
					'about_footer_line'   => [ 'label' => 'Footer line',      'type' => 'text',     'placeholder' => 'Published by The FisHotel · Est. 2019 · Champlin & Blaine, MN' ],
					// Photos — each slot is a media-library picker + caption + credit.
					// Slots gracefully hide when no image is attached.
					'about_hero_image'             => [ 'label' => 'Hero image',                  'type' => 'image',    'description' => 'Full-width photo between byline and the first section. Hidden when empty.' ],
					'about_hero_caption'           => [ 'label' => 'Hero caption',                'type' => 'text',     'placeholder' => 'Jeff at the FisHotel quarantine shop' ],
					'about_hero_credit'            => [ 'label' => 'Hero credit',                 'type' => 'text',     'placeholder' => 'STAFF PHOTO' ],
					'about_inline_image_1'         => [ 'label' => 'Inline image #1',             'type' => 'image',    'description' => 'Appears between section 1 and section 2, breaking the column flow.' ],
					'about_inline_image_1_caption' => [ 'label' => 'Inline image #1 caption',     'type' => 'text' ],
					'about_inline_image_1_credit'  => [ 'label' => 'Inline image #1 credit',      'type' => 'text',     'placeholder' => 'ILLUSTRATION' ],
					'about_inline_image_2'         => [ 'label' => 'Inline image #2',             'type' => 'image',    'description' => 'Appears between section 3 and section 4, breaking the column flow.' ],
					'about_inline_image_2_caption' => [ 'label' => 'Inline image #2 caption',     'type' => 'text' ],
					'about_inline_image_2_credit'  => [ 'label' => 'Inline image #2 credit',      'type' => 'text',     'placeholder' => 'ILLUSTRATION' ],
				],
			],
			// Contacts page
			'contacts' => [
				'page'   => 'fishotel-contacts',
				'title'  => 'Contacts Page',
				'fields' => [
					'contacts_eyebrow'            => [ 'label' => 'Eyebrow',              'type' => 'text',     'placeholder' => 'FRONT DESK' ],
					'contacts_heading'            => [ 'label' => 'Heading',              'type' => 'text',     'placeholder' => 'Contact' ],
					'contacts_location_text'     => [ 'label' => 'Location text',        'type' => 'text',     'placeholder' => 'Champlin, MN 55316' ],
					'contacts_location_map_image' => [ 'label' => 'Location map image',   'type' => 'image',    'description' => 'Decorative vintage-style map shown above the city text. Hidden when empty.' ],
					'contacts_email'              => [ 'label' => 'Email address',        'type' => 'text',     'placeholder' => 'Jeff@FisHotel.com' ],
					'contacts_email_label'        => [ 'label' => 'Email link label',     'type' => 'text',     'placeholder' => 'Write us' ],
					'contacts_forum_url'          => [ 'label' => 'Forum URL',            'type' => 'text',     'placeholder' => 'https://humble.fish/...' ],
					'contacts_forum_label'        => [ 'label' => 'Forum link label',     'type' => 'text',     'placeholder' => 'Visit our Humble.Fish forum' ],
				],
			],
			// Placeholders page
			'placeholders' => [
				'page'   => 'fishotel-placeholders',
				'title'  => 'Product Image Placeholder Library',
				'fields' => [
					'fh_placeholder_library' => [
						'label'       => 'Placeholder images',
						'type'        => 'placeholder_library',
						'description' => 'Images here stand in whenever a product has no featured image. Drag thumbnails to reorder, click × to remove. Each product gets a stable assignment that resets the next time it goes out of stock.',
					],
				],
			],
			'placeholders_cs' => [
				'page'   => 'fishotel-placeholders',
				'title'  => 'Coming Soon Placeholder Library',
				'fields' => [
					'fh_cs_placeholder_library' => [
						'label'       => 'Coming Soon placeholder images',
						'type'        => 'placeholder_library',
						'description' => 'Images here stand in whenever a Coming Soon product has no featured image. Drag thumbnails to reorder. Each Coming Soon product gets a stable assignment until its release date passes.',
					],
				],
			],
			// Branding page
			'branding' => [
				'page'   => 'fishotel-branding',
				'title'  => 'Branding',
				'fields' => [
					'fh_tagline' => [ 'label' => 'Logo tagline', 'type' => 'text', 'placeholder' => 'We quarantine. You reef.' ],
				],
			],
		];
	}

	public static function register_settings() {
		foreach ( self::sections() as $section_id => $section ) {
			$group = self::group_for_page( $section['page'] );

			add_settings_section(
				'fh_section_' . $section_id,
				$section['title'],
				'__return_null',
				$section['page']
			);

			foreach ( $section['fields'] as $key => $field ) {
				if ( $field['type'] === 'multicheck' ) {
					register_setting( $group, $key, [
						'type'              => 'array',
						'sanitize_callback' => function( $val ) {
							if ( ! is_array( $val ) ) return [];
							return array_map( 'sanitize_text_field', $val );
						},
						'default'           => [],
					] );
				} elseif ( $field['type'] === 'stages_fixed' ) {
					register_setting( $group, $key, [
						'type'              => 'array',
						'sanitize_callback' => [ __CLASS__, 'sanitize_stages' ],
						'default'           => self::default_quarantine_stages(),
					] );
				} elseif ( $field['type'] === 'repeater_wysiwyg' ) {
					register_setting( $group, $key, [
						'type'              => 'array',
						'sanitize_callback' => [ __CLASS__, 'sanitize_faq_items' ],
						'default'           => self::default_faq_items(),
					] );
				} elseif ( $field['type'] === 'testimonials_repeater' ) {
					register_setting( $group, $key, [
						'type'              => 'array',
						'sanitize_callback' => [ __CLASS__, 'sanitize_home_testimonials' ],
						'default'           => self::default_home_testimonials(),
					] );
				} elseif ( $field['type'] === 'wysiwyg' ) {
					register_setting( $group, $key, [
						'type'              => 'string',
						'sanitize_callback' => 'wp_kses_post',
						'default'           => self::defaults()[ $key ] ?? '',
					] );
				} elseif ( $field['type'] === 'image' ) {
					register_setting( $group, $key, [
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
						'default'           => 0,
					] );
				} elseif ( $field['type'] === 'placeholder_library' ) {
					register_setting( $group, $key, [
						'type'              => 'array',
						'sanitize_callback' => [ __CLASS__, 'sanitize_id_list' ],
						'default'           => [],
					] );
				} elseif ( $field['type'] === 'number' ) {
					$min = isset( $field['min'] ) ? (int) $field['min'] : null;
					$max = isset( $field['max'] ) ? (int) $field['max'] : null;
					register_setting( $group, $key, [
						'type'              => 'integer',
						'sanitize_callback' => function ( $val ) use ( $min, $max, $key ) {
							$n = (int) $val;
							if ( $min !== null && $n < $min ) $n = $min;
							if ( $max !== null && $n > $max ) $n = $max;
							return $n;
						},
						'default'           => self::defaults()[ $key ] ?? 0,
					] );
				} else {
					// text | textarea | select | checkbox. Same string type,
					// but text/textarea get a defensive preserve-on-empty
					// sanitizer so an accidental empty POST (e.g. a future
					// refactor that drops a field from the form HTML while
					// the option is still registered) can no longer wipe
					// stored copy. Select + checkbox pass empty through
					// normally — unchecking a checkbox MUST persist.
					$type        = $field['type'];
					$is_textarea = ( $type === 'textarea' );
					$preserve    = in_array( $type, [ 'text', 'textarea' ], true );
					register_setting( $group, $key, [
						'type'              => 'string',
						'sanitize_callback' => function ( $val ) use ( $key, $is_textarea, $preserve ) {
							$sanitized = $is_textarea
								? sanitize_textarea_field( (string) $val )
								: sanitize_text_field( (string) $val );
							if ( $preserve && trim( $sanitized ) === '' ) {
								$existing = get_option( $key, null );
								if ( is_string( $existing ) && trim( $existing ) !== '' ) {
									return $existing;
								}
							}
							return $sanitized;
						},
						'default'           => self::defaults()[ $key ] ?? '',
					] );
				}

				add_settings_field(
					$key,
					$field['label'],
					[ __CLASS__, 'render_field' ],
					$section['page'],
					'fh_section_' . $section_id,
					array_merge( $field, [ 'key' => $key ] )
				);
			}
		}
	}

	public static function sanitize_stages( $val ) {
		$defaults = self::default_quarantine_stages();
		$out = [];
		for ( $i = 0; $i < 5; $i++ ) {
			$row = ( is_array( $val ) && isset( $val[ $i ] ) && is_array( $val[ $i ] ) ) ? $val[ $i ] : [];
			$out[ $i ] = [
				'label'       => isset( $row['label'] )       ? sanitize_text_field( $row['label'] )           : $defaults[ $i ]['label'],
				'sublabel'    => isset( $row['sublabel'] )    ? sanitize_text_field( $row['sublabel'] )        : $defaults[ $i ]['sublabel'],
				'duration'    => isset( $row['duration'] )    ? sanitize_text_field( $row['duration'] )        : $defaults[ $i ]['duration'],
				'description' => isset( $row['description'] ) ? sanitize_textarea_field( $row['description'] ) : $defaults[ $i ]['description'],
				'if_needed'   => ! empty( $row['if_needed'] ),
			];
		}
		return $out;
	}

	/** Accepts an array or comma-separated string of attachment IDs. */
	public static function sanitize_id_list( $val ) {
		if ( is_string( $val ) ) {
			$val = array_map( 'trim', explode( ',', $val ) );
		}
		if ( ! is_array( $val ) ) {
			return [];
		}
		$out = [];
		foreach ( $val as $id ) {
			$id = (int) $id;
			if ( $id > 0 && ! in_array( $id, $out, true ) ) {
				$out[] = $id;
			}
		}
		return $out;
	}

	public static function sanitize_home_testimonials( $val ) {
		if ( ! is_array( $val ) ) return [];
		$out = [];
		foreach ( $val as $row ) {
			if ( ! is_array( $row ) ) continue;
			$quote  = isset( $row['quote'] )  ? trim( wp_kses_post( $row['quote'] ) ) : '';
			$author = isset( $row['author'] ) ? sanitize_text_field( $row['author'] ) : '';
			$source = isset( $row['source'] ) ? sanitize_text_field( $row['source'] ) : '';
			if ( $quote === '' ) continue; // require quote text
			$out[] = [ 'quote' => $quote, 'author' => $author, 'source' => $source ];
		}
		return $out;
	}

	public static function sanitize_faq_items( $val ) {
		if ( ! is_array( $val ) ) return [];
		$categories = array_keys( self::faq_categories() );
		$out = [];
		foreach ( $val as $row ) {
			if ( ! is_array( $row ) ) continue;
			$question = isset( $row['question'] ) ? sanitize_text_field( $row['question'] ) : '';
			$answer   = isset( $row['answer'] )   ? wp_kses_post( $row['answer'] )          : '';
			$category = isset( $row['category'] ) && in_array( $row['category'], $categories, true ) ? $row['category'] : 'General';
			if ( $question === '' && trim( wp_strip_all_tags( $answer ) ) === '' ) continue; // drop fully empty rows
			$out[] = [
				'question' => $question,
				'answer'   => $answer,
				'category' => $category,
			];
		}
		return $out;
	}

	public static function render_field( $args ) {
		$key   = $args['key'];
		$type  = $args['type'];
		$value = self::get( $key );

		if ( $type === 'text' ) {
			printf(
				'<input type="text" name="%s" value="%s" class="regular-text" placeholder="%s">',
				esc_attr( $key ),
				esc_attr( $value ),
				esc_attr( $args['placeholder'] ?? '' )
			);
		} elseif ( $type === 'number' ) {
			printf(
				'<input type="number" name="%s" value="%s" class="small-text" placeholder="%s"%s%s%s>',
				esc_attr( $key ),
				esc_attr( $value ),
				esc_attr( $args['placeholder'] ?? '' ),
				isset( $args['min'] )  ? ' min="'  . esc_attr( (int) $args['min'] )  . '"' : '',
				isset( $args['max'] )  ? ' max="'  . esc_attr( (int) $args['max'] )  . '"' : '',
				isset( $args['step'] ) ? ' step="' . esc_attr( (int) $args['step'] ) . '"' : ' step="1"'
			);
		} elseif ( $type === 'textarea' ) {
			printf(
				'<textarea name="%s" rows="3" class="large-text" placeholder="%s">%s</textarea>',
				esc_attr( $key ),
				esc_attr( $args['placeholder'] ?? '' ),
				esc_textarea( $value )
			);
		} elseif ( $type === 'select' ) {
			echo '<select name="' . esc_attr( $key ) . '">';
			foreach ( $args['options'] as $opt_val => $opt_label ) {
				printf(
					'<option value="%s" %s>%s</option>',
					esc_attr( $opt_val ),
					selected( $value, $opt_val, false ),
					esc_html( $opt_label )
				);
			}
			echo '</select>';
		} elseif ( $type === 'checkbox' ) {
			printf(
				'<label><input type="checkbox" name="%s" value="1" %s> Yes</label>',
				esc_attr( $key ),
				checked( $value, '1', false )
			);
		} elseif ( $type === 'multicheck' ) {
			$saved = get_option( $key, [] );
			if ( ! is_array( $saved ) ) $saved = [];
			$all_cats = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => false, 'orderby' => 'name' ] );
			if ( $all_cats && ! is_wp_error( $all_cats ) ) {
				echo '<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:6px 20px;max-width:620px;">';
				foreach ( $all_cats as $cat ) {
					printf(
						'<label style="display:flex;align-items:center;gap:6px;white-space:nowrap;"><input type="checkbox" name="%s[]" value="%s" %s> %s</label>',
						esc_attr( $key ),
						esc_attr( $cat->slug ),
						checked( in_array( $cat->slug, $saved, true ), true, false ),
						esc_html( $cat->name )
					);
				}
				echo '</div>';
			}
		} elseif ( $type === 'stages_fixed' ) {
			$stages = self::get_quarantine_stages();
			echo '<div class="fh-faq-stages">';
			foreach ( $stages as $i => $stage ) {
				$n = $i + 1;
				echo '<div class="fh-faq-stage">';
				echo '<h4>Stage ' . esc_html( $n ) . '</h4>';
				echo '<div class="fh-row">';
				printf(
					'<div><label>Label</label><input type="text" name="%1$s[%2$d][label]" value="%3$s"></div>',
					esc_attr( $key ), (int) $i, esc_attr( $stage['label'] )
				);
				printf(
					'<div><label>Sublabel</label><input type="text" name="%1$s[%2$d][sublabel]" value="%3$s"></div>',
					esc_attr( $key ), (int) $i, esc_attr( $stage['sublabel'] )
				);
				echo '</div>';
				printf(
					'<label>Duration</label><input type="text" name="%1$s[%2$d][duration]" value="%3$s" style="max-width:280px;">',
					esc_attr( $key ), (int) $i, esc_attr( $stage['duration'] )
				);
				printf(
					'<label style="margin-top:10px;">Description</label><textarea name="%1$s[%2$d][description]">%3$s</textarea>',
					esc_attr( $key ), (int) $i, esc_textarea( $stage['description'] )
				);
				printf(
					'<label style="margin-top:10px; display:flex; align-items:center; gap:6px; font-weight:600;"><input type="checkbox" name="%1$s[%2$d][if_needed]" value="1" %3$s> Show <em>(if needed)</em> tag on this stage</label>',
					esc_attr( $key ),
					(int) $i,
					checked( ! empty( $stage['if_needed'] ), true, false )
				);
				echo '</div>';
			}
			echo '</div>';
		} elseif ( $type === 'image' ) {
			$attachment_id = (int) $value;
			$preview_url   = $attachment_id ? wp_get_attachment_image_url( $attachment_id, 'medium' ) : '';
			?>
			<div class="fh-image-picker">
				<input type="hidden" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $attachment_id ); ?>" class="fh-image-picker__id">
				<div class="fh-image-picker__preview"<?php echo $preview_url ? '' : ' hidden'; ?>>
					<img src="<?php echo esc_url( $preview_url ); ?>" alt="">
				</div>
				<p class="fh-image-picker__buttons">
					<button type="button" class="button fh-image-picker__choose"><?php echo $preview_url ? 'Replace Image' : 'Choose Image'; ?></button>
					<button type="button" class="button-link button-link-delete fh-image-picker__remove"<?php echo $preview_url ? '' : ' hidden'; ?>>Remove</button>
				</p>
			</div>
			<?php
		} elseif ( $type === 'wysiwyg' ) {
			$content = ( $key === 'about_body' ) ? self::get_about_body() : $value;
			wp_editor(
				$content,
				$key,
				[
					'textarea_name' => $key,
					'textarea_rows' => 18,
					'media_buttons' => false,
					'tinymce'       => [
						'toolbar1' => 'formatselect,bold,italic,underline,bullist,numlist,blockquote,link,unlink,undo,redo',
						'toolbar2' => '',
						'block_formats' => 'Paragraph=p;Section heading=h2;Sub heading=h3',
					],
					'quicktags'     => true,
				]
			);
		} elseif ( $type === 'placeholder_library' ) {
			$ids = get_option( $key, [] );
			if ( ! is_array( $ids ) ) $ids = [];
			$csv = implode( ',', array_map( 'intval', $ids ) );
			?>
			<div class="fh-ph-library" data-name="<?php echo esc_attr( $key ); ?>">
				<input type="hidden" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $csv ); ?>" class="fh-ph-library__input">
				<ul class="fh-ph-library__grid">
					<?php foreach ( $ids as $id ) :
						$id  = (int) $id;
						$url = $id ? wp_get_attachment_image_url( $id, 'thumbnail' ) : '';
						if ( ! $url ) continue;
					?>
						<li class="fh-ph-library__item" data-id="<?php echo esc_attr( $id ); ?>">
							<img src="<?php echo esc_url( $url ); ?>" alt="">
							<button type="button" class="fh-ph-library__remove" aria-label="Remove">&times;</button>
						</li>
					<?php endforeach; ?>
				</ul>
				<p>
					<button type="button" class="button button-secondary fh-ph-library__add">+ Add Images</button>
				</p>
			</div>
			<?php
		} elseif ( $type === 'repeater_wysiwyg' ) {
			$items = self::get_faq_items();
			$cats  = self::faq_categories();
			echo '<div class="fh-faq-repeater">';
			echo '<ol class="fh-faq-list" id="fh-faq-list">';
			foreach ( $items as $i => $item ) {
				self::render_faq_row( $key, $i, $item, $cats );
			}
			echo '</ol>';
			echo '<div class="fh-faq-actions"><button type="button" class="button button-secondary" id="fh-faq-add">+ Add FAQ</button></div>';

			// Hidden template row (used by JS to clone new items). __INDEX__ placeholder is replaced at clone time.
			echo '<script type="text/template" id="fh-faq-row-template">';
			self::render_faq_row( $key, '__INDEX__', [ 'question' => '', 'answer' => '', 'category' => 'General' ], $cats, true );
			echo '</script>';
			echo '</div>';
		} elseif ( $type === 'testimonials_repeater' ) {
			$items = self::get_home_testimonials();
			echo '<div class="fh-tm-repeater" data-name="' . esc_attr( $key ) . '">';
			echo '<ol class="fh-tm-list">';
			foreach ( $items as $i => $item ) {
				self::render_testimonial_row( $key, $i, $item );
			}
			echo '</ol>';
			echo '<p><button type="button" class="button button-secondary fh-tm-add">+ Add Testimonial</button></p>';
			echo '<script type="text/template" class="fh-tm-row-template">';
			self::render_testimonial_row( $key, '__INDEX__', [ 'quote' => '', 'author' => '', 'source' => '' ], true );
			echo '</script>';
			echo '</div>';
		}

		if ( ! empty( $args['description'] ) ) {
			echo '<p class="description">' . esc_html( $args['description'] ) . '</p>';
		}
	}

	/** Render a single FAQ repeater row. $is_template=true uses __INDEX__ placeholder for JS cloning. */
	protected static function render_faq_row( $key, $index, $item, $cats, $is_template = false ) {
		$idx_attr = $is_template ? '__INDEX__' : (int) $index;
		$editor_id = 'fh_faq_answer_' . ( $is_template ? '__INDEX__' : (int) $index );
		?>
		<li class="fh-faq-row" data-index="<?php echo esc_attr( $idx_attr ); ?>">
			<div class="fh-faq-row-head">
				<span class="fh-faq-handle" title="Drag to reorder">&#8597;</span>
				<input type="text" class="fh-faq-q" name="<?php echo esc_attr( $key ); ?>[<?php echo esc_attr( $idx_attr ); ?>][question]" value="<?php echo esc_attr( $item['question'] ); ?>" placeholder="Question">
				<select name="<?php echo esc_attr( $key ); ?>[<?php echo esc_attr( $idx_attr ); ?>][category]">
					<?php foreach ( $cats as $v => $l ) : ?>
						<option value="<?php echo esc_attr( $v ); ?>" <?php selected( $item['category'], $v ); ?>><?php echo esc_html( $l ); ?></option>
					<?php endforeach; ?>
				</select>
				<button type="button" class="button fh-faq-up" title="Move up">&uarr;</button>
				<button type="button" class="button fh-faq-down" title="Move down">&darr;</button>
				<button type="button" class="button button-link-delete fh-faq-delete" title="Delete">Delete</button>
			</div>
			<label class="fh-faq-answer-label" for="<?php echo esc_attr( $editor_id ); ?>">Answer</label>
			<?php
			if ( $is_template ) {
				// Plain textarea in template; JS swaps in wp.editor on clone.
				printf(
					'<textarea id="%1$s" class="fh-faq-answer" name="%2$s[%3$s][answer]" rows="6" style="width:100%%;">%4$s</textarea>',
					esc_attr( $editor_id ),
					esc_attr( $key ),
					esc_attr( $idx_attr ),
					esc_textarea( $item['answer'] )
				);
			} else {
				wp_editor(
					$item['answer'],
					$editor_id,
					[
						'textarea_name' => $key . '[' . (int) $index . '][answer]',
						'textarea_rows' => 6,
						'media_buttons' => false,
						'teeny'         => true,
						'quicktags'     => true,
						'tinymce'       => [
							'toolbar1' => 'bold,italic,underline,bullist,numlist,link,unlink,undo,redo',
							'toolbar2' => '',
						],
					]
				);
			}
			?>
		</li>
		<?php
	}

	/** Render a single homepage-testimonial row. */
	protected static function render_testimonial_row( $key, $index, $item, $is_template = false ) {
		$idx_attr = $is_template ? '__INDEX__' : (int) $index;
		?>
		<li class="fh-tm-row" data-index="<?php echo esc_attr( $idx_attr ); ?>">
			<div class="fh-tm-row-head">
				<span class="fh-tm-handle" title="Drag to reorder">&#8597;</span>
				<strong>Testimonial</strong>
				<button type="button" class="button fh-tm-up" title="Move up">&uarr;</button>
				<button type="button" class="button fh-tm-down" title="Move down">&darr;</button>
				<button type="button" class="button button-link-delete fh-tm-delete" title="Delete">Delete</button>
			</div>
			<label class="fh-tm-label">Quote
				<textarea name="<?php echo esc_attr( $key ); ?>[<?php echo esc_attr( $idx_attr ); ?>][quote]" rows="3" placeholder="Quote text" required><?php echo esc_textarea( $item['quote'] ); ?></textarea>
			</label>
			<div class="fh-tm-meta">
				<label class="fh-tm-label">Author <span class="fh-tm-optional">(optional)</span>
					<input type="text" name="<?php echo esc_attr( $key ); ?>[<?php echo esc_attr( $idx_attr ); ?>][author]" value="<?php echo esc_attr( $item['author'] ); ?>" placeholder="e.g. Mike R.">
				</label>
				<label class="fh-tm-label">Source <span class="fh-tm-optional">(optional)</span>
					<input type="text" name="<?php echo esc_attr( $key ); ?>[<?php echo esc_attr( $idx_attr ); ?>][source]" value="<?php echo esc_attr( $item['source'] ); ?>" placeholder="e.g. Reef2Reef, Humble.Fish">
				</label>
			</div>
		</li>
		<?php
	}

	/**
	 * Generic sub-page renderer used for every Settings-API-backed page.
	 * Each sub-page only renders the sections registered against its own
	 * page slug, keeping forms small and focused.
	 */
	public static function render_settings_subpage( $page_slug, $title ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'fishotel' ) );
		}
		$group = self::group_for_page( $page_slug );
		?>
		<div class="wrap">
			<h1><?php echo esc_html( $title ); ?></h1>
			<?php
			// settings_errors() is auto-emitted on core Settings pages but
			// not on custom add_submenu_page screens like ours, so without
			// this call the "Settings Saved" notice never renders even
			// when a save succeeded. (Phase 3.5.1 root cause #2.)
			settings_errors();
			?>
			<form method="post" action="options.php">
				<?php
				// Per-page group: saving this form only iterates the
				// options registered to $group, so a Product Page save
				// no longer touches Placeholders / Branding / etc.
				settings_fields( $group );
				do_settings_sections( $page_slug );
				submit_button( 'Save Settings' );
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Dashboard landing page — a card grid linking to every sub-page so
	 * the user can scan what's editable without diving in.
	 */
	public static function render_dashboard() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'fishotel' ) );
		}
		$cards = [
			'fishotel-homepage'     => [ 'title' => 'Homepage',      'desc' => 'Available Now count and homepage testimonials.' ],
			'fishotel-shop'         => [ 'title' => 'Shop Page',     'desc' => 'Display mode and which categories to hide.' ],
			'fishotel-product'      => [ 'title' => 'Product Page',  'desc' => 'QT certificate, trust strip, care guide defaults.' ],
			'fishotel-faq'          => [ 'title' => 'FAQ Page',      'desc' => 'Concierge intro, quarantine stages, FAQ items.' ],
			'fishotel-about'        => [ 'title' => 'About Page',    'desc' => "Founder's Edition masthead, headline, byline, body." ],
			'fishotel-contacts'     => [ 'title' => 'Contacts Page', 'desc' => 'Email, forum, location text and map image.' ],
			'fishotel-placeholders' => [ 'title' => 'Placeholders',  'desc' => 'In-stock and Coming Soon placeholder libraries.' ],
			'fishotel-branding'     => [ 'title' => 'Branding',      'desc' => 'Logo tagline and global brand variables.' ],
			'fishotel-tools'        => [ 'title' => 'Tools',         'desc' => 'Theme update check, product migration, compat backfill.' ],
		];
		?>
		<div class="wrap">
			<h1>FisHotel Theme</h1>
			<p style="color:#666; max-width:760px;">Theme-side content and configuration, organized by where it appears on the site. Pick a section below to edit.</p>
			<div class="fh-dash-grid">
				<?php foreach ( $cards as $slug => $card ) : ?>
					<a class="fh-dash-card" href="<?php echo esc_url( admin_url( 'admin.php?page=' . $slug ) ); ?>">
						<strong><?php echo esc_html( $card['title'] ); ?></strong>
						<span><?php echo esc_html( $card['desc'] ); ?></span>
					</a>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Tools sub-page — theme update check, product description migration,
	 * and the Compatibility Guide backfill button. Wired up by the host
	 * classes which render their own buttons + handle their own AJAX.
	 */
	public static function render_tools() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'fishotel' ) );
		}
		?>
		<div class="wrap">
			<h1>FisHotel Tools</h1>

			<?php if ( class_exists( 'FisHotel_Hotel_Data' ) ) : ?>
				<?php FisHotel_Hotel_Data::render_tools_panels(); ?>
			<?php endif; ?>

			<?php if ( class_exists( 'FisHotel_Compat_Backfill' ) ) : ?>
				<?php FisHotel_Compat_Backfill::render_button(); ?>
			<?php endif; ?>
		</div>
		<?php
	}
}

FisHotel_Admin_Settings::init();
