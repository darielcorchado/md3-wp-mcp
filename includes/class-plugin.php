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

		OAuth\OAuth::boot();
		Uploads\Upload_Page::register();

		Cleanup::register();
		// Activation hooks don't run on every update path (sync, manual copy).
		add_action( 'admin_init', [ self::class, 'schedule_cleanup' ] );
		Admin\Diagnostics::register();
		if ( is_admin() ) {
			Admin\Settings_Page::register();
			Admin\Profile_Connections::register();
		}
	}

	/**
	 * Creates tables, schedules the daily cleanup and flushes rewrite rules
	 * with the authorize page's rule in them.
	 */
	public static function activate(): void {
		Schema::install();
		self::schedule_cleanup();

		OAuth\Authorize::add_rewrite_rule();
		flush_rewrite_rules();
	}

	/**
	 * Schedules the daily cleanup when it is missing.
	 */
	public static function schedule_cleanup(): void {
		if ( ! wp_next_scheduled( self::CLEANUP_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CLEANUP_HOOK );
		}
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
		return hash( 'sha256', wp_salt( 'auth' ) . self::client_ip() );
	}

	/**
	 * The visitor's IP, for the per-IP rate limits and the log: REMOTE_ADDR.
	 * Behind a reverse proxy or CDN that is the proxy's address for every
	 * visitor, so a site there can return the real one with the
	 * `md3_wp_mcp_client_ip` filter. Forwarded headers are never trusted by
	 * default because any client can send them.
	 */
	public static function client_ip(): string {
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- hashed, never output.

		/**
		 * Filters the client IP used for rate limiting and the log.
		 *
		 * @param string $ip REMOTE_ADDR.
		 */
		$ip = apply_filters( 'md3_wp_mcp_client_ip', $remote );

		return is_string( $ip ) && false !== filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : $remote;
	}
}
