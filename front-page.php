<?php
/**
 * FisHotel — Homepage (front-page.php)
 * @package FisHotel
 */
get_header(); ?>

<?php /* ══════════════════════════════════════
   HERO — full-bleed, cinematic
══════════════════════════════════════ */ ?>
<section class="fh-hero">
    <div class="fh-hero__bg"></div>
    <div class="fh-hero__bubbles" id="fh-bubbles"></div>
    <div class="fh-hero__content">
        <p class="fh-eyebrow" style="letter-spacing:5px; margin-bottom:20px;">Premium Marine Fish Quarantine</p>
        <h1 class="fh-hero__title">
            <span class="fh-hero__title-line">Where Fish</span>
            <em class="fh-hero__title-em">Check Out</em>
            <span class="fh-hero__title-line">Healthy</span>
        </h1>
        <p class="fh-hero__subtitle">Every saltwater fish quarantined, observed, and treated before it ever reaches your tank. Because your reef deserves nothing less.</p>
        <div class="fh-hero__actions">
            <a href="<?php echo esc_url( get_permalink( wc_get_page_id('shop') ) ); ?>" class="fh-btn fh-btn--gold">View Available Fish</a>
            <a href="<?php echo esc_url( home_url('/our-process/') ); ?>" class="fh-btn fh-btn--ghost">Our Process</a>
        </div>
    </div>
    <div class="fh-hero__scroll"><span>Scroll</span><div class="fh-hero__scroll-line"></div></div>
</section>

<?php /* ══════════════════════════════════════
   STATS BAR
══════════════════════════════════════ */ ?>
<div class="fh-stats-bar">
    <div class="fh-stats-bar__inner">
        <div class="fh-stat"><span class="fh-stat__num">30+</span><span class="fh-stat__label">Day Min. Quarantine</span></div>
        <div class="fh-stat"><span class="fh-stat__num">100%</span><span class="fh-stat__label">Transparency Policy</span></div>
        <div class="fh-stat"><span class="fh-stat__num">3</span><span class="fh-stat__label">Vetted Importers</span></div>
        <div class="fh-stat"><span class="fh-stat__num">★★★★★</span><span class="fh-stat__label">Community Rated</span></div>
    </div>
</div>

<?php /* ══════════════════════════════════════
   AVAILABLE FISH — live from WooCommerce
══════════════════════════════════════ */ ?>
<?php
$available_count = class_exists( 'FisHotel_Admin_Settings' )
    ? (int) FisHotel_Admin_Settings::get( 'fh_home_available_count' )
    : 8;
if ( $available_count < 1 )  $available_count = 8;
if ( $available_count > 24 ) $available_count = 24;

$available_args = [
    'post_type'      => 'product',
    'posts_per_page' => $available_count,
    'post_status'    => 'publish',
    'tax_query'      => [[
        'taxonomy' => 'product_cat',
        'field'    => 'slug',
        'terms'    => 'quarantined-fish',
    ]],
    'meta_query'     => [[
        'key'     => '_fishotel_qt_stage',
        'value'   => 'souvenir-shop',
        'compare' => '=',
    ]],
];
$available_query = new WP_Query($available_args);

