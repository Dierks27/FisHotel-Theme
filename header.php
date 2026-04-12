<?php
/**
 * FisHotel Theme — header.php
 * Safari-2 split-nav: [LEFT NAV] [CENTERED LOGO] [RIGHT NAV]
 * @package FisHotel
 */
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>

<header class="site-header" id="masthead">
    <div class="site-header__inner">

        <nav class="site-header__nav site-header__nav--left">
            <?php if ( has_nav_menu( 'primary-left' ) ) :
                wp_nav_menu([ 'theme_location' => 'primary-left', 'container' => false, 'items_wrap' => '<ul class="site-header__menu">%3$s</ul>', 'fallback_cb' => false, 'depth' => 1 ]);
            else : ?>
                <ul class="site-header__menu">
                    <li <?php if ( is_front_page() ) echo 'class="current-menu-item"'; ?>><a href="<?php echo esc_url( home_url('/') ); ?>">Home</a></li>
                    <li><a href="<?php echo esc_url( home_url('/our-process/') ); ?>">Our Process</a></li>
                    <li <?php if ( is_shop() || is_product_category() || is_product() ) echo 'class="current-menu-item"'; ?>><a href="<?php echo esc_url( get_permalink( wc_get_page_id('shop') ) ); ?>">Shop</a></li>
                </ul>
            <?php endif; ?>
        </nav>

        <a href="<?php echo esc_url( home_url('/') ); ?>" class="site-logo">
            <?php if ( has_custom_logo() ) :
                the_custom_logo();
            else : ?>
                <img src="https://fishotel.com/wp-content/uploads/2020/06/Small-Fish-Hotel-White.png"
                     alt="The FisHotel" class="site-logo__img" width="80" height="80">
            <?php endif; ?>
            <span class="site-logo__tagline">We quarantine. You reef.</span>
        </a>

        <nav class="site-header__nav site-header__nav--right">
            <?php if ( has_nav_menu( 'primary-right' ) ) :
                wp_nav_menu([ 'theme_location' => 'primary-right', 'container' => false, 'items_wrap' => '<ul class="site-header__menu">%3$s</ul>', 'fallback_cb' => false, 'depth' => 1 ]);
            else : ?>
                <ul class="site-header__menu">
                    <li><a href="<?php echo esc_url( home_url('/faqs/') ); ?>">FAQ's</a></li>
                    <li><a href="<?php echo esc_url( home_url('/about-us/') ); ?>">About Us</a></li>
                    <li>
                        <?php if ( class_exists('WooCommerce') ) : $count = WC()->cart ? WC()->cart->get_cart_contents_count() : 0; ?>
                        <a href="<?php echo esc_url( wc_get_cart_url() ); ?>" class="site-header__cart">
                            Cart <span class="site-header__cart-count <?php echo $count > 0 ? 'has-items' : ''; ?>"><?php echo $count; ?></span>
                        </a>
                        <?php endif; ?>
                    </li>
                </ul>
            <?php endif; ?>
        </nav>

        <button class="site-header__toggle" aria-label="Menu" aria-expanded="false">
            <span></span><span></span><span></span>
        </button>

    </div>

    <div class="site-header__drawer" id="mobile-nav" aria-hidden="true">
        <ul class="site-header__drawer-menu">
            <li><a href="<?php echo esc_url( home_url('/') ); ?>">Home</a></li>
            <li><a href="<?php echo esc_url( home_url('/our-process/') ); ?>">Our Process</a></li>
            <li><a href="<?php echo esc_url( get_permalink( wc_get_page_id('shop') ) ); ?>">Shop</a></li>
            <li><a href="<?php echo esc_url( home_url('/faqs/') ); ?>">FAQ's</a></li>
            <li><a href="<?php echo esc_url( home_url('/about-us/') ); ?>">About Us</a></li>
            <li><a href="<?php echo esc_url( home_url('/newsletter/') ); ?>">Newsletter</a></li>
            <?php if ( class_exists('WooCommerce') ) : ?>
            <li><a href="<?php echo esc_url( wc_get_cart_url() ); ?>">Cart (<?php echo WC()->cart ? WC()->cart->get_cart_contents_count() : 0; ?>)</a></li>
            <li><a href="<?php echo esc_url( wc_get_account_endpoint_url('dashboard') ); ?>">My Account</a></li>
            <?php endif; ?>
        </ul>
    </div>
</header>
