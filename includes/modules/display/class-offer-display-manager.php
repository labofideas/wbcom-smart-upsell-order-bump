<?php
/**
 * Offer display helper.
 *
 * @package WbcomSmartUpsellOrderBump
 */

namespace Wbcom\SmartUpsell\Modules\Display;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OfferDisplayManager {
	/**
	 * Resolve style classes.
	 *
	 * @param array $offer Offer config.
	 */
	public function get_classes( array $offer ): string {
		$global   = get_option( 'wbcom_suo_settings', array() );
		$template = $global['template_style'] ?? 'minimal';
		$display  = $offer['display_type'] ?? 'checkbox';

		return sprintf(
			'wbcom-suo-offer wbcom-suo-template-%s wbcom-suo-display-%s',
			esc_attr( $template ),
			esc_attr( $display )
		);
	}
}