// Fallback — just show recent quarantined fish if none flagged yet
if (!$available_query->have_posts()) {
    $available_args['meta_query'] = [];
    $available_query = new WP_Query($available_args);
}
?>
<?php if ($available_query->have_posts()) : ?>
<section class="fh-section" style="padding-top:72px; padding-bottom:72px;">
    <div style="max-width:var(--fh-max-width); margin:0 auto; padding:0 var(--fh-page-pad);">
        <div style="display:flex; align-items:flex-end; justify-content:space-between; margin-bottom:36px;">
            <div>
                <span class="fh-eyebrow">Currently Checking Out</span>
                <h2 class="fh-home-section-title" style="margin-top:8px;">Available <em>Now</em></h2>
            </div>
            <a href="<?php echo esc_url( get_permalink( wc_get_page_id('shop') ) ); ?>" class="fh-btn fh-btn--ghost" style="padding:10px 24px; font-size:9px;">View All Fish</a>
        </div>
        <div class="fh-product-grid" style="padding:0;">
            <?php while ($available_query->have_posts()) : $available_query->the_post();
                global $product;
                $pid   = $product->get_ID();
                $hotel = function_exists('fishotel_get_hotel_data') ? fishotel_get_hotel_data($pid) : [];
                $days  = $hotel['days_in_qt'] ?? '';
            ?>
                <div class="fh-fish-card-wrap product">
                    <?php do_action( 'woocommerce_before_shop_loop_item' ); ?>
                    <a href="<?php the_permalink(); ?>" class="fh-fish-card">
                        <div class="fh-fish-card__image">
                            <?php fishotel_product_thumbnail( $pid, 'fishotel-product-card' ); ?>
                            <?php do_action( 'woocommerce_before_shop_loop_item_title' ); ?>
                            <?php fishotel_cs_render_card_badge( $pid ); ?>
                            <?php $cs_release_ts = fishotel_cs_release_ts( $pid ); ?>
                            <?php if ( ! $cs_release_ts ) : ?>
                            <span class="fh-fish-card__status fh-fish-card__status--available">Available</span>
                            <?php endif; ?>
                            <?php if ($days && ! $cs_release_ts) : ?>
                            <div class="fh-fish-card__days">
                                <span class="fh-fish-card__days-num"><?php echo esc_html($days); ?></span>
                                <span class="fh-fish-card__days-label">Days QT</span>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="fh-fish-card__body">
                            <div class="fh-fish-card__name"><?php the_title(); ?></div>
                            <?php do_action( 'woocommerce_after_shop_loop_item_title' ); ?>
                            <div class="fh-fish-card__latin"><?php echo wp_kses_post($product->get_short_description()); ?></div>
                            <div class="fh-fish-card__footer">
                                <?php if ( $cs_release_ts ) : ?>
                                    <?php fishotel_cs_render_card_line( $pid ); ?>
                                <?php else : ?>
                                    <span class="fh-fish-card__price"><?php echo $product->get_price_html(); ?></span>
                                    <?php if ($days) : ?><span class="fh-fish-card__qt"><?php echo esc_html($days); ?> Days QT</span><?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </a>
                    <?php do_action( 'woocommerce_after_shop_loop_item' ); ?>
                </div>
            <?php endwhile; wp_reset_postdata(); ?>
        </div>
    </div>
</section>
<?php endif; ?>

<?php /* ══════════════════════════════════════
   THE DIFFERENCE — about section
══════════════════════════════════════ */ ?>
<section class="fh-section fh-section-dk">
    <div class="fh-home-split" style="max-width:var(--fh-max-width); margin:0 auto; padding:0 var(--fh-page-pad); display:grid; grid-template-columns:1fr 1fr; gap:80px; align-items:center;">
        <div>
            <span class="fh-eyebrow">The FisHotel Difference</span>
            <span class="fh-rule"></span>
            <h2 class="fh-home-section-title">Your fish deserve a proper <em>welcome</em></h2>
            <p style="font-size:14px; line-height:1.8; color:var(--fh-text-2); margin-bottom:16px;">Most fish go straight from the bag to your display tank — stressed, potentially sick, and carrying parasites your tank has never seen.</p>
            <p style="font-size:14px; line-height:1.8; color:var(--fh-text-2); margin-bottom:32px;">Every fish at FisHotel spends a minimum of 30 days in dedicated quarantine, monitored daily, treated proactively, and cleared before entering your system.</p>
            <a href="<?php echo esc_url( home_url('/our-process/') ); ?>" class="fh-btn fh-btn--gold">Learn Our Approach</a>
        </div>
        <div class="fh-home-logo-block">
            <img src="<?php echo esc_url( 'https://fishotel.com/wp-content/uploads/2021/12/FisHotel-Retro-Hotel-Sign.gif' ); ?>"
                 alt="The FisHotel" style="width:100%; max-width:400px; margin:0 auto; display:block;">
            <div class="fh-qt-badge">
                <span style="font-family:var(--fh-serif); font-size:48px; font-weight:700; color:var(--fh-gold-lt); line-height:1; display:block;">30</span>
                <span style="font-size:9px; font-weight:700; letter-spacing:2px; text-transform:uppercase; color:var(--fh-text-3);">Day Min. QT</span>
            </div>
        </div>
    </div>
