<?php
/**
 * Installer.
 *
 * @package WbcomSmartUpsellOrderBump
 */

namespace Wbcom\SmartUpsell;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Installer {
	/**
	 * Ensure schema is up to date on plugin upgrades.
	 */
	public static function maybe_upgrade(): void {
		$current = (string) get_option( 'wbcom_suo_db_version', '' );
		if ( version_compare( $current, WBCOM_SUO_VERSION, '>=' ) ) {
			return;
		}

		self::create_tables();
		self::seed_defaults();
	}

	/**
	 * Run on activation.
	 */
	public static function activate(): void {
		self::maybe_upgrade();
	}

	/**
	 * Run on deactivation.
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'wbcom_suo_cleanup_analytics' );
	}

	/**
	 * Create custom tables for tracking.
	 */
	private static function create_tables(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$collate        = $wpdb->get_charset_collate();
		$analytics_table = $wpdb->prefix . 'wbcom_suo_analytics';
		$offers_table    = $wpdb->prefix . 'wbcom_suo_offers';

		$sql = "CREATE TABLE {$analytics_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			offer_type VARCHAR(30) NOT NULL,
			offer_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			action_type VARCHAR(30) NOT NULL,
			order_id BIGINT UNSIGNED NULL,
			user_id BIGINT UNSIGNED NULL,
			revenue DECIMAL(18,2) NOT NULL DEFAULT 0,
			context VARCHAR(40) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY offer_type (offer_type),
			KEY offer_id (offer_id),
			KEY action_type (action_type),
			KEY created_at (created_at)
		) {$collate};";

		dbDelta( $sql );

		$sql_offers = "CREATE TABLE {$offers_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(191) NOT NULL DEFAULT '',
			offer_type VARCHAR(30) NOT NULL DEFAULT 'checkout',
			status VARCHAR(20) NOT NULL DEFAULT 'draft',
			priority INT UNSIGNED NOT NULL DEFAULT 10,
			product_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			discount_type VARCHAR(20) NOT NULL DEFAULT 'fixed',
			discount_value DECIMAL(18,2) NOT NULL DEFAULT 0,
			title VARCHAR(191) NOT NULL DEFAULT '',
			description TEXT NULL,
			display_type VARCHAR(30) NOT NULL DEFAULT 'checkbox',
			position VARCHAR(30) NOT NULL DEFAULT 'before_payment',
			rules_json LONGTEXT NULL,
			schedule_json LONGTEXT NULL,
			settings_json LONGTEXT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY offer_type (offer_type),
			KEY status (status),
			KEY priority (priority),
			KEY product_id (product_id)
		) {$collate};";

		dbDelta( $sql_offers );

		update_option( 'wbcom_suo_db_version', WBCOM_SUO_VERSION );
	}

	/**
	 * Seed default settings.
	 */
	private static function seed_defaults(): void {
		$defaults = array(
			'enable_order_bumps'          => 1,
			'enable_post_purchase_upsell' => 1,
			'enable_cart_bumps'           => 0,
			'enable_analytics'            => 1,
			'disable_reports'             => 0,
			'lazy_load_scripts'           => 1,
			'cache_mode'                  => 1,
			'animation'                   => 0,
			'template_style'              => 'minimal',
			'checkout_bump'               => array(),
			'post_purchase_upsell'        => array(),
			'cart_bump'                   => array(),
		);

		if ( ! get_option( 'wbcom_suo_settings' ) ) {
			add_option( 'wbcom_suo_settings', $defaults );
		}
	}
}
