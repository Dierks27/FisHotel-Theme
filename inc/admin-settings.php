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

	/** All settings with defaults */
	public static function defaults() {
		return [
			// Shop
			'fh_shop_display'        => 'categories',
			'fh_shop_hide_empty'     => '1',
			'fh_shop_hidden_cats'    => [],
			// QT Certificate
			'fh_qt_line_1'           => '14 days observation',
			'fh_qt_line_2'           => '+ 14 days treatment',
			// Trust Strip
			'fh_trust_1'             => '28-day QT protocol',
			'fh_trust_2'             => 'Live arrival guarantee',
			'fh_trust_3'             => 'Ships Mon–Tue',
			// Branding
			'fh_tagline'             => 'We quarantine. You reef.',
			// Care Guide Defaults
			'fh_default_foods'       => '',
			'fh_default_habitat'     => '',
		];
	}

	/** Get a setting with its default fallback */
	public static function get( $key ) {
		$defaults = self::defaults();
		return get_option( $key, $defaults[ $key ] ?? '' );
	}

	public static function init() {
		add_action( 'admin_menu', [ __CLASS__, 'add_page' ] );
		add_action( 'admin_init', [ __CLASS__, 'register_settings' ] );
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
		}

		if ( ! empty( $args['description'] ) ) {
			echo '<p class="description">' . esc_html( $args['description'] ) . '</p>';
		}
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
