<?php
/**
 * A/B testing helper for offer variants.
 *
 * @package WbcomSmartUpsellOrderBump
 */

namespace Wbcom\SmartUpsell\Modules\Offers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AbTestingManager {
	/**
	 * Resolve variant key for an offer.
	 */
	public function resolve_variant( array $offer ): string {
		if ( empty( $offer['ab_testing_enabled'] ) || empty( $offer['offer_id'] ) ) {
			return 'a';
		}

		$offer_id = (int) $offer['offer_id'];

		if ( ! empty( $offer['ab_auto_winner'] ) ) {
			$winner = $this->resolve_auto_winner( $offer );
			if ( in_array( $winner, array( 'a', 'b' ), true ) ) {
				return $winner;
			}
		}

		$split = max( 1, min( 99, (int) ( $offer['ab_split_percentage'] ?? 50 ) ) );
		$seed  = $this->get_visitor_seed( $offer_id );
		return ( $seed % 100 ) < $split ? 'a' : 'b';
	}

	/**
	 * Apply variant-specific values to offer payload.
	 */
	public function apply_variant( array $offer, string $variant ): array {
		if ( 'b' !== $variant || empty( $offer['ab_testing_enabled'] ) ) {
			$offer['_ab_variant'] = 'a';
			return $offer;
		}

		$offer['_ab_variant'] = 'b';

		if ( ! empty( $offer['ab_variant_b_title'] ) ) {
			$offer['title'] = (string) $offer['ab_variant_b_title'];
		}
		if ( ! empty( $offer['ab_variant_b_description'] ) ) {
			$offer['description'] = (string) $offer['ab_variant_b_description'];
		}
		if ( ! empty( $offer['ab_variant_b_discount_type'] ) ) {
			$offer['discount_type'] = (string) $offer['ab_variant_b_discount_type'];
		}
		if ( isset( $offer['ab_variant_b_discount_value'] ) && '' !== (string) $offer['ab_variant_b_discount_value'] ) {
			$offer['discount_value'] = (float) $offer['ab_variant_b_discount_value'];
		}

		return $offer;
	}

	/**
	 * Persist lightweight variant stats used by auto winner mode.
	 */
	public function record_variant_event( int $offer_id, string $variant, string $action ): void {
		if ( $offer_id <= 0 || ! in_array( $variant, array( 'a', 'b' ), true ) ) {
			return;
		}

		$key   = 'wbcom_suo_ab_stats_' . $offer_id;
		$stats = get_option( $key, array() );
		$stats = is_array( $stats ) ? $stats : array();

		if ( empty( $stats[ $variant ] ) || ! is_array( $stats[ $variant ] ) ) {
			$stats[ $variant ] = array( 'views' => 0, 'accepts' => 0 );
		}

		if ( 'view' === $action ) {
			$stats[ $variant ]['views'] = (int) $stats[ $variant ]['views'] + 1;
		}
		if ( 'accept' === $action ) {
			$stats[ $variant ]['accepts'] = (int) $stats[ $variant ]['accepts'] + 1;
		}

		update_option( $key, $stats, false );
	}

	/**
	 * Determine auto-winner variant when enough sample exists.
	 */
	private function resolve_auto_winner( array $offer ): string {
		$offer_id  = (int) ( $offer['offer_id'] ?? 0 );
		$min_views = max( 10, (int) ( $offer['ab_min_views'] ?? 100 ) );
		if ( $offer_id <= 0 ) {
			return '';
		}

		$stats = get_option( 'wbcom_suo_ab_stats_' . $offer_id, array() );
		$stats = is_array( $stats ) ? $stats : array();

		$a_views = (int) ( $stats['a']['views'] ?? 0 );
		$b_views = (int) ( $stats['b']['views'] ?? 0 );
		if ( $a_views < $min_views || $b_views < $min_views ) {
			return '';
		}

		$a_accepts = (int) ( $stats['a']['accepts'] ?? 0 );
		$b_accepts = (int) ( $stats['b']['accepts'] ?? 0 );
		$a_rate    = $a_views > 0 ? $a_accepts / $a_views : 0;
		$b_rate    = $b_views > 0 ? $b_accepts / $b_views : 0;

		if ( abs( $a_rate - $b_rate ) < 0.0001 ) {
			return '';
		}

		return $a_rate > $b_rate ? 'a' : 'b';
	}

	/**
	 * Stable visitor seed for split assignment.
	 */
	private function get_visitor_seed( int $offer_id ): int {
		$seed = '';
		if ( is_user_logged_in() ) {
			$seed = 'u:' . get_current_user_id();
		} elseif ( function_exists( 'WC' ) && WC()->session ) {
			$seed = 's:' . (string) WC()->session->get_customer_id();
		} elseif ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$seed = 'ip:' . sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		}

		return abs( crc32( $offer_id . '|' . $seed ) );
	}
}
