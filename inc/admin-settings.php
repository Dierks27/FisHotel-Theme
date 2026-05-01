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
				'duration'    => 'As needed, Days 15–28',
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
			// QT Certificate
			'fh_qt_line_1'                => '14 days observation',
			'fh_qt_line_2'                => '+ 14 days treatment',
			// Trust Strip
			'fh_trust_1'                  => '28-day QT protocol',
			'fh_trust_2'                  => 'Live arrival guarantee',
			'fh_trust_3'                  => 'Ships Mon–Tue',
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
			$out[ $i ] = [
				'label'       => isset( $row['label'] )       && $row['label']       !== '' ? $row['label']       : $defaults[ $i ]['label'],
				'sublabel'    => isset( $row['sublabel'] )    && $row['sublabel']    !== '' ? $row['sublabel']    : $defaults[ $i ]['sublabel'],
				'duration'    => isset( $row['duration'] )    && $row['duration']    !== '' ? $row['duration']    : $defaults[ $i ]['duration'],
				'description' => isset( $row['description'] ) && $row['description'] !== '' ? $row['description'] : $defaults[ $i ]['description'],
			];
		}
		return $out;
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
		return get_option( $key, $defaults[ $key ] ?? '' );
	}

	public static function init() {
		add_action( 'admin_menu', [ __CLASS__, 'add_page' ] );
		add_action( 'admin_init', [ __CLASS__, 'register_settings' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_admin_assets' ] );
	}

	public static function enqueue_admin_assets( $hook ) {
		if ( $hook !== 'product_page_fishotel-settings' ) {
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
		wp_enqueue_media();
		wp_add_inline_script( 'jquery-core', self::image_picker_js() );
		wp_add_inline_style( 'wp-admin', self::admin_inline_css() );
	}

	public static function image_picker_js() {
		return <<<'JS'
(function($){
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
		';
	}

	public static function add_page() {
		add_submenu_page(
			'edit.php?post_type=product',
			'FisHotel Settings',
			'FisHotel Settings',
			'manage_woocommerce',
			'fishotel-settings',
			[ __CLASS__, 'render_page' ]
		);
	}

	public static function register_settings() {
		$fields = [
			// Shop section
			'shop' => [
				'title'  => 'Shop Page',
				'fields' => [
					'fh_shop_display'     => [ 'label' => 'Shop page display',    'type' => 'select', 'options' => [ 'categories' => 'Categories', 'products' => 'Products', 'both' => 'Both' ] ],
					'fh_shop_hide_empty'  => [ 'label' => 'Hide empty categories', 'type' => 'checkbox' ],
					'fh_shop_hidden_cats' => [ 'label' => 'Hidden categories',     'type' => 'multicheck', 'description' => 'Checked categories will never appear on the shop page.' ],
				],
			],
			// QT Certificate section
			'qt' => [
				'title'  => 'QT Certificate',
				'fields' => [
					'fh_qt_line_1' => [ 'label' => 'Protocol line 1', 'type' => 'text', 'placeholder' => '14 days observation' ],
					'fh_qt_line_2' => [ 'label' => 'Protocol line 2', 'type' => 'text', 'placeholder' => '+ 14 days treatment' ],
				],
			],
			// Trust Strip section
			'trust' => [
				'title'  => 'Trust Strip (below Add to Cart)',
				'fields' => [
					'fh_trust_1' => [ 'label' => 'Trust item 1', 'type' => 'text', 'placeholder' => '28-day QT protocol' ],
					'fh_trust_2' => [ 'label' => 'Trust item 2', 'type' => 'text', 'placeholder' => 'Live arrival guarantee' ],
					'fh_trust_3' => [ 'label' => 'Trust item 3', 'type' => 'text', 'placeholder' => 'Ships Mon–Tue' ],
				],
			],
			// Branding section
			'branding' => [
				'title'  => 'Branding',
				'fields' => [
					'fh_tagline' => [ 'label' => 'Logo tagline', 'type' => 'text', 'placeholder' => 'We quarantine. You reef.' ],
				],
			],
			// Care Guide section
			'care' => [
				'title'  => 'Care Guide Defaults',
				'fields' => [
					'fh_default_foods'   => [ 'label' => 'Default Foods & Feeding text',    'type' => 'textarea', 'description' => 'Shown when a product has no Foods & Feeding custom field.' ],
					'fh_default_habitat' => [ 'label' => 'Default Habitat & Behavior text', 'type' => 'textarea', 'description' => 'Shown when a product has no Habitat & Behavior custom field.' ],
				],
			],
			// FAQ Page section
			'faq' => [
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
			// About Page section
			'about' => [
				'title'  => 'About Page (Founder\'s Edition)',
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
		];

		foreach ( $fields as $section_id => $section ) {
			add_settings_section(
				'fh_section_' . $section_id,
				$section['title'],
				'__return_null',
				self::OPTION_GROUP
			);

			foreach ( $section['fields'] as $key => $field ) {
				if ( $field['type'] === 'multicheck' ) {
					register_setting( self::OPTION_GROUP, $key, [
						'type'              => 'array',
						'sanitize_callback' => function( $val ) {
							if ( ! is_array( $val ) ) return [];
							return array_map( 'sanitize_text_field', $val );
						},
						'default'           => [],
					] );
				} elseif ( $field['type'] === 'stages_fixed' ) {
					register_setting( self::OPTION_GROUP, $key, [
						'type'              => 'array',
						'sanitize_callback' => [ __CLASS__, 'sanitize_stages' ],
						'default'           => self::default_quarantine_stages(),
					] );
				} elseif ( $field['type'] === 'repeater_wysiwyg' ) {
					register_setting( self::OPTION_GROUP, $key, [
						'type'              => 'array',
						'sanitize_callback' => [ __CLASS__, 'sanitize_faq_items' ],
						'default'           => self::default_faq_items(),
					] );
				} elseif ( $field['type'] === 'wysiwyg' ) {
					register_setting( self::OPTION_GROUP, $key, [
						'type'              => 'string',
						'sanitize_callback' => 'wp_kses_post',
						'default'           => self::defaults()[ $key ] ?? '',
					] );
				} elseif ( $field['type'] === 'image' ) {
					register_setting( self::OPTION_GROUP, $key, [
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
						'default'           => 0,
					] );
				} else {
					register_setting( self::OPTION_GROUP, $key, [
						'type'              => 'string',
						'sanitize_callback' => $field['type'] === 'textarea' ? 'sanitize_textarea_field' : 'sanitize_text_field',
						'default'           => self::defaults()[ $key ] ?? '',
					] );
				}

				add_settings_field(
					$key,
					$field['label'],
					[ __CLASS__, 'render_field' ],
					self::OPTION_GROUP,
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
			];
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

	public static function render_page() {
		?>
		<div class="wrap">
			<h1>FisHotel Settings</h1>
			<p style="color:#666; margin-bottom:20px;">Every value shown on the product page and shop. Change it here, see it live.</p>
			<form method="post" action="options.php">
				<?php
				settings_fields( self::OPTION_GROUP );
				do_settings_sections( self::OPTION_GROUP );
				submit_button( 'Save Settings' );
				?>
			</form>
		</div>
		<?php
	}
}

FisHotel_Admin_Settings::init();
