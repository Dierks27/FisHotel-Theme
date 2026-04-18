<?php
/**
 * FisHotel_Medication_Data
 *
 * Loads medication-data-seed.json into wp_options on theme activation,
 * provides read API for the front-end, and a write API for the admin.
 *
 * @package fishotel-theme
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class FisHotel_Medication_Data {

    const OPTION_KEY   = 'fishotel_medication_data';
    const VERSION_KEY  = 'fishotel_medication_data_schema_version';
    const SEED_VERSION = '1.2';

    /**
     * Seed wp_options from JSON on first load, or whenever the seed schema bumps.
     * Hooked to `after_setup_theme`.
     */
    public static function maybe_seed() {
        $current_version = get_option( self::VERSION_KEY, '' );

        if ( $current_version === self::SEED_VERSION ) {
            return;
        }

        $seed_path = get_template_directory() . '/inc/calculator/medication-data-seed.json';
        if ( ! file_exists( $seed_path ) ) {
            return;
        }

        $raw = file_get_contents( $seed_path );
        if ( ! $raw ) { return; }

        $data = json_decode( $raw, true );
        if ( ! is_array( $data ) || empty( $data['medications'] ) ) {
            return;
        }

        update_option( self::OPTION_KEY, $data, false );
        update_option( self::VERSION_KEY, self::SEED_VERSION, false );
    }

    /**
     * Force reseed from JSON file — use after manual edits to the seed or via an admin button.
     */
    public static function force_reseed() {
        delete_option( self::VERSION_KEY );
        self::maybe_seed();
    }

    /**
     * Get the entire data structure.
     *
     * @return array
     */
    public static function get_all() {
        $data = get_option( self::OPTION_KEY, array() );
        return is_array( $data ) ? $data : array();
    }

    /**
     * Fetch a single medication by its med_id.
     *<?php<?php
/**
 * FisHotel_Medication_Data
 *
 * Loads medication-data-seed.json into wp_options on theme activation,
 * provides read API for the front-end, and a write API for the admin.
 *
 * @package fishotel-theme
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class FisHotel_Medication_Data {

    const OPTION_KEY   = 'fishotel_medication_data';
    const VERSION_KEY  = 'fishotel_medication_data_schema_version';
    const SEED_VERSION = '1.3';

    /**
     * Seed wp_options from JSON on first load, or whenever the seed schema bumps.
     * Hooked to `after_setup_theme`.
     */
    public static function maybe_seed() {
        $current_version = get_option( self::VERSION_KEY, '' );

        if ( $current_version === self::SEED_VERSION ) {
            return;
        }

        $seed_path = get_template_directory() . '/inc/calculator/medication-data-seed.json';
        if ( ! file_exists( $seed_path ) ) {
            return;
        }

        $raw = file_get_contents( $seed_path );
        if ( ! $raw ) { return; }

        $data = json_decode( $raw, true );
        if ( ! is_array( $data ) || empty( $data['medications'] ) ) {
            return;
        }

        update_option( self::OPTION_KEY, $data, false );
        update_option( self::VERSION_KEY, self::SEED_VERSION, false );
    }

    /**
     * Force reseed from JSON file — use after manual edits to the seed or via an admin button.
     */
    public static function force_reseed() {
        delete_option( self::VERSION_KEY );
        self::maybe_seed();
    }

    /**
     * Get the entire data structure.
     *
     * @return array
     */
    public static function get_all() {
        $data = get_option( self::OPTION_KEY, array() );
        return is_array( $data ) ? $data : array();
    }

    /**
     * Fetch a single medication by its med_id.
     *<?php
/**
 * FisHotel_Medication_Data
 *
 * Loads medication-data-seed.json into wp_options on theme activation,
 * provides read API for the front-end, and a write API for the admin.
 *
 * @package fishotel-theme
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class FisHotel_Medication_Data {

    const OPTION_KEY   = 'fishotel_medication_data';
    const VERSION_KEY  = 'fishotel_medication_data_schema_version';
    const SEED_VERSION = '1.2';

    /**
     * Seed wp_options from JSON on first load, or whenever the seed schema bumps.
     * Hooked to `after_setup_theme`.
     */
    public static function maybe_seed() {
        $current_version = get_option( self::VERSION_KEY, '' );

        if ( $current_version === self::SEED_VERSION ) {
            return;
        }

        $seed_path = get_template_directory() . '/inc/calculator/medication-data-seed.json';
        if ( ! file_exists( $seed_path ) ) {
            return;
        }

        $raw = file_get_contents( $seed_path );
        if ( ! $raw ) { return; }

        $data = json_decode( $raw, true );
        if ( ! is_array( $data ) || empty( $data['medications'] ) ) {
            return;
        }

        update_option( self::OPTION_KEY, $data, false );
        update_option( self::VERSION_KEY, self::SEED_VERSION, false );
    }

    /**
     * Force reseed from JSON file — use after manual edits to the seed or via an admin button.
     */
    public static function force_reseed() {
        delete_option( self::VERSION_KEY );
        self::maybe_seed();
    }

    /**
     * Get the entire data structure.
     *
     * @return array
     */
    public static function get_all() {
        $data = get_option( self::OPTION_KEY, array() );
        return is_array( $data ) ? $data : array();
    }

    /**
     * Fetch a single medication by its med_id.
     *
     * @param string $med_id
     * @return array|null
     */
    public static function get_by_id( $med_id ) {
        $data = self::get_all();
        if ( empty( $data['medications'] ) ) {
            return null;
        }
        foreach ( $data['medications'] as $med ) {
            if ( isset( $med['med_id'] ) && $med['med_id'] === $med_id ) {
                return $med;
            }
        }
        return null;
    }

    /**
     * Save a single medication back into the options blob.
     *
     * @param string $med_id
     * @param array  $med_data
     * @return bool
     */
    public static function update_medication( $med_id, $med_data ) {
        $data = self::get_all();
        if ( empty( $data['medications'] ) ) { return false; }

        foreach ( $data['medications'] as $idx => $med ) {
            if ( isset( $med['med_id'] ) && $med['med_id'] === $med_id ) {
                $data['medications'][ $idx ] = array_merge( $med, $med_data );
                return update_option( self::OPTION_KEY, $data, false );
            }
        }
        return false;
    }
}
