<?php
/**
 * Performance controls.
 *
 * @package WbcomSmartUpsellOrderBump
 */

namespace Wbcom\SmartUpsell\Modules\Performance;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PerformanceControl {
	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_front_assets' ) );
	}

	/**
	 * Enqueue assets only where needed.
	 */
	public function enqueue_front_assets(): void {
		if ( ! function_exists( 'is_cart' ) || ! function_exists( 'is_checkout' ) ) {
			return;
		}

		$should_load = is_cart() || is_checkout() || is_order_received_page();
		if ( ! $should_load ) {
			return;
		}

		wp_enqueue_style(
			'wbcom-suo-frontend',
			WBCOM_SUO_PLUGIN_URL . 'assets/css/frontend.css',
			array(),
			WBCOM_SUO_VERSION
		);

		wp_enqueue_script(
			'wbcom-suo-frontend',
			WBCOM_SUO_PLUGIN_URL . 'assets/js/frontend.js',
			array(),
			WBCOM_SUO_VERSION,
			true
		);

		wp_localize_script(
			'wbcom-suo-frontend',
			'wbcomSuo',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'wbcom_suo_dismiss' ),
			)
		);
	}
}
