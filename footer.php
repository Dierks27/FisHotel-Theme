<?php
/**
 * FisHotel Theme — footer.php
 *
 * Brand blurb + four link columns: the "footer" nav menu, the Tools column
 * (resolved from the tool-page templates so renamed slugs keep working),
 * static Info links and Community links.
 *
 * @package FisHotel
 */

// Tools column — each entry resolves by page template first, slug second
// (see fishotel_tool_page_url). Only links whose page exists and is
// published are rendered; the whole column is skipped when none do.
$fh_footer_tools = [];
if ( function_exists( 'fishotel_tool_page_url' ) ) {
    $fh_footer_tool_defs = [
        [ 'page-quarantine-help.php',    'medication-dosing',   __( 'Medication Dosing', 'fishotel' ) ],
        [ 'page-qt-scheduler.php',       'qt-scheduler',        __( 'QT Scheduler', 'fishotel' ) ],
        [ 'page-bleach-calculator.php',  'bleach-calculator',   __( 'Bleach Calculator', 'fishotel' ) ],
        [ 'page-compatibility-guide.php','compatibility-guide', __( 'Build-a-Tank', 'fishotel' ) ],
    ];
    foreach ( $fh_footer_tool_defs as $def ) {
        $tool_url = fishotel_tool_page_url( $def[0], $def[1] );
        if ( $tool_url !== '' ) {
            $fh_footer_tools[] = [ 'url' => $tool_url, 'label' => $def[2] ];
        }
    }
}
?>

<footer class="site-footer" id="colophon">

    <?php /* ── FOOTER COLUMNS ── */ ?>
    <div class="site-footer__main<?php echo empty( $fh_footer_tools ) ? ' site-footer__main--no-tools' : ''; ?>">
        <div>
            <div class="site-footer__brand-name">The FisHotel</div>
            <p class="site-footer__tagline">Premium saltwater fish quarantine. Every fish checked in, observed, treated, and cleared before it ever reaches your tank.</p>
        </div>
        <div>
            <p class="site-footer__col-title">Navigate</p>
            <?php wp_nav_menu([
                'theme_location' => 'footer',
                'container'      => false,
                'items_wrap'     => '<ul class="site-footer__col-links">%3$s</ul>',
                'fallback_cb'    => false,
                'depth'          => 1,
            ]); ?>
        </div>
        <?php if ( ! empty( $fh_footer_tools ) ) : ?>
        <div>
            <p class="site-footer__col-title"><?php esc_html_e( 'Tools', 'fishotel' ); ?></p>
            <ul class="site-footer__col-links">
                <?php foreach ( $fh_footer_tools as $tool ) : ?>
                <li><a href="<?php echo esc_url( $tool['url'] ); ?>"><?php echo esc_html( $tool['label'] ); ?></a></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>
        <div>
            <p class="site-footer__col-title">Info</p>
            <ul class="site-footer__col-links">
                <li><a href="<?php echo esc_url( home_url( '/faq' ) ); ?>">FAQ</a></li>
                <li><a href="<?php echo esc_url( home_url( '/about-us' ) ); ?>">About Us</a></li>
                <li><a href="<?php echo esc_url( home_url( '/shop' ) ); ?>">Shop</a></li>
            </ul>
        </div>
        <div>
            <p class="site-footer__col-title">Community</p>
            <ul class="site-footer__col-links">
                <li><a href="https://humble.fish" target="_blank" rel="noopener">Humble.Fish</a></li>
                <li><a href="<?php echo esc_url( home_url( '/newsletter' ) ); ?>">Newsletter</a></li>
            </ul>
        </div>
    </div>
    <div class="site-footer__bottom">
        <span class="site-footer__copy">
            &copy; <?php echo date( 'Y' ); ?> The FisHotel &middot; fishotel.com
        </span>
    </div>
</footer>

<?php wp_footer(); ?>
</body>
</html>
