<?php
/**
 * Post purchase upsell module.
 *
 * @package WbcomSmartUpsellOrderBump
 */

namespace Wbcom\SmartUpsell\Modules\Upsell;

use Wbcom\SmartUpsell\Modules\Offers\AbTestingManager;
use Wbcom\SmartUpsell\Modules\Offers\OfferRepository;
use Wbcom\SmartUpsell\Modules\Payments\OneClickPaymentManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PostPurchaseUpsellEngine {
	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'woocommerce_thankyou', array( $this, 'render_offer' ), 20 );
		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'render_offer_from_order' ), 20 );
		add_action( 'template_redirect', array( $this, 'handle_response' ) );
		add_action( 'woocommerce_payment_complete', array( $this, 'handle_upsell_payment_complete' ) );
		add_shortcode( 'wbcom_suo_upsell', array( $this, 'render_offer_shortcode' ) );
	}

	/**
	 * Fallback renderer for block-based order-received templates.
	 *
	 * @param \WC_Order $order Order object.
	 */
	public function render_offer_from_order( \WC_Order $order ): void {
		if ( ! function_exists( 'is_order_received_page' ) || ! is_order_received_page() ) {
			return;
		}

		$this->render_offer( $order->get_id() );
	}

	/**
	 * Shortcode renderer for post-purchase upsell.
	 *
	 * @param array<string,mixed> $atts Shortcode attributes.
	 */
	public function render_offer_shortcode( array $atts = array() ): string {
		$atts = shortcode_atts(
			array(
				'order_id' => 0,
			),
			$atts,
			'wbcom_suo_upsell'
		);

		$order_id = absint( $atts['order_id'] ?? 0 );
		if ( $order_id <= 0 ) {
			$order_id = absint( get_query_var( 'order-received' ) );
		}
		if ( $order_id <= 0 ) {
			$order_id = absint( $_GET['order_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		if ( $order_id <= 0 ) {
			return '';
		}

		ob_start();
		$this->render_offer( $order_id );
		return (string) ob_get_clean();
	}

	/**
	 * Render offer on thank-you page.
	 *
	 * @param int $order_id Order ID.
	 */
	public function render_offer( int $order_id ): void {
		static $rendered_order_ids = array();
		if ( isset( $rendered_order_ids[ $order_id ] ) ) {
			return;
		}

		$settings = get_option( 'wbcom_suo_settings', array() );
		if ( empty( $settings['enable_post_purchase_upsell'] ) ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order || $order->get_meta( '_wbcom_suo_upsell_accepted' ) || $order->get_meta( '_wbcom_suo_upsell_clicked' ) ) {
			return;
		}

		$offer = $this->get_post_purchase_offer( $order );
		if ( empty( $offer ) || empty( $offer['product_id'] ) ) {
			return;
		}

		$offer = $this->apply_offer_variant( $offer, $order, true );
		if ( ! empty( $offer['dismiss_limit'] ) && $this->get_dismiss_count( $order, (int) $offer['offer_id'] ) >= (int) $offer['dismiss_limit'] ) {
			return;
		}

		$product = wc_get_product( (int) $offer['product_id'] );
		if ( ! $product ) {
			return;
		}

		$accept_url = wp_nonce_url(
			add_query_arg(
				array(
					'wbcom_suo_upsell' => 'accept',
					'offer_id'         => (int) $offer['offer_id'],
					'variant'          => (string) ( $offer['_ab_variant'] ?? 'a' ),
					'order_id'         => $order_id,
					'key'              => $order->get_order_key(),
				),
				wc_get_endpoint_url( 'order-received', $order_id, wc_get_checkout_url() )
			),
			'wbcom_suo_upsell_' . $order_id
		);

		$skip_url = wp_nonce_url(
			add_query_arg(
				array(
					'wbcom_suo_upsell' => 'skip',
					'offer_id'         => (int) $offer['offer_id'],
					'variant'          => (string) ( $offer['_ab_variant'] ?? 'a' ),
					'order_id'         => $order_id,
					'key'              => $order->get_order_key(),
				),
				wc_get_endpoint_url( 'order-received', $order_id, wc_get_checkout_url() )
			),
			'wbcom_suo_upsell_' . $order_id
		);

		$variant = (string) ( $offer['_ab_variant'] ?? 'a' );
		do_action( 'wbcom_suo_track_event', 'post_purchase', (int) ( $offer['offer_id'] ?? 0 ), 'view', array( 'order_id' => $order_id, 'context' => 'thankyou_' . $variant ) );
		( new AbTestingManager() )->record_variant_event( (int) ( $offer['offer_id'] ?? 0 ), $variant, 'view' );
		$rendered_order_ids[ $order_id ] = true;
		?>
			<section class="wbcom-suo-upsell-offer">
				<h2><?php echo esc_html( $offer['title'] ?: __( 'Special one-time offer', 'wbcom-smart-upsell-order-bump' ) ); ?></h2>
				<?php if ( ! empty( $offer['show_image'] ) ) : ?>
					<div class="wbcom-suo-product-image-wrap">
						<?php
						echo wp_kses_post(
							$product->get_image(
								'woocommerce_thumbnail',
								array(
									'class'    => 'wbcom-suo-product-image wbcom-suo-product-image-upsell',
									'loading'  => 'lazy',
									'decoding' => 'async',
								)
							)
						);
						?>
					</div>
				<?php endif; ?>
				<p><?php echo esc_html( $offer['description'] ?: $product->get_short_description() ); ?></p>
				<?php echo $this->render_countdown_markup( $offer, $order ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php if ( ! empty( $offer['coupon_code'] ) ) : ?>
					<?php /* translators: %s: coupon code. */ ?>
					<p class="wbcom-suo-coupon"><?php echo esc_html( sprintf( __( 'Coupon: %s', 'wbcom-smart-upsell-order-bump' ), (string) $offer['coupon_code'] ) ); ?></p>
				<?php endif; ?>
				<?php echo $this->render_bundle_markup( $offer, $product ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<p>
				<a class="button" href="<?php echo esc_url( $accept_url ); ?>"><?php esc_html_e( 'Yes, add this to my order', 'wbcom-smart-upsell-order-bump' ); ?></a>
				<a class="button-link" href="<?php echo esc_url( $skip_url ); ?>"><?php echo esc_html( $offer['skip_label'] ?: __( 'No thanks', 'wbcom-smart-upsell-order-bump' ) ); ?></a>
			</p>
		</section>
		<?php
	}

	/**
	 * Accept/skip handler.
	 */
	public function handle_response(): void {
		if ( empty( $_GET['wbcom_suo_upsell'] ) || empty( $_GET['order_id'] ) ) {
			return;
		}

		$order_id = absint( $_GET['order_id'] );
		$offer_id = absint( $_GET['offer_id'] ?? 0 );
		$action   = sanitize_key( wp_unslash( $_GET['wbcom_suo_upsell'] ) );

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), 'wbcom_suo_upsell_' . $order_id ) ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		if ( ! $this->can_handle_upsell_for_order( $order ) ) {
			return;
		}

		$offer = ( new OfferRepository() )->get_offer( $offer_id );
		if ( empty( $offer ) || 'post_purchase' !== ( $offer['offer_type'] ?? '' ) ) {
			return;
		}

		$offer = $this->apply_offer_variant( $offer, $order, false, sanitize_key( wp_unslash( $_GET['variant'] ?? '' ) ) );

		if ( 'skip' === $action ) {
			$this->increment_dismiss_count( $order, (int) ( $offer['offer_id'] ?? 0 ) );
			$order->update_meta_data( '_wbcom_suo_upsell_skipped', 1 );
			$order->save();
			do_action( 'wbcom_suo_track_event', 'post_purchase', (int) ( $offer['offer_id'] ?? 0 ), 'skip', array( 'order_id' => $order_id, 'context' => 'thankyou' ) );
			wp_safe_redirect( $order->get_view_order_url() );
			exit;
		}

		if ( 'accept' !== $action ) {
			return;
		}

		$product = wc_get_product( (int) ( $offer['product_id'] ?? 0 ) );
		if ( ! $product ) {
			return;
		}

		$upsell_order = wc_create_order(
			array(
				'customer_id' => $order->get_customer_id(),
				'parent'      => $order->get_id(),
			)
		);

		if ( is_wp_error( $upsell_order ) ) {
			return;
		}

		$price = $this->get_discounted_price( $product, $offer );
		$item  = new \WC_Order_Item_Product();
		$item->set_product( $product );
		$item->set_quantity( 1 );
		$item->set_subtotal( (float) $product->get_price() );
		$item->set_total( $price );
		$upsell_order->add_item( $item );
		$upsell_order->set_address( $order->get_address( 'billing' ), 'billing' );
		$upsell_order->set_address( $order->get_address( 'shipping' ), 'shipping' );
		$upsell_order->set_payment_method( $order->get_payment_method() );
		$upsell_order->set_payment_method_title( $order->get_payment_method_title() );
		$upsell_order->calculate_totals();
		$this->maybe_apply_offer_coupon( $upsell_order, $offer );
		$upsell_order->update_meta_data( '_wbcom_suo_parent_order_id', $order->get_id() );
		$upsell_order->update_meta_data( '_wbcom_suo_offer_id', (int) ( $offer['offer_id'] ?? 0 ) );
		$upsell_order->save();

		$order->update_meta_data( '_wbcom_suo_upsell_clicked', 1 );
		$order->update_meta_data( '_wbcom_suo_upsell_offer_id', (int) ( $offer['offer_id'] ?? 0 ) );
		$order->update_meta_data( '_wbcom_suo_upsell_order_id', $upsell_order->get_id() );
		$order->save();

		do_action(
			'wbcom_suo_track_event',
			'post_purchase',
			(int) ( $offer['offer_id'] ?? 0 ),
			'accept_click',
			array(
				'order_id' => $order_id,
				'revenue'  => $price,
				'context'  => 'thankyou',
			)
		);
		( new AbTestingManager() )->record_variant_event( (int) ( $offer['offer_id'] ?? 0 ), (string) ( $offer['_ab_variant'] ?? 'a' ), 'accept' );

		$auto_charged = ( new OneClickPaymentManager() )->maybe_auto_charge( $upsell_order, $order, $offer );
		if ( $auto_charged ) {
			$this->mark_parent_accepted_and_track( $order, (int) ( $offer['offer_id'] ?? 0 ), (float) $upsell_order->get_total(), 'thankyou_auto' );
			wc_add_notice( $offer['thank_you_message'] ?: __( 'Upsell added successfully.', 'wbcom-smart-upsell-order-bump' ) );
			wp_safe_redirect( $order->get_view_order_url() );
			exit;
		}

		wp_safe_redirect( $upsell_order->get_checkout_payment_url() );
		exit;
	}

	/**
	 * Mark parent order accepted + analytics once upsell payment is complete.
	 *
	 * @param int $order_id Paid order ID.
	 */
	public function handle_upsell_payment_complete( int $order_id ): void {
		$upsell_order = wc_get_order( $order_id );
		if ( ! $upsell_order ) {
			return;
		}

		$parent_order_id = absint( $upsell_order->get_meta( '_wbcom_suo_parent_order_id' ) );
		$offer_id        = absint( $upsell_order->get_meta( '_wbcom_suo_offer_id' ) );
		if ( $parent_order_id <= 0 || $offer_id <= 0 ) {
			return;
		}

		$parent_order = wc_get_order( $parent_order_id );
		if ( ! $parent_order || $parent_order->get_meta( '_wbcom_suo_upsell_accepted' ) ) {
			return;
		}

		$this->mark_parent_accepted_and_track( $parent_order, $offer_id, (float) $upsell_order->get_total(), 'post_payment' );
	}

	/**
	 * Persist acceptance and analytics in one place.
	 */
	private function mark_parent_accepted_and_track( \WC_Order $parent_order, int $offer_id, float $revenue, string $context ): void {
		$parent_order->update_meta_data( '_wbcom_suo_upsell_accepted', 1 );
		$parent_order->save();

		do_action(
			'wbcom_suo_track_event',
			'post_purchase',
			$offer_id,
			'accept',
			array(
				'order_id' => $parent_order->get_id(),
				'revenue'  => $revenue,
				'context'  => $context,
			)
		);
	}

	/**
	 * Validate actor can perform upsell actions for this order.
	 */
	private function can_handle_upsell_for_order( \WC_Order $order ): bool {
		if ( current_user_can( 'manage_woocommerce' ) ) {
			return true;
		}

		$customer_id = (int) $order->get_customer_id();
		if ( $customer_id > 0 ) {
			return get_current_user_id() === $customer_id;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Order key is sanitized with wc_clean() and verified via hash_equals().
		$requested_key = isset( $_GET['key'] ) ? wc_clean( wp_unslash( $_GET['key'] ) ) : '';
		return '' !== $requested_key && hash_equals( (string) $order->get_order_key(), (string) $requested_key );
	}

	/**
	 * Resolve first matching upsell for the order.
	 */
	private function get_post_purchase_offer( \WC_Order $order ): array {
		$repo   = new OfferRepository();
		$offers = $repo->get_active_offers_by_type( 'post_purchase' );

		foreach ( $offers as $offer ) {
			if ( $this->matches_order( $offer, $order ) ) {
				return $offer;
			}
		}

		return array();
	}

	/**
	 * Order-based targeting.
	 */
	private function matches_order( array $offer, \WC_Order $order ): bool {
		if ( ! $this->is_schedule_valid( $offer ) ) {
			return false;
		}

		if ( ! $this->matches_customer_rules( $offer, $order ) ) {
			return false;
		}

		if ( ! $this->matches_country_rules( $offer, $order ) ) {
			return false;
		}

		if ( ! $this->matches_device_rules( $offer ) ) {
			return false;
		}

		if ( ! empty( $offer['min_cart_total'] ) && (float) $order->get_subtotal() < (float) $offer['min_cart_total'] ) {
			return false;
		}
		if ( ! empty( $offer['max_cart_total'] ) && (float) $order->get_subtotal() > (float) $offer['max_cart_total'] ) {
			return false;
		}

		$required_products = array_filter( array_map( 'absint', explode( ',', (string) ( $offer['trigger_product_ids'] ?? '' ) ) ) );
		if ( ! empty( $required_products ) ) {
			$has_required = false;
			foreach ( $order->get_items() as $item ) {
				if ( in_array( (int) $item->get_product_id(), $required_products, true ) ) {
					$has_required = true;
					break;
				}
			}
			if ( ! $has_required ) {
				return false;
			}
		}

		$required_categories = array_filter( array_map( 'absint', explode( ',', (string) ( $offer['trigger_category_ids'] ?? '' ) ) ) );
		if ( ! empty( $required_categories ) ) {
			$has_required_cat = false;
			foreach ( $order->get_items() as $item ) {
				$terms = get_the_terms( (int) $item->get_product_id(), 'product_cat' );
				if ( empty( $terms ) || is_wp_error( $terms ) ) {
					continue;
				}
				foreach ( $terms as $term ) {
					if ( in_array( (int) $term->term_id, $required_categories, true ) ) {
						$has_required_cat = true;
						break 2;
					}
				}
			}
			if ( ! $has_required_cat ) {
				return false;
			}
		}

		if ( ! empty( $offer['skip_if_purchased'] ) ) {
			$email = $order->get_billing_email();
			if ( $email && wc_customer_bought_product( $email, $order->get_customer_id(), (int) $offer['product_id'] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Validate schedule windows.
	 */
	private function is_schedule_valid( array $offer ): bool {
		$current_date = gmdate( 'Y-m-d' );
		$current_time = gmdate( 'H:i' );
		if ( ! empty( $offer['schedule_start'] ) && $current_date < $offer['schedule_start'] ) {
			return false;
		}
		if ( ! empty( $offer['schedule_end'] ) && $current_date > $offer['schedule_end'] ) {
			return false;
		}

		if ( ! empty( $offer['weekdays'] ) ) {
			$allowed = array_map( 'trim', explode( ',', (string) $offer['weekdays'] ) );
			$today   = strtolower( gmdate( 'D' ) );
			$today   = substr( $today, 0, 3 );
			if ( ! in_array( $today, $allowed, true ) ) {
				return false;
			}
		}

		if ( ! empty( $offer['start_time'] ) && $current_time < (string) $offer['start_time'] ) {
			return false;
		}

		if ( ! empty( $offer['end_time'] ) && $current_time > (string) $offer['end_time'] ) {
			return false;
		}

		return true;
	}

	/**
	 * Customer and order-history rules for post-purchase offers.
	 */
	private function matches_customer_rules( array $offer, \WC_Order $order ): bool {
		$customer_id = (int) $order->get_customer_id();
		$email       = (string) $order->get_billing_email();

		if ( ! empty( $offer['customer_email'] ) && strtolower( (string) $offer['customer_email'] ) !== strtolower( $email ) ) {
			return false;
		}

		if ( ! empty( $offer['lifetime_spend_threshold'] ) ) {
			if ( $customer_id <= 0 ) {
				return false;
			}
			$spent = (float) wc_get_customer_total_spent( $customer_id );
			if ( $spent < (float) $offer['lifetime_spend_threshold'] ) {
				return false;
			}
		}

		if ( ! empty( $offer['first_time_only'] ) ) {
			if ( $customer_id <= 0 || (int) wc_get_customer_order_count( $customer_id ) > 1 ) {
				return false;
			}
		}

		if ( ! empty( $offer['returning_only'] ) ) {
			if ( $customer_id <= 0 || (int) wc_get_customer_order_count( $customer_id ) <= 1 ) {
				return false;
			}
		}

		if ( ! empty( $offer['purchase_frequency_min'] ) ) {
			if ( $customer_id <= 0 || (int) wc_get_customer_order_count( $customer_id ) < (int) $offer['purchase_frequency_min'] ) {
				return false;
			}
		}

		$required_products = array_filter( array_map( 'absint', explode( ',', (string) ( $offer['purchased_product_ids'] ?? '' ) ) ) );
		if ( ! empty( $required_products ) ) {
			$matched = false;
			foreach ( $required_products as $product_id ) {
				if ( wc_customer_bought_product( $email, $customer_id, $product_id ) ) {
					$matched = true;
					break;
				}
			}
			if ( ! $matched ) {
				return false;
			}
		}

		$required_categories = array_filter( array_map( 'absint', explode( ',', (string) ( $offer['purchased_category_ids'] ?? '' ) ) ) );
		if ( ! empty( $required_categories ) && $customer_id > 0 ) {
			if ( ! $this->has_purchased_category_before( $customer_id, $required_categories ) ) {
				return false;
			}
		} elseif ( ! empty( $required_categories ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Country-based segmentation for post-purchase offers.
	 */
	private function matches_country_rules( array $offer, \WC_Order $order ): bool {
		if ( empty( $offer['country_codes'] ) ) {
			return true;
		}

		$allowed = array_filter( array_map( 'strtoupper', array_map( 'trim', explode( ',', (string) $offer['country_codes'] ) ) ) );
		if ( empty( $allowed ) ) {
			return true;
		}

		$country = (string) $order->get_billing_country();
		if ( '' === $country ) {
			$country = (string) $order->get_shipping_country();
		}

		return '' !== $country && in_array( strtoupper( $country ), $allowed, true );
	}

	/**
	 * Device-based segmentation for post-purchase offers.
	 */
	private function matches_device_rules( array $offer ): bool {
		$target = (string) ( $offer['device_target'] ?? 'all' );
		if ( '' === $target || 'all' === $target ) {
			return true;
		}

		$is_mobile = wp_is_mobile();
		if ( 'mobile' === $target ) {
			return $is_mobile;
		}
		if ( 'desktop' === $target ) {
			return ! $is_mobile;
		}

		return true;
	}

	/**
	 * Check if customer purchased any product in categories.
	 *
	 * @param int   $customer_id Customer ID.
	 * @param int[] $category_ids Category IDs.
	 */
	private function has_purchased_category_before( int $customer_id, array $category_ids ): bool {
		$orders = wc_get_orders(
			array(
				'customer_id' => $customer_id,
				'status'      => array( 'wc-processing', 'wc-completed', 'wc-on-hold' ),
				'limit'       => 50,
				'orderby'     => 'date',
				'order'       => 'DESC',
				'return'      => 'objects',
			)
		);

		foreach ( $orders as $past_order ) {
			if ( ! $past_order instanceof \WC_Order ) {
				continue;
			}
			foreach ( $past_order->get_items() as $item ) {
				$terms = get_the_terms( (int) $item->get_product_id(), 'product_cat' );
				if ( empty( $terms ) || is_wp_error( $terms ) ) {
					continue;
				}
				foreach ( $terms as $term ) {
					if ( in_array( (int) $term->term_id, $category_ids, true ) ) {
						return true;
					}
				}
			}
		}

		return false;
	}

	/**
	 * Resolve dismiss count for customer/order context.
	 */
	private function get_dismiss_count( \WC_Order $order, int $offer_id ): int {
		$customer_id = (int) $order->get_customer_id();
		if ( $customer_id > 0 ) {
			return (int) get_user_meta( $customer_id, '_wbcom_suo_dismiss_' . $offer_id, true );
		}

		return (int) $order->get_meta( '_wbcom_suo_dismiss_' . $offer_id );
	}

	/**
	 * Increment dismiss counter for customer/order context.
	 */
	private function increment_dismiss_count( \WC_Order $order, int $offer_id ): void {
		if ( $offer_id <= 0 ) {
			return;
		}

		$customer_id = (int) $order->get_customer_id();
		if ( $customer_id > 0 ) {
			$current = (int) get_user_meta( $customer_id, '_wbcom_suo_dismiss_' . $offer_id, true );
			update_user_meta( $customer_id, '_wbcom_suo_dismiss_' . $offer_id, $current + 1 );
			return;
		}

		$current = (int) $order->get_meta( '_wbcom_suo_dismiss_' . $offer_id );
		$order->update_meta_data( '_wbcom_suo_dismiss_' . $offer_id, $current + 1 );
		$order->save();
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

	/**
	 * Apply coupon on upsell order when configured.
	 */
	private function maybe_apply_offer_coupon( \WC_Order $order, array $offer ): void {
		$code = sanitize_text_field( (string) ( $offer['coupon_code'] ?? '' ) );
		if ( '' === $code || empty( $offer['coupon_auto_apply'] ) ) {
			return;
		}

		$coupon = new \WC_Coupon( $code );
		if ( ! $coupon->get_id() ) {
			return;
		}

		$limit = absint( $offer['coupon_usage_limit'] ?? 0 );
		if ( $limit > 0 && absint( $coupon->get_usage_count() ) >= $limit ) {
			return;
		}

		$line_items = $order->get_items( 'line_item' );
		if ( empty( $line_items ) ) {
			return;
		}

		$current_codes = array_map( 'wc_strtolower', $order->get_coupon_codes() );
		if ( in_array( wc_strtolower( $code ), $current_codes, true ) ) {
			return;
		}

		try {
			if ( class_exists( '\WC_Discounts' ) ) {
				$discounts = new \WC_Discounts( $order );
				$validity  = $discounts->is_coupon_valid( $coupon );
				if ( is_wp_error( $validity ) ) {
					return;
				}
			}

			$result = $order->apply_coupon( $code );
			if ( is_wp_error( $result ) ) {
				return;
			}

			$order->calculate_totals();
		} catch ( \Throwable $e ) {
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->warning(
					'WBCOM SUO upsell coupon auto-apply skipped: ' . $e->getMessage(),
					array(
						'source'   => 'wbcom-suo',
						'order_id' => $order->get_id(),
						'offer_id' => (int) ( $offer['offer_id'] ?? 0 ),
						'coupon'   => $code,
					)
				);
			}
		}
	}

	/**
	 * Countdown markup for upsell offers.
	 */
	private function render_countdown_markup( array $offer, \WC_Order $order ): string {
		$mode = (string) ( $offer['countdown_mode'] ?? 'none' );
		if ( '' === $mode || 'none' === $mode ) {
			return '';
		}

		$end_epoch = 0;
		if ( 'fixed' === $mode ) {
			$raw = sanitize_text_field( (string) ( $offer['countdown_end'] ?? '' ) );
			$ts  = strtotime( $raw );
			$end_epoch = $ts ? (int) $ts : 0;
		} elseif ( 'evergreen' === $mode ) {
			$key = '_wbcom_suo_countdown_' . (int) ( $offer['offer_id'] ?? 0 );
			$existing = (int) $order->get_meta( $key );
			if ( $existing > time() ) {
				$end_epoch = $existing;
			} else {
				$minutes = max( 1, absint( $offer['countdown_minutes'] ?? 15 ) );
				$end_epoch = time() + ( $minutes * MINUTE_IN_SECONDS );
				$order->update_meta_data( $key, $end_epoch );
				$order->save();
			}
		}

		if ( $end_epoch <= time() ) {
			return '';
		}

		return '<p class="wbcom-suo-countdown" data-countdown-end="' . esc_attr( (string) $end_epoch ) . '">' . esc_html__( 'Offer expires soon', 'wbcom-smart-upsell-order-bump' ) . '</p>';
	}

	/**
	 * Apply AB variant and persist variant key to order meta for consistent handling.
	 */
	private function apply_offer_variant( array $offer, \WC_Order $order, bool $for_display, string $requested_variant = '' ): array {
		if ( empty( $offer['ab_testing_enabled'] ) || empty( $offer['offer_id'] ) ) {
			$offer['_ab_variant'] = 'a';
			return $offer;
		}

		$key = '_wbcom_suo_variant_' . (int) $offer['offer_id'];
		$variant = 'a';

		if ( $for_display ) {
			$variant = ( new AbTestingManager() )->resolve_variant( $offer );
			$order->update_meta_data( $key, $variant );
			$order->save();
		} else {
			$stored = sanitize_key( (string) $order->get_meta( $key ) );
			if ( in_array( $requested_variant, array( 'a', 'b' ), true ) ) {
				$variant = $requested_variant;
			} elseif ( in_array( $stored, array( 'a', 'b' ), true ) ) {
				$variant = $stored;
			}
		}

		return ( new AbTestingManager() )->apply_variant( $offer, $variant );
	}

	/**
	 * Render upsell bundle list.
	 */
	private function render_bundle_markup( array $offer, \WC_Product $base_product ): string {
		$mode  = (string) ( $offer['bundle_mode'] ?? 'none' );
		$limit = max( 1, min( 8, (int) ( $offer['bundle_limit'] ?? 3 ) ) );
		$ids   = array();

		if ( 'manual' === $mode ) {
			$ids = array_filter( array_map( 'absint', explode( ',', (string) ( $offer['bundle_product_ids'] ?? '' ) ) ) );
		} elseif ( in_array( $mode, array( 'fbt', 'same_category', 'tag_match' ), true ) ) {
			$ids = wc_get_related_products( $base_product->get_id(), $limit + 2 );
		}

		$ids = array_slice( array_values( array_diff( array_unique( array_map( 'absint', $ids ) ), array( $base_product->get_id() ) ) ), 0, $limit );
		if ( empty( $ids ) ) {
			return '';
		}

		ob_start();
		echo '<div class="wbcom-suo-bundle"><strong>' . esc_html__( 'You may also like', 'wbcom-smart-upsell-order-bump' ) . '</strong><ul>';
		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );
			if ( ! $product ) {
				continue;
			}
			echo '<li><a href="' . esc_url( get_permalink( $id ) ) . '">' . esc_html( $product->get_name() ) . '</a></li>';
		}
		echo '</ul></div>';
		return (string) ob_get_clean();
	}
}
