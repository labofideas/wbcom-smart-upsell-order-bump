<?php
/**
 * Analytics repository.
 *
 * @package WbcomSmartUpsellOrderBump
 */

namespace Wbcom\SmartUpsell\Modules\Analytics;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AnalyticsRepository {
	/**
	 * Save analytics event.
	 *
	 * @param string $offer_type Offer type.
	 * @param int    $offer_id Offer ID.
	 * @param string $action Action type.
	 * @param array  $context Context data.
	 */
	public function track( string $offer_type, int $offer_id, string $action, array $context = array() ): void {
		$settings = get_option( 'wbcom_suo_settings', array() );
		if ( empty( $settings['enable_analytics'] ) || ! empty( $settings['disable_reports'] ) ) {
			return;
		}

		global $wpdb;

		$table = $wpdb->prefix . 'wbcom_suo_analytics';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$table,
			array(
				'offer_type' => sanitize_key( $offer_type ),
				'offer_id'   => $offer_id,
				'action_type'=> sanitize_key( $action ),
				'order_id'   => isset( $context['order_id'] ) ? absint( $context['order_id'] ) : null,
				'user_id'    => get_current_user_id() ?: null,
				'revenue'    => isset( $context['revenue'] ) ? (float) $context['revenue'] : 0,
				'context'    => isset( $context['context'] ) ? sanitize_key( $context['context'] ) : '',
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%s', '%d', '%s', '%d', '%d', '%f', '%s', '%s' )
		);
	}

	/**
	 * Dashboard summary.
	 */
	public function get_summary( array $filters = array() ): array {
		global $wpdb;
		$table     = esc_sql( $wpdb->prefix . 'wbcom_suo_analytics' );
		$where     = $this->build_where_clause( $filters );
		$sql_where = ! empty( $where['sql'] ) ? ' AND ' . $where['sql'] : '';
		$cache_key = 'summary_' . md5( wp_json_encode( $filters ) );
		$cached    = wp_cache_get( $cache_key, 'wbcom_suo_analytics' );

		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		$views       = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,PluginCheck.Security.DirectDB.UnescapedDBParameter
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE action_type = %s{$sql_where}", array_merge( array( 'view' ), $where['params'] ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
		);
		$conversions = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,PluginCheck.Security.DirectDB.UnescapedDBParameter
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE action_type = %s{$sql_where}", array_merge( array( 'accept' ), $where['params'] ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
		);
		$revenue     = (float) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,PluginCheck.Security.DirectDB.UnescapedDBParameter
			$wpdb->prepare( "SELECT SUM(revenue) FROM {$table} WHERE action_type = %s{$sql_where}", array_merge( array( 'accept' ), $where['params'] ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
		);

		$summary = array(
			'views'           => $views,
			'conversions'     => $conversions,
			'conversion_rate' => $views > 0 ? round( ( $conversions / $views ) * 100, 2 ) : 0,
			'revenue'         => $revenue,
		);

		wp_cache_set( $cache_key, $summary, 'wbcom_suo_analytics', 300 );

		return $summary;
	}

	/**
	 * Top offer analytics.
	 *
	 * @param int $limit Limit.
	 */
	public function get_top_offers( int $limit = 10, array $filters = array() ): array {
		global $wpdb;
		$table = esc_sql( $wpdb->prefix . 'wbcom_suo_analytics' );
		$where = $this->build_where_clause( $filters );
		$sql_where = ! empty( $where['sql'] ) ? 'WHERE ' . $where['sql'] : '';
		$limit = max( 1, absint( $limit ) );
		$cache_key = 'top_offers_' . md5( $limit . '|' . wp_json_encode( $filters ) );
		$cached    = wp_cache_get( $cache_key, 'wbcom_suo_analytics' );

		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,PluginCheck.Security.DirectDB.UnescapedDBParameter
			$wpdb->prepare(
				"SELECT offer_type, offer_id,
				SUM(CASE WHEN action_type='view' THEN 1 ELSE 0 END) AS views,
				SUM(CASE WHEN action_type='accept' THEN 1 ELSE 0 END) AS conversions,
				SUM(CASE WHEN action_type='accept' THEN revenue ELSE 0 END) AS revenue
				FROM {$table}
				{$sql_where}
				GROUP BY offer_type, offer_id
				ORDER BY revenue DESC, conversions DESC
				LIMIT %d",
				array_merge( $where['params'], array( $limit ) )
				) // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
			);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = is_array( $rows ) ? $rows : array();
		wp_cache_set( $cache_key, $rows, 'wbcom_suo_analytics', 300 );

		return $rows;
	}

	/**
	 * Build WHERE clause from analytics filters.
	 */
	private function build_where_clause( array $filters ): array {
		$parts = array();
		$params = array();

		if ( ! empty( $filters['offer_type'] ) ) {
			$parts[]  = 'offer_type = %s';
			$params[] = sanitize_key( (string) $filters['offer_type'] );
		}

		if ( ! empty( $filters['offer_id'] ) ) {
			$parts[]  = 'offer_id = %d';
			$params[] = absint( $filters['offer_id'] );
		}

		if ( ! empty( $filters['product_id'] ) ) {
			$offer_ids = $this->get_offer_ids_for_product( absint( $filters['product_id'] ) );
			if ( empty( $offer_ids ) ) {
				$parts[] = '1=0';
			} else {
				$placeholders = implode( ',', array_fill( 0, count( $offer_ids ), '%d' ) );
				$parts[]      = "offer_id IN ({$placeholders})";
				$params       = array_merge( $params, array_map( 'absint', $offer_ids ) );
			}
		}

		if ( ! empty( $filters['start_date'] ) ) {
			$parts[]  = 'DATE(created_at) >= %s';
			$params[] = sanitize_text_field( (string) $filters['start_date'] );
		}

		if ( ! empty( $filters['end_date'] ) ) {
			$parts[]  = 'DATE(created_at) <= %s';
			$params[] = sanitize_text_field( (string) $filters['end_date'] );
		}

		return array(
			'sql'    => implode( ' AND ', $parts ),
			'params' => $params,
		);
	}

	/**
	 * Resolve offer IDs that map to a product.
	 *
	 * @return int[]
	 */
	private function get_offer_ids_for_product( int $product_id ): array {
		global $wpdb;

		if ( $product_id <= 0 ) {
			return array();
		}

		$table = esc_sql( $wpdb->prefix . 'wbcom_suo_offers' );
		$cache_key = 'offer_ids_for_product_' . $product_id;
		$cached    = wp_cache_get( $cache_key, 'wbcom_suo_analytics' );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
		$rows  = $wpdb->get_col(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT id FROM {$table} WHERE product_id = %d",
				$product_id
			)
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$ids = array_values( array_filter( array_map( 'absint', $rows ) ) );
		wp_cache_set( $cache_key, $ids, 'wbcom_suo_analytics', 300 );

		return $ids;
	}
}
