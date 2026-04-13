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
            <?php $short = $product->get_short_description();
            if ($short) : ?>
                <span class="page-hero__latin"><?php echo wp_kses_post($short); ?></span>
            <?php endif; ?>

            <?php if ($tags) : ?>
            <div class="fh-tag-list">
                <?php foreach ($tags as $tag) :
                    $slug = $tag->slug;
                    $mod = '';
                    if ( strpos( $slug, 'reef-safe' ) !== false )   $mod = 'fh-tag--reef-safe';
                    elseif ( strpos( $slug, 'peaceful' ) !== false ) $mod = 'fh-tag--peaceful';
                    elseif ( strpos( $slug, 'carnivore' ) !== false ) $mod = 'fh-tag--carnivore';
                    elseif ( strpos( $slug, 'aggressive' ) !== false ) $mod = 'fh-tag--aggressive';
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
                <?php echo woocommerce_placeholder_img('fishotel-product-hero'); ?>
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

        <?php /* QT Certificate Panel */ ?>
        <div class="fh-qt-cert">
            <div class="fh-qt-cert__header">
                <span class="fh-qt-cert__check">&#10003;</span>
                <span class="fh-qt-cert__title">Quarantine Complete</span>
            </div>
            <div class="fh-qt-cert__protocol">
                14 days observation<br>+ 14 days treatment
            </div>
            <?php if ($region) : ?>
            <div class="fh-qt-cert__region">
                Region: <?php echo esc_html($region); ?>
            </div>
            <?php endif; ?>
            <?php if (!empty($hotel['notes'])) : ?>
            <div class="fh-qt-cert__notes"><?php echo esc_html($hotel['notes']); ?></div>
            <?php endif; ?>
        </div>

        <?php /* Price */ ?>
        <div class="fh-purchase__from"><?php echo $product->is_type('variable') ? esc_html__('Starting from', 'fishotel') : esc_html__('Price', 'fishotel'); ?></div>
        <div class="fh-purchase__price"><?php echo $product->get_price_html(); ?></div>

        <?php /* Variation selectors + Add to Cart form */ ?>
        <?php do_action('woocommerce_before_add_to_cart_form'); ?>
        <form class="fh-purchase__form variations_form cart"
              action="<?php echo esc_url(apply_filters('woocommerce_add_to_cart_form_action', $product->get_permalink())); ?>"
              method="post"
              enctype='multipart/form-data'
              data-product_id="<?php echo absint($product->get_id()); ?>"
              data-product_variations="<?php echo esc_attr(htmlspecialchars(wp_json_encode($product->get_available_variations()))); ?>">

            <?php if ($product->is_type('variable')) : ?>
            <div class="fh-variations">
                <?php foreach ($product->get_variation_attributes() as $attr_name => $options) :
                    $label = wc_attribute_label($attr_name);
                    $selected = isset($_REQUEST['attribute_' . sanitize_title($attr_name)]) ? wc_clean(wp_unslash($_REQUEST['attribute_' . sanitize_title($attr_name)])) : $product->get_variation_default_attribute($attr_name);
                ?>
                <div class="fh-variation-group">
                    <div class="fh-variation-label">
                        <?php echo esc_html($label); ?>
                        <span class="fh-variation-selected">
                            <?php echo $selected ? '— ' . esc_html($selected) . ' selected' : ''; ?>
                        </span>
                    </div>
                    <div class="fh-var-buttons" data-attribute="attribute_<?php echo esc_attr(sanitize_title($attr_name)); ?>">
                        <?php foreach ($options as $option) :
                            $is_sel = sanitize_title($option) === sanitize_title($selected);
                        ?>
                            <button type="button"
                                    class="fh-var-btn <?php echo $is_sel ? 'selected' : ''; ?>"
                                    data-value="<?php echo esc_attr($option); ?>"
                                    data-attribute="attribute_<?php echo esc_attr(sanitize_title($attr_name)); ?>">
                                <?php echo esc_html($option); ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                    <select name="attribute_<?php echo esc_attr(sanitize_title($attr_name)); ?>"
                            id="attribute_<?php echo esc_attr(sanitize_title($attr_name)); ?>"
                            class="fh-var-select-hidden"
                            style="display:none"
                            data-attribute_name="attribute_<?php echo esc_attr(sanitize_title($attr_name)); ?>">
                        <option value=""><?php esc_html_e('Choose an option', 'fishotel'); ?></option>
                        <?php foreach ($options as $option) : ?>
                            <option value="<?php echo esc_attr($option); ?>" <?php selected($selected, $option); ?>>
                                <?php echo esc_html($option); ?>
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

            <?php /* Add to cart row */ ?>
            <div class="fh-add-to-cart">
                <div class="fh-qty">
                    <div class="fh-qty__num" id="fh-qty-display">1</div>
                    <button type="button" class="fh-qty__up" aria-label="Increase">&#9650;</button>
                    <button type="button" class="fh-qty__down" aria-label="Decrease">&#9660;</button>
                    <input type="hidden" name="quantity" id="fh-qty-input" value="1" min="1">
                </div>
                <button type="submit" name="add-to-cart" value="<?php echo esc_attr($product->get_id()); ?>" class="fh-btn fh-btn--gold fh-btn--full">
                    <?php esc_html_e('Add to Cart', 'fishotel'); ?>
                </button>
            </div>

            <?php /* Trust strip */ ?>
            <div class="fh-trust-strip">
                <span class="fh-trust-strip__item">&#10003; 28-day QT protocol</span>
                <span class="fh-trust-strip__item">&#10003; Live arrival guarantee</span>
                <span class="fh-trust-strip__item">&#10003; Ships Mon&ndash;Tue</span>
            </div>

            <?php do_action('woocommerce_after_add_to_cart_button'); ?>
        </form>
        <?php do_action('woocommerce_after_add_to_cart_form'); ?>

        <?php /* Product meta */ ?>
        <div class="fh-product-meta">
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
            <?php if ($tags) : ?>
            <div class="fh-product-meta__row">
                <span class="fh-product-meta__label">Tags</span>
                <span><?php echo wc_get_product_tag_list($product_id, ', '); ?></span>
            </div>
            <?php endif; ?>
        </div>

    </div><!-- .fh-purchase -->
