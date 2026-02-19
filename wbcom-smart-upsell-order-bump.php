<?php
/**
 * Plugin Name: Wbcom Smart Upsell & Order Bump
 * Description: Increase average order value with lightweight checkout order bumps and post-purchase upsells.
 * Version: 1.0.0
 * Author: Wbcom Designs
 * License: GPL-2.0-or-later
 * Text Domain: wbcom-smart-upsell-order-bump
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Requires Plugins: woocommerce
 *
 * @package WbcomSmartUpsellOrderBump
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WBCOM_SUO_VERSION', '1.0.0' );
define( 'WBCOM_SUO_PLUGIN_FILE', __FILE__ );
define( 'WBCOM_SUO_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WBCOM_SUO_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WBCOM_SUO_BASENAME', plugin_basename( __FILE__ ) );

require_once WBCOM_SUO_PLUGIN_DIR . 'includes/class-autoloader.php';

Wbcom\SmartUpsell\Autoloader::register();

register_activation_hook( __FILE__, array( 'Wbcom\\SmartUpsell\\Installer', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Wbcom\\SmartUpsell\\Installer', 'deactivate' ) );

function wbcom_suo_bootstrap(): void {
	if ( ! Wbcom\SmartUpsell\Dependencies::is_compatible() ) {
		add_action( 'admin_notices', array( 'Wbcom\\SmartUpsell\\Dependencies', 'render_admin_notice' ) );
		return;
	}

	$plugin = new Wbcom\SmartUpsell\Plugin();
	$plugin->run();
}

add_action( 'plugins_loaded', 'wbcom_suo_bootstrap' );
