<?php
/**
 * Template Name: Quarantine Help — Medication Dosing Calculator
 *
 * FisHotel custom calculator replacing the legacy Calculated Fields Form instances.
 * Requires: FisHotel_Medication_Data loader (inc/calculator/class-fishotel-medication-data.php)
 *
 * @package fishotel-theme
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

get_header();

// Pull data out of options (seeded from JSON on theme activation)
$medication_data = FisHotel_Medication_Data::get_all();
$medications     = isset( $medication_data['medications'] ) ? $medication_data['medications'] : array();

// Deep-link support: ?med=<med_id> and ?tank=<gal>
$initial_med_id = isset( $_GET['med'] ) ? sanitize_text_field( wp_unslash( $_GET['med'] ) ) : 'copper_power';
$initial_tank   = isset( $_GET['tank'] ) ? absint( $_GET['tank'] ) : 30;
if ( $initial_tank < 3 )   { $initial_tank = 3; }
if ( $initial_tank > 500 ) { $initial_tank = 500; }

// Category display order + human labels
$categories = array(
    'antibiotic'        => 'Antibiotics',
    'copper'            => 'Copper',
    'dewormer_external' => 'Dewormers',
    'misc'              => 'Misc',
);
?>
<main id="primary" class="site-main fh-qh-page">
    <div class="fh-qh-wrap">

        <!-- Hero -->
        <header class="fh-qh-hero">
            <div class="fh-qh-eyebrow">The Pharmacy</div>
            <h1 class="fh-qh-title">Medication Dosing</h1>
            <div class="fh-qh-hairline" aria-hidden="true"></div>
            <p class="fh-qh-intro">Enter your tank volume. Pick a medication. We'll handle the math.</p>
        </header>

        <!-- Tank Volume (primary input, affects every med) -->
        <div class="fh-qh-tankrow">
            <label for="fh-qh-tank" class="fh-qh-tanklabel">Tank Volume</label>
            <input id="fh-qh-tank" class="fh-qh-tankslider" type="range" min="3" max="500" step="1" value="<?php echo esc_attr( $initial_tank ); ?>">
            <span class="fh-qh-tankval"><span id="fh-qh-gal"><?php echo esc_html( $initial_tank ); ?></span><small>gal</small></span>
        </div>

        <!-- Category tabs -->
        <nav class="fh-qh-tabs" role="tablist" aria-label="Medication categories">
            <?php
            $first = true;
            foreach ( $categories as $cat_key => $cat_label ) :
                $classes = 'fh-qh-tab' . ( $first ? ' is-on' : '' );
                $first = false;
                ?>
                <button type="button" class="<?php echo esc_attr( $classes ); ?>"
                        role="tab" aria-selected="false"
                        data-cat="<?php echo esc_attr( $cat_key ); ?>">
                    <?php echo esc_html( $cat_label ); ?>
                </button>
            <?php endforeach; ?>
        </nav>

        <!-- Medication picker grid (all meds, filtered via JS by active tab) -->
        <div id="fh-qh-medgrid" class="fh-qh-medgrid" role="tablist" aria-label="Medication picker">
            <?php foreach ( $medications as $med ) :
                $cat = isset( $med['category'] ) ? $med['category'] : 'misc';
                // Map internal_dewormer category into misc for tab purposes (only ext shown)
                $display_cat = $cat === 'dewormer_internal' ? 'misc' : ( $cat === 'protocol' ? 'misc' : $cat );
                $short = isset( $med['name_generic'] )
                    ? preg_replace( '/\s*\(.+?\)\s*/', '', $med['name_generic'] )
                    : $med['med_id'];
                ?>
                <button type="button"
                        class="fh-qh-med"
                        data-med-id="<?php echo esc_attr( $med['med_id'] ); ?>"
                        data-cat="<?php echo esc_attr( $display_cat ); ?>">
                    <?php echo esc_html( $short ); ?>
                </button>
            <?php endforeach; ?>
        </div>

        <!-- The selected medication panel — all rendering done by JS -->
        <section id="fh-qh-panel" class="fh-qh-panel" aria-live="polite">
            <!-- populated by fishotel-calculator.js -->
        </section>

        <!-- Actions row -->
        <div class="fh-qh-actions">
            <button type="button" id="fh-qh-print" class="fh-qh-actbtn">Print Schedule</button>
            <button type="button" id="fh-qh-ics"   class="fh-qh-actbtn">Save to Calendar (.ics)</button>
        </div>

        <!-- Disclaimer -->
        <p class="fh-qh-disclaimer">
            Doses reference Humblefish's pure-powder protocols and manufacturer-verified active-ingredient percentages.
            Always verify copper with a Hanna HI702 checker. If in doubt, ask FisHotel before dosing — it's why we're here.
        </p>

    </div>
</main>
<?php
get_footer();
