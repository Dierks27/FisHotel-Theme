<?php
/**
 * FisHotel — Single Product Page
 * @package FisHotel
 */
defined('ABSPATH') || exit;

get_header();

while ( have_posts() ) :
    the_post();
    global $product;
    $product_id  = $product->get_ID();

    // WC Gift Cards does NOT register a custom product type — gift-card
    // products stay `simple`/`variable` and are flagged only by the
    // `_gift_card` meta. Detect that here so the add-to-cart path can defer
    // to the plugin's native denomination + gift form rather than building
    // our own variation UI over the top of it.
    $is_gift_card = ( $product instanceof WC_Product ) && 'yes' === $product->get_meta( '_gift_card' );

    // The theme overrides the default `woocommerce_template_single_add_to_cart`
    // action in inc/woocommerce.php, which is what normally enqueues
    // `wc-add-to-cart-variation` (it ships with WC's variable.php template
    // part). Enqueue it explicitly for variable products so wc_variation_form()
    // auto-initialises and the hidden `variation_id` input gets populated when
    // the user picks a combo. Skipped for gift cards — the GC plugin runs
    // its own denomination JS.
    if ( $product && 'variable' === $product->get_type() && ! $is_gift_card ) {
        wp_enqueue_script( 'wc-add-to-cart-variation' );
    }

    $hotel       = function_exists('fishotel_get_hotel_data') ? fishotel_get_hotel_data($product_id) : [];
    $tags        = get_the_terms($product_id, 'product_tag') ?: [];
    $gallery_ids = $product->get_gallery_image_ids();
    $main_img_id = $product->get_image_id();

    // Region from tags
    $known_regions = ['Indo-Pacific', 'Caribbean', 'Red Sea', 'Eastern Pacific', 'Atlantic'];
    $region = '';
    if ($tags) {
        foreach ($tags as $tag) {
            foreach ($known_regions as $r) {
                if (strcasecmp($tag->name, $r) === 0) {
                    $region = $r;
                    break 2;
                }
            }
        }
    }
    // Fallback: check hotel data region field
    if (!$region && !empty($hotel['region'])) {
        $region = $hotel['region'];
    }

    /**
     * Fires once per product on the PDP, before any of our markup.
     * Standard WC hook — plugins (e.g. Coming Soon) inject countdowns,
     * notices, badges here.
     */
    do_action( 'woocommerce_before_single_product' );

    // Coming Soon detection — single source of truth in inc/coming-soon.php.
    // When active, our QT cert + variations + Add-to-Cart block is
    // suppressed and the theme renders its own Arrival Panel in its place.
    $cs_release_ts  = fishotel_cs_release_ts( $product_id );
    $is_coming_soon = (bool) $cs_release_ts;

    // Amazon-mode medication detection — when true, the medication
    // store module renders a "Buy on Amazon" outbound CTA in place
    // of the QT cert + variations + Add-to-Cart block.
    $is_amazon_med = function_exists( 'fishotel_med_is_amazon_panel' )
        ? fishotel_med_is_amazon_panel( $product )
        : false;

    // Allowlist gate for fish-specific UI (QT badge, trust strip,
    // "About This Species" data table, prose heading copy). Anything
    // not in the quarantined-fish category — meds, foods, future
    // categories Jeff adds — falls through to the non-fish branches.
    $is_fish = function_exists( 'fishotel_is_quarantined_fish' )
        ? fishotel_is_quarantined_fish( $product_id )
        : false;
?>

