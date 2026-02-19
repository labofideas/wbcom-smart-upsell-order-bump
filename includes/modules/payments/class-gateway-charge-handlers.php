<?php
/**
 * Gateway charge handlers.
 *
 * @package WbcomSmartUpsellOrderBump
 */

namespace Wbcom\SmartUpsell\Modules\Payments;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GatewayChargeHandlers {
	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_filter( 'wbcom_suo_process_one_click_charge', array( $this, 'handle_one_click_charge' ), 20, 2 );
	}

	/**
	 * Try to charge upsell order using gateway + saved token.
	 *
	 * @param mixed $result Previous filter result.
	 * @param array $context Charge context.
	 *
	 * @return mixed
	 */
	public function handle_one_click_charge( $result, array $context ) {
		if ( null !== $result ) {
			return $result;
		}

		$gateway_id   = sanitize_key( (string) ( $context['gateway_id'] ?? '' ) );
		$gateway      = $context['gateway'] ?? null;
		$upsell_order = $context['upsell_order'] ?? null;
		$token_id     = absint( $context['token_id'] ?? 0 );

		if ( ! $gateway || ! $upsell_order instanceof \WC_Order ) {
			return new \WP_Error( 'wbcom_suo_invalid_context', __( 'Invalid one-click charge context.', 'wbcom-smart-upsell-order-bump' ) );
		}

		if ( ! method_exists( $gateway, 'process_payment' ) ) {
			return new \WP_Error( 'wbcom_suo_no_process_payment', __( 'Gateway does not support direct charge processing.', 'wbcom-smart-upsell-order-bump' ) );
		}

		if ( $token_id <= 0 ) {
			return new \WP_Error( 'wbcom_suo_no_token', __( 'No reusable payment token found for this customer.', 'wbcom-smart-upsell-order-bump' ) );
		}

		$token = \WC_Payment_Tokens::get( $token_id );
		if ( ! $token || (int) $token->get_user_id() !== (int) $upsell_order->get_customer_id() ) {
			return new \WP_Error( 'wbcom_suo_invalid_token', __( 'Saved payment token is missing or invalid.', 'wbcom-smart-upsell-order-bump' ) );
		}

		$payload  = $this->build_post_payload( $gateway_id, $token_id );
		$payload  = apply_filters( 'wbcom_suo_gateway_post_payload', $payload, $gateway_id, $token_id, $upsell_order, $context );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Internal gateway request shaping, not direct user form processing.
		$snapshot = $_POST;
		$family   = $this->detect_gateway_family( $gateway_id );

		try {
			foreach ( $payload as $key => $value ) {
				$_POST[ $key ] = $value;
			}

			if ( 'stripe' === $family ) {
				add_filter( 'wc_stripe_generate_create_intent_request', array( $this, 'force_stripe_card_intent' ), 999, 3 );
			}

			/**
			 * Allow integrations to apply pre-process request shaping.
			 */
			do_action( 'wbcom_suo_before_gateway_process_payment', $gateway_id, $upsell_order, $token_id, $context );

			$processed = $gateway->process_payment( $upsell_order->get_id() );
			if ( ! is_array( $processed ) ) {
				return new \WP_Error( 'wbcom_suo_unknown_gateway_response', __( 'Gateway returned an unexpected response.', 'wbcom-smart-upsell-order-bump' ) );
			}

			$status = (string) ( $processed['result'] ?? '' );
			if ( 'success' !== $status ) {
				$error_message = (string) ( $processed['message'] ?? __( 'Payment was not completed by gateway.', 'wbcom-smart-upsell-order-bump' ) );
				return new \WP_Error( 'wbcom_suo_gateway_declined', $error_message, $processed );
			}

			$transaction_id = (string) $upsell_order->get_transaction_id();
			if ( '' === $transaction_id && ! empty( $processed['transaction_id'] ) ) {
				$transaction_id = sanitize_text_field( (string) $processed['transaction_id'] );
			}

			return array(
				'success'        => true,
				'transaction_id' => $transaction_id,
				'gateway_result' => $processed,
			);
		} catch ( \Throwable $throwable ) {
			return new \WP_Error( 'wbcom_suo_gateway_exception', $throwable->getMessage() );
		} finally {
			if ( 'stripe' === $family ) {
				remove_filter( 'wc_stripe_generate_create_intent_request', array( $this, 'force_stripe_card_intent' ), 999 );
			}
			$_POST = $snapshot;
		}
	}

	/**
	 * Restrict Stripe intent generation to cards for one-click upsells.
	 *
	 * @param array $request Stripe request payload.
	 * @return array
	 */
	public function force_stripe_card_intent( array $request ): array {
		$request['payment_method_types'] = array( 'card' );
		return $request;
	}

	/**
	 * Build gateway-specific request payload.
	 */
	private function build_post_payload( string $gateway_id, int $token_id ): array {
		$family = $this->detect_gateway_family( $gateway_id );

		if ( 'stripe' === $family ) {
			return $this->build_stripe_payload( $gateway_id, $token_id );
		}

		if ( 'wcpay' === $family ) {
			return $this->build_wcpay_payload( $gateway_id, $token_id );
		}

		if ( 'paypal_payments' === $family ) {
			return $this->build_ppcp_payload( $gateway_id, $token_id );
		}

		return $this->build_generic_payload( $gateway_id, $token_id );
	}

	/**
	 * Detect known gateway families.
	 */
	private function detect_gateway_family( string $gateway_id ): string {
		if ( in_array( $gateway_id, array( 'stripe', 'stripe_cc', 'wc_stripe' ), true ) ) {
			return 'stripe';
		}

		if ( in_array( $gateway_id, array( 'woocommerce_payments', 'wcpay' ), true ) ) {
			return 'wcpay';
		}

		if ( in_array( $gateway_id, array( 'ppcp-gateway', 'ppcp-credit-card-gateway', 'ppcp-axo-gateway' ), true ) ) {
			return 'paypal_payments';
		}

		return 'generic';
	}

	/**
	 * Payload for Stripe-like gateways.
	 */
	private function build_stripe_payload( string $gateway_id, int $token_id ): array {
		return array(
			'payment_method'               => $gateway_id,
			'wc-' . $gateway_id . '-payment-token' => (string) $token_id,
			'wc-stripe-payment-token'      => (string) $token_id,
			'stripe-payment-token'         => (string) $token_id,
			'wc-stripe-new-payment-method' => 'false',
			'save_payment_method'          => '0',
		);
	}

	/**
	 * Payload for WooPayments.
	 */
	private function build_wcpay_payload( string $gateway_id, int $token_id ): array {
		return array(
			'payment_method'                         => $gateway_id,
			'wcpay-payment-token'                    => (string) $token_id,
			'wc-' . $gateway_id . '-payment-token'   => (string) $token_id,
			$gateway_id . '-payment-token'           => (string) $token_id,
			'save_payment_method'                    => '0',
		);
	}

	/**
	 * Payload for Woo PayPal Payments vaulted flows.
	 */
	private function build_ppcp_payload( string $gateway_id, int $token_id ): array {
		return array(
			'payment_method'                       => $gateway_id,
			'ppcp-payment-token'                   => (string) $token_id,
			'wc-' . $gateway_id . '-payment-token' => (string) $token_id,
			$gateway_id . '-payment-token'         => (string) $token_id,
		);
	}

	/**
	 * Payload for unknown but tokenized gateways.
	 */
	private function build_generic_payload( string $gateway_id, int $token_id ): array {
		return array(
			'payment_method'                       => $gateway_id,
			'wc-' . $gateway_id . '-payment-token' => (string) $token_id,
			$gateway_id . '-payment-token'         => (string) $token_id,
		);
	}
}
