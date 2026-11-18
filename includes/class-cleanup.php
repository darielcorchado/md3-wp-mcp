<?php
namespace MD3\WPMCP;

use MD3\WPMCP\OAuth\Tokens;
use MD3\WPMCP\Uploads\Upload_Links;

defined( 'ABSPATH' ) || exit;

/**
 * Daily cleanup of expired OAuth rows, upload links and old audit log rows.
 */
final class Cleanup {

	const LOG_RETENTION_DAYS = 90;

	public static function register(): void {
		add_action( Plugin::CLEANUP_HOOK, [ self::class, 'run' ] );
	}

	public static function run(): void {
		Tokens::prune();
		Upload_Links::prune();
		Audit_Log::prune( self::LOG_RETENTION_DAYS );
	}
}