<?php /* ── PAGE HERO BANNER ── */ ?>
<div class="page-hero">
    <div class="page-hero__inner">
        <nav class="page-hero__breadcrumb" aria-label="Breadcrumb">
            <a href="<?php echo esc_url( home_url('/') ); ?>">Home</a>
            <span>/</span>
            <?php $product_cats = get_the_terms( $product_id, 'product_cat' );
            $product_cat = $product_cats ? $product_cats[0] : null;
            if ( $product_cat ) : ?>
                <a href="<?php echo esc_url( get_term_link( $product_cat ) ); ?>"><?php echo esc_html( $product_cat->name ); ?></a>
            <?php else : ?>
                <a href="<?php echo esc_url( get_permalink( wc_get_page_id('shop') ) ); ?>">Shop</a>
            <?php endif; ?>
            <span>/</span>
            <span style="color:var(--fh-text-2)"><?php the_title(); ?></span>
        </nav>

        <h1 class="page-hero__title">
            <?php
            $words = explode(' ', get_the_title(), 2);
            echo '<span class="word-1">' . esc_html($words[0]) . '</span>';
            if (!empty($words[1])) { echo '&nbsp;<span class="word-2">' . esc_html($words[1]) . '</span>'; }
            ?>
        </h1>

        <div class="page-hero__meta">
            <?php
            // Subtitle under the H1. Fish products surface the short
            // description (where the italicized scientific name lives).
            // Medications + Tier 2 surface their active ingredient(s);
            // foods surface their source species. Short description acts
            // as the fallback for everything else (gift cards, merch,
            // future categories Jeff adds). The tag pill row's genus
            // deny-list (fishotel_pdp_display_tags) drops any pill that
            // duplicates the first word of this subtitle so the same
            // word never appears in both places.
            $short    = (string) $product->get_short_description();
            $subtitle = '';
            if ( $is_fish ) {
                $subtitle = $short;
            } elseif ( function_exists( 'fishotel_is_amazon_affiliate_product' )
                && ( fishotel_is_amazon_affiliate_product( $product_id )
                    || ( function_exists( 'fishotel_is_medication_product' ) && fishotel_is_medication_product( $product_id ) ) ) ) {
                $subtitle = (string) get_post_meta( $product_id, '_fishotel_active_ingredient', true );
                if ( $subtitle === '' ) { $subtitle = $short; }
            } elseif ( function_exists( 'fishotel_is_food_product' ) && fishotel_is_food_product( $product_id ) ) {
                $subtitle = (string) get_post_meta( $product_id, '_fishotel_food_source', true );
                if ( $subtitle === '' ) { $subtitle = $short; }
            } else {
                $subtitle = $short;
            }
            if ( $subtitle !== '' ) : ?>
                <span class="page-hero__latin"><?php echo wp_kses_post( $subtitle ); ?></span>
            <?php endif; ?>

            <?php
            $display_tags = function_exists( 'fishotel_pdp_display_tags' )
                ? fishotel_pdp_display_tags( $tags, $product_id )
                : $tags;
            if ( $display_tags ) : ?>
            <div class="fh-tag-list">
                <?php foreach ($display_tags as $tag) :
                    $mod = function_exists( 'fishotel_tag_modifier_class' )
                        ? fishotel_tag_modifier_class( $tag->slug )
                        : '';
                ?>
                    <a href="<?php echo esc_url( get_term_link($tag) ); ?>" class="fh-tag <?php echo esc_attr($mod); ?>">
                        <?php echo esc_html($tag->name); ?>
                    </a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php /* ── MAIN 2-COL: GALLERY + PURCHASE ── */ ?>
