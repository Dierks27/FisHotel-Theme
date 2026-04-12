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
    $journal     = class_exists('FisHotel_Journal') ? FisHotel_Journal::get_entries($product_id) : [];
    $tags        = get_the_terms($product_id, 'product_tag') ?: [];
    $gallery_ids = $product->get_gallery_image_ids();
    $main_img_id = $product->get_image_id();
    $stage       = $hotel['qt_stage'] ?? '';
    $is_avail    = $hotel['is_available'] ?? false;
    $days        = $hotel['days_in_qt'] ?? '—';
    $treatments  = $hotel['treatments'] ?? [];

    // Status CSS modifier
    if ( $stage === 'souvenir-shop' )  { $status_mod = ''; }
    elseif ( $stage === 'treatment' )  { $status_mod = 'fh-hotel-status--treatment'; }
    else                               { $status_mod = 'fh-hotel-status--pending'; }
?>

<?php /* ── PAGE HERO BANNER ── */ ?>
<div class="page-hero">
    <div class="page-hero__inner">
        <nav class="page-hero__breadcrumb" aria-label="Breadcrumb">
            <a href="<?php echo esc_url( home_url('/') ); ?>">Home</a>
            <span>/</span>
            <a href="<?php echo esc_url( get_permalink( wc_get_page_id('shop') ) ); ?>">Shop</a>
            <span>/</span>
            <?php $cats = wc_get_product_category_list($product_id, ' / ');
            if ($cats) echo '<span>' . wp_strip_all_tags($cats) . '</span><span>/</span>'; ?>
            <span style="color:var(--fh-text-2)"><?php the_title(); ?></span>
        </nav>

        <h1 class="page-hero__title">
            <?php
            $words = explode(' ', get_the_title(), 2);
            echo '<span class="word-1">' . esc_html($words[0]) . '</span>';
            if (!empty($words[1])) echo '&nbsp;<span class="word-2">' . esc_html($words[1]) . '</span>';
            ?>
        </h1>

        <div class="page-hero__meta">
            <?php
            // Latin name from short description
            $short = $product->get_short_description();
            if ($short) : ?>
                <span class="page-hero__latin"><?php echo wp_kses_post($short); ?></span>
            <?php endif; ?>

            <?php if ($tags) : ?>
            <div class="fh-tag-list">
                <?php foreach ($tags as $tag) :
                    $slug = $tag->slug;
                    $mod = '';
                    if (str_contains($slug,'reef-safe'))   $mod = 'fh-tag--reef-safe';
                    elseif (str_contains($slug,'peaceful')) $mod = 'fh-tag--peaceful';
                    elseif (str_contains($slug,'carnivore')) $mod = 'fh-tag--carnivore';
                    elseif (str_contains($slug,'aggressive')) $mod = 'fh-tag--aggressive';
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

            <?php /* Status badge */ ?>
            <?php if ($stage) : ?>
            <div class="fh-gallery__badge-status <?php echo $stage === 'treatment' ? 'fh-gallery__badge-status--treatment' : ''; ?>">
                <?php if ($is_avail) : ?><div class="fh-status-pulse"></div><?php endif; ?>
                <?php echo esc_html($hotel['stage_label'] ?? 'Quarantine'); ?>
            </div>
            <?php endif; ?>

            <?php /* Days badge */ ?>
            <?php if ($days !== '—') : ?>
            <div class="fh-gallery__badge-qt">
                <span class="fh-gallery__qt-num"><?php echo esc_html($days); ?></span>
                <span class="fh-gallery__qt-label">Days In<br>Quarantine</span>
            </div>
            <?php endif; ?>
        </div>

        <?php /* Thumbs */ ?>
        <?php if (!empty($gallery_ids)) : ?>
        <div class="fh-gallery__thumbs">
            <?php // First thumb = main image
            if ($main_img_id) : ?>
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

        <?php /* Price */ ?>
        <div class="fh-purchase__from"><?php echo $product->is_type('variable') ? esc_html__('Starting from', 'fishotel') : esc_html__('Price', 'fishotel'); ?></div>
        <div class="fh-purchase__price"><?php echo $product->get_price_html(); ?></div>
        <p class="fh-purchase__price-note"><?php esc_html_e('Includes quarantine, full treatment history & live arrival guarantee.', 'fishotel'); ?></p>

        <?php /* Hotel status panel — only if plugin data exists */ ?>
        <?php if (!empty($hotel) && !empty($stage)) : ?>
        <div class="fh-hotel-status <?php echo esc_attr($status_mod); ?>">
            <div class="fh-hotel-status__header">
                <div class="fh-hotel-status__title">
                    <?php if ($is_avail) : ?><div class="fh-status-pulse"></div><?php endif; ?>
                    <?php echo esc_html($hotel['health_note'] ?: $hotel['stage_label']); ?>
                </div>
                <span class="fh-hotel-status__stage"><?php echo esc_html($hotel['stage_label']); ?></span>
            </div>
            <div class="fh-hotel-status__stats">
                <div class="fh-hotel-stat">
                    <span class="fh-hotel-stat__val"><?php echo esc_html($days); ?></span>
                    <span class="fh-hotel-stat__key">Days in QT</span>
                </div>
                <div class="fh-hotel-stat">
                    <span class="fh-hotel-stat__val"><?php echo esc_html($hotel['health_grade'] ?: '—'); ?></span>
                    <span class="fh-hotel-stat__key">Health Grade</span>
                </div>
                <div class="fh-hotel-stat">
                    <span class="fh-hotel-stat__val"><?php echo $hotel['arrival_date'] ? esc_html(date('M j', strtotime($hotel['arrival_date']))) : '—'; ?></span>
                    <span class="fh-hotel-stat__key">Check-in</span>
                </div>
                <div class="fh-hotel-stat">
                    <span class="fh-hotel-stat__val"><?php echo esc_html($hotel['foods_eating'] ? count(explode(',', $hotel['foods_eating'])) : '—'); ?></span>
                    <span class="fh-hotel-stat__key">Foods Eating</span>
                </div>
            </div>
            <?php if (!empty($treatments)) : ?>
            <div class="fh-hotel-status__treatments">
                <span class="fh-treatment-label">Treatments:</span>
                <?php foreach ($treatments as $t) : ?>
                    <span class="fh-treatment-badge"><?php echo esc_html($t['name']); ?></span>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

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

            <?php /* Selected variation summary — JS updates this */ ?>
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
                    <button type="button" class="fh-qty__up" aria-label="Increase">▲</button>
                    <button type="button" class="fh-qty__down" aria-label="Decrease">▼</button>
                    <input type="hidden" name="quantity" id="fh-qty-input" value="1" min="1">
                </div>
                <button type="submit" name="add-to-cart" value="<?php echo esc_attr($product->get_id()); ?>" class="fh-btn fh-btn--gold fh-btn--full">
                    <?php echo esc_html($product->is_type('variable') ? __('Add to Cart', 'fishotel') : __('Add to Cart', 'fishotel')); ?>
                </button>
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
            <?php if (!empty($hotel['importer'])) : ?>
            <div class="fh-product-meta__row">
                <span class="fh-product-meta__label">Importer</span>
                <span><?php echo esc_html($hotel['importer']); ?></span>
            </div>
            <?php endif; ?>
            <?php if (!empty($hotel['foods_eating'])) : ?>
            <div class="fh-product-meta__row">
                <span class="fh-product-meta__label">Eating</span>
                <span><?php echo esc_html($hotel['foods_eating']); ?></span>
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

