<!DOCTYPE html>
<html <?php language_attributes();?>>
<head>
<meta charset="<?php bloginfo('charset');?>">
<meta name="viewport" content="width=device-width,initial-scale=1">
<?php wp_head();?>
</head>
<body <?php body_class();?>>
<?php wp_body_open();?>
<header id="pk-header">
<div class="pk-header-inner">
<div class="pk-logo"><a href="<?php echo esc_url(home_url('/'));?>"><?php bloginfo('name');?></a></div>
<nav id="pk-nav"><?php wp_nav_menu(['theme_location'=>'primary','container'=>false,'menu_class'=>'pk-nav-list']);?></nav>
<div class="pk-header-actions">
<a href="<?php echo esc_url(wc_get_cart_url());?>" class="pk-cart-link">🛒 <span class="pk-cart-count"><?php echo WC()->cart?WC()->cart->get_cart_contents_count():0;?></span></a>
</div>
</div>
</header>
