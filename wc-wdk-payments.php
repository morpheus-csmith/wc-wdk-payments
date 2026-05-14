<?php
/**
 * Plugin Name:       WDK Payments for WooCommerce
 * Plugin URI:        https://github.com/morpheus-csmith/wc-wdk-payments
 * Description:       Accept self-custodial USDt payments via Tether's Wallet Development Kit (WDK). Supports Tron, Polygon, Arbitrum, and Ethereum.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            morpheus-csmith
 * License:           Apache-2.0
 * License URI:       https://www.apache.org/licenses/LICENSE-2.0
 * Text Domain:       wc-wdk-payments
 *
 * WC requires at least: 8.0
 * WC tested up to:      9.2
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WC_WDK_PAYMENTS_VERSION', '0.1.0' );
define( 'WC_WDK_PAYMENTS_FILE', __FILE__ );
define( 'WC_WDK_PAYMENTS_PATH', plugin_dir_path( __FILE__ ) );
define( 'WC_WDK_PAYMENTS_URL', plugin_dir_url( __FILE__ ) );

/**
 * Bootstrap the plugin once WooCommerce has loaded.
 *
 * The gateway class extends WC_Payment_Gateway, which is only defined
 * after WC is initialized. Registering on `plugins_loaded` is too early
 * for some setups; `woocommerce_loaded` is the safest hook.
 */
add_action( 'woocommerce_loaded', static function (): void {
	require_once WC_WDK_PAYMENTS_PATH . 'includes/class-indexer-client.php';
	require_once WC_WDK_PAYMENTS_PATH . 'includes/class-poller.php';
	require_once WC_WDK_PAYMENTS_PATH . 'includes/class-gateway.php';

	add_filter(
		'woocommerce_payment_gateways',
		static function ( array $methods ): array {
			$methods[] = WC_Gateway_WDK_USDt::class;
			return $methods;
		}
	);
} );

/**
 * Declare HPOS (Custom Order Tables) compatibility.
 *
 * Modern WC stores orders in custom tables instead of wp_posts.
 * Plugins must explicitly opt in.
 */
add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				WC_WDK_PAYMENTS_FILE,
				true
			);
		}
	}
);