<?php /* ── DOSSIER SECTION ── */ ?>
<div class="fh-dossier">
    <div class="fh-dossier__inner">

        <div>
            <span class="fh-eyebrow">Care Guide</span>
            <span class="fh-rule"></span>
            <h2 class="fh-serif-head" style="font-size:30px; margin-bottom:24px;">Fish <em style="font-style:normal; color:var(--fh-gold);">Dossier</em></h2>
            <table class="fh-spec-table">
                <?php
                // Parse the product description for structured data
                $desc = $product->get_description();
                // Look for bullet point data
                $spec_fields = [
                    'Scientific Name'    => '',
                    'Common Names'       => '',
                    'Maximum Length'     => '',
                    'Minimum Aquarium Size' => '',
                    'Reef Safety'        => '',
                    'Temperament'        => '',
                    'Foods and Feeding'  => '',
                ];

                foreach ($spec_fields as $label => $val) {
                    preg_match('/' . preg_quote($label, '/') . '[:\s]+([^\n<]+)/i', $desc, $matches);
                    if (!empty($matches[1])) {
                        $spec_fields[$label] = trim(strip_tags($matches[1]));
                    }
                }

                foreach ($spec_fields as $label => $val) :
                    if (empty($val)) continue;
                    $badge = '';
                    if (stripos($label, 'reef') !== false) {
                        $is_safe = stripos($val, 'reef-safe') !== false || stripos($val, 'safe') !== false;
                        $badge = $is_safe
                            ? '<span class="fh-spec-badge fh-spec-badge--green">✓ Reef Safe</span>'
                            : '<span class="fh-spec-badge fh-spec-badge--amber">With Caution</span>';
                        $val = $badge;
                    }
                ?>
                <tr>
                    <td><?php echo esc_html($label); ?></td>
                    <td><?php echo $badge ? wp_kses_post($badge) : esc_html($val); ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (!empty($hotel['importer'])) : ?>
                <tr>
                    <td>Importer</td>
                    <td><?php echo esc_html($hotel['importer']); ?></td>
                </tr>
                <?php endif; ?>
                <?php if (!empty($hotel['arrival_date'])) : ?>
                <tr>
                    <td>Check-in Date</td>
                    <td><?php echo esc_html(date('F j, Y', strtotime($hotel['arrival_date']))); ?></td>
                </tr>
                <?php endif; ?>
            </table>
        </div>

        <div>
            <span class="fh-eyebrow">Description</span>
            <span class="fh-rule"></span>
            <h2 class="fh-serif-head" style="font-size:30px; margin-bottom:24px;">About This <em style="font-style:normal; color:var(--fh-gold);">Species</em></h2>
            <div class="desc-prose" style="font-size:13px; line-height:1.8; color:var(--fh-text-2);">
                <?php echo wp_kses_post($product->get_description()); ?>
            </div>
        </div>

    </div>
