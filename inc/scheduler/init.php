<?php
/**
 * QT Scheduler — wiring (enqueue + page bootstrap).
 *
 * The QT Scheduler shares window.FISHOTEL_MEDS with the medication calculator
 * (CP variants come from there) and adds window.FISHOTEL_QT_METHODS as the
 * method catalog.
 *
 * @package fishotel-theme
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

add_action( 'wp_enqueue_scripts', 'fishotel_enqueue_qt_scheduler_assets' );
function fishotel_enqueue_qt_scheduler_assets() {
    if ( ! is_page_template( 'page-qt-scheduler.php' ) ) {
        return;
    }

    wp_enqueue_style(
        'fishotel-qt-scheduler',
        get_template_directory_uri() . '/assets/css/qt-scheduler.css',
        array(),
        fishotel_asset_version( 'assets/css/qt-scheduler.css' )
    );

    wp_enqueue_style(
        'fishotel-qt-scheduler-print',
        get_template_directory_uri() . '/assets/css/qt-scheduler-print.css',
        array( 'fishotel-qt-scheduler' ),
        fishotel_asset_version( 'assets/css/qt-scheduler-print.css' ),
        'print'
    );

    // Scheduler method catalog (static literal)
    wp_enqueue_script(
        'fishotel-qt-scheduler-data',
        get_template_directory_uri() . '/assets/js/scheduler/qt-scheduler-data.js',
        array(),
        fishotel_asset_version( 'assets/js/scheduler/qt-scheduler-data.js' ),
        true
    );

    // Main scheduler logic
    wp_enqueue_script(
        'fishotel-qt-scheduler',
        get_template_directory_uri() . '/assets/js/scheduler/qt-scheduler.js',
        array( 'fishotel-qt-scheduler-data' ),
        fishotel_asset_version( 'assets/js/scheduler/qt-scheduler.js' ),
        true
    );

    // Pass medication data to the scheduler (same as calculator).
    wp_localize_script(
        'fishotel-qt-scheduler-data',
        'FISHOTEL_MEDS',
        FisHotel_Medication_Data::get_all()
    );
}

// Bootstrap the /qt-scheduler/ page if it doesn't already exist.
add_action( 'admin_init', 'fishotel_bootstrap_qt_scheduler_page' );
function fishotel_bootstrap_qt_scheduler_page() {
    if ( function_exists( 'fishotel_bootstrap_template_page' ) ) {
        fishotel_bootstrap_template_page( 'qt-scheduler', 'page-qt-scheduler.php', 'QT Scheduler' );
    }
}
