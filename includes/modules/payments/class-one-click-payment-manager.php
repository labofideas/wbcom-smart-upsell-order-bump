<?php
/**
 * One-click payment manager.
 *
 * @package WbcomSmartUpsellOrderBump
 */

namespace Wbcom\SmartUpsell\Modules\Payments;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OneClickPaymentManager {
	/**
	 * Attempt one-click charge using parent order payment context.
	 *
	 * @param \WC_Order $upsell_order Upsell order.
	 * @param \WC_Order $parent_order Parent order.
	 * @param array      $offer Offer config.
	 */
	public function maybe_auto_charge( \WC_Order $upsell_order, \WC_Order $parent_order, array $offer ): bool {
		if ( empty( $offer['auto_charge'] ) ) {
			return false;
		}

		if ( (float) $upsell_order->get_total() <= 0 ) {
			$upsell_order->payment_complete();
			$upsell_order->add_order_note( __( 'Upsell auto-completed with zero total.', 'wbcom-smart-upsell-order-bump' ) );
			return true;
		}

		$gateway_id = (string) $parent_order->get_payment_method();
		if ( '' === $gateway_id ) {
			return false;
		}

		$gateways = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();
		$gateway  = $gateways[ $gateway_id ] ?? null;
		if ( ! $gateway || ! method_exists( $gateway, 'supports' ) || ! $gateway->supports( 'tokenization' ) ) {
			return false;
		}

		$context = array(
			'gateway'     => $gateway,
			'gateway_id'  => $gateway_id,
			'parent_order'=> $parent_order,
			'upsell_order'=> $upsell_order,
			'token_id'    => $this->resolve_token_id( $parent_order, $gateway_id ),
		);

		$result = apply_filters( 'wbcom_suo_process_one_click_charge', null, $context );
		if ( true === $result || ( is_array( $result ) && ! empty( $result['success'] ) ) ) {
			$transaction_id = is_array( $result ) ? (string) ( $result['transaction_id'] ?? '' ) : '';
			$upsell_order->payment_complete( $transaction_id );
			$upsell_order->add_order_note( __( 'Upsell captured via one-click token charge.', 'wbcom-smart-upsell-order-bump' ) );
			return true;
		}

		if ( is_wp_error( $result ) ) {
			$upsell_order->add_order_note( sprintf( 'One-click charge failed: %s', $result->get_error_message() ) );
		}

		return false;
	}

	/**
	 * Resolve token ID from order or customer tokens.
	 */
	private function resolve_token_id( \WC_Order $order, string $gateway_id ): int {
		$token_ids = $order->get_payment_tokens();
		if ( ! empty( $token_ids ) ) {
			return (int) $token_ids[0];
		}

		$customer_id = (int) $order->get_customer_id();
		if ( $customer_id <= 0 ) {
			return 0;
		}

		$tokens = \WC_Payment_Tokens::get_customer_tokens( $customer_id, $gateway_id );
		if ( empty( $tokens ) ) {
			return 0;
		}

		// Prefer exact source match where gateway stores source/payment-method ID in order meta.
		$order_source_id = (string) $order->get_meta( '_stripe_source_id' );
		if ( '' !== $order_source_id ) {
			foreach ( $tokens as $token ) {
				if ( method_exists( $token, 'get_token' ) && (string) $token->get_token() === $order_source_id ) {
					return (int) $token->get_id();
				}
			}
		}

		foreach ( $tokens as $token ) {
			if ( method_exists( $token, 'is_default' ) && $token->is_default() ) {
				return (int) $token->get_id();
			}
		}

		$first = reset( $tokens );
		return $first ? (int) $first->get_id() : 0;
	}
}
