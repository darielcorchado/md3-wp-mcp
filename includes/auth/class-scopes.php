<?php
namespace MD3\WPMCP\Auth;

defined( 'ABSPATH' ) || exit;

/**
 * OAuth scope catalog and normalization.
 */
final class Scopes {

	const READ    = 'content:read';
	const WRITE   = 'content:write';
	const PUBLISH = 'content:publish';
	const DELETE  = 'content:delete';

	/**
	 * @return string[] Scopes in canonical order.
	 */
	public static function all(): array {
		return [ self::READ, self::WRITE, self::PUBLISH, self::DELETE ];
	}

	/**
	 * @return array<string,string> Scope => consent-screen description.
	 */
	public static function descriptions(): array {
		return [
			self::READ    => __( 'Read posts and pages', 'md3-wp-mcp' ),
			self::WRITE   => __( 'Create and edit drafts', 'md3-wp-mcp' ),
			self::PUBLISH => __( 'Publish and schedule content', 'md3-wp-mcp' ),
			self::DELETE  => __( 'Move content to the trash', 'md3-wp-mcp' ),
		];
	}

	/**
	 * Turns a space-separated scope request into the granted scope list.
	 *
	 * @return string[]|null Null when any scope is unknown.
	 */
	public static function normalize( string $requested ): ?array {
		$requested = trim( $requested );
		if ( '' === $requested ) {
			return [ self::READ, self::WRITE ];
		}

		$asked = preg_split( '/\s+/', $requested );
		if ( array_diff( $asked, self::all() ) ) {
			return null;
		}

		$asked[] = self::READ;
		return array_values( array_intersect( self::all(), $asked ) );
	}
}
