<?php
/**
 * Removes the plugin's data when it is deleted from the Plugins screen.
 *
 * Drops the five tables, deletes the `md3_wp_mcp_*` options and the
 * `md3_mcp_*` transients, and clears the cleanup cron. User roles are never
 * touched: the plugin does not add any.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

foreach ( [ 'clients', 'auth_codes', 'access_tokens', 'refresh_tokens', 'log' ] as $md3_mcp_table ) {
	$wpdb->query( 'DROP TABLE IF EXISTS `' . $wpdb->prefix . 'md3_mcp_' . $md3_mcp_table . '`' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
}

// Options and transients (with their timeouts), also on multisite's per-site tables.
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( 'md3_wp_mcp_' ) . '%',
		$wpdb->esc_like( '_transient_md3_mcp_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_md3_mcp_' ) . '%'
	)
); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

wp_clear_scheduled_hook( 'md3_wp_mcp_cleanup' );
wp_cache_flush();
