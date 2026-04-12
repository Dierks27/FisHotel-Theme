<?php
/**
 * FisHotel Customizer
 * @package FisHotel
 */
defined('ABSPATH') || exit;

add_action('customize_register', function($wp_customize) {

    // FisHotel panel
    $wp_customize->add_panel('fishotel_panel', [
        'title'    => 'FisHotel Theme',
        'priority' => 30,
    ]);

    // ── Branding section ──
    $wp_customize->add_section('fishotel_branding', [
        'title' => 'Branding & Logo',
        'panel' => 'fishotel_panel',
    ]);
    $wp_customize->add_setting('fishotel_tagline', ['default' => 'Premium Marine Quarantine', 'sanitize_callback' => 'sanitize_text_field']);
    $wp_customize->add_control('fishotel_tagline', ['label' => 'Nav Tagline', 'section' => 'fishotel_branding', 'type' => 'text']);

    // ── Hero section ──
    $wp_customize->add_section('fishotel_hero', [
        'title' => 'Homepage Hero',
        'panel' => 'fishotel_panel',
    ]);
    $wp_customize->add_setting('fishotel_hero_eyebrow', ['default' => 'Premium Marine Fish Quarantine', 'sanitize_callback' => 'sanitize_text_field']);
    $wp_customize->add_control('fishotel_hero_eyebrow', ['label' => 'Hero Eyebrow Text', 'section' => 'fishotel_hero', 'type' => 'text']);
    $wp_customize->add_setting('fishotel_hero_subtitle', ['default' => 'Every saltwater fish quarantined, observed, and treated before it ever reaches your tank.', 'sanitize_callback' => 'sanitize_text_field']);
    $wp_customize->add_control('fishotel_hero_subtitle', ['label' => 'Hero Subtitle', 'section' => 'fishotel_hero', 'type' => 'textarea']);

    // ── Contact section ──
    $wp_customize->add_section('fishotel_contact', [
        'title' => 'Contact & Social',
        'panel' => 'fishotel_panel',
    ]);
    $wp_customize->add_setting('fishotel_humble_fish_url', ['default' => 'https://www.humble.fish', 'sanitize_callback' => 'esc_url_raw']);
    $wp_customize->add_control('fishotel_humble_fish_url', ['label' => 'Humble.Fish Profile URL', 'section' => 'fishotel_contact', 'type' => 'url']);
    $wp_customize->add_setting('fishotel_reef2reef_url', ['default' => 'https://www.reef2reef.com', 'sanitize_callback' => 'esc_url_raw']);
    $wp_customize->add_control('fishotel_reef2reef_url', ['label' => 'Reef2Reef Profile URL', 'section' => 'fishotel_contact', 'type' => 'url']);

});
