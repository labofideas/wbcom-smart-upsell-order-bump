<?php
/**
 * Offer repository.
 *
 * @package WbcomSmartUpsellOrderBump
 */

namespace Wbcom\SmartUpsell\Modules\Offers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OfferRepository {
	/**
	 * Fetch offers by type.
	 *
	 * @param string $offer_type checkout|cart|post_purchase.
	 */
	public function get_offers_by_type( string $offer_type ): array {
		global $wpdb;

		$table      = esc_sql( $wpdb->prefix . 'wbcom_suo_offers' );
		$offer_type = sanitize_key( $offer_type );
		$cache_key  = 'offers_by_type_' . md5( $offer_type );
		$cached     = wp_cache_get( $cache_key, 'wbcom_suo_offers' );

		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,PluginCheck.Security.DirectDB.UnescapedDBParameter
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$table} WHERE offer_type = %s ORDER BY priority ASC, id DESC",
				$offer_type
			),
			ARRAY_A
		);
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$offers = array_map( array( $this, 'hydrate_offer' ), $rows );
		wp_cache_set( $cache_key, $offers, 'wbcom_suo_offers', 300 );

		return $offers;
	}

	/**
	 * Fetch active offers by type.
	 *
	 * @param string $offer_type checkout|cart|post_purchase.
	 */
	public function get_active_offers_by_type( string $offer_type ): array {
		$offers = $this->get_offers_by_type( $offer_type );
		return array_values(
			array_filter(
				$offers,
				static function ( array $offer ): bool {
					return 'active' === ( $offer['status'] ?? 'draft' );
				}
			)
		);
	}

	/**
	 * Fetch first matching active offer.
	 *
	 * @param string        $offer_type Offer type.
	 * @param callable|null $matcher Optional filter callback.
	 */
	public function get_first_active_offer( string $offer_type, ?callable $matcher = null ): array {
		$offers = $this->get_active_offers_by_type( $offer_type );

		foreach ( $offers as $offer ) {
			if ( null === $matcher || $matcher( $offer ) ) {
				return $offer;
			}
		}

		return array();
	}

	/**
	 * Fetch a single offer.
	 */
	public function get_offer( int $id ): array {
		global $wpdb;

		$table = esc_sql( $wpdb->prefix . 'wbcom_suo_offers' );
		$id    = absint( $id );
		$cache_key = 'offer_' . $id;
		$cached    = wp_cache_get( $cache_key, 'wbcom_suo_offers' );

		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,PluginCheck.Security.DirectDB.UnescapedDBParameter
		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$table} WHERE id = %d",
				$id
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			return array();
		}

		$offer = $this->hydrate_offer( $row );
		wp_cache_set( $cache_key, $offer, 'wbcom_suo_offers', 300 );

		return $offer;
	}

	/**
	 * Insert or update offer.
	 *
	 * @param array    $data Offer payload.
	 * @param int|null $id Existing ID.
	 */
	public function save_offer( array $data, ?int $id = null ): int {
		global $wpdb;

		$table   = esc_sql( $wpdb->prefix . 'wbcom_suo_offers' );
		$payload = $this->prepare_db_payload( $data );

		if ( $id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->update(
				$table,
				$payload,
				array( 'id' => $id ),
				array( '%s', '%s', '%s', '%d', '%d', '%s', '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ),
				array( '%d' )
			);
			wp_cache_delete( 'offer_' . $id, 'wbcom_suo_offers' );
			wp_cache_delete( 'offers_count', 'wbcom_suo_offers' );
			return $id;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$table,
			$payload,
			array( '%s', '%s', '%s', '%d', '%d', '%s', '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		$new_id = (int) $wpdb->insert_id;
		wp_cache_delete( 'offers_count', 'wbcom_suo_offers' );

		return $new_id;
	}

	/**
	 * Delete offer.
	 */
	public function delete_offer( int $id ): void {
		global $wpdb;
		$table = esc_sql( $wpdb->prefix . 'wbcom_suo_offers' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );
		wp_cache_delete( 'offer_' . absint( $id ), 'wbcom_suo_offers' );
		wp_cache_delete( 'offers_count', 'wbcom_suo_offers' );
	}

	/**
	 * Count offers.
	 */
	public function count_offers(): int {
		global $wpdb;
		$cached = wp_cache_get( 'offers_count', 'wbcom_suo_offers' );
		if ( false !== $cached ) {
			return (int) $cached;
		}

		$table = esc_sql( $wpdb->prefix . 'wbcom_suo_offers' );
		$count = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE 1 = %d", 1 ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		wp_cache_set( 'offers_count', $count, 'wbcom_suo_offers', 300 );

		return $count;
	}

	/**
	 * Migrate legacy single-offer settings into offers table.
	 */
	public function maybe_migrate_legacy_settings(): void {
		if ( $this->count_offers() > 0 ) {
			return;
		}

		$settings = get_option( 'wbcom_suo_settings', array() );
		$map      = array(
			'checkout_bump'        => 'checkout',
			'cart_bump'            => 'cart',
			'post_purchase_upsell' => 'post_purchase',
		);

		foreach ( $map as $legacy_key => $type ) {
			$legacy_offer = $settings[ $legacy_key ] ?? array();
			if ( empty( $legacy_offer['product_id'] ) ) {
				continue;
			}
			$this->save_offer( $this->legacy_to_payload( $legacy_offer, $type ) );
		}
	}

	/**
	 * Normalize DB row for runtime.
	 */
	private function hydrate_offer( array $row ): array {
		$rules    = json_decode( (string) ( $row['rules_json'] ?? '{}' ), true );
		$schedule = json_decode( (string) ( $row['schedule_json'] ?? '{}' ), true );
		$settings = json_decode( (string) ( $row['settings_json'] ?? '{}' ), true );

		$rules    = is_array( $rules ) ? $rules : array();
		$schedule = is_array( $schedule ) ? $schedule : array();
		$settings = is_array( $settings ) ? $settings : array();

		return array(
			'id'                       => (int) $row['id'],
			'offer_id'                 => (int) $row['id'],
			'name'                     => (string) $row['name'],
			'offer_type'               => (string) $row['offer_type'],
			'status'                   => (string) $row['status'],
			'priority'                 => (int) $row['priority'],
			'product_id'               => (int) $row['product_id'],
			'discount_type'            => (string) $row['discount_type'],
			'discount_value'           => (float) $row['discount_value'],
			'title'                    => (string) $row['title'],
			'description'              => (string) $row['description'],
			'display_type'             => (string) $row['display_type'],
			'position'                 => (string) $row['position'],
			'trigger_product_ids'      => (string) ( $rules['trigger_product_ids'] ?? '' ),
			'trigger_category_ids'     => (string) ( $rules['trigger_category_ids'] ?? '' ),
			'min_cart_total'           => (float) ( $rules['min_cart_total'] ?? 0 ),
			'max_cart_total'           => (float) ( $rules['max_cart_total'] ?? 0 ),
			'quantity_threshold'       => (int) ( $rules['quantity_threshold'] ?? 0 ),
			'user_roles'               => (string) ( $rules['user_roles'] ?? '' ),
			'customer_email'           => (string) ( $rules['customer_email'] ?? '' ),
			'lifetime_spend_threshold' => (float) ( $rules['lifetime_spend_threshold'] ?? 0 ),
			'purchase_frequency_min'   => (int) ( $rules['purchase_frequency_min'] ?? 0 ),
			'country_codes'            => (string) ( $rules['country_codes'] ?? '' ),
			'device_target'            => (string) ( $rules['device_target'] ?? 'all' ),
			'first_time_only'          => (int) ( $rules['first_time_only'] ?? 0 ),
			'returning_only'           => (int) ( $rules['returning_only'] ?? 0 ),
			'purchased_product_ids'    => (string) ( $rules['purchased_product_ids'] ?? '' ),
			'purchased_category_ids'   => (string) ( $rules['purchased_category_ids'] ?? '' ),
			'schedule_start'           => (string) ( $schedule['start_date'] ?? '' ),
			'schedule_end'             => (string) ( $schedule['end_date'] ?? '' ),
			'weekdays'                 => (string) ( $schedule['weekdays'] ?? '' ),
			'start_time'               => (string) ( $schedule['start_time'] ?? '' ),
			'end_time'                 => (string) ( $schedule['end_time'] ?? '' ),
			'skip_if_in_cart'          => (int) ( $settings['skip_if_in_cart'] ?? 0 ),
			'skip_if_purchased'        => (int) ( $settings['skip_if_purchased'] ?? 0 ),
			'dismiss_limit'            => (int) ( $settings['dismiss_limit'] ?? 3 ),
			'thank_you_message'        => (string) ( $settings['thank_you_message'] ?? '' ),
			'skip_label'               => (string) ( $settings['skip_label'] ?? '' ),
			'show_image'               => (int) ( $settings['show_image'] ?? 0 ),
			'auto_charge'              => (int) ( $settings['auto_charge'] ?? 0 ),
			'abandoned_enabled'        => (int) ( $settings['abandoned_enabled'] ?? 0 ),
			'exit_intent_enabled'      => (int) ( $settings['exit_intent_enabled'] ?? 0 ),
			'abandoned_delay_seconds'  => (int) ( $settings['abandoned_delay_seconds'] ?? 0 ),
			'coupon_code'              => (string) ( $settings['coupon_code'] ?? '' ),
			'coupon_auto_apply'        => (int) ( $settings['coupon_auto_apply'] ?? 0 ),
			'coupon_usage_limit'       => (int) ( $settings['coupon_usage_limit'] ?? 0 ),
			'countdown_mode'           => (string) ( $settings['countdown_mode'] ?? 'none' ),
			'countdown_end'            => (string) ( $settings['countdown_end'] ?? '' ),
			'countdown_minutes'        => (int) ( $settings['countdown_minutes'] ?? 0 ),
			'ab_testing_enabled'       => (int) ( $settings['ab_testing_enabled'] ?? 0 ),
			'ab_split_percentage'      => (int) ( $settings['ab_split_percentage'] ?? 50 ),
			'ab_auto_winner'           => (int) ( $settings['ab_auto_winner'] ?? 0 ),
			'ab_min_views'             => (int) ( $settings['ab_min_views'] ?? 100 ),
			'ab_variant_b_title'       => (string) ( $settings['ab_variant_b_title'] ?? '' ),
			'ab_variant_b_description' => (string) ( $settings['ab_variant_b_description'] ?? '' ),
			'ab_variant_b_discount_type' => (string) ( $settings['ab_variant_b_discount_type'] ?? '' ),
			'ab_variant_b_discount_value' => (float) ( $settings['ab_variant_b_discount_value'] ?? 0 ),
			'bundle_mode'              => (string) ( $settings['bundle_mode'] ?? 'none' ),
			'bundle_product_ids'       => (string) ( $settings['bundle_product_ids'] ?? '' ),
			'bundle_limit'             => (int) ( $settings['bundle_limit'] ?? 3 ),
		);
	}

	/**
	 * Prepare payload for database operations.
	 */
	private function prepare_db_payload( array $data ): array {
		$device_target = (string) ( $data['device_target'] ?? 'all' );
		if ( ! in_array( $device_target, array( 'all', 'mobile', 'desktop' ), true ) ) {
			$device_target = 'all';
		}

		$countdown_mode = (string) ( $data['countdown_mode'] ?? 'none' );
		if ( ! in_array( $countdown_mode, array( 'none', 'fixed', 'evergreen' ), true ) ) {
			$countdown_mode = 'none';
		}

		$ab_variant_b_discount_type = (string) ( $data['ab_variant_b_discount_type'] ?? '' );
		if ( ! in_array( $ab_variant_b_discount_type, array( 'fixed', 'percent', '' ), true ) ) {
			$ab_variant_b_discount_type = '';
		}

		$bundle_mode = (string) ( $data['bundle_mode'] ?? 'none' );
		if ( ! in_array( $bundle_mode, array( 'none', 'fbt', 'same_category', 'tag_match', 'manual' ), true ) ) {
			$bundle_mode = 'none';
		}

		$rules = array(
			'trigger_product_ids'      => $this->sanitize_int_csv( $data['trigger_product_ids'] ?? '' ),
			'trigger_category_ids'     => $this->sanitize_int_csv( $data['trigger_category_ids'] ?? '' ),
			'min_cart_total'           => (float) wc_format_decimal( $data['min_cart_total'] ?? 0, 2 ),
			'max_cart_total'           => (float) wc_format_decimal( $data['max_cart_total'] ?? 0, 2 ),
			'quantity_threshold'       => absint( $data['quantity_threshold'] ?? 0 ),
			'user_roles'               => $this->sanitize_slug_csv( $data['user_roles'] ?? '' ),
			'customer_email'           => sanitize_email( $data['customer_email'] ?? '' ),
			'lifetime_spend_threshold' => (float) wc_format_decimal( $data['lifetime_spend_threshold'] ?? 0, 2 ),
			'purchase_frequency_min'   => absint( $data['purchase_frequency_min'] ?? 0 ),
			'country_codes'            => strtoupper( $this->sanitize_slug_csv( $data['country_codes'] ?? '' ) ),
			'device_target'            => $device_target,
			'first_time_only'          => empty( $data['first_time_only'] ) ? 0 : 1,
			'returning_only'           => empty( $data['returning_only'] ) ? 0 : 1,
			'purchased_product_ids'    => $this->sanitize_int_csv( $data['purchased_product_ids'] ?? '' ),
			'purchased_category_ids'   => $this->sanitize_int_csv( $data['purchased_category_ids'] ?? '' ),
		);

		$schedule = array(
			'start_date' => sanitize_text_field( $data['schedule_start'] ?? '' ),
			'end_date'   => sanitize_text_field( $data['schedule_end'] ?? '' ),
			'weekdays'   => $this->sanitize_slug_csv( $data['weekdays'] ?? '' ),
			'start_time' => sanitize_text_field( $data['start_time'] ?? '' ),
			'end_time'   => sanitize_text_field( $data['end_time'] ?? '' ),
		);

			$settings = array(
				'skip_if_in_cart'   => empty( $data['skip_if_in_cart'] ) ? 0 : 1,
				'skip_if_purchased' => empty( $data['skip_if_purchased'] ) ? 0 : 1,
				'dismiss_limit'     => absint( $data['dismiss_limit'] ?? 3 ),
				'thank_you_message' => sanitize_textarea_field( $data['thank_you_message'] ?? '' ),
				'skip_label'        => sanitize_text_field( $data['skip_label'] ?? '' ),
				'show_image'        => empty( $data['show_image'] ) ? 0 : 1,
				'auto_charge'       => empty( $data['auto_charge'] ) ? 0 : 1,
			'abandoned_enabled' => empty( $data['abandoned_enabled'] ) ? 0 : 1,
			'exit_intent_enabled' => empty( $data['exit_intent_enabled'] ) ? 0 : 1,
			'abandoned_delay_seconds' => absint( $data['abandoned_delay_seconds'] ?? 0 ),
			'coupon_code' => sanitize_text_field( $data['coupon_code'] ?? '' ),
			'coupon_auto_apply' => empty( $data['coupon_auto_apply'] ) ? 0 : 1,
			'coupon_usage_limit' => absint( $data['coupon_usage_limit'] ?? 0 ),
			'countdown_mode' => $countdown_mode,
			'countdown_end' => sanitize_text_field( $data['countdown_end'] ?? '' ),
			'countdown_minutes' => absint( $data['countdown_minutes'] ?? 0 ),
			'ab_testing_enabled' => empty( $data['ab_testing_enabled'] ) ? 0 : 1,
			'ab_split_percentage' => max( 1, min( 99, absint( $data['ab_split_percentage'] ?? 50 ) ) ),
			'ab_auto_winner' => empty( $data['ab_auto_winner'] ) ? 0 : 1,
			'ab_min_views' => max( 10, absint( $data['ab_min_views'] ?? 100 ) ),
			'ab_variant_b_title' => sanitize_text_field( $data['ab_variant_b_title'] ?? '' ),
			'ab_variant_b_description' => sanitize_textarea_field( $data['ab_variant_b_description'] ?? '' ),
			'ab_variant_b_discount_type' => $ab_variant_b_discount_type,
			'ab_variant_b_discount_value' => (float) wc_format_decimal( $data['ab_variant_b_discount_value'] ?? 0, 2 ),
			'bundle_mode' => $bundle_mode,
			'bundle_product_ids' => $this->sanitize_int_csv( $data['bundle_product_ids'] ?? '' ),
			'bundle_limit' => max( 1, min( 8, absint( $data['bundle_limit'] ?? 3 ) ) ),
		);

		$offer_type = sanitize_key( (string) ( $data['offer_type'] ?? 'checkout' ) );
		$offer_type = in_array( $offer_type, array( 'checkout', 'cart', 'post_purchase' ), true ) ? $offer_type : 'checkout';

		$status = sanitize_key( (string) ( $data['status'] ?? 'active' ) );
		$status = in_array( $status, array( 'active', 'draft' ), true ) ? $status : 'draft';

		$position = sanitize_key( (string) ( $data['position'] ?? 'before_payment' ) );
		$position = in_array( $position, array( 'before_payment', 'after_order_summary' ), true ) ? $position : 'before_payment';

		$discount = sanitize_key( (string) ( $data['discount_type'] ?? 'fixed' ) );
		$discount = in_array( $discount, array( 'fixed', 'percent' ), true ) ? $discount : 'fixed';

		$display = sanitize_key( (string) ( $data['display_type'] ?? 'checkbox' ) );
		$display = in_array( $display, array( 'checkbox', 'highlight', 'inline', 'popup', 'grid' ), true ) ? $display : 'checkbox';

		return array(
			'name'           => sanitize_text_field( $data['name'] ?? '' ),
			'offer_type'     => $offer_type,
			'status'         => $status,
			'priority'       => absint( $data['priority'] ?? 10 ),
			'product_id'     => absint( $data['product_id'] ?? 0 ),
			'discount_type'  => $discount,
			'discount_value' => (float) wc_format_decimal( $data['discount_value'] ?? 0, 2 ),
			'title'          => sanitize_text_field( $data['title'] ?? '' ),
			'description'    => sanitize_textarea_field( $data['description'] ?? '' ),
			'display_type'   => $display,
			'position'       => $position,
			'rules_json'     => wp_json_encode( $rules ),
			'schedule_json'  => wp_json_encode( $schedule ),
			'settings_json'  => wp_json_encode( $settings ),
		);
	}

	/**
	 * Transform old option payload to table payload.
	 */
	private function legacy_to_payload( array $legacy, string $offer_type ): array {
		return array_merge(
			$legacy,
			array(
				'name'       => $legacy['title'] ?? ucfirst( str_replace( '_', ' ', $offer_type ) ) . ' offer',
				'offer_type' => $offer_type,
				'status'     => ! empty( $legacy['enabled'] ) ? 'active' : 'draft',
				'priority'   => 10,
			)
		);
	}

	/**
	 * Sanitize comma-separated integer list.
	 */
	private function sanitize_int_csv( string $value ): string {
		$parts = array_filter( array_map( 'absint', array_map( 'trim', explode( ',', $value ) ) ) );
		return implode( ',', $parts );
	}

	/**
	 * Sanitize comma-separated slug list.
	 */
	private function sanitize_slug_csv( string $value ): string {
		$parts = array_filter( array_map( 'sanitize_key', array_map( 'trim', explode( ',', $value ) ) ) );
		return implode( ',', $parts );
	}
}
