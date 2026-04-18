<?php
/**
 * Additions to functions.php — paste inside the existing functions.php,
 * near other require_once / add_action blocks. Do NOT replace the whole file.
 *
 * @package fishotel-theme
 */

/* ========================================================================
 * FisHotel Medication Dosing Calculator — wiring
 * ======================================================================== */

// 1. Require the data loader class
require_once get_template_directory() . '/inc/calculator/class-fishotel-medication-data.php';

// 2. Seed the medication data into wp_options on theme load (cheap no-op after first call)
add_action( 'after_setup_theme', array( 'FisHotel_Medication_Data', 'maybe_seed' ) );

// 3. Enqueue calculator assets only on the Quarantine Help page
add_action( 'wp_enqueue_scripts', 'fishotel_enqueue_calculator_assets' );
function fishotel_enqueue_calculator_assets() {
    if ( ! is_page_template( 'page-quarantine-help.php' ) ) {
        return;
    }

    $ver = wp_get_theme()->get( 'Version' );

    wp_enqueue_style(
        'fishotel-calculator',
        get_template_directory_uri() . '/assets/css/calculator.css',
        array(),
        $ver
    );

    wp_enqueue_style(
        'fishotel-calculator-print',
        get_template_directory_uri() . '/assets/css/calculator-print.css',
        array( 'fishotel-calculator' ),
        $ver,
        'print'
    );

    wp_enqueue_script(
        'fishotel-calculator',
        get_template_directory_uri() . '/assets/js/calculator/fishotel-calculator.js',
        array(),
        $ver,
        true
    );

    // Pass the medication data to the front-end
    wp_localize_script(
        'fishotel-calculator',
        'FISHOTEL_MEDS',
        FisHotel_Medication_Data::get_all()
    );
}

/* End Quarantine Help Calculator wiring */