</div>

<?php /* ── QUARANTINE JOURNAL ── */ ?>
<?php if (!empty($journal)) : ?>
<div class="fh-journal">
    <div class="fh-journal__inner">
        <div class="fh-journal__header">
            <div>
                <span class="fh-eyebrow">FisHotel Transparency</span>
                <h2 class="fh-serif-head" style="font-size:30px; margin-top:8px;">
                    Quarantine <em style="font-style:normal; color:var(--fh-gold);">Journal</em>
                </h2>
            </div>
            <span class="fh-journal__meta"><?php echo count($journal); ?> <?php esc_html_e('Days Documented', 'fishotel'); ?></span>
        </div>

        <div class="fh-journal__grid">

            <?php /* Sidebar */ ?>
            <div>
                <?php if (!empty($hotel['importer'])) : ?>
                <div class="fh-importer-card">
                    <span class="fh-eyebrow" style="margin-bottom:10px; display:block;">Sourced From</span>
                    <div style="font-family:var(--fh-serif); font-size:17px; font-weight:700; color:var(--fh-text-1); margin-bottom:3px;"><?php echo esc_html($hotel['importer']); ?></div>
                </div>
                <?php endif; ?>

                <?php if (!empty($hotel['arrival_date'])) : ?>
                <div class="fh-sidebar-stat">
                    <span class="fh-sidebar-stat__label">Check-in</span>
                    <span class="fh-sidebar-stat__val"><?php echo esc_html(date('M j, Y', strtotime($hotel['arrival_date']))); ?></span>
                </div>
                <?php endif; ?>
                <?php if (!empty($hotel['health_grade'])) : ?>
                <div class="fh-sidebar-stat">
                    <span class="fh-sidebar-stat__label">Health Grade</span>
                    <span class="fh-sidebar-stat__val fh-sidebar-stat__val--good"><?php echo esc_html($hotel['health_grade']); ?></span>
                </div>
                <?php endif; ?>
                <?php if (!empty($hotel['foods_eating'])) : ?>
                <div class="fh-sidebar-stat">
                    <span class="fh-sidebar-stat__label">Eating</span>
                    <span class="fh-sidebar-stat__val fh-sidebar-stat__val--good"><?php echo esc_html($hotel['foods_eating']); ?></span>
                </div>
                <?php endif; ?>
                <?php foreach ($treatments as $t) : ?>
                <div class="fh-sidebar-stat">
                    <span class="fh-sidebar-stat__label"><?php echo esc_html($t['name']); ?></span>
                    <span class="fh-sidebar-stat__val fh-sidebar-stat__val--good">✓ Done</span>
                </div>
                <?php endforeach; ?>
            </div>

            <?php /* Timeline */ ?>
            <div class="fh-timeline">
                <?php $type_config = class_exists('FisHotel_Journal') ? FisHotel_Journal::get_type_config() : [];
                foreach ($journal as $entry) :
                    $type = $entry['type'] ?? 'observation';
                    $mod  = $type_config[$type]['modifier'] ?? '';
                    $label = $type_config[$type]['label'] ?? ucfirst($type);
                    $d = !empty($entry['date']) ? strtotime($entry['date']) : time();
                ?>
                <div class="fh-entry">
                    <div class="fh-entry__date">
                        <span class="fh-entry__day"><?php echo date('j', $d); ?></span>
                        <span class="fh-entry__mon"><?php echo date('M', $d); ?></span>
                    </div>
                    <div class="fh-entry__dot <?php echo $mod ? 'fh-entry__dot--' . esc_attr($mod) : ''; ?>"></div>
                    <div class="fh-entry__card <?php echo $mod ? 'fh-entry__card--' . esc_attr($mod) : ''; ?>">
                        <span class="fh-entry__type"><?php echo esc_html($label); ?></span>
                        <p class="fh-entry__text"><?php echo wp_kses_post($entry['text']); ?></p>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

        </div>
    </div>
