<?php
/**
 * Lightweight performance benchmark helper.
 *
 * Run with:
 * wp eval-file wp-content/plugins/wbcom-smart-upsell-order-bump/scripts/perf-benchmark.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

function wbcom_suo_run_perf_benchmark(): void {
	$plugin_dir = WP_CONTENT_DIR . '/plugins/wbcom-smart-upsell-order-bump/';
	$assets     = array(
		$plugin_dir . 'assets/css/frontend.css',
		$plugin_dir . 'assets/js/frontend.js',
	);

	$asset_bytes = 0;
	foreach ( $assets as $file ) {
		if ( file_exists( $file ) ) {
			$asset_bytes += (int) filesize( $file );
		}
	}

	$offers = new \Wbcom\SmartUpsell\Modules\Offers\OfferRepository();
	$target = new \Wbcom\SmartUpsell\Modules\Targeting\SmartTargetingEngine();
	$sample = $offers->get_first_active_offer( 'checkout' );

	global $wpdb;
	$query_before = (int) $wpdb->num_queries;
	$start        = microtime( true );

	for ( $i = 0; $i < 200; $i++ ) {
		if ( ! empty( $sample ) ) {
			$target->should_show_offer( $sample );
		}
	}

	$elapsed_ms      = ( microtime( true ) - $start ) * 1000;
	$query_after     = (int) $wpdb->num_queries;
	$queries_used    = max( 0, $query_after - $query_before );
	$avg_eval_ms     = round( $elapsed_ms / 200, 4 );
	$asset_kb        = round( $asset_bytes / 1024, 2 );
	$asset_budget_ok = $asset_bytes <= 51200;
	$eval_budget_ok  = $avg_eval_ms <= 10;

	$report = array(
		'asset_kb'             => $asset_kb,
		'asset_budget_ok'      => $asset_budget_ok,
		'avg_target_eval_ms'   => $avg_eval_ms,
		'eval_budget_ok'       => $eval_budget_ok,
		'queries_during_probe' => $queries_used,
		'sample_offer_found'   => ! empty( $sample ),
	);

	echo wp_json_encode( $report, JSON_PRETTY_PRINT ) . PHP_EOL;
}

wbcom_suo_run_perf_benchmark();