<div class="fh-product-layout">

    <?php /* ── GALLERY ── */ ?>
    <div class="fh-gallery">
        <div class="fh-gallery__main">
            <?php if ($main_img_id) : ?>
                <?php echo wp_get_attachment_image($main_img_id, 'fishotel-product-hero', false, ['class' => '', 'id' => 'fh-main-img', 'alt' => get_the_title()]); ?>
            <?php else : ?>
                <?php echo FisHotel_Placeholder_Library::render( $product_id, 'fishotel-product-hero', ['id' => 'fh-main-img', 'alt' => get_the_title()] ); ?>
            <?php endif; ?>
        </div>

        <?php if (!empty($gallery_ids)) : ?>
        <div class="fh-gallery__thumbs">
            <?php if ($main_img_id) : ?>
                <div class="fh-gallery__thumb active" data-full="<?php echo esc_url(wp_get_attachment_url($main_img_id)); ?>">
                    <?php echo wp_get_attachment_image($main_img_id, 'fishotel-product-thumb', false, ['alt' => '']); ?>
                </div>
            <?php endif;
            foreach (array_slice($gallery_ids, 0, 3) as $gid) : ?>
                <div class="fh-gallery__thumb" data-full="<?php echo esc_url(wp_get_attachment_url($gid)); ?>">
                    <?php echo wp_get_attachment_image($gid, 'fishotel-product-thumb', false, ['alt' => '']); ?>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <?php /* ── PURCHASE PANEL ── */ ?>
    <div class="fh-purchase">

        <?php
        if ( $is_coming_soon ) {
            // Coming Soon PDP — render our own panel in place of the
            // live purchase UI. We deliberately skip the summary
            // lifecycle hooks here so the plugin's own price /
            // availability output can't leak above the panel.
            fishotel_cs_render_arrival_panel( $product, $cs_release_ts );
        } elseif ( $is_amazon_med ) {
            // Amazon-mode medication — render the outbound CTA panel.
            // We still fire `before_single_product_summary` so the
            // medication eyebrow + any plugin badges render above it.
            // The Tier 2 trust strip + the "From the Pharmacy" badge
            // are rendered immediately after the Amazon CTA so affiliate
            // products visually parallel the EA-fulfilled meds layout
            // (Phase 3.5.1 + 3.6).
            do_action( 'woocommerce_before_single_product_summary' );
            fishotel_med_render_amazon_panel( $product );
            if ( function_exists( 'fishotel_render_purchase_badge' ) ) {
                fishotel_render_purchase_badge( $product_id );
            }
            if ( function_exists( 'fishotel_get_trust_strip_items' ) && function_exists( 'fishotel_render_trust_strip' ) ) {
                fishotel_render_trust_strip( fishotel_get_trust_strip_items( $product_id ) );
            }
        } else {
            /**
             * Standard WC summary lifecycle hooks. Defaults (title, price,
             * add-to-cart, etc.) are removed in inc/woocommerce.php so only
             * plugin callbacks fire here.
             */
            do_action( 'woocommerce_before_single_product_summary' );
            do_action( 'woocommerce_single_product_summary' );
        }
        ?>

        <?php if ( ! $is_coming_soon && ! $is_amazon_med ) : ?>

        <?php /* QT Certificate Panel — quarantined-fish gets the QT lines;
                medications + Tier 2 get "FROM THE PHARMACY"; foods get
                "FROM THE PANTRY". Gift cards / merch render nothing. Same
                fh-qt-cert markup for all three so they share styling. */ ?>
        <?php
        if ( function_exists( 'fishotel_render_purchase_badge' ) ) {
            fishotel_render_purchase_badge( $product_id, [
                'region'      => $region,
                'hotel_notes' => $hotel['notes'] ?? '',
            ] );
        }
        ?>

        <?php
        // Resolve buy-box state once: drives the initial price/eyebrow,
        // the stock badge, and the auto-select hint on the variations form.
        // JS (main.js) updates these slots on found_variation/reset_data so
        // layout never shifts as the user picks a combo.
        $is_variable     = ( 'variable' === $product->get_type() ) && ! $is_gift_card;
        $variations_data = $is_variable ? $product->get_available_variations() : [];

        // Drop OOS variations entirely. The size row never renders an
        // unpurchaseable button, the data-product_variations JSON only
        // exposes valid combos to WC's variation form, and when filtering
        // leaves exactly one variation the single-variation auto-select
        // (JS) kicks in so the customer doesn't have to pick.
        $variations_data = array_values( array_filter( $variations_data, function ( $v ) {
            return ! empty( $v['is_in_stock'] );
        } ) );

        // Per-attribute set of in-stock values, used to filter the buttons
        // + hidden <select> options below. sanitize_title() on both sides
        // so taxonomy-slug values match display-name option strings.
        $attr_in_stock    = []; // attr_key => [ sanitized_value => true ]
        $attr_any_stocked = []; // attr_key => true (variation with "any" value)
        foreach ( $variations_data as $v ) {
            if ( empty( $v['attributes'] ) ) continue;
            foreach ( $v['attributes'] as $attr_key => $val ) {
                if ( $val === '' ) {
                    $attr_any_stocked[ $attr_key ] = true;
                } else {
                    $attr_in_stock[ $attr_key ][ sanitize_title( $val ) ] = true;
                }
            }
        }

        $variation_count = count( $variations_data );
        $is_multi_var    = $variation_count > 1;
        $is_single_var   = $variation_count === 1;

        if ( $is_multi_var || $is_single_var ) {
            // Min price across in-stock variations only — never advertise a
            // "Starting from $X" price for an OOS option.
            $prices          = array_map( function ( $v ) { return (float) $v['display_price']; }, $variations_data );
            $price_initial   = wc_price( min( $prices ) );
            $eyebrow_initial = $is_multi_var ? __( 'Starting from', 'fishotel' ) : '';
        } else {
            $eyebrow_initial = '';
            $price_initial   = $product->get_price_html();
        }

        // Initial stock badge. Single-variation reads the lone variation so
        // the badge already shows the per-size detail before JS auto-selects.
        if ( $is_single_var ) {
            $v = $variations_data[0];
            $stock_badge = fishotel_stock_badge_label(
                ! empty( $v['is_in_stock'] ),
                isset( $v['max_qty'] ) ? (int) $v['max_qty'] : -1
            );
        } else {
            $stock_badge = fishotel_stock_badge_label(
                $product->is_in_stock(),
                (int) $product->get_max_purchase_quantity()
            );
        }
        $is_sold_out = ( $stock_badge['state'] === 'soldout' );
        ?>

        <?php /* Price — eyebrow slot is always rendered so layout doesn't
                shift between simple/single-variation (empty) and multi-
                variation (STARTING FROM / SELECTED) cases. CSS reserves the
                min-height; the &nbsp; here keeps initial render exact. */ ?>
        <div class="fh-purchase__from"><?php echo $eyebrow_initial !== '' ? esc_html( $eyebrow_initial ) : '&nbsp;'; ?></div>
        <div class="fh-purchase__price"><?php echo wp_kses_post( $price_initial ); ?></div>

        <?php /* Variation selectors + Add to Cart form. Gift cards (any
                product type, flagged by `_gift_card` meta) defer to the WC
                Gift Cards native form — denomination picker, "Send as gift?"
                toggle, recipient/sender/message, and its own Add to Cart
                button. `simple`/`variable` non-gift-card products use our
                custom form; any other type also falls through to its own
                product-type template. */ ?>
        <?php if ( $is_gift_card ) : ?>
        <?php do_action( 'woocommerce_' . $product->get_type() . '_add_to_cart' ); ?>
        <?php elseif ( in_array( $product->get_type(), array( 'simple', 'variable' ), true ) ) : ?>
        <?php do_action('woocommerce_before_add_to_cart_form'); ?>
        <form class="fh-purchase__form variations_form cart"
              action="<?php echo esc_url(apply_filters('woocommerce_add_to_cart_form_action', $product->get_permalink())); ?>"
              method="post"
              enctype='multipart/form-data'
              data-product_id="<?php echo absint($product->get_id()); ?>"
              data-fh-multi-variations="<?php echo $is_multi_var ? '1' : '0'; ?>"
              data-product_variations="<?php echo esc_attr(htmlspecialchars(wp_json_encode($variations_data))); ?>">

            <?php if ( 'variable' === $product->get_type() ) : ?>
            <?php /* `variations` is the class WC's variation form binds to:
                    wc_variation_form() listens for change events delegated
                    against `.variations select` and reads chosen attributes
                    from the same selector. The hidden selects below must be
                    descendants of an element carrying that class or WC never
                    matches a combo and `variation_id` stays empty. */ ?>
            <div class="fh-variations variations">
                <?php foreach ($product->get_variation_attributes() as $attr_name => $options) :
                    $attr_key = 'attribute_' . sanitize_title($attr_name);
                    $has_any  = ! empty( $attr_any_stocked[ $attr_key ] );
                    $has_some = ! empty( $attr_in_stock[ $attr_key ] );
                    // Every option for this attribute is OOS — skip the group
                    // entirely. The outer .fh-variations wrapper still reserves
                    // the slot, so an all-OOS product renders an empty size row.
                    if ( ! $has_any && ! $has_some ) continue;
                    $label = wc_attribute_label($attr_name);
                    $selected = isset($_REQUEST['attribute_' . sanitize_title($attr_name)]) ? wc_clean(wp_unslash($_REQUEST['attribute_' . sanitize_title($attr_name)])) : $product->get_variation_default_attribute($attr_name);
                    // Sort numerically when labels carry an extractable number
                    // (e.g. 0.25oz / 0.5oz / 1oz / 2oz) — WC's default
                    // alphabetic order on slugs produces the wrong sequence.
                    $options = fishotel_sort_variation_options( $options, $attr_name );
                    $selected_label = $selected !== '' ? fishotel_variation_option_label( $attr_name, $selected ) : '';
                ?>
                <div class="fh-variation-group">
                    <div class="fh-variation-label">
                        <?php echo esc_html($label); ?>
                        <span class="fh-variation-selected">
                            <?php echo $selected_label !== '' ? '— ' . esc_html($selected_label) . ' selected' : ''; ?>
                        </span>
                    </div>
                    <div class="fh-var-buttons" data-attribute="<?php echo esc_attr( $attr_key ); ?>">
                        <?php foreach ($options as $option) :
                            if ( ! $has_any && ! isset( $attr_in_stock[ $attr_key ][ sanitize_title( $option ) ] ) ) continue;
                            $is_sel  = sanitize_title($option) === sanitize_title($selected);
                            // Display label uses the term name; data-value
                            // keeps the slug so WC's variation matcher and
                            // our availability tracker can still resolve it.
                            $display = fishotel_variation_option_label( $attr_name, $option );
                        ?>
                            <button type="button"
                                    class="fh-var-btn <?php echo $is_sel ? 'selected' : ''; ?>"
                                    data-value="<?php echo esc_attr($option); ?>"
                                    data-attribute="<?php echo esc_attr( $attr_key ); ?>">
                                <?php echo esc_html($display); ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                    <select name="<?php echo esc_attr( $attr_key ); ?>"
                            id="<?php echo esc_attr( $attr_key ); ?>"
                            class="fh-var-select-hidden"
                            style="display:none"
                            data-attribute_name="<?php echo esc_attr( $attr_key ); ?>">
                        <option value=""><?php esc_html_e('Choose an option', 'fishotel'); ?></option>
                        <?php foreach ($options as $option) :
                            if ( ! $has_any && ! isset( $attr_in_stock[ $attr_key ][ sanitize_title( $option ) ] ) ) continue;
                            $display = fishotel_variation_option_label( $attr_name, $option );
                        ?>
                            <option value="<?php echo esc_attr($option); ?>" <?php selected($selected, $option); ?>>
                                <?php echo esc_html($display); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endforeach; ?>
            </div>

            <div class="fh-variation-summary" id="fh-variation-summary" style="display:none">
                <div>
                    <div class="fh-variation-summary__label">Selected</div>
                    <div class="fh-variation-summary__combo" id="fh-variation-combo"></div>
                </div>
                <div style="text-align:right">
                    <div class="fh-variation-summary__price" id="fh-variation-price"></div>
                    <div class="fh-variation-summary__stock" id="fh-variation-stock"></div>
                </div>
            </div>

            <input type="hidden" name="variation_id" id="variation_id" value="">
            <?php endif; ?>

            <?php /* Stock badge — slot always rendered. JS updates state +
                    label on found_variation; this initial render covers
                    simple products, multi-variation aggregates, and the
                    single-variation case (so there's no flicker before
                    auto-select fires). */ ?>
            <div class="fh-stock-badge" data-state="<?php echo esc_attr( $stock_badge['state'] ); ?>" data-fh-stock-slot>
                <span class="fh-stock-badge__dot" aria-hidden="true"></span>
                <span class="fh-stock-badge__text"><?php echo esc_html( $stock_badge['text'] ); ?></span>
            </div>

            <?php /* Add to cart row */ ?>
            <div class="fh-add-to-cart">
                <div class="fh-qty">
                    <div class="fh-qty__num" id="fh-qty-display">1</div>
                    <button type="button" class="fh-qty__up" aria-label="Increase">&#9650;</button>
                    <button type="button" class="fh-qty__down" aria-label="Decrease">&#9660;</button>
                    <input type="hidden" name="quantity" id="fh-qty-input" value="1" min="1">
                </div>
                <?php /* Standard WC injection point for plugins that add fields
                        to the add-to-cart form (WC Gift Cards' recipient email /
                        sender name / message, custom-fields plugins, etc.).
                        Required: WC Gift Cards refuses cart submissions when
                        its fields aren't rendered ("required data is missing"). */ ?>
                <?php do_action( 'woocommerce_before_add_to_cart_button' ); ?>
                <button type="submit"
                        name="add-to-cart"
                        value="<?php echo esc_attr($product->get_id()); ?>"
                        class="fh-btn fh-btn--gold fh-btn--full single_add_to_cart_button<?php echo $is_sold_out ? ' fh-btn--notify' : ''; ?>"
                        data-fh-label-add="<?php echo esc_attr__( 'Add to Cart', 'fishotel' ); ?>"
                        data-fh-label-notify="<?php echo esc_attr__( 'Notify Me', 'fishotel' ); ?>"
                        <?php disabled( $is_sold_out, true ); ?>>
                    <?php echo $is_sold_out ? esc_html__( 'Notify Me', 'fishotel' ) : esc_html__( 'Add to Cart', 'fishotel' ); ?>
                </button>
            </div>

            <?php /* Trust strip — content swaps by product category:
                    quarantined-fish gets the QT/live-arrival/Mon-Wed lines,
                    medications get "the same meds we use in QT"-style copy,
                    foods get "the same foods we feed in-house"-style copy,
                    everything else (gift cards, merch) gets no strip. The
                    helper resolves the lines + the renderer emits the
                    same markup for all three so visual layout matches. */ ?>
            <?php
            if ( function_exists( 'fishotel_get_trust_strip_items' ) && function_exists( 'fishotel_render_trust_strip' ) ) {
                fishotel_render_trust_strip( fishotel_get_trust_strip_items( $product_id ) );
            }
            ?>

            <?php do_action('woocommerce_after_add_to_cart_button'); ?>
        </form>
        <?php do_action('woocommerce_after_add_to_cart_form'); ?>
        <?php else : ?>
        <?php /* Any other (non-simple/variable, non-gift-card) product type
                builds its own complete add-to-cart form via the
                product-type-specific hook, exactly as WC core's
                content-single-product.php does. The type hook fires
                `woocommerce_before_add_to_cart_form` / `_after_add_to_cart_form`
                itself, so we don't fire them here. */ ?>
        <?php do_action( 'woocommerce_' . $product->get_type() . '_add_to_cart' ); ?>
        <?php endif; ?>

        <?php endif; /* ! $is_coming_soon && ! $is_amazon_med */ ?>

        <?php /* Product meta */ ?>
        <div class="fh-product-meta product_meta">
            <?php do_action( 'woocommerce_product_meta_start' ); ?>
            <?php if ($product->get_sku()) : ?>
            <div class="fh-product-meta__row">
                <span class="fh-product-meta__label">SKU</span>
                <span><?php echo esc_html($product->get_sku()); ?></span>
            </div>
            <?php endif; ?>
            <div class="fh-product-meta__row">
                <span class="fh-product-meta__label">Category</span>
                <span><?php echo wc_get_product_category_list($product_id, ', '); ?></span>
            </div>
            <?php do_action( 'woocommerce_product_meta_end' ); ?>
        </div>

    </div><!-- .fh-purchase -->
