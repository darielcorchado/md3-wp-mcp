<?php
namespace MD3\WPMCP\OAuth;

defined( 'ABSPATH' ) || exit;

/**
 * Which redirect URIs a client may register (design §4.3).
 */
final class Redirect_Uri_Policy {

	const LOOPBACK_HOSTS = [ 'localhost', '127.0.0.1', '[::1]' ];

	/**
	 * - `https` on a public host;
	 * - `http` only on a loopback host (any port, RFC 8252);
	 * - a custom app scheme from the `md3_wp_mcp_redirect_schemes` allowlist.
	 *
	 * Fragments, wildcards, credentials and whitespace are refused.
	 */
	public static function allowed( string $uri ): bool {
		if ( '' === $uri || strlen( $uri ) > 2048 ) {
			return false;
		}
		if ( preg_match( '/[\s\x00-\x1f\x7f*#\\\\]/', $uri ) ) {
			return false;
		}

		$parts = wp_parse_url( $uri );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) ) {
			return false;
		}
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return false;
		}

		$scheme = strtolower( $parts['scheme'] );
		$host   = strtolower( $parts['host'] ?? '' );

		if ( 'https' === $scheme ) {
			return self::is_public_host( $host );
		}
		if ( 'http' === $scheme ) {
			return in_array( $host, self::LOOPBACK_HOSTS, true );
		}

		$schemes = array_map( 'strtolower', (array) apply_filters( 'md3_wp_mcp_redirect_schemes', [ 'claude', 'cursor', 'chatgpt', 'vscode' ] ) );
		return in_array( $scheme, $schemes, true );
	}

	/**
	 * Whether a requested redirect URI matches one of the client's registered
	 * URIs: exactly, or, for an `http` loopback URI, with any port (RFC 8252
	 * §7.3). Scheme, host, path and query must still match.
	 *
	 * @param string[] $registered Registered redirect URIs.
	 */
	public static function matches( string $uri, array $registered ): bool {
		if ( in_array( $uri, $registered, true ) ) {
			return true;
		}

		$loopback = self::without_port( $uri );
		if ( null === $loopback ) {
			return false;
		}

		foreach ( $registered as $candidate ) {
			if ( is_string( $candidate ) && self::without_port( $candidate ) === $loopback ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * An `http` loopback URI without its port, or null for any other URI.
	 */
	private static function without_port( string $uri ): ?string {
		if ( ! self::allowed( $uri ) ) {
			return null;
		}

		$parts = wp_parse_url( $uri );
		if ( 'http' !== strtolower( $parts['scheme'] ?? '' ) || ! in_array( strtolower( $parts['host'] ?? '' ), self::LOOPBACK_HOSTS, true ) ) {
			return null;
		}

		return 'http://' . strtolower( $parts['host'] ) . ( $parts['path'] ?? '' ) . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );
	}

	/**
	 * A host name with a dot that isn't a local name, or a public IP literal.
	 * No DNS lookup: the redirect happens in the user's browser.
	 */
	private static function is_public_host( string $host ): bool {
		if ( '' === $host ) {
			return false;
		}

		$ip = trim( $host, '[]' );
		if ( false !== filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
		}

		if ( ! str_contains( $host, '.' ) || ! preg_match( '/^[a-z0-9.-]+$/', $host ) ) {
			return false;
		}

		foreach ( [ '.localhost', '.local', '.internal', '.localdomain' ] as $suffix ) {
			if ( str_ends_with( $host, $suffix ) ) {
				return false;
			}
		}

		return true;
	}
}