</div><!-- .fh-product-layout -->

<?php /* ── ABOUT THIS SPECIES — Stat Grid from meta fields ── */ ?>
<?php
$desc_raw    = $product->get_description();
$sci_name    = get_post_meta( $product_id, '_fh_scientific_name', true );
$common      = get_post_meta( $product_id, '_fh_common_names', true );
$max_length  = get_post_meta( $product_id, '_fh_max_length', true );
$tank_size   = get_post_meta( $product_id, '_fh_min_tank_size', true );
$temperament = get_post_meta( $product_id, '_fh_temperament', true );
$reef_safe   = get_post_meta( $product_id, '_fh_reef_safe', true );

$spec_rows = [];
if ( $sci_name )    $spec_rows[] = [ 'Scientific Name', esc_html( $sci_name ) ];
if ( $common )      $spec_rows[] = [ 'Common Names',    esc_html( $common ) ];
if ( $max_length )  $spec_rows[] = [ 'Max Length',      esc_html( $max_length ) ];
if ( $tank_size )   $spec_rows[] = [ 'Min Tank Size',   esc_html( $tank_size ) ];
if ( $temperament ) $spec_rows[] = [ 'Temperament',     esc_html( $temperament ) ];
if ( $reef_safe ) {
    $reef_labels = [
        'yes'     => '<span class="fh-spec-badge fh-spec-badge--green">&#10003; Yes</span>',
        'no'      => '<span class="fh-spec-badge fh-spec-badge--amber">&#10007; No</span>',
        'caution' => '<span class="fh-spec-badge fh-spec-badge--amber">&#9888; With Caution</span>',
    ];
    $spec_rows[] = [ 'Reef Safe', $reef_labels[ $reef_safe ] ?? esc_html( $reef_safe ) ];
}
if ( $region )      $spec_rows[] = [ 'Region',          esc_html( $region ) ];
?>

<?php if ( ! empty( $spec_rows ) || $desc_raw ) : ?>
<div class="fh-species">
    <div class="fh-species__inner">
        <span class="fh-eyebrow">Description</span>
        <span class="fh-rule"></span>
        <h2 class="fh-serif-head" style="font-size:30px; margin-bottom:32px;">About This <em style="font-style:normal; color:var(--fh-gold);">Species</em></h2>

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
        <?php if ( $prose ) : ?>
        <div class="fh-species__prose">
            <h3 class="fh-species__prose-label">About This Fish</h3>
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
$habitat       = get_post_meta( $product_id, '_fh_habitat', true );
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
        <h2 class="fh-serif-head" style="font-size:30px; margin-bottom:6px;">Also in <em style="font-style:normal; color:var(--fh-gold);">Quarantine</em></h2>
        <p style="font-size:11px; color:var(--fh-text-3); margin-bottom:36px;">Currently checking in at The FisHotel</p>
        <div class="fh-product-grid">
            <?php foreach ($related_products as $rel) :
                $rel_id = $rel->get_ID();
            ?>
                <a href="<?php echo esc_url($rel->get_permalink()); ?>" class="fh-fish-card">
                    <div class="fh-fish-card__image">
                        <?php echo $rel->get_image('fishotel-product-card'); ?>
                    </div>
                    <div class="fh-fish-card__body">
                        <div class="fh-fish-card__name"><?php echo esc_html($rel->get_name()); ?></div>
                        <div class="fh-fish-card__latin"><?php echo wp_kses_post($rel->get_short_description()); ?></div>
                        <div class="fh-fish-card__footer">
                            <span class="fh-fish-card__price"><?php echo $rel->get_price_html(); ?></span>
                        </div>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<?php endwhile; ?>

<?php get_footer(); ?>