</div><!-- .fh-product-layout -->

<?php do_action( 'woocommerce_after_single_product_summary' ); ?>

<?php /* ── ABOUT THIS SPECIES / MEDICATION / FOOD — Stat Grid from meta fields ── */ ?>
<?php
$desc_raw = $product->get_description();

// Build the row list per category. Empty fields skip so the table stays
// clean. Medicated foods (a medication product that's also a flake or
// pellet, e.g. Praziquantel Pellet) merge both sets — meds first, then
// the food-specific rows. Phase 3.6.
$spec_rows         = [];
$is_amazon_aff     = function_exists( 'fishotel_is_amazon_affiliate_product' ) && fishotel_is_amazon_affiliate_product( $product_id );
$is_med_or_aff     = $is_amazon_aff || ( function_exists( 'fishotel_is_medication_product' ) && fishotel_is_medication_product( $product_id ) );
$is_food           = function_exists( 'fishotel_is_food_product' ) && fishotel_is_food_product( $product_id );
$_card_cat_slugs   = wp_get_post_terms( $product_id, 'product_cat', [ 'fields' => 'slugs' ] );
$_card_cat_slugs   = is_wp_error( $_card_cat_slugs ) ? [] : (array) $_card_cat_slugs;
$is_medicated_food = $is_med_or_aff && (
    in_array( 'flakes', $_card_cat_slugs, true ) || in_array( 'pellets', $_card_cat_slugs, true )
);

