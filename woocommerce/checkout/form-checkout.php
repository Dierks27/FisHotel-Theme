<?php defined('ABSPATH') || exit;
if(!is_user_logged_in()) wc_print_notices();
do_action('woocommerce_before_checkout_form', $checkout); ?>
<div class="page-hero"><div class="page-hero__inner">
<nav class="page-hero__breadcrumb"><a href="<?php echo esc_url(home_url('/')); ?>">Home</a><span>/</span><a href="<?php echo esc_url(wc_get_cart_url()); ?>">Cart</a><span>/</span><span style="color:var(--fh-text-2)">Checkout</span></nav>
<h1 class="page-hero__title"><span class="word-1">Check</span>&nbsp;<span class="word-2">Out</span></h1>
</div></div>
<div style="max-width:var(--fh-max-width); margin:0 auto; padding:48px var(--fh-page-pad);">
<form name="checkout" method="post" class="checkout woocommerce-checkout" action="<?php echo esc_url(wc_get_checkout_url()); ?>" enctype="multipart/form-data">
<?php if($checkout->get_checkout_fields()): ?>
<div class="col2-set" id="customer_details">
<div class="col-1"><?php do_action('woocommerce_checkout_billing'); ?></div>
<div class="col-2"><?php do_action('woocommerce_checkout_shipping'); ?></div>
</div>
<?php endif; ?>
<?php do_action('woocommerce_checkout_before_order_review_heading'); ?>
<div id="order_review_heading" style="margin-top:40px; margin-bottom:16px; font-size:12px; font-weight:800; text-transform:uppercase; letter-spacing:2px; color:var(--fh-text-1);"><?php esc_html_e('Your Order','fishotel'); ?></div>
<?php do_action('woocommerce_checkout_before_order_review'); ?>
<div id="order_review"><?php do_action('woocommerce_checkout_order_review'); ?></div>
<?php do_action('woocommerce_checkout_after_order_review'); ?>
</form>
</div>
<?php do_action('woocommerce_after_checkout_form',$checkout); ?>
