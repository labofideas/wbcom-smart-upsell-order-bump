<?php
/**
 * Main plugin class.
 *
 * @package WbcomSmartUpsellOrderBump
 */

namespace Wbcom\SmartUpsell;

use Wbcom\SmartUpsell\Modules\Admin\AdminMenu;
use Wbcom\SmartUpsell\Modules\Analytics\AnalyticsModule;
use Wbcom\SmartUpsell\Modules\Offers\OfferRepository;
use Wbcom\SmartUpsell\Modules\OrderBump\OrderBumpEngine;
use Wbcom\SmartUpsell\Modules\Payments\GatewayChargeHandlers;
use Wbcom\SmartUpsell\Modules\Performance\PerformanceControl;
use Wbcom\SmartUpsell\Modules\StoreApi\StoreApiSupport;
use Wbcom\SmartUpsell\Modules\Upsell\PostPurchaseUpsellEngine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Plugin {
	/**
	 * Register module hooks.
	 */
	public function run(): void {
		add_action( 'init', array( 'Wbcom\\SmartUpsell\\Installer', 'maybe_upgrade' ), 5 );
		add_action( 'init', array( $this, 'maybe_migrate_offers' ), 20 );

		$admin = new AdminMenu();
		$admin->register();

		$performance = new PerformanceControl();
		$performance->register();

		$order_bumps = new OrderBumpEngine();
		$order_bumps->register();

		$upsells = new PostPurchaseUpsellEngine();
		$upsells->register();

		$analytics = new AnalyticsModule();
		$analytics->register();

		$store_api = new StoreApiSupport();
		$store_api->register();

		$charge_handlers = new GatewayChargeHandlers();
		$charge_handlers->register();
	}

	/**
	 * Migrate existing single-offer option data to custom offers table.
	 */
	public function maybe_migrate_offers(): void {
		( new OfferRepository() )->maybe_migrate_legacy_settings();
	}
}
