<?php
/**
 * FisHotel Quarantine Journal
 * Repeatable journal entries stored as post meta
 * Each entry: date, type (arrival/observation/treatment/cleared), text
 *
 * @package FisHotel
 * TODO: Build full CRUD UI in Phase 3
 */
defined( 'ABSPATH' ) || exit;

class FisHotel_Journal {

    const META_KEY = '_fishotel_journal';

    public static function init() {
        add_action( 'woocommerce_process_product_meta', [ __CLASS__, 'save_entries' ] );
    }

    public static function get_entries( $product_id ) {
        $entries = get_post_meta( $product_id, self::META_KEY, true );
        if ( ! is_array( $entries ) ) return [];
        // Sort newest first
        usort( $entries, fn( $a, $b ) => strtotime( $b['date'] ) - strtotime( $a['date'] ) );
        return $entries;
    }

    public static function add_entry( $product_id, $entry ) {
        $entries   = self::get_entries( $product_id );
        $entries[] = [
            'date' => sanitize_text_field( $entry['date'] ?? current_time( 'Y-m-d' ) ),
            'type' => sanitize_text_field( $entry['type'] ?? 'observation' ),
            'text' => sanitize_textarea_field( $entry['text'] ?? '' ),
        ];
        update_post_meta( $product_id, self::META_KEY, $entries );
    }

    public static function save_entries( $product_id ) {
        if ( isset( $_POST['fishotel_journal'] ) && is_array( $_POST['fishotel_journal'] ) ) {
            $entries = array_map( fn( $e ) => [
                'date' => sanitize_text_field( $e['date'] ?? '' ),
                'type' => sanitize_text_field( $e['type'] ?? 'observation' ),
                'text' => sanitize_textarea_field( $e['text'] ?? '' ),
            ], $_POST['fishotel_journal'] );
            update_post_meta( $product_id, self::META_KEY, array_filter( $entries, fn( $e ) => ! empty( $e['text'] ) ) );
        }
    }

    /**
     * Type labels and CSS modifier classes
     */
    public static function get_type_config() {
        return [
            'arrival'     => [ 'label' => 'Arrival',           'modifier' => 'arrival' ],
            'observation' => [ 'label' => 'Observation',       'modifier' => '' ],
            'treatment'   => [ 'label' => 'Treatment',         'modifier' => 'treatment' ],
            'cleared'     => [ 'label' => 'Cleared',           'modifier' => 'cleared' ],
            'eating'      => [ 'label' => 'Eating Well',       'modifier' => 'cleared' ],
            'note'        => [ 'label' => 'Note',              'modifier' => '' ],
        ];
    }
}

FisHotel_Journal::init();
