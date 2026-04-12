<?php
/**
 * FisHotel Theme — header.php
 * TODO: Build full nav in Phase 2
 *
 * @package FisHotel
 */
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="profile" href="https://gmpg.org/xfn/11">
    <?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>

<header class="site-header" id="masthead">
    <div class="site-header__inner">

        <ul class="nav-primary-left">
            <?php wp_nav_menu([
                'theme_location' => 'primary-left',
                'items_wrap'     => '%3$s',
                'container'      => false,
                'depth'          => 1,
                'fallback_cb'    => false,
            ]); ?>
        </ul>

        <a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="site-logo">
            <?php if ( has_custom_logo() ) :
                the_custom_logo();
            else : ?>
                <div class="site-logo__icon">⚓</div>
                <span class="site-logo__name">The FisHotel</span>
                <span class="site-logo__tagline">Premium Marine Quarantine</span>
            <?php endif; ?>
        </a>

        <ul class="nav-primary-right">
            <?php wp_nav_menu([
                'theme_location' => 'primary-right',
                'items_wrap'     => '%3$s',
                'container'      => false,
                'depth'          => 1,
                'fallback_cb'    => false,
            ]); ?>
            <li>
                <a href="<?php echo wc_get_cart_url(); ?>" class="nav-cart">
                    Cart
                    <span class="nav-cart__count"><?php echo WC()->cart->get_cart_contents_count(); ?></span>
                </a>
            </li>
        </ul>

        <button class="nav-toggle" aria-label="Menu">
            <span></span><span></span><span></span>
        </button>

    </div>
</header>
