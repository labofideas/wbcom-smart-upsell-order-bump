<?php
/**
 * Dependency checks.
 *
 * @package WbcomSmartUpsellOrderBump
 */

namespace Wbcom\SmartUpsell;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Dependencies {
	/**
	 * Check compatibility.
	 */
	public static function is_compatible(): bool {
		if ( version_compare( PHP_VERSION, '8.0.0', '<' ) ) {
			return false;
		}

		if ( ! class_exists( 'WooCommerce' ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Render admin notice.
	 */
	public static function render_admin_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		?>
		<div class="notice notice-error"><p>
			<?php echo esc_html__( 'Wbcom Smart Upsell & Order Bump requires WooCommerce and PHP 8.0+.', 'wbcom-smart-upsell-order-bump' ); ?>
		</p></div>
		<?php
	}
}
