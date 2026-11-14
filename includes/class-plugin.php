<?php
namespace MD3\WPMCP;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin lifecycle: boot, activation and deactivation.
 */
final class Plugin {

	const CLEANUP_HOOK = 'md3_wp_mcp_cleanup';

	/**
	 * Runs on plugins_loaded once requirements are met.
	 */
	public static function boot(): void {
		if ( get_option( 'md3_wp_mcp_db_version' ) !== Schema::DB_VERSION ) {
			Schema::install();
		}

		Abilities\Registry::register();

		Mcp\Server::hook();
		\WP\MCP\Core\McpAdapter::instance();
	}

	/**
	 * Creates tables, schedules the daily cleanup and flushes rewrite rules.
	 */
	public static function activate(): void {
		Schema::install();

		if ( ! wp_next_scheduled( self::CLEANUP_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CLEANUP_HOOK );
		}

		flush_rewrite_rules();
	}

	/**
	 * Unschedules the cleanup and flushes rewrite rules.
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::CLEANUP_HOOK );
		flush_rewrite_rules();
	}

	/**
	 * Salted hash of the client IP, for rate limiting and the audit log.
	 */
	public static function client_ip_hash(): string {
		return hash( 'sha256', wp_salt( 'auth' ) . ( $_SERVER['REMOTE_ADDR'] ?? '' ) );
	}
}