</section>

<?php /* ══════════════════════════════════════
   HOW IT WORKS — stages grid
══════════════════════════════════════ */ ?>
<section class="fh-section">
    <div style="max-width:var(--fh-max-width); margin:0 auto; padding:0 var(--fh-page-pad);">
        <div style="text-align:center; margin-bottom:56px;">
            <span class="fh-eyebrow">How It Works</span>
            <span class="fh-rule" style="margin:14px auto;"></span>
            <h2 class="fh-home-section-title">The Quarantine <em>Journey</em></h2>
        </div>
        <div class="fh-stages-grid">
            <div class="fh-stage-card">
                <div class="fh-stage-card__num">01</div>
                <h3 class="fh-stage-card__title">Check-In</h3>
                <p class="fh-stage-card__body">Fish arrive from vetted importers and are immediately transferred to individual quarantine systems. Initial health assessment documented.</p>
            </div>
            <div class="fh-stage-card">
                <div class="fh-stage-card__num">02</div>
                <h3 class="fh-stage-card__title">Observation</h3>
                <p class="fh-stage-card__body">Daily monitoring for disease symptoms, eating behavior, and stress indicators. All observations logged — good days and bad ones.</p>
            </div>
            <div class="fh-stage-card">
                <div class="fh-stage-card__num">03</div>
                <h3 class="fh-stage-card__title">Treatment</h3>
                <p class="fh-stage-card__body">Proactive treatment protocols for common saltwater pathogens. No shortcuts — every cycle completed before a fish is listed.</p>
            </div>
            <div class="fh-stage-card">
                <div class="fh-stage-card__num">04</div>
                <h3 class="fh-stage-card__title">The Souvenir Shop</h3>
                <p class="fh-stage-card__body">Fish are listed for reservation only after full clearance. Clients can follow the quarantine journey before committing.</p>
            </div>
            <div class="fh-stage-card">
                <div class="fh-stage-card__num">05</div>
                <h3 class="fh-stage-card__title">Flying Home</h3>
                <p class="fh-stage-card__body">Carefully packaged with full documentation of quarantine history, treatments, and feeding record. Your fish arrives with a story.</p>
                <img src="<?php echo esc_url( 'https://fishotel.com/wp-content/uploads/2026/03/fishotel-plane.png' ); ?>" alt="" style="width:60px; margin-top:16px; opacity:.6;">
            </div>
            <div class="fh-stage-card fh-stage-card--dark">
                <div class="fh-stage-card__num" style="color:var(--fh-gold); opacity:.5;">✓</div>
                <h3 class="fh-stage-card__title" style="color:var(--fh-gold-lt);">Ready For Your Reef</h3>
                <p class="fh-stage-card__body" style="color:rgba(225,218,209,.45);">No emergency dips. No surprises. Just a healthy, eating fish that's had a proper stay.</p>
            </div>
        </div>
    </div>
</section>

<?php /* ══════════════════════════════════════
   TRANSPARENCY SECTION — dark with radial glow
══════════════════════════════════════ */ ?>
<section class="fh-section fh-section-dkr fh-transparency">
    <div style="max-width:var(--fh-max-width); margin:0 auto; padding:0 var(--fh-page-pad); display:grid; grid-template-columns:1fr 1fr; gap:80px; align-items:center;">
        <div>
            <span class="fh-eyebrow">Why FisHotel</span>
            <span class="fh-rule"></span>
            <h2 class="fh-home-section-title">Radical transparency in an industry <em>that has none</em></h2>
            <p style="font-size:14px; line-height:1.8; color:var(--fh-text-2); margin-bottom:36px;">We publish our loss rates. We name our importers. We tell you when a fish had a rough week. It's unusual in this hobby — but it's ours, and it's why our clients stay loyal.</p>
            <a href="<?php echo esc_url( home_url('/about-us/') ); ?>" class="fh-btn fh-btn--ghost">Read Our Transparency Policy</a>
        </div>
        <ul class="fh-features-list">
            <li class="fh-feature"><div class="fh-feature__dot"></div><div><strong>Published Loss Rates</strong>Every importer we use has documented mortality statistics, updated regularly and available before purchase.</div></li>
            <li class="fh-feature"><div class="fh-feature__dot"></div><div><strong>No Surprises</strong>Treatment history, feeding log, and behavioral notes travel with every fish. What happened in quarantine doesn't stay in quarantine.</div></li>
            <li class="fh-feature"><div class="fh-feature__dot"></div><div><strong>Community-First</strong>Active on Humble.Fish. We're hobbyists first, business second.</div></li>
            <li class="fh-feature"><div class="fh-feature__dot"></div><div><strong>Solo Operated</strong>One person, one standard. Every fish is personal.</div></li>
        </ul>
    </div>
