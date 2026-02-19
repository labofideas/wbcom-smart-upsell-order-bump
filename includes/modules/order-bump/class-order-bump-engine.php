<?php
/**
 * Checkout/cart bump module.
 *
 * @package WbcomSmartUpsellOrderBump
 */

namespace Wbcom\SmartUpsell\Modules\OrderBump;

use Wbcom\SmartUpsell\Modules\Display\OfferDisplayManager;
use Wbcom\SmartUpsell\Modules\Offers\AbTestingManager;
use Wbcom\SmartUpsell\Modules\Offers\OfferRepository;
use Wbcom\SmartUpsell\Modules\Targeting\SmartTargetingEngine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OrderBumpEngine {
	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'woocommerce_review_order_before_payment', array( $this, 'render_checkout_bump_before_payment' ) );
		add_action( 'woocommerce_review_order_after_order_total', array( $this, 'render_checkout_bump_after_summary' ) );
		add_action( 'woocommerce_checkout_create_order', array( $this, 'append_order_bump_item' ), 20, 2 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'append_order_bump_item_from_store_api' ), 20 );
		add_action( 'woocommerce_before_checkout_form', array( $this, 'render_checkout_bump_for_block_checkout' ), 25 );
		add_action( 'woocommerce_before_cart', array( $this, 'render_cart_bump' ), 25 );
		add_action( 'woocommerce_before_cart_collaterals', array( $this, 'render_cart_bump' ) );
		add_filter( 'render_block', array( $this, 'append_cart_bump_to_cart_block' ), 20, 2 );
		add_filter( 'render_block', array( $this, 'append_checkout_bump_to_checkout_block' ), 20, 2 );
		add_action( 'wp_ajax_wbcom_suo_dismiss_offer', array( $this, 'handle_dismiss_offer' ) );
		add_action( 'wp_ajax_nopriv_wbcom_suo_dismiss_offer', array( $this, 'handle_dismiss_offer' ) );
		add_action( 'wp_ajax_wbcom_suo_toggle_checkout_bump', array( $this, 'handle_checkout_bump_toggle' ) );
		add_action( 'wp_ajax_nopriv_wbcom_suo_toggle_checkout_bump', array( $this, 'handle_checkout_bump_toggle' ) );
		add_action( 'wp_footer', array( $this, 'render_exit_intent_modal' ), 40 );
		add_shortcode( 'wbcom_suo_checkout_bump', array( $this, 'render_checkout_bump_shortcode' ) );
		add_shortcode( 'wbcom_suo_cart_bump', array( $this, 'render_cart_bump_shortcode' ) );
	}

	/**
	 * Render bump before payment block.
	 */
	public function render_checkout_bump_before_payment(): void {
		$offer = $this->get_checkout_offer( true );
		if ( empty( $offer ) || 'before_payment' !== ( $offer['position'] ?? 'before_payment' ) ) {
			return;
		}

		$this->render_checkout_offer_markup( $offer );
	}

	/**
	 * Render bump after summary block.
	 */
	public function render_checkout_bump_after_summary(): void {
		$offer = $this->get_checkout_offer( true );
		if ( empty( $offer ) || 'after_order_summary' !== ( $offer['position'] ?? 'before_payment' ) ) {
			return;
		}

		echo '<tr><td colspan="2">';
		$this->render_checkout_offer_markup( $offer );
		echo '</td></tr>';
	}

	/**
	 * Render cart bump.
	 */
	public function render_cart_bump(): void {
		static $rendered = false;
		if ( $rendered ) {
			return;
		}

		$offer = $this->get_cart_offer( true );
		if ( empty( $offer ) ) {
			return;
		}
		$rendered = true;
		echo wp_kses_post( $this->build_cart_offer_markup( $offer ) );
	}

	/**
	 * Append bump item on checkout submit.
	 *
	 * @param \WC_Order $order Order object.
	 */
	public function append_order_bump_item( \WC_Order $order ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce checkout submission is nonce validated upstream.
		$is_checked_classic = ! empty( $_POST['wbcom_suo_checkout_bump'] );
		$is_checked_store   = (bool) $order->get_meta( '_wbcom_suo_store_api_checkout_bump' );
		$is_checked_session = function_exists( 'WC' ) && WC()->session ? (bool) WC()->session->get( 'wbcom_suo_checkout_bump_selected', false ) : false;
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Cookie is sanitized with wc_clean() and compared to a fixed string.
		$checkout_bump_cookie = isset( $_COOKIE['wbcom_suo_checkout_bump'] ) ? wc_clean( wp_unslash( $_COOKIE['wbcom_suo_checkout_bump'] ) ) : '';
		$is_checked_cookie    = '1' === $checkout_bump_cookie;
		if ( ! $is_checked_classic && ! $is_checked_store && ! $is_checked_session && ! $is_checked_cookie ) {
			return;
		}

		if ( $order->get_meta( '_wbcom_suo_checkout_bump_added' ) ) {
			return;
		}

		$offer = $this->get_checkout_offer();
		if ( empty( $offer ) ) {
			return;
		}

		$this->append_offer_to_order( $order, $offer );

		if ( function_exists( 'WC' ) && WC()->session ) {
			WC()->session->set( 'wbcom_suo_checkout_bump_selected', false );
		}
		if ( isset( $_COOKIE['wbcom_suo_checkout_bump'] ) ) {
			wc_setcookie( 'wbcom_suo_checkout_bump', '0', time() + HOUR_IN_SECONDS );
		}
	}

	/**
	 * Apply checkout bump for Store API (block checkout) orders.
	 *
	 * @param \WC_Order $order Order object.
	 */
	public function append_order_bump_item_from_store_api( \WC_Order $order ): void {
		$this->append_order_bump_item( $order );
		if ( $order->get_meta( '_wbcom_suo_checkout_bump_added' ) ) {
			$order->calculate_totals();
			$order->save();
		}
	}

	/**
	 * Append configured offer to order.
	 */
	public function append_offer_to_order( \WC_Order $order, array $offer ): void {
		$product = wc_get_product( (int) $offer['product_id'] );
		if ( ! $product ) {
			return;
		}

		$price = $this->get_discounted_price( $product, $offer );

		$item = new \WC_Order_Item_Product();
		$item->set_product( $product );
		$item->set_quantity( 1 );
		$item->set_subtotal( (float) $product->get_price() );
		$item->set_total( $price );
		$order->add_item( $item );
		$this->maybe_apply_offer_coupon( $order, $offer );

		$order->update_meta_data( '_wbcom_suo_checkout_bump_added', 1 );
		$order->update_meta_data( '_wbcom_suo_checkout_bump_offer_id', (int) ( $offer['offer_id'] ?? 0 ) );

		do_action(
			'wbcom_suo_track_event',
			'checkout',
			(int) ( $offer['offer_id'] ?? 0 ),
			'accept',
			array(
				'order_id' => $order->get_id(),
				'revenue'  => $price,
				'context'  => 'checkout',
			)
		);

		$variant = (string) ( $offer['_ab_variant'] ?? 'a' );
		( new AbTestingManager() )->record_variant_event( (int) ( $offer['offer_id'] ?? 0 ), $variant, 'accept' );
	}

	/**
	 * Get active checkout offer.
	 */
	private function get_checkout_offer( bool $for_display = false ): array {
		$settings = get_option( 'wbcom_suo_settings', array() );
		if ( empty( $settings['enable_order_bumps'] ) ) {
			return array();
		}

		$targeting = new SmartTargetingEngine();
		$offer = ( new OfferRepository() )->get_first_active_offer(
			'checkout',
			static function ( array $candidate ) use ( $targeting ): bool {
				return $targeting->should_show_offer( $candidate );
			}
		);

		if ( empty( $offer ) ) {
			return array();
		}

		return $this->apply_offer_variant( $offer, $for_display );
	}

	/**
	 * Render checkout offer markup.
	 *
	 * @param array $offer Offer data.
	 */
	private function render_checkout_offer_markup( array $offer ): void {
		$product = wc_get_product( (int) $offer['product_id'] );
		if ( ! $product ) {
			return;
		}

		$classes      = ( new OfferDisplayManager() )->get_classes( $offer );
		$price        = $this->get_discounted_price( $product, $offer );
		$display_type = (string) ( $offer['display_type'] ?? 'checkbox' );
		$offer_id     = (int) ( $offer['offer_id'] ?? 0 );
		$title        = (string) ( $offer['title'] ?: __( 'Add this special offer', 'wbcom-smart-upsell-order-bump' ) );
		$description  = (string) ( $offer['description'] ?: $product->get_short_description() );
		$image_markup = $this->get_offer_image_markup( $product, $offer, 'checkout' );
		$checkbox     = '<input type="checkbox" class="wbcom-suo-checkout-checkbox" name="wbcom_suo_checkout_bump" value="1" />';

		$variant = (string) ( $offer['_ab_variant'] ?? 'a' );
		do_action( 'wbcom_suo_track_event', 'checkout', (int) ( $offer['offer_id'] ?? 0 ), 'view', array( 'context' => 'checkout_' . $variant ) );
		( new AbTestingManager() )->record_variant_event( (int) ( $offer['offer_id'] ?? 0 ), $variant, 'view' );

		ob_start();
		?>
		<div class="<?php echo esc_attr( $classes ); ?> wbcom-suo-checkout-card" data-offer-id="<?php echo esc_attr( (string) $offer_id ); ?>">
			<?php if ( 'checkbox' === $display_type ) : ?>
				<label class="wbcom-suo-choice"><?php echo $checkbox; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <strong><?php echo esc_html( $title ); ?></strong></label>
			<?php else : ?>
				<h4 class="wbcom-suo-title"><?php echo esc_html( $title ); ?></h4>
			<?php endif; ?>
			<?php echo $image_markup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<p><?php echo esc_html( $description ); ?></p>
			<p class="wbcom-suo-price"><?php echo wp_kses_post( wc_price( $price ) ); ?></p>
			<?php if ( 'checkbox' !== $display_type ) : ?>
				<label class="wbcom-suo-choice"><?php echo $checkbox; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <span><?php esc_html_e( 'Add this offer to order', 'wbcom-smart-upsell-order-bump' ); ?></span></label>
			<?php endif; ?>
			<?php echo $this->render_countdown_markup( $offer ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php if ( ! empty( $offer['coupon_code'] ) ) : ?>
				<?php /* translators: %s: coupon code. */ ?>
				<p class="wbcom-suo-coupon"><?php echo esc_html( sprintf( __( 'Use coupon: %s', 'wbcom-smart-upsell-order-bump' ), (string) $offer['coupon_code'] ) ); ?></p>
			<?php endif; ?>
			<?php echo $this->render_bundle_markup( $offer, $product ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<button type="button" class="button-link wbcom-suo-dismiss" data-offer-id="<?php echo esc_attr( (string) $offer_id ); ?>"><?php esc_html_e( 'Dismiss', 'wbcom-smart-upsell-order-bump' ); ?></button>
		</div>
		<?php
		$card_markup = (string) ob_get_clean();

		if ( 'popup' === $display_type ) {
			?>
			<button type="button" class="button wbcom-suo-popup-trigger" data-offer-id="<?php echo esc_attr( (string) $offer_id ); ?>"><?php esc_html_e( 'View Checkout Offer', 'wbcom-smart-upsell-order-bump' ); ?></button>
			<div class="wbcom-suo-cart-popup wbcom-suo-checkout-popup" data-offer-id="<?php echo esc_attr( (string) $offer_id ); ?>" hidden>
				<div class="wbcom-suo-popup-card">
					<?php echo $card_markup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<button type="button" class="button-link wbcom-suo-popup-close"><?php esc_html_e( 'Close', 'wbcom-smart-upsell-order-bump' ); ?></button>
				</div>
			</div>
			<?php
			return;
		}

		if ( 'grid' === $display_type ) {
			echo '<div class="wbcom-suo-grid">';
			echo $card_markup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '</div>';
			return;
		}
		?>
		<?php echo $card_markup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		<?php
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
	 * Persist dismiss action for checkout/cart offers.
	 */
	public function handle_dismiss_offer(): void {
		check_ajax_referer( 'wbcom_suo_dismiss', 'nonce' );

		$offer_id = absint( $_POST['offer_id'] ?? 0 );
		if ( $offer_id <= 0 ) {
			wp_send_json_error( array( 'message' => 'Invalid offer.' ), 400 );
		}

		$user_id = get_current_user_id();
		if ( $user_id > 0 ) {
			$key   = '_wbcom_suo_dismiss_' . $offer_id;
			$count = (int) get_user_meta( $user_id, $key, true );
			update_user_meta( $user_id, $key, $count + 1 );
		} elseif ( function_exists( 'WC' ) && WC()->session ) {
			$counts = WC()->session->get( 'wbcom_suo_dismiss_counts', array() );
			$counts = is_array( $counts ) ? $counts : array();
			$counts[ $offer_id ] = (int) ( $counts[ $offer_id ] ?? 0 ) + 1;
			WC()->session->set( 'wbcom_suo_dismiss_counts', $counts );
		}

		wp_send_json_success( array( 'dismissed' => true ) );
	}

	/**
	 * Inject cart bump after Woo block-cart output.
	 *
	 * @param string $block_content Block HTML.
	 * @param array  $block Block data.
	 */
	public function append_cart_bump_to_cart_block( string $block_content, array $block ): string {
		if ( empty( $block['blockName'] ) || 'woocommerce/cart' !== $block['blockName'] || ! function_exists( 'is_cart' ) || ! is_cart() ) {
			return $block_content;
		}

		$offer = $this->get_cart_offer( true );
		if ( empty( $offer ) ) {
			return $block_content;
		}

		return $block_content . $this->build_cart_offer_markup( $offer );
	}

	/**
	 * Inject checkout bump after Woo block checkout output.
	 *
	 * @param string $block_content Block HTML.
	 * @param array  $block Block data.
	 */
	public function append_checkout_bump_to_checkout_block( string $block_content, array $block ): string {
		if ( empty( $block['blockName'] ) || 'woocommerce/checkout' !== $block['blockName'] || ! function_exists( 'is_checkout' ) || ! is_checkout() || is_order_received_page() ) {
			return $block_content;
		}

		$offer = $this->get_checkout_offer( true );
		if ( empty( $offer ) ) {
			return $block_content;
		}

		ob_start();
		$this->render_checkout_offer_markup( $offer );
		$markup = (string) ob_get_clean();

		if ( '' === trim( $markup ) ) {
			return $block_content;
		}

		return $block_content . $markup;
	}

	/**
	 * Render checkout bump via classic checkout wrapper hook for block compatibility.
	 */
	public function render_checkout_bump_for_block_checkout(): void {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_order_received_page() || ! has_block( 'woocommerce/checkout' ) ) {
			return;
		}

		$offer = $this->get_checkout_offer();
		if ( empty( $offer ) ) {
			return;
		}

		$this->render_checkout_offer_markup( $offer );
	}

	/**
	 * Persist checkout bump selection in WC session for block checkout.
	 */
	public function handle_checkout_bump_toggle(): void {
		check_ajax_referer( 'wbcom_suo_dismiss', 'nonce' );

		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			wp_send_json_error( array( 'message' => 'Session unavailable.' ), 400 );
		}

		$selected = ! empty( $_POST['selected'] );
		WC()->session->set( 'wbcom_suo_checkout_bump_selected', $selected ? 1 : 0 );

		wp_send_json_success( array( 'selected' => $selected ) );
	}

	/**
	 * Shortcode renderer for checkout bump.
	 */
	public function render_checkout_bump_shortcode(): string {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_order_received_page() ) {
			return '';
		}

		$offer = $this->get_checkout_offer();
		if ( empty( $offer ) ) {
			return '';
		}

		ob_start();
		$this->render_checkout_offer_markup( $offer );
		return (string) ob_get_clean();
	}

	/**
	 * Shortcode renderer for cart bump.
	 */
	public function render_cart_bump_shortcode(): string {
		if ( ! function_exists( 'is_cart' ) || ! is_cart() ) {
			return '';
		}

		$offer = $this->get_cart_offer();
		if ( empty( $offer ) ) {
			return '';
		}

		return $this->build_cart_offer_markup( $offer );
	}

	/**
	 * Resolve active cart offer using targeting.
	 */
	private function get_cart_offer( bool $for_display = false ): array {
		$settings = get_option( 'wbcom_suo_settings', array() );
		if ( empty( $settings['enable_cart_bumps'] ) ) {
			return array();
		}

		$targeting = new SmartTargetingEngine();
		$offer = ( new OfferRepository() )->get_first_active_offer(
			'cart',
			static function ( array $candidate ) use ( $targeting ): bool {
				return $targeting->should_show_offer( $candidate );
			}
		);

		if ( empty( $offer ) ) {
			return array();
		}

		return $this->apply_offer_variant( $offer, $for_display );
	}

	/**
	 * Build cart bump markup.
	 */
	private function build_cart_offer_markup( array $offer ): string {
		$product = wc_get_product( (int) $offer['product_id'] );
		if ( ! $product ) {
			return '';
		}

		$variant = (string) ( $offer['_ab_variant'] ?? 'a' );
		do_action( 'wbcom_suo_track_event', 'cart', (int) ( $offer['offer_id'] ?? 0 ), 'view', array( 'context' => 'cart_' . $variant ) );
		( new AbTestingManager() )->record_variant_event( (int) ( $offer['offer_id'] ?? 0 ), $variant, 'view' );

		$display_type = (string) ( $offer['display_type'] ?? 'inline' );
		$offer_id     = (int) ( $offer['offer_id'] ?? 0 );
		$add_url      = add_query_arg( 'add-to-cart', $product->get_id(), wc_get_cart_url() );

		ob_start();
		if ( 'popup' === $display_type ) {
			?>
			<button type="button" class="button wbcom-suo-popup-trigger" data-offer-id="<?php echo esc_attr( (string) $offer_id ); ?>"><?php esc_html_e( 'View Special Offer', 'wbcom-smart-upsell-order-bump' ); ?></button>
			<div class="wbcom-suo-cart-popup" data-offer-id="<?php echo esc_attr( (string) $offer_id ); ?>" hidden>
				<div class="wbcom-suo-cart-bump wbcom-suo-popup-card">
					<h3><?php echo esc_html( $offer['title'] ?: __( 'Recommended add-on', 'wbcom-smart-upsell-order-bump' ) ); ?></h3>
					<?php echo $this->get_offer_image_markup( $product, $offer, 'cart' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<p><?php echo esc_html( $offer['description'] ?: $product->get_short_description() ); ?></p>
					<a class="button" href="<?php echo esc_url( $add_url ); ?>"><?php esc_html_e( 'Add to Cart', 'wbcom-smart-upsell-order-bump' ); ?></a>
					<button type="button" class="button-link wbcom-suo-popup-close"><?php esc_html_e( 'Close', 'wbcom-smart-upsell-order-bump' ); ?></button>
					<button type="button" class="button-link wbcom-suo-dismiss" data-offer-id="<?php echo esc_attr( (string) $offer_id ); ?>"><?php esc_html_e( 'Dismiss', 'wbcom-smart-upsell-order-bump' ); ?></button>
				</div>
			</div>
			<?php
			return (string) ob_get_clean();
		}

		if ( 'grid' === $display_type ) {
			echo '<div class="wbcom-suo-grid">';
		}
		?>
		<div class="wbcom-suo-cart-bump" data-offer-id="<?php echo esc_attr( (string) $offer_id ); ?>">
			<h3><?php echo esc_html( $offer['title'] ?: __( 'Recommended add-on', 'wbcom-smart-upsell-order-bump' ) ); ?></h3>
				<?php echo $this->get_offer_image_markup( $product, $offer, 'cart' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<p><?php echo esc_html( $offer['description'] ?: $product->get_short_description() ); ?></p>
				<?php echo $this->render_countdown_markup( $offer ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php if ( ! empty( $offer['coupon_code'] ) ) : ?>
					<?php /* translators: %s: coupon code. */ ?>
					<p class="wbcom-suo-coupon"><?php echo esc_html( sprintf( __( 'Coupon available: %s', 'wbcom-smart-upsell-order-bump' ), (string) $offer['coupon_code'] ) ); ?></p>
				<?php endif; ?>
				<?php echo $this->render_bundle_markup( $offer, $product ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<a class="button" href="<?php echo esc_url( $add_url ); ?>"><?php esc_html_e( 'Add to Cart', 'wbcom-smart-upsell-order-bump' ); ?></a>
			<button type="button" class="button-link wbcom-suo-dismiss" data-offer-id="<?php echo esc_attr( (string) $offer_id ); ?>"><?php esc_html_e( 'Dismiss', 'wbcom-smart-upsell-order-bump' ); ?></button>
		</div>
		<?php
		if ( 'grid' === $display_type ) {
			echo '</div>';
		}
		return (string) ob_get_clean();
	}

	/**
	 * Apply persisted/assigned A/B variant to offer payload.
	 */
	private function apply_offer_variant( array $offer, bool $for_display ): array {
		if ( empty( $offer['ab_testing_enabled'] ) || empty( $offer['offer_id'] ) ) {
			$offer['_ab_variant'] = 'a';
			return $offer;
		}

		$offer_id = (int) $offer['offer_id'];
		$variant  = 'a';

		if ( $for_display ) {
			$variant = ( new AbTestingManager() )->resolve_variant( $offer );
			if ( function_exists( 'WC' ) && WC()->session ) {
				WC()->session->set( 'wbcom_suo_offer_variant_' . $offer_id, $variant );
			}
		} elseif ( function_exists( 'WC' ) && WC()->session ) {
			$variant = (string) WC()->session->get( 'wbcom_suo_offer_variant_' . $offer_id, 'a' );
		}

		return ( new AbTestingManager() )->apply_variant( $offer, $variant );
	}

	/**
	 * Render related bundle suggestions for an offer.
	 */
	private function render_bundle_markup( array $offer, \WC_Product $base_product ): string {
		$products = $this->get_bundle_products( $offer, $base_product );
		if ( empty( $products ) ) {
			return '';
		}

		ob_start();
		echo '<div class="wbcom-suo-bundle"><strong>' . esc_html__( 'Recommended bundle items', 'wbcom-smart-upsell-order-bump' ) . '</strong><ul>';
		foreach ( $products as $product ) {
			echo '<li><a href="' . esc_url( get_permalink( $product->get_id() ) ) . '">' . esc_html( $product->get_name() ) . '</a></li>';
		}
		echo '</ul></div>';
		return (string) ob_get_clean();
	}

	/**
	 * Resolve bundle products by mode.
	 *
	 * @return \WC_Product[]
	 */
	private function get_bundle_products( array $offer, \WC_Product $base_product ): array {
		$mode  = (string) ( $offer['bundle_mode'] ?? 'none' );
		$limit = max( 1, min( 8, (int) ( $offer['bundle_limit'] ?? 3 ) ) );
		$ids   = array();

		if ( 'manual' === $mode ) {
			$ids = array_filter( array_map( 'absint', explode( ',', (string) ( $offer['bundle_product_ids'] ?? '' ) ) ) );
		} elseif ( 'same_category' === $mode ) {
			$terms = wp_get_post_terms( $base_product->get_id(), 'product_cat', array( 'fields' => 'ids' ) );
			if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
				$ids = wc_get_products(
					array(
						'status'   => 'publish',
						'limit'    => $limit + 2,
						'category' => array_map( 'strval', $terms ),
						'return'   => 'ids',
					)
				);
			}
		} elseif ( 'tag_match' === $mode ) {
			$terms = wp_get_post_terms( $base_product->get_id(), 'product_tag', array( 'fields' => 'ids' ) );
			if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
				$ids = wc_get_products(
					array(
						'status'  => 'publish',
						'limit'   => $limit + 2,
						'tag'     => array_map( 'strval', $terms ),
						'return'  => 'ids',
					)
				);
			}
		} elseif ( 'fbt' === $mode ) {
			$ids = wc_get_related_products( $base_product->get_id(), $limit + 2 );
		}

		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		$ids = array_slice( array_values( array_diff( $ids, array( $base_product->get_id() ) ) ), 0, $limit );

		$products = array();
		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );
			if ( $product instanceof \WC_Product ) {
				$products[] = $product;
			}
		}

		return $products;
	}

	/**
	 * Auto-apply configured coupon when offer is accepted.
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
					'WBCOM SUO coupon auto-apply skipped: ' . $e->getMessage(),
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
	 * Render countdown timer markup.
	 */
	private function render_countdown_markup( array $offer ): string {
		$mode = (string) ( $offer['countdown_mode'] ?? 'none' );
		if ( 'none' === $mode || '' === $mode ) {
			return '';
		}

		$end_epoch = $this->resolve_countdown_end_epoch( $offer );
		if ( $end_epoch <= time() ) {
			return '';
		}

		return '<p class="wbcom-suo-countdown" data-countdown-end="' . esc_attr( (string) $end_epoch ) . '">' . esc_html__( 'Offer expires soon', 'wbcom-smart-upsell-order-bump' ) . '</p>';
	}

	/**
	 * Resolve countdown end time for fixed/evergreen modes.
	 */
	private function resolve_countdown_end_epoch( array $offer ): int {
		$mode = (string) ( $offer['countdown_mode'] ?? 'none' );
		if ( 'fixed' === $mode ) {
			$raw = sanitize_text_field( (string) ( $offer['countdown_end'] ?? '' ) );
			$ts  = strtotime( $raw );
			return $ts ? (int) $ts : 0;
		}

		if ( 'evergreen' === $mode ) {
			$minutes = max( 1, absint( $offer['countdown_minutes'] ?? 15 ) );
			$key     = 'wbcom_suo_countdown_' . (int) ( $offer['offer_id'] ?? 0 );

			if ( function_exists( 'WC' ) && WC()->session ) {
				$existing = (int) WC()->session->get( $key, 0 );
				if ( $existing > time() ) {
					return $existing;
				}
				$end = time() + ( $minutes * MINUTE_IN_SECONDS );
				WC()->session->set( $key, $end );
				return $end;
			}
		}

		return 0;
	}

	/**
	 * Render exit-intent popup for abandoned cart bumps.
	 */
	public function render_exit_intent_modal(): void {
		if ( ! function_exists( 'is_cart' ) || ! function_exists( 'is_checkout' ) ) {
			return;
		}

		if ( ! ( is_cart() || ( is_checkout() && ! is_order_received_page() ) ) ) {
			return;
		}

		$offer = $this->get_exit_intent_offer();
		if ( empty( $offer ) ) {
			return;
		}

		$product = wc_get_product( (int) $offer['product_id'] );
		if ( ! $product ) {
			return;
		}

		$add_url = add_query_arg( 'add-to-cart', $product->get_id(), wc_get_cart_url() );
		$delay   = max( 0, absint( $offer['abandoned_delay_seconds'] ?? 0 ) );
		?>
		<div class="wbcom-suo-exit-modal" data-wbcom-suo-exit-intent="1" data-delay-seconds="<?php echo esc_attr( (string) $delay ); ?>" data-offer-id="<?php echo esc_attr( (string) ( $offer['offer_id'] ?? 0 ) ); ?>" hidden>
			<div class="wbcom-suo-exit-card">
				<h3><?php echo esc_html( $offer['title'] ?: __( 'Wait! Special offer before you go', 'wbcom-smart-upsell-order-bump' ) ); ?></h3>
				<?php echo $this->get_offer_image_markup( $product, $offer, 'exit' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<p><?php echo esc_html( $offer['description'] ?: $product->get_short_description() ); ?></p>
				<?php echo $this->render_countdown_markup( $offer ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<p>
					<a class="button" href="<?php echo esc_url( $add_url ); ?>"><?php esc_html_e( 'Add Offer to Cart', 'wbcom-smart-upsell-order-bump' ); ?></a>
					<button type="button" class="button-link wbcom-suo-exit-close"><?php esc_html_e( 'No thanks', 'wbcom-smart-upsell-order-bump' ); ?></button>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Find first eligible exit-intent offer.
	 */
	private function get_exit_intent_offer(): array {
		$types = is_cart() ? array( 'cart', 'checkout' ) : array( 'checkout', 'cart' );
		$repo  = new OfferRepository();
		$targeting = new SmartTargetingEngine();

		foreach ( $types as $type ) {
			$offer = $repo->get_first_active_offer(
				$type,
				static function ( array $candidate ) use ( $targeting ): bool {
					return ! empty( $candidate['abandoned_enabled'] ) && ! empty( $candidate['exit_intent_enabled'] ) && $targeting->should_show_offer( $candidate );
				}
			);

			if ( ! empty( $offer ) ) {
				return $this->apply_offer_variant( $offer, true );
			}
		}

		return array();
	}

	/**
	 * Render product image for offer cards if enabled.
	 */
	private function get_offer_image_markup( \WC_Product $product, array $offer, string $context ): string {
		if ( empty( $offer['show_image'] ) ) {
			return '';
		}

		$image = $product->get_image(
			'woocommerce_thumbnail',
			array(
				'class'    => 'wbcom-suo-product-image wbcom-suo-product-image-' . sanitize_html_class( $context ),
				'loading'  => 'lazy',
				'decoding' => 'async',
			)
		);

		if ( '' === trim( $image ) ) {
			return '';
		}

		return '<div class="wbcom-suo-product-image-wrap">' . $image . '</div>';
	}
}
