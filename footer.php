<?php
/**
 * FisHotel Theme — footer.php
 * TODO: Populate with real nav menus in Phase 2
 *
 * @package FisHotel
 */
?>

<footer class="site-footer" id="colophon">

    <?php /* ── FOOTER COLUMNS ── */ ?>
    <div class="site-footer__main">
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
        <div>
            <p class="site-footer__col-title">Info</p>
            <ul class="site-footer__col-links">
                <li><a href="<?php echo esc_url( home_url( '/faq' ) ); ?>">FAQ's</a></li>
                <li><a href="<?php echo esc_url( home_url( '/about-us' ) ); ?>">About Us</a></li>
                <li><a href="<?php echo esc_url( home_url( '/shop' ) ); ?>">Shop</a></li>
            </ul>
        </div>
        <div>
            <p class="site-footer__col-title">Community</p>
            <ul class="site-footer__col-links">
                <li><a href="https://humble.fish" target="_blank" rel="noopener">Humble.Fish</a></li>
                <li><a href="https://reef2reef.com" target="_blank" rel="noopener">Reef2Reef</a></li>
                <li><a href="<?php echo esc_url( home_url( '/newsletter' ) ); ?>">Newsletter</a></li>
            </ul>
        </div>
    </div>
    <div class="site-footer__bottom">
        <span class="site-footer__copy">
            &copy; <?php echo date( 'Y' ); ?> The FisHotel &middot; fishotel.com
        </span>
        <span class="site-footer__copy">
            <?php printf( esc_html__( 'Powered by %s', 'fishotel' ), '<a href="https://wordpress.org">WordPress</a>' ); ?>
        </span>
    </div>
</footer>

<?php wp_footer(); ?>
</body>
</html>