</div>
<?php endif; ?>

<?php /* ── RELATED PRODUCTS ── */ ?>
<?php
$related_ids = wc_get_related_products($product_id, 4);
if (!empty($related_ids)) :
    $related_products = array_map('wc_get_product', $related_ids);
    $related_products = array_filter($related_products);
?>
<div class="related-section" style="background:var(--fh-bg-dk); border-top:1px solid var(--fh-border); padding:72px 48px;">
    <div style="max-width:var(--fh-max-width); margin:0 auto;">
        <h2 class="fh-serif-head" style="font-size:30px; margin-bottom:6px;">Also in <em style="font-style:normal; color:var(--fh-gold);">Quarantine</em></h2>
        <p style="font-size:11px; color:var(--fh-text-3); margin-bottom:36px;">Currently checking in at The FisHotel</p>
        <div class="fh-product-grid" style="padding:0;">
            <?php foreach ($related_products as $rel) :
                $rel_id     = $rel->get_ID();
                $rel_hotel  = function_exists('fishotel_get_hotel_data') ? fishotel_get_hotel_data($rel_id) : [];
                $rel_stage  = $rel_hotel['qt_stage'] ?? '';
                $rel_days   = $rel_hotel['days_in_qt'] ?? '';
                if ( $rel_stage === 'souvenir-shop' )                                   { $rel_status_class = 'fh-fish-card__status--available'; }
                elseif ( in_array( $rel_stage, array('treatment','observation','checkin') ) ) { $rel_status_class = 'fh-fish-card__status--qt'; }
                else                                                                    { $rel_status_class = 'fh-fish-card__status--qt'; }
                if ( $rel_stage === 'souvenir-shop' ) { $rel_status_label = 'Available'; }
                elseif ( $rel_stage === 'treatment' ) { $rel_status_label = 'In Treatment'; }
                elseif ( $rel_stage === 'observation' ) { $rel_status_label = 'In QT'; }
                elseif ( $rel_stage === 'checkin' )   { $rel_status_label = 'Checked In'; }
                else                                  { $rel_status_label = 'In QT'; }
            ?>
                <a href="<?php echo esc_url($rel->get_permalink()); ?>" class="fh-fish-card">
                    <div class="fh-fish-card__image">
                        <?php echo $rel->get_image('fishotel-product-card'); ?>
                        <?php if ($rel_stage) : ?>
                        <span class="fh-fish-card__status <?php echo esc_attr($rel_status_class); ?>">
                            <?php echo esc_html($rel_status_label); ?>
                        </span>
                        <?php endif; ?>
                        <?php if ($rel_days) : ?>
                        <div class="fh-fish-card__days">
                            <span class="fh-fish-card__days-num"><?php echo esc_html($rel_days); ?></span>
                            <span class="fh-fish-card__days-label">Days QT</span>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="fh-fish-card__body">
                        <div class="fh-fish-card__name"><?php echo esc_html($rel->get_name()); ?></div>
                        <div class="fh-fish-card__latin"><?php echo wp_kses_post($rel->get_short_description()); ?></div>
                        <div class="fh-fish-card__footer">
                            <span class="fh-fish-card__price"><?php echo $rel->get_price_html(); ?></span>
                            <?php if ($rel_days) : ?>
                            <span class="fh-fish-card__qt"><?php echo esc_html($rel_days); ?> Days QT</span>
                            <?php endif; ?>
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
