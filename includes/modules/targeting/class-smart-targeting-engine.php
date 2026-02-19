<?php
/**
 * Targeting engine.
 *
 * @package WbcomSmartUpsellOrderBump
 */

namespace Wbcom\SmartUpsell\Modules\Targeting;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SmartTargetingEngine {
	/**
	 * Check if offer matches current cart context.
	 *
	 * @param array $offer Offer settings.
	 */
	public function should_show_offer( array $offer ): bool {
		if ( empty( $offer['product_id'] ) ) {
			return false;
		}

		// Table-backed offers are already filtered by "active" status in repository methods.
		if ( isset( $offer['enabled'] ) && empty( $offer['enabled'] ) ) {
			return false;
		}

		if ( ! $this->is_schedule_valid( $offer ) ) {
			return false;
		}

		if ( ! $this->matches_role( $offer ) ) {
			return false;
		}

		if ( ! $this->matches_customer( $offer ) ) {
			return false;
		}

		if ( ! $this->matches_country( $offer ) ) {
			return false;
		}

		if ( ! $this->matches_device( $offer ) ) {
			return false;
		}

		if ( ! $this->matches_cart_total( $offer ) ) {
			return false;
		}

		if ( ! $this->matches_trigger_products( $offer ) ) {
			return false;
		}

		if ( ! $this->matches_trigger_categories( $offer ) ) {
			return false;
		}

		if ( ! empty( $offer['skip_if_in_cart'] ) && $this->is_product_in_cart( (int) $offer['product_id'] ) ) {
			return false;
		}

		if ( ! empty( $offer['skip_if_purchased'] ) && $this->has_purchased_before( (int) $offer['product_id'] ) ) {
			return false;
		}

		if ( ! empty( $offer['dismiss_limit'] ) && $this->get_dismiss_count( (int) ( $offer['offer_id'] ?? 0 ) ) >= (int) $offer['dismiss_limit'] ) {
			return false;
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
	 * User role matching.
	 */
	private function matches_role( array $offer ): bool {
		if ( empty( $offer['user_roles'] ) ) {
			return true;
		}

		$user = wp_get_current_user();
		if ( ! $user instanceof \WP_User ) {
			return false;
		}

		$allowed_roles = array_map( 'trim', explode( ',', (string) $offer['user_roles'] ) );
		return (bool) array_intersect( $allowed_roles, (array) $user->roles );
	}

	/**
	 * Customer-based and order-history rules.
	 */
	private function matches_customer( array $offer ): bool {
		$user_id = get_current_user_id();
		$email   = '';

		if ( $user_id > 0 ) {
			$user  = get_user_by( 'id', $user_id );
			$email = $user ? (string) $user->user_email : '';
		}

		if ( ! empty( $offer['customer_email'] ) ) {
			if ( '' === $email || strtolower( (string) $offer['customer_email'] ) !== strtolower( $email ) ) {
				return false;
			}
		}

		if ( ! empty( $offer['lifetime_spend_threshold'] ) ) {
			if ( $user_id <= 0 ) {
				return false;
			}
			$spent = (float) wc_get_customer_total_spent( $user_id );
			if ( $spent < (float) $offer['lifetime_spend_threshold'] ) {
				return false;
			}
		}

		if ( ! empty( $offer['first_time_only'] ) ) {
			if ( $user_id <= 0 || (int) wc_get_customer_order_count( $user_id ) > 0 ) {
				return false;
			}
		}

		if ( ! empty( $offer['returning_only'] ) ) {
			if ( $user_id <= 0 || (int) wc_get_customer_order_count( $user_id ) <= 0 ) {
				return false;
			}
		}

		if ( ! empty( $offer['purchase_frequency_min'] ) ) {
			if ( $user_id <= 0 ) {
				return false;
			}
			if ( (int) wc_get_customer_order_count( $user_id ) < (int) $offer['purchase_frequency_min'] ) {
				return false;
			}
		}

		$required_products = array_filter( array_map( 'absint', explode( ',', (string) ( $offer['purchased_product_ids'] ?? '' ) ) ) );
		if ( ! empty( $required_products ) ) {
			if ( '' === $email && $user_id <= 0 ) {
				return false;
			}
			$matched = false;
			foreach ( $required_products as $product_id ) {
				if ( wc_customer_bought_product( $email, $user_id, $product_id ) ) {
					$matched = true;
					break;
				}
			}
			if ( ! $matched ) {
				return false;
			}
		}

		$required_categories = array_filter( array_map( 'absint', explode( ',', (string) ( $offer['purchased_category_ids'] ?? '' ) ) ) );
		if ( ! empty( $required_categories ) ) {
			if ( $user_id <= 0 ) {
				return false;
			}
			if ( ! $this->has_purchased_category_before( $user_id, $required_categories ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Country-based segmentation.
	 */
	private function matches_country( array $offer ): bool {
		if ( empty( $offer['country_codes'] ) ) {
			return true;
		}

		$allowed = array_filter( array_map( 'strtoupper', array_map( 'trim', explode( ',', (string) $offer['country_codes'] ) ) ) );
		if ( empty( $allowed ) ) {
			return true;
		}

		$current = '';
		if ( function_exists( 'WC' ) && WC()->customer ) {
			$current = (string) WC()->customer->get_billing_country();
			if ( '' === $current ) {
				$current = (string) WC()->customer->get_shipping_country();
			}
		}

		if ( '' === $current && function_exists( 'wc_get_customer_default_location' ) ) {
			$geo = wc_get_customer_default_location();
			$current = is_array( $geo ) ? (string) ( $geo['country'] ?? '' ) : '';
		}

		return '' !== $current && in_array( strtoupper( $current ), $allowed, true );
	}

	/**
	 * Device-based segmentation.
	 */
	private function matches_device( array $offer ): bool {
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
	 * Cart total rule.
	 */
	private function matches_cart_total( array $offer ): bool {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return empty( $offer['min_cart_total'] ) && empty( $offer['max_cart_total'] ) && empty( $offer['quantity_threshold'] );
		}

		$total = (float) WC()->cart->get_subtotal();

		if ( ! empty( $offer['min_cart_total'] ) && $total < (float) $offer['min_cart_total'] ) {
			return false;
		}

		if ( ! empty( $offer['max_cart_total'] ) && $total > (float) $offer['max_cart_total'] ) {
			return false;
		}

		if ( ! empty( $offer['quantity_threshold'] ) && WC()->cart->get_cart_contents_count() < (int) $offer['quantity_threshold'] ) {
			return false;
		}

		return true;
	}

	/**
	 * Trigger product matching.
	 */
	private function matches_trigger_products( array $offer ): bool {
		if ( empty( $offer['trigger_product_ids'] ) ) {
			return true;
		}

		$required = array_filter( array_map( 'absint', explode( ',', (string) $offer['trigger_product_ids'] ) ) );
		if ( empty( $required ) ) {
			return true;
		}

		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return false;
		}

		foreach ( WC()->cart->get_cart() as $item ) {
			if ( in_array( (int) $item['product_id'], $required, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Trigger category matching.
	 */
	private function matches_trigger_categories( array $offer ): bool {
		if ( empty( $offer['trigger_category_ids'] ) ) {
			return true;
		}

		$required = array_filter( array_map( 'absint', explode( ',', (string) $offer['trigger_category_ids'] ) ) );
		if ( empty( $required ) ) {
			return true;
		}

		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return false;
		}

		foreach ( WC()->cart->get_cart() as $item ) {
			$terms = get_the_terms( (int) $item['product_id'], 'product_cat' );
			if ( empty( $terms ) || is_wp_error( $terms ) ) {
				continue;
			}

			foreach ( $terms as $term ) {
				if ( in_array( (int) $term->term_id, $required, true ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Check if product is in cart.
	 */
	private function is_product_in_cart( int $product_id ): bool {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return false;
		}

		foreach ( WC()->cart->get_cart() as $item ) {
			if ( (int) $item['product_id'] === $product_id ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check user purchase history.
	 */
	private function has_purchased_before( int $product_id ): bool {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		return wc_customer_bought_product( '', get_current_user_id(), $product_id );
	}

	/**
	 * Check if customer purchased any product from given categories before.
	 *
	 * @param int   $user_id User ID.
	 * @param int[] $category_ids Category IDs.
	 */
	private function has_purchased_category_before( int $user_id, array $category_ids ): bool {
		$orders = wc_get_orders(
			array(
				'customer_id' => $user_id,
				'status'      => array( 'wc-processing', 'wc-completed', 'wc-on-hold' ),
				'limit'       => 50,
				'orderby'     => 'date',
				'order'       => 'DESC',
				'return'      => 'objects',
			)
		);

		foreach ( $orders as $order ) {
			if ( ! $order instanceof \WC_Order ) {
				continue;
			}
			foreach ( $order->get_items() as $item ) {
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
	 * Get persistent dismiss count for an offer.
	 */
	private function get_dismiss_count( int $offer_id ): int {
		if ( $offer_id <= 0 ) {
			return 0;
		}

		$user_id = get_current_user_id();
		if ( $user_id > 0 ) {
			return (int) get_user_meta( $user_id, '_wbcom_suo_dismiss_' . $offer_id, true );
		}

		if ( function_exists( 'WC' ) && WC()->session ) {
			$counts = WC()->session->get( 'wbcom_suo_dismiss_counts', array() );
			$counts = is_array( $counts ) ? $counts : array();
			return (int) ( $counts[ $offer_id ] ?? 0 );
		}

		return 0;
	}
}
