<?php
/**
 * Removes the plugin's data when it is deleted from the Plugins screen.
 *
 * Drops the six tables, deletes the `md3_wp_mcp_*` options and the
 * `md3_mcp_*` transients, and clears the cleanup cron. User roles are never
 * touched: the plugin does not add any.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

foreach ( [ 'clients', 'auth_codes', 'access_tokens', 'refresh_tokens', 'log', 'uploads' ] as $md3_mcp_table ) {
	$wpdb->query( 'DROP TABLE IF EXISTS `' . $wpdb->prefix . 'md3_mcp_' . $md3_mcp_table . '`' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
}

// The known options and stored transients through the API, so their cache
// entries go too (never the whole object cache).
foreach ( [ 'md3_wp_mcp_post_types', 'md3_wp_mcp_default_author', 'md3_wp_mcp_security', 'md3_wp_mcp_db_version', 'md3_wp_mcp_private_key', 'md3_wp_mcp_encryption_key' ] as $md3_mcp_option ) {
	delete_option( $md3_mcp_option );
}
$md3_mcp_transients = $wpdb->get_col(
	$wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( '_transient_md3_mcp_' ) . '%' )
); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
foreach ( $md3_mcp_transients as $md3_mcp_transient ) {
	delete_transient( substr( $md3_mcp_transient, strlen( '_transient_' ) ) );
}

// Options and transients (with their timeouts) of the current site only;
// on multisite, other sites keep theirs.
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( 'md3_wp_mcp_' ) . '%',
		$wpdb->esc_like( '_transient_md3_mcp_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_md3_mcp_' ) . '%'
	)
); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
// That SQL bypasses the option cache: drop the autoloaded set, not every cache.
wp_cache_delete( 'alloptions', 'options' );

wp_clear_scheduled_hook( 'md3_wp_mcp_cleanup' );