$reef_labels = [
    'yes'     => '<span class="fh-spec-badge fh-spec-badge--green">&#10003; Yes</span>',
    'no'      => '<span class="fh-spec-badge fh-spec-badge--amber">&#10007; No</span>',
    'caution' => '<span class="fh-spec-badge fh-spec-badge--amber">&#9888; With Caution</span>',
];

if ( $is_fish ) {
    $sci_name    = get_post_meta( $product_id, '_fh_scientific_name', true );
    $common      = get_post_meta( $product_id, '_fh_common_names', true );
    $max_length  = get_post_meta( $product_id, '_fh_max_length', true );
    $tank_size   = get_post_meta( $product_id, '_fh_min_tank_size', true );
    $temperament = get_post_meta( $product_id, '_fh_temperament', true );
    $reef_safe   = get_post_meta( $product_id, '_fh_reef_safe', true );
    if ( $sci_name )    $spec_rows[] = [ 'Scientific Name', esc_html( $sci_name ) ];
    if ( $common )      $spec_rows[] = [ 'Common Names',    esc_html( $common ) ];
    if ( $max_length )  $spec_rows[] = [ 'Max Length',      esc_html( $max_length ) ];
    if ( $tank_size )   $spec_rows[] = [ 'Min Tank Size',   esc_html( $tank_size ) ];
    if ( $temperament ) $spec_rows[] = [ 'Temperament',     esc_html( $temperament ) ];
    if ( $reef_safe )   $spec_rows[] = [ 'Reef Safe', $reef_labels[ $reef_safe ] ?? esc_html( $reef_safe ) ];
    if ( $region )      $spec_rows[] = [ 'Region',          esc_html( $region ) ];
} else {
    if ( $is_med_or_aff ) {
        $active_ing = get_post_meta( $product_id, '_fishotel_active_ingredient', true );
        $treats     = get_post_meta( $product_id, '_fishotel_treats',             true );
        $use_in     = get_post_meta( $product_id, '_fishotel_use_in',             true );
        $reef_safe  = get_post_meta( $product_id, '_fishotel_reef_safe',          true );
        if ( $active_ing ) $spec_rows[] = [ 'Active Ingredients', esc_html( $active_ing ) ];
        if ( $treats )     $spec_rows[] = [ 'Treats',             esc_html( $treats ) ];
        if ( $use_in )     $spec_rows[] = [ 'Use In',             esc_html( $use_in ) ];
        if ( $reef_safe )  $spec_rows[] = [ 'Reef Safe', $reef_labels[ $reef_safe ] ?? esc_html( $reef_safe ) ];
    }
    if ( $is_food || $is_medicated_food ) {
        $food_source = get_post_meta( $product_id, '_fishotel_food_source', true );
        $best_for    = get_post_meta( $product_id, '_fishotel_best_for',    true );
        $nutrition   = get_post_meta( $product_id, '_fishotel_nutrition',   true );
        $additives   = get_post_meta( $product_id, '_fishotel_additives',   true );
        if ( $is_food && $food_source ) $spec_rows[] = [ 'Food Source', esc_html( $food_source ) ];
        if ( $best_for )                $spec_rows[] = [ 'Best For',    esc_html( $best_for ) ];
        if ( $nutrition )               $spec_rows[] = [ 'Nutrition Value', esc_html( $nutrition ) ];
        if ( $additives )               $spec_rows[] = [ 'Additives & Enrichments', esc_html( $additives ) ];
    }
}

