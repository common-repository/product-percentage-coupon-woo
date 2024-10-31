<?php
/**
 * Plugin Name: Percentage Coupon per Product for WooCommerce
 * Plugin URI: 
 * Description: Create WooCommerce coupons with variable discount percentage per product
 * Version: 1.1
 * Author: PT Woo Plugins (by Webdados)
 * Author URI: https://ptwooplugins.com
 * Text Domain: product-percentage-coupon-woo
 * Domain Path: /lang
 * Requires at least: 5.4
 * Requires PHP: 7.0
 * WC requires at least: 5.0
 * WC tested up to: 8.4
**/

/* WooCommerce CRUD ready (except get_all_coupons, because there's no CRUD methods) */
/* WooCommerce HPOS ready - https://github.com/woocommerce/woocommerce/wiki/High-Performance-Order-Storage-Upgrade-Recipe-Book */

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/* Add plugin initialization hook. */
add_action( 'plugins_loaded', 'woo_product_percentage_coupon_init', 1 );
function woo_product_percentage_coupon_init() {
	if ( ! function_exists( 'get_plugin_data' ) ) {
		include ABSPATH . '/wp-admin/includes/plugin.php';
	}
	$plugin_data = get_plugin_data( __FILE__ );
	define( 'PCPW_VERSION', $plugin_data['Version'] );
	define( 'PCPW_WC_REQ_VERSION', $plugin_data['WC requires at least'] );
	load_plugin_textdomain( 'product-percentage-coupon-woo' );
	if ( class_exists( 'WooCommerce' ) && defined( 'WC_VERSION' ) && version_compare( WC_VERSION, PCPW_WC_REQ_VERSION, '>=' ) ) {
		require_once( dirname( __FILE__ ) . '/includes/class-woo-product-percentage-coupon.php' );
		$GLOBALS['Woo_Product_Percentage_Coupon'] = Woo_Product_Percentage_Coupon();
	} else {
		add_action( 'admin_notices', 'admin_notices_woo_product_percentage_coupon_woocommerce_not_active' );
	}
}

/* Main class */
function Woo_Product_Percentage_Coupon() {
	return Woo_Product_Percentage_Coupon::instance(); 
}

/* Admin notice */
function admin_notices_woo_product_percentage_coupon_woocommerce_not_active() {
	?>
	<div class="notice notice-error is-dismissible">
		<p>
			<?php
			printf(
				__( '<strong>Percentage Coupon per Product for WooCommerce</strong> is installed and active but <strong>WooCommerce (%s or above)</strong> is not.', 'product-percentage-coupon-woo' ),
				PCPW_WC_REQ_VERSION
			);
			?>
		</p>
	</div>
	<?php
}

/* HPOS Compatible */
add_action( 'before_woocommerce_init', function() {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
	}
} );


/* If you're reading this you must know what you're doing ;-) Greetings from sunny Portugal! */
