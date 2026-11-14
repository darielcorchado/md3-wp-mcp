<?php
namespace MD3\WPMCP\Auth;

defined( 'ABSPATH' ) || exit;

/**
 * Holds the identity of the request being served.
 */
final class Request_Context {

	private static ?Identity $identity = null;

	public static function set( Identity $identity ): void {
		self::$identity = $identity;
	}

	public static function get(): ?Identity {
		return self::$identity;
	}

	public static function clear(): void {
		self::$identity = null;
	}

	public static function has_scope( string $scope ): bool {
		return null !== self::$identity && in_array( $scope, self::$identity->scopes, true );
	}
}
