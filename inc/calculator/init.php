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

    // Use filemtime() for cache-busting so on-disk changes invalidate browser
    // and edge caches. Was previously the static theme version, which caused
    // stale JS/CSS to be served after deploys (same symptom we hit on the
    // bleach calculator during QA round 2).

    wp_enqueue_style(
        'fishotel-calculator',
        get_template_directory_uri() . '/assets/css/calculator.css',
        array(),
        fishotel_asset_version( 'assets/css/calculator.css' )
    );

    wp_enqueue_style(
        'fishotel-calculator-print',
        get_template_directory_uri() . '/assets/css/calculator-print.css',
        array( 'fishotel-calculator' ),
        fishotel_asset_version( 'assets/css/calculator-print.css' ),
        'print'
    );

    wp_enqueue_script(
        'fishotel-calculator',
        get_template_directory_uri() . '/assets/js/calculator/fishotel-calculator.js',
        array(),
        fishotel_asset_version( 'assets/js/calculator/fishotel-calculator.js' ),
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