// Resolve the H2 copy by category. Phase 3.6 mirrors the prose-heading
// logic from Phase 3.2 so the section header and the prose sub-heading
// stay consistent on the same product.
if ( $is_fish ) {
    $about_label = 'About This <em style="font-style:normal; color:var(--fh-gold);">Species</em>';
} elseif ( $is_med_or_aff && $is_medicated_food ) {
    $about_label = 'About This <em style="font-style:normal; color:var(--fh-gold);">Medication</em>';
} elseif ( $is_med_or_aff ) {
    $about_label = 'About This <em style="font-style:normal; color:var(--fh-gold);">Medication</em>';
} elseif ( $is_food ) {
    $about_label = 'About This <em style="font-style:normal; color:var(--fh-gold);">Food</em>';
} else {
    $about_label = '';
}
?>

<?php if ( ! empty( $spec_rows ) || $desc_raw ) : ?>
<div class="fh-species">
    <div class="fh-species__inner">
        <span class="fh-eyebrow">Description</span>
        <span class="fh-rule"></span>

        <?php /* "About This …" H2 + structured spec table — fish gets the
                species rows, meds/affiliate get medication rows, foods get
                food rows, medicated foods (meds in flakes/pellets) get
                both sets merged. Anything outside those buckets renders
                neither H2 nor table; the prose body below still renders. */ ?>
        <?php if ( $about_label !== '' ) : ?>
        <h2 class="fh-serif-head" style="font-size:30px; margin-bottom:32px;">
            <?php echo wp_kses( $about_label, [ 'em' => [ 'style' => true ] ] ); ?>
        </h2>

        <?php if ( ! empty( $spec_rows ) ) : ?>
        <table class="fh-species-grid">
            <?php foreach ( $spec_rows as $row ) : ?>
            <tr>
                <td><?php echo esc_html( $row[0] ); ?></td>
                <td><?php echo wp_kses_post( $row[1] ); ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
        <?php endif; ?>
        <?php endif; ?>

        <?php
        // Strip structured data lines from prose — they now live in custom fields
        if ( $desc_raw ) :
            $prose = strip_tags( $desc_raw );
            $strip_labels = [
                'Scientific Name', 'Common Names', 'Maximum Length',
                'Minimum Aquarium Size', 'Reef Safety', 'Temperament',
                'Foods and Feeding', 'Foods and Feeding Habits',
                'Habitat and Behavior', 'Habitat', 'Habits',
            ];
            foreach ( $strip_labels as $lbl ) {
                $prose = preg_replace( '/' . preg_quote( $lbl, '/' ) . '\s*[:\-–—]\s*[^\n]*/i', '', $prose );
            }
            // Strip "Description:" prefix
            $prose = preg_replace( '/^Description:\s*/i', '', trim( $prose ) );
            // Strip Fun Facts and everything after
            $prose = preg_replace( '/Fun Facts?:?[\s\S]*/i', '', $prose );
            $prose = trim( preg_replace( '/\n{3,}/', "\n\n", $prose ) );
        ?>
        <?php if ( $prose ) :
            // Sub-heading varies by product category. Anything outside
            // fish/meds/foods (gift cards, merch, future categories)
            // renders no heading — the prose still appears below the
            // "Description" eyebrow.
            $prose_cats = wp_get_post_terms( $product_id, 'product_cat', [ 'fields' => 'slugs' ] );
            $prose_cats = is_wp_error( $prose_cats ) ? [] : (array) $prose_cats;
            if ( $is_fish ) {
                $prose_label = 'About This Fish';
            } elseif ( in_array( 'medications', $prose_cats, true ) ) {
                $prose_label = 'About This Medication';
            } elseif ( in_array( 'foods', $prose_cats, true ) || in_array( 'freeze-dried-foods', $prose_cats, true ) ) {
                $prose_label = 'About This Food';
            } else {
                $prose_label = '';
            }
        ?>
        <div class="fh-species__prose">
            <?php if ( $prose_label !== '' ) : ?>
            <h3 class="fh-species__prose-label"><?php echo esc_html( $prose_label ); ?></h3>
            <?php endif; ?>
            <p><?php echo wp_kses_post( nl2br( esc_html( $prose ) ) ); ?></p>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php /* ── CARE GUIDE (Dossier) — reads from custom meta fields ── */ ?>