</section>

<?php /* ══════════════════════════════════════
   TESTIMONIAL QUOTE — admin-managed repeater
══════════════════════════════════════ */ ?>
<?php
$testimonials = class_exists( 'FisHotel_Admin_Settings' )
    ? FisHotel_Admin_Settings::get_home_testimonials()
    : [];
$tm_count = count( $testimonials );
if ( $tm_count > 0 ) :
    $tm_multi = $tm_count > 1;
?>
<section class="fh-quote-section<?php echo $tm_multi ? ' fh-quote-section--multi' : ''; ?>"
         data-fh-testimonials="<?php echo $tm_multi ? '1' : '0'; ?>">
    <span class="fh-quote-mark" aria-hidden="true">"</span>
    <div class="fh-quote-stack">
        <?php foreach ( $testimonials as $i => $tm ) :
            $author = isset( $tm['author'] ) ? trim( $tm['author'] ) : '';
            $source = isset( $tm['source'] ) ? trim( $tm['source'] ) : '';
            $has_attr = ( $author !== '' || $source !== '' );
        ?>
            <figure class="fh-quote-slide<?php echo $i === 0 ? ' is-active' : ''; ?>"
                    data-index="<?php echo (int) $i; ?>"
                    aria-hidden="<?php echo $i === 0 ? 'false' : 'true'; ?>">
                <blockquote class="fh-quote-text"><?php echo wp_kses_post( $tm['quote'] ); ?></blockquote>
                <?php if ( $has_attr ) : ?>
                <figcaption class="fh-quote-author">
                    <?php
                    $bits = [];
                    if ( $author !== '' ) $bits[] = esc_html( $author );
                    if ( $source !== '' ) $bits[] = esc_html( $source );
                    echo implode( ' <span class="fh-quote-divider" aria-hidden="true">·</span> ', $bits );
                    ?>
                </figcaption>
                <?php endif; ?>
            </figure>
        <?php endforeach; ?>
    </div>
    <?php if ( $tm_multi ) : ?>
    <div class="fh-quote-dots" role="tablist" aria-label="Testimonial pagination">
        <?php foreach ( $testimonials as $i => $tm ) : ?>
            <button type="button"
                    class="fh-quote-dot<?php echo $i === 0 ? ' is-active' : ''; ?>"
                    role="tab"
                    aria-label="<?php printf( esc_attr__( 'Show testimonial %d', 'fishotel' ), $i + 1 ); ?>"
                    aria-selected="<?php echo $i === 0 ? 'true' : 'false'; ?>"
                    data-target="<?php echo (int) $i; ?>"></button>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php /* ══════════════════════════════════════
   CTA SECTION
══════════════════════════════════════ */ ?>
<section class="fh-cta-section">
    <h2 class="fh-cta-title">Ready to check <em>your fish</em> in?</h2>
    <p class="fh-cta-sub">Browse current availability or get in touch about an upcoming arrival.</p>
    <div style="display:flex; gap:16px; justify-content:center; position:relative;">
        <a href="<?php echo esc_url( get_permalink( wc_get_page_id('shop') ) ); ?>" class="fh-btn fh-btn--gold">View Current Availability</a>
        <a href="<?php echo esc_url( home_url('/newsletter/') ); ?>" class="fh-btn fh-btn--ghost">Get Arrival Notifications</a>
    </div>
</section>

<?php get_footer(); ?>
