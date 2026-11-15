<?php
namespace MD3\WPMCP\Auth;

defined( 'ABSPATH' ) || exit;

/**
 * Holds the identity of the request being served.
 */
final class Request_Context {

	private static ?Identity $identity = null;

	/** Scope a tool call of this request was refused for, if any. */
	private static ?string $denied_scope = null;

	public static function set( Identity $identity ): void {
		self::$identity = $identity;
	}

	public static function get(): ?Identity {
		return self::$identity;
	}

	public static function clear(): void {
		self::$identity     = null;
		self::$denied_scope = null;
	}

	public static function has_scope( string $scope ): bool {
		return null !== self::$identity && in_array( $scope, self::$identity->scopes, true );
	}

	/**
	 * Records that a tool was refused because the token lacks a scope, so the
	 * response can carry an `insufficient_scope` challenge.
	 */
	public static function deny_scope( string $scope ): void {
		self::$denied_scope = $scope;
	}

	public static function denied_scope(): ?string {
		return self::$denied_scope;
	}
}