<?php
$foods_feeding = get_post_meta( $product_id, '_fh_foods_feeding', true );
if ( ! $foods_feeding && class_exists('FisHotel_Admin_Settings') ) {
    $foods_feeding = FisHotel_Admin_Settings::get('fh_default_foods');
}
$habitat = get_post_meta( $product_id, '_fh_habitat', true );
if ( ! $habitat && class_exists('FisHotel_Admin_Settings') ) {
    $habitat = FisHotel_Admin_Settings::get('fh_default_habitat');
}
?>

<?php if ( $foods_feeding || $habitat ) : ?>
<div class="fh-careguide">
    <div class="fh-careguide__inner">
        <span class="fh-eyebrow">Care Guide</span>
        <span class="fh-rule"></span>
        <h2 class="fh-serif-head" style="font-size:30px; margin-bottom:32px;">Fish <em style="font-style:normal; color:var(--fh-gold);">Dossier</em></h2>

        <div class="fh-careguide__blocks">
            <?php if ( $foods_feeding ) : ?>
            <div class="fh-careguide__block">
                <h3 class="fh-careguide__block-title">Foods &amp; Feeding</h3>
                <p class="fh-careguide__block-text"><?php echo esc_html( $foods_feeding ); ?></p>
            </div>
            <?php endif; ?>

            <?php if ( $habitat ) : ?>
            <div class="fh-careguide__block">
                <h3 class="fh-careguide__block-title">Habitat &amp; Behavior</h3>
                <p class="fh-careguide__block-text"><?php echo esc_html( $habitat ); ?></p>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<?php /* ── RELATED PRODUCTS — Also in Quarantine ── */ ?>
