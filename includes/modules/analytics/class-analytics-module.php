<?php
/**
 * Analytics module.
 *
 * @package WbcomSmartUpsellOrderBump
 */

namespace Wbcom\SmartUpsell\Modules\Analytics;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AnalyticsModule {
	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'wbcom_suo_track_event', array( $this, 'track_event' ), 10, 4 );
	}

	/**
	 * Action callback to save event.
	 */
	public function track_event( string $offer_type, int $offer_id, string $action, array $context = array() ): void {
		( new AnalyticsRepository() )->track( $offer_type, $offer_id, $action, $context );
	}
}
