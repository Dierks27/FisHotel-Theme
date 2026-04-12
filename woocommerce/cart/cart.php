<?php defined('ABSPATH') || exit; ?>
<?php do_action('woocommerce_before_cart'); ?>
<div class="page-hero"><div class="page-hero__inner">
<nav class="page-hero__breadcrumb"><a href="<?php echo esc_url(home_url('/')); ?>">Home</a><span>/</span><span style="color:var(--fh-text-2)">Cart</span></nav>
<h1 class="page-hero__title"><span class="word-1">Your</span>&nbsp;<span class="word-2">Cart</span></h1>
</div></div>
<div style="max-width:var(--fh-max-width); margin:0 auto; padding:48px var(--fh-page-pad);">
<form class="woocommerce-cart-form" action="<?php echo esc_url(wc_get_cart_url()); ?>" method="post">
<?php do_action('woocommerce_before_cart_table'); ?>
<table class="shop_table cart">
<thead><tr>
<th class="product-remove"></th>
<th class="product-thumbnail"></th>
<th class="product-name"><?php esc_html_e('Product','fishotel'); ?></th>
<th class="product-price"><?php esc_html_e('Price','fishotel'); ?></th>
<th class="product-quantity"><?php esc_html_e('Quantity','fishotel'); ?></th>
<th class="product-subtotal"><?php esc_html_e('Total','fishotel'); ?></th>
</tr></thead>
<tbody>
<?php do_action('woocommerce_before_cart_contents');
foreach(WC()->cart->get_cart() as $k=>$item):
$p=apply_filters('woocommerce_cart_item_product',$item['data'],$item,$k);
if($p&&$p->exists()&&$item['quantity']>0): ?>
<tr class="<?php echo esc_attr(apply_filters('woocommerce_cart_item_class','cart_item',$item,$k)); ?>">
<td><?php echo apply_filters('woocommerce_cart_item_remove_link',sprintf('<a href="%s" class="remove" aria-label="%s" data-product_id="%s">×</a>',esc_url(wc_get_cart_remove_url($k)),esc_html__('Remove','fishotel'),esc_attr($p->get_id())),$k); ?></td>
<td><?php echo apply_filters('woocommerce_cart_item_thumbnail',$p->get_image('fishotel-product-thumb'),$item,$k); ?></td>
<td class="product-name"><a href="<?php echo esc_url($p->get_permalink($item)); ?>"><?php echo apply_filters('woocommerce_cart_item_name',$p->get_name(),$item,$k); ?></a><?php echo wc_get_formatted_cart_item_data($item); ?></td>
<td class="product-price"><?php echo apply_filters('woocommerce_cart_item_price',WC()->cart->get_product_price($p),$item,$k); ?></td>
<td class="product-quantity"><?php woocommerce_quantity_input(['input_name'=>"cart[{$k}][qty]",'input_value'=>$item['quantity'],'max_value'=>$p->get_max_purchase_quantity(),'min_value'=>'0'],$p); ?></td>
<td class="product-subtotal"><?php echo apply_filters('woocommerce_cart_item_subtotal',WC()->cart->get_product_subtotal($p,$item['quantity']),$item,$k); ?></td>
</tr>
<?php endif; endforeach; do_action('woocommerce_cart_contents'); ?>
<tr><td colspan="6" style="padding:20px 0;">
<?php if(wc_coupons_enabled()): ?><div style="display:flex;gap:8px;"><input type="text" name="coupon_code" class="fh-input" style="width:200px;" placeholder="<?php esc_attr_e('Coupon code','fishotel'); ?>"><button type="submit" class="fh-btn fh-btn--ghost" style="padding:11px 18px;" name="apply_coupon"><?php esc_html_e('Apply','fishotel'); ?></button></div><?php endif; ?>
<?php do_action('woocommerce_cart_actions'); wp_nonce_field('woocommerce-cart','woocommerce-cart-nonce'); ?>
</td></tr>
<?php do_action('woocommerce_after_cart_contents'); ?>
</tbody></table>
<?php do_action('woocommerce_after_cart_table'); ?>
</form>
<div class="cart-collaterals" style="max-width:380px; margin-left:auto; margin-top:40px;"><?php do_action('woocommerce_cart_collaterals'); ?></div>
</div>
<?php do_action('woocommerce_after_cart'); ?>