<?php
$related_ids = wc_get_related_products($product_id, 4);
if (!empty($related_ids)) :
    $related_products = array_map('wc_get_product', $related_ids);
    $related_products = array_filter($related_products);
?>
<div class="fh-related">
    <div class="fh-related__inner">
        <h2 class="fh-serif-head" style="font-size:30px; margin-bottom:6px;">More Available <em style="font-style:normal; color:var(--fh-gold);">Now</em></h2>
        <p style="font-size:11px; color:var(--fh-text-3); margin-bottom:36px;">Ready to Check Out Today</p>
        <div class="fh-product-grid">
            <?php foreach ($related_products as $rel) :
                $rel_id = $rel->get_ID();
                // Set up the WC product loop globals so plugin callbacks
                // hooked into woocommerce_*_shop_loop_item see the right
                // product instead of the parent PDP product.
                $GLOBALS['post']    = get_post( $rel_id );
                $GLOBALS['product'] = $rel;
                setup_postdata( $GLOBALS['post'] );
            ?>
                <div class="fh-fish-card-wrap product">
                    <?php do_action( 'woocommerce_before_shop_loop_item' ); ?>
                    <a href="<?php echo esc_url($rel->get_permalink()); ?>" class="fh-fish-card">
                        <div class="fh-fish-card__image">
                            <?php fishotel_product_thumbnail( $rel_id, 'fishotel-product-card' ); ?>
                            <?php do_action( 'woocommerce_before_shop_loop_item_title' ); ?>
                            <?php fishotel_cs_render_card_badge( $rel_id ); ?>
                        </div>
                        <div class="fh-fish-card__body">
                            <div class="fh-fish-card__name"><?php echo esc_html($rel->get_name()); ?></div>
                            <?php do_action( 'woocommerce_after_shop_loop_item_title' ); ?>
                            <div class="fh-fish-card__latin"><?php echo wp_kses_post($rel->get_short_description()); ?></div>
                            <div class="fh-fish-card__footer">
                                <?php $rel_cs = fishotel_cs_release_ts( $rel_id ); ?>
                                <?php if ( $rel_cs ) : ?>
                                    <?php fishotel_cs_render_card_line( $rel_id ); ?>
                                <?php else : ?>
                                <span class="fh-fish-card__price"><?php echo $rel->get_price_html(); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </a>
                    <?php do_action( 'woocommerce_after_shop_loop_item' ); ?>
                </div>
            <?php endforeach; wp_reset_postdata(); ?>
        </div>
    </div>
</div>
<?php endif; ?>

<?php do_action( 'woocommerce_after_single_product' ); ?>

<?php endwhile; ?>

<?php get_footer(); ?>
