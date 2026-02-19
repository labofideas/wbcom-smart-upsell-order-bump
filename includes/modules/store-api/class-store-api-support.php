<?php
/**
 * Store API support for block checkout.
 *
 * @package WbcomSmartUpsellOrderBump
 */

namespace Wbcom\SmartUpsell\Modules\StoreApi;

use Automattic\WooCommerce\StoreApi\Schemas\V1\CartSchema;
use Automattic\WooCommerce\StoreApi\Schemas\V1\CheckoutSchema;
use Wbcom\SmartUpsell\Modules\Offers\OfferRepository;
use Wbcom\SmartUpsell\Modules\Targeting\SmartTargetingEngine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class StoreApiSupport {
	/**
	 * Extension namespace.
	 */
	private const NAMESPACE = 'wbcom/suo';

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'woocommerce_blocks_loaded', array( $this, 'register_store_api_extensions' ) );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( $this, 'capture_checkout_extension_data' ), 20, 2 );
	}

	/**
	 * Register data extension for cart + checkout endpoints.
	 */
	public function register_store_api_extensions(): void {
		if ( ! function_exists( 'woocommerce_store_api_register_endpoint_data' ) ) {
			return;
		}

		$schema = array(
			'checkout_offer' => array(
				'description' => __( 'Active checkout bump offer', 'wbcom-smart-upsell-order-bump' ),
				'type'        => array( 'object', 'null' ),
				'context'     => array(),
			),
			'accept_checkout_bump' => array(
				'description' => __( 'When true, apply checkout bump to the order.', 'wbcom-smart-upsell-order-bump' ),
				'type'        => array( 'boolean', 'null' ),
				'context'     => array(),
			),
		);

		$args = array(
			'namespace'       => self::NAMESPACE,
			'schema_callback' => static function () use ( $schema ) {
				return $schema;
			},
			'data_callback'   => array( $this, 'get_store_api_data' ),
		);

		$checkout_args             = $args;
		$checkout_args['endpoint'] = CheckoutSchema::IDENTIFIER;
		woocommerce_store_api_register_endpoint_data( $checkout_args );

		$cart_args             = $args;
		$cart_args['endpoint'] = CartSchema::IDENTIFIER;
		woocommerce_store_api_register_endpoint_data( $cart_args );
	}

	/**
	 * Data callback for cart/checkout endpoint.
	 */
	public function get_store_api_data(): array {
		$settings = get_option( 'wbcom_suo_settings', array() );
		if ( empty( $settings['enable_order_bumps'] ) ) {
			return array( 'checkout_offer' => null );
		}

		$repo   = new OfferRepository();
		$target = new SmartTargetingEngine();
		$offer  = $repo->get_first_active_offer(
			'checkout',
			static function ( array $candidate ) use ( $target ): bool {
				return $target->should_show_offer( $candidate );
			}
		);

		if ( empty( $offer ) ) {
			return array( 'checkout_offer' => null );
		}

		$product = wc_get_product( (int) $offer['product_id'] );
		if ( ! $product ) {
			return array( 'checkout_offer' => null );
		}

		$discounted = $this->get_discounted_price( $product, $offer );

		return array(
			'checkout_offer' => array(
				'offer_id'       => (int) $offer['offer_id'],
				'product_id'     => (int) $offer['product_id'],
				'title'          => (string) $offer['title'],
				'description'    => (string) $offer['description'],
				'discount_type'  => (string) $offer['discount_type'],
				'discount_value' => (float) $offer['discount_value'],
				'price'          => (float) $discounted,
			),
		);
	}

	/**
	 * Capture extension payload into order meta.
	 *
	 * @param \WC_Order           $order Order.
	 * @param \WP_REST_Request    $request Request.
	 */
	public function capture_checkout_extension_data( \WC_Order $order, \WP_REST_Request $request ): void {
		$extensions = $request->get_param( 'extensions' );
		$params     = $extensions[ self::NAMESPACE ] ?? array();

		$accepted_via_extension = ! empty( $params ) && ! empty( $params['accept_checkout_bump'] );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Cookie is sanitized with wc_clean() and compared to a fixed string.
		$checkout_bump_cookie   = isset( $_COOKIE['wbcom_suo_checkout_bump'] ) ? wc_clean( wp_unslash( $_COOKIE['wbcom_suo_checkout_bump'] ) ) : '';
		$accepted_via_cookie    = '1' === $checkout_bump_cookie;
		$accepted_via_session   = function_exists( 'WC' ) && WC()->session ? (bool) WC()->session->get( 'wbcom_suo_checkout_bump_selected', false ) : false;

		if ( ! $accepted_via_extension && ! $accepted_via_cookie && ! $accepted_via_session ) {
			return;
		}

		$order->update_meta_data( '_wbcom_suo_store_api_checkout_bump', 1 );
	}

	/**
	 * Discount calculation.
	 */
	private function get_discounted_price( \WC_Product $product, array $offer ): float {
		$base = (float) $product->get_price();
		$type = $offer['discount_type'] ?? 'fixed';
		$val  = (float) ( $offer['discount_value'] ?? 0 );

		if ( 'percent' === $type ) {
			$discounted = $base - ( $base * ( $val / 100 ) );
		} else {
			$discounted = $base - $val;
		}

		return max( 0, (float) wc_format_decimal( $discounted, 2 ) );
	}
}
