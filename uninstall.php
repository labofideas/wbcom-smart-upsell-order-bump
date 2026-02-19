<?php
/**
 * Uninstall handler.
 *
 * @package WbcomSmartUpsellOrderBump
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$wbcom_suo_analytics_table = esc_sql( $wpdb->prefix . 'wbcom_suo_analytics' );
$wbcom_suo_offers_table    = esc_sql( $wpdb->prefix . 'wbcom_suo_offers' );

$wpdb->query( "DROP TABLE IF EXISTS {$wbcom_suo_analytics_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query( "DROP TABLE IF EXISTS {$wbcom_suo_offers_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange

delete_option( 'wbcom_suo_settings' );
delete_option( 'wbcom_suo_db_version' );
