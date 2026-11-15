<?php
namespace MD3\WPMCP\Admin;

use MD3\WPMCP\OAuth\Discovery;
use MD3\WPMCP\OAuth\OAuth;

defined( 'ABSPATH' ) || exit;

/**
 * Loopback checks that the site's web server lets the OAuth flow through
 * (design §8): no page cache, no redirects, and the Authorization header
 * reaching PHP.
 */
final class Diagnostics {

	const TRANSIENT = 'md3_mcp_diag';
	const ROUTE     = '/diagnostics/auth-echo';

	public static function register(): void {
		add_action( 'rest_api_init', [ self::class, 'register_routes' ] );
	}

	public static function register_routes(): void {
		register_rest_route(
			OAuth::REST_NAMESPACE,
			self::ROUTE,
			[
				'methods'             => 'GET',
				'callback'            => static fn () => OAuth::json( [ 'received' => true ] ),
				'permission_callback' => [ self::class, 'permission' ],
			]
		);
	}

	/**
	 * True only when the request carries `Bearer <nonce>` for the nonce `run()`
	 * stored in the last 60 seconds. Nothing else is revealed.
	 */
	public static function permission( \WP_REST_Request $request ): bool {
		$nonce = get_transient( self::TRANSIENT );
		$given = (string) $request->get_header( 'authorization' );
		if ( ! is_string( $nonce ) || '' === $nonce || ! str_starts_with( $given, 'Bearer ' ) ) {
			return false;
		}
		return hash_equals( $nonce, substr( $given, 7 ) );
	}

	/**
	 * @return array<array{check: string, ok: bool, detail: string}>
	 */
	public static function run(): array {
		$nonce = wp_generate_password( 32, false );
		set_transient( self::TRANSIENT, $nonce, MINUTE_IN_SECONDS );

		$rows = [
			self::check_protected_resource(),
			self::check_authorization_server(),
			self::check_unauthenticated_mcp(),
			self::check_authorization_header( $nonce ),
			self::check_authorize_page(),
		];

		delete_transient( self::TRANSIENT );
		return $rows;
	}

	private static function check_protected_resource(): array {
		$check    = __( 'Protected resource metadata is served and not cached', 'md3-wp-mcp' );
		$response = self::request( 'GET', Discovery::protected_resource_url() );
		if ( is_string( $response ) ) {
			return self::row( $check, false, $response );
		}

		$code = self::status( $response );
		$type = (string) wp_remote_retrieve_header( $response, 'content-type' );
		$ctrl = (string) wp_remote_retrieve_header( $response, 'cache-control' );
		$ok   = 200 === $code && str_contains( $type, 'json' ) && str_contains( $ctrl, 'no-store' );

		/* translators: 1: HTTP status, 2: Content-Type, 3: Cache-Control. */
		return self::row( $check, $ok, sprintf( __( 'HTTP %1$d, Content-Type "%2$s", Cache-Control "%3$s".', 'md3-wp-mcp' ), $code, $type, $ctrl ) );
	}

	private static function check_authorization_server(): array {
		$check = __( 'Authorization server metadata is served', 'md3-wp-mcp' );
		$path  = (string) array_search( Discovery::AUTHORIZATION_SERVER, Discovery::paths(), true );
		$url   = self::origin() . $path;

		$response = self::request( 'GET', $url );
		if ( is_string( $response ) ) {
			return self::row( $check, false, $response );
		}
		$code = self::status( $response );

		/* translators: %d: HTTP status. */
		return self::row( $check, 200 === $code, sprintf( __( 'HTTP %d.', 'md3-wp-mcp' ), $code ) );
	}

	private static function check_unauthenticated_mcp(): array {
		$check    = __( 'The MCP endpoint refuses requests without a token', 'md3-wp-mcp' );
		$response = self::request(
			'POST',
			OAuth::resource_url(),
			[
				'headers' => [ 'Content-Type' => 'application/json' ],
				'body'    => '{"jsonrpc":"2.0","id":1,"method":"ping"}',
			]
		);
		if ( is_string( $response ) ) {
			return self::row( $check, false, $response );
		}

		$code      = self::status( $response );
		$challenge = (string) wp_remote_retrieve_header( $response, 'www-authenticate' );
		$ok        = 401 === $code && '' !== $challenge;

		/* translators: 1: HTTP status, 2: whether a WWW-Authenticate header was sent (yes/no). */
		return self::row( $check, $ok, sprintf( __( 'HTTP %1$d, WWW-Authenticate header: %2$s.', 'md3-wp-mcp' ), $code, '' !== $challenge ? __( 'yes', 'md3-wp-mcp' ) : __( 'no', 'md3-wp-mcp' ) ) );
	}

	private static function check_authorization_header( string $nonce ): array {
		$check    = __( 'The Authorization header reaches PHP', 'md3-wp-mcp' );
		$response = self::request(
			'GET',
			rest_url( OAuth::REST_NAMESPACE . self::ROUTE ),
			[ 'headers' => [ 'Authorization' => 'Bearer ' . $nonce ] ]
		);
		if ( is_string( $response ) ) {
			return self::row( $check, false, $response );
		}

		$code = self::status( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$ok   = 200 === $code && is_array( $body ) && true === ( $body['received'] ?? false );

		return self::row(
			$check,
			$ok,
			$ok
				? __( 'Received.', 'md3-wp-mcp' )
				/* translators: %d: HTTP status. */
				: sprintf( __( 'Not received (HTTP %d). The web server strips the Authorization header; pass it to PHP.', 'md3-wp-mcp' ), $code )
		);
	}

	private static function check_authorize_page(): array {
		$check    = __( 'The authorize page is reachable and not cached or redirected', 'md3-wp-mcp' );
		$response = self::request( 'GET', OAuth::authorize_url() );
		if ( is_string( $response ) ) {
			return self::row( $check, false, $response );
		}
		$code = self::status( $response );

		/* translators: %d: HTTP status. */
		return self::row( $check, 400 === $code, sprintf( __( 'HTTP %d without parameters (400 expected).', 'md3-wp-mcp' ), $code ) );
	}

	/**
	 * A loopback request that follows no redirects.
	 *
	 * @param array<string,mixed> $args wp_remote_request() arguments.
	 * @return array<string,mixed>|string The response, or an error message.
	 */
	private static function request( string $method, string $url, array $args = [] ) {
		$response = wp_remote_request(
			$url,
			$args + [
				'method'      => $method,
				'timeout'     => 10,
				'redirection' => 0,
				'sslverify'   => apply_filters( 'https_local_ssl_verify', false ),
			]
		);
		return is_wp_error( $response ) ? $response->get_error_message() : $response;
	}

	/**
	 * @param array<string,mixed> $response Response.
	 */
	private static function status( array $response ): int {
		return (int) wp_remote_retrieve_response_code( $response );
	}

	/**
	 * Scheme and host of the home URL, to prefix a path that already contains the home path.
	 */
	private static function origin(): string {
		$parts = wp_parse_url( home_url( '/' ) );
		return $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
	}

	/**
	 * @return array{check: string, ok: bool, detail: string}
	 */
	private static function row( string $check, bool $ok, string $detail ): array {
		return [
			'check'  => $check,
			'ok'     => $ok,
			'detail' => $detail,
		];
	}
}
