<?php
namespace MD3\WPMCP;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin options, with defaults and validation.
 */
final class Settings {

	const OPT_POST_TYPES     = 'md3_wp_mcp_post_types';
	const OPT_DEFAULT_AUTHOR = 'md3_wp_mcp_default_author';
	const OPT_SECURITY       = 'md3_wp_mcp_security';

	const DEFAULT_RATE_LIMIT = 120;

	/**
	 * Post types the MCP tools may work with, limited to types that are
	 * still registered, public and not attachments.
	 *
	 * @return string[]
	 */
	public static function post_types(): array {
		$stored = get_option( self::OPT_POST_TYPES, [ 'post', 'page' ] );
		if ( ! is_array( $stored ) ) {
			$stored = [ 'post', 'page' ];
		}
		return self::filter_post_types( $stored );
	}

	public static function is_allowed_post_type( string $type ): bool {
		return in_array( $type, self::post_types(), true );
	}

	/**
	 * @param string[] $types Candidate post types.
	 */
	public static function update_post_types( array $types ): void {
		update_option( self::OPT_POST_TYPES, self::filter_post_types( $types ) );
	}

	/**
	 * @return array{mcp_user_id: int, dcr: bool, mcp_rate_limit: int}
	 */
	public static function security(): array {
		$stored = get_option( self::OPT_SECURITY, [] );
		if ( ! is_array( $stored ) ) {
			$stored = [];
		}
		$stored += [
			'mcp_user_id'    => 0,
			'dcr'            => true,
			'mcp_rate_limit' => self::DEFAULT_RATE_LIMIT,
		];

		return [
			'mcp_user_id'    => (int) $stored['mcp_user_id'],
			'dcr'            => (bool) $stored['dcr'],
			'mcp_rate_limit' => self::clamp_rate_limit( $stored['mcp_rate_limit'] ),
		];
	}

	/**
	 * Saves the security settings. A missing `mcp_user_id` or `dcr` keeps the
	 * current value; a missing or invalid `mcp_rate_limit` resets to 120.
	 *
	 * @param array<string, mixed> $values Submitted values.
	 */
	public static function update_security( array $values ): void {
		$current = self::security();

		$user_id = array_key_exists( 'mcp_user_id', $values ) ? (int) $values['mcp_user_id'] : $current['mcp_user_id'];
		if ( ! self::is_valid_mcp_user( $user_id, true ) ) {
			$user_id = 0;
		}

		update_option(
			self::OPT_SECURITY,
			[
				'mcp_user_id'    => $user_id,
				'dcr'            => array_key_exists( 'dcr', $values ) ? (bool) $values['dcr'] : $current['dcr'],
				'mcp_rate_limit' => self::clamp_rate_limit( $values['mcp_rate_limit'] ?? null ),
			]
		);
	}

	/**
	 * The MCP user ID, or 0 when unset, the user is gone, or has manage_options.
	 * Evaluated on every call so a promoted or deleted user stops working at once.
	 */
	public static function mcp_user_id(): int {
		$user_id = self::security()['mcp_user_id'];
		return self::is_valid_mcp_user( $user_id, false ) ? $user_id : 0;
	}

	/**
	 * Author for content created through MCP: the configured user while they
	 * can still edit posts, otherwise the MCP user.
	 */
	public static function default_author_id(): int {
		$user_id = (int) get_option( self::OPT_DEFAULT_AUTHOR, 0 );
		if ( $user_id > 0 && get_userdata( $user_id ) && user_can( $user_id, 'edit_posts' ) ) {
			return $user_id;
		}
		return self::mcp_user_id();
	}

	/**
	 * @param int $user_id User ID, or 0 for "use the MCP user".
	 */
	public static function update_default_author( int $user_id ): void {
		if ( $user_id > 0 && ! ( get_userdata( $user_id ) && user_can( $user_id, 'edit_posts' ) ) ) {
			$user_id = 0;
		}
		update_option( self::OPT_DEFAULT_AUTHOR, $user_id );
	}

	/**
	 * @param string[] $types Candidate post types.
	 * @return string[]
	 */
	private static function filter_post_types( array $types ): array {
		$allowed = [];
		foreach ( $types as $type ) {
			if ( ! is_string( $type ) || 'attachment' === $type ) {
				continue;
			}
			$object = get_post_type_object( $type );
			if ( $object && $object->public ) {
				$allowed[] = $type;
			}
		}
		return array_values( array_unique( $allowed ) );
	}

	/**
	 * @param int  $user_id       User ID.
	 * @param bool $require_role  Also require the Editor role (used when saving).
	 */
	private static function is_valid_mcp_user( int $user_id, bool $require_role ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}
		$user = get_userdata( $user_id );
		if ( ! $user || $user->has_cap( 'manage_options' ) ) {
			return false;
		}
		return ! $require_role || in_array( 'editor', (array) $user->roles, true );
	}

	/**
	 * @param mixed $value Raw rate limit.
	 */
	private static function clamp_rate_limit( $value ): int {
		if ( ! is_numeric( $value ) ) {
			return self::DEFAULT_RATE_LIMIT;
		}
		return max( 1, min( 1000, (int) $value ) );
	}
}
