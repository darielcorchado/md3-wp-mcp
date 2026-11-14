<?php
namespace MD3\WPMCP\OAuth;

defined( 'ABSPATH' ) || exit;

/**
 * OAuth 2.1 URLs, the transport requirement and boot wiring.
 */
final class OAuth {

	/**
	 * The protected resource (the MCP endpoint); every access token's audience.
	 */
	public static function resource_url(): string {
		return rest_url( 'md3-wp-mcp/mcp' );
	}

	/**
	 * Authorization server issuer. It has its own path so its metadata lives at
	 * the path-insert well-known URL.
	 */
	public static function issuer(): string {
		return home_url( '/md3-mcp' );
	}

	public static function authorize_url(): string {
		return home_url( '/md3-mcp/authorize' );
	}

	/**
	 * OAuth requires HTTPS, except on local development sites.
	 */
	public static function transport_allowed(): bool {
		return is_ssl() || 'local' === wp_get_environment_type();
	}

	/**
	 * Wires the OAuth endpoints and the bearer guard (Tasks 9–10) when the
	 * transport is allowed.
	 */
	public static function boot(): void {
		if ( ! self::transport_allowed() ) {
			return;
		}
	}
}
