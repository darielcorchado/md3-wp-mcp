<?php
namespace MD3\WPMCP;

defined( 'ABSPATH' ) || exit;

/**
 * Database tables. All DATETIME values are stored in UTC.
 */
final class Schema {

	const DB_VERSION = '1';

	/**
	 * Full table name for a short name (clients, auth_codes, ...).
	 */
	public static function table( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'md3_mcp_' . $name;
	}

	/**
	 * Creates or updates every table and records the schema version.
	 */
	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();

		$clients = self::table( 'clients' );
		$codes   = self::table( 'auth_codes' );
		$access  = self::table( 'access_tokens' );
		$refresh = self::table( 'refresh_tokens' );
		$log     = self::table( 'log' );

		$sql = [];

		$sql[] = "CREATE TABLE {$clients} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  client_id VARCHAR(191) NOT NULL,
  name VARCHAR(191) NOT NULL DEFAULT '',
  redirect_uris TEXT NOT NULL,
  type VARCHAR(8) NOT NULL DEFAULT 'dcr',
  created_ip_hash CHAR(64) NOT NULL DEFAULT '',
  completed TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  last_used_at DATETIME NULL DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY client_id (client_id)
) {$charset};";

		$sql[] = "CREATE TABLE {$codes} (
  id_hash CHAR(64) NOT NULL,
  client_id VARCHAR(191) NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  expires_at DATETIME NOT NULL,
  revoked TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id_hash)
) {$charset};";

		$sql[] = "CREATE TABLE {$access} (
  id_hash CHAR(64) NOT NULL,
  client_id VARCHAR(191) NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  scopes VARCHAR(191) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL,
  expires_at DATETIME NOT NULL,
  last_used_at DATETIME NULL DEFAULT NULL,
  revoked TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id_hash),
  KEY user_id (user_id),
  KEY expires_at (expires_at)
) {$charset};";

		$sql[] = "CREATE TABLE {$refresh} (
  id_hash CHAR(64) NOT NULL,
  access_token_hash CHAR(64) NOT NULL,
  client_id VARCHAR(191) NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  expires_at DATETIME NOT NULL,
  revoked TINYINT(1) NOT NULL DEFAULT 0,
  rotated_at DATETIME NULL DEFAULT NULL,
  PRIMARY KEY  (id_hash)
) {$charset};";

		$sql[] = "CREATE TABLE {$log} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  created_at DATETIME NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  client_id VARCHAR(191) NOT NULL DEFAULT '',
  tool VARCHAR(64) NOT NULL DEFAULT '',
  object_type VARCHAR(32) NOT NULL DEFAULT '',
  object_id BIGINT UNSIGNED NULL DEFAULT NULL,
  result VARCHAR(8) NOT NULL DEFAULT '',
  message VARCHAR(255) NOT NULL DEFAULT '',
  ip_hash CHAR(64) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  KEY created_at (created_at)
) {$charset};";

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}

		update_option( 'md3_wp_mcp_db_version', self::DB_VERSION );
	}
}
