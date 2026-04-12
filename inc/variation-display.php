<?php
/**
 * FisHotel Visual Variation Selectors
 * Replaces WooCommerce default dropdowns with visual button groups
 * Supports: Size, Sex, Phase, Color, and any custom attribute
 *
 * @package FisHotel
 * TODO: Build full JS interaction in Phase 2
 */
defined( 'ABSPATH' ) || exit;

// Replace default variation dropdowns with our button UI
remove_action( 'woocommerce_single_variation', 'woocommerce_single_variation', 10 );

add_filter( 'woocommerce_dropdown_variation_attribute_options_html', function( $html, $args ) {
    $product    = $args['product'];
    $attribute  = $args['attribute'];
    $options    = $args['options'];
    $name       = $args['name'] ?: 'attribute_' . sanitize_title( $attribute );
    $selected   = $args['selected'];
    $id         = $args['id'] ?: sanitize_title( $attribute );

    // Get available options for this attribute
    if ( empty( $options ) ) {
        if ( $product && $product->is_type( 'variable' ) ) {
            $variations = $product->get_variation_attributes();
            $options    = array_values( $variations[ $attribute ] ?? [] );
        }
    }

    if ( empty( $options ) ) return $html;

    $buttons = '';
    foreach ( $options as $option ) {
        $is_selected = sanitize_title( $option ) === sanitize_title( $selected );
        $classes     = 'fh-var-btn' . ( $is_selected ? ' selected' : '' );
        $buttons    .= sprintf(
            '<button type="button" class="%s" data-value="%s" data-attribute="%s">%s</button>',
            esc_attr( $classes ),
            esc_attr( $option ),
            esc_attr( $name ),
            esc_html( $option )
        );
    }

    // Hidden select (keeps WooCommerce JS working)
    $select = sprintf(
        '<select id="%s" name="%s" class="fh-var-select-hidden" style="display:none" data-attribute_name="%s">',
        esc_attr( $id ),
        esc_attr( $name ),
        esc_attr( $name )
    );
    $select .= '<option value="">' . esc_html__( 'Choose an option', 'fishotel' ) . '</option>';
    foreach ( $options as $option ) {
        $select .= sprintf(
            '<option value="%s" %s>%s</option>',
            esc_attr( $option ),
            selected( $selected, $option, false ),
            esc_html( $option )
        );
    }
    $select .= '</select>';

    return sprintf(
        '<div class="fh-var-buttons" data-attribute="%s">%s</div>%s',
        esc_attr( $name ),
        $buttons,
        $select
    );

}, 10, 2 );
