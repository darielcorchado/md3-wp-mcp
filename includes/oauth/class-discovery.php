<?php
namespace MD3\WPMCP\OAuth;

use MD3\WPMCP\Auth\Scopes;
use MD3\WPMCP\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The well-known metadata documents (RFC 9728 and RFC 8414), served early on
 * `parse_request` before any theme output (design §4.2).
 */
final class Discovery {

	const PROTECTED_RESOURCE   = 'protected_resource';
	const AUTHORIZATION_SERVER = 'authorization_server';

	/**
	 * RFC 9728 protected resource metadata for the MCP endpoint.
	 *
	 * @return array<string,mixed>
	 */
	public static function protected_resource_metadata(): array {
		return [
			'resource'                 => OAuth::resource_url(),
			'authorization_servers'    => [ OAuth::issuer() ],
			'scopes_supported'         => Scopes::all(),
			'bearer_methods_supported' => [ 'header' ],
			'resource_name'            => 'MD3 WP MCP',
		];
	}

	/**
	 * RFC 8414 authorization server metadata. It advertises only what the
	 * server supports; the registration endpoint only while DCR is on.
	 *
	 * @return array<string,mixed>
	 */
	public static function authorization_server_metadata(): array {
		$metadata = [
			'issuer'                                => OAuth::issuer(),
			'authorization_endpoint'                => OAuth::authorize_url(),
			'token_endpoint'                        => OAuth::endpoint_url( 'token' ),
			'registration_endpoint'                 => OAuth::endpoint_url( 'register' ),
			'revocation_endpoint'                   => OAuth::endpoint_url( 'revoke' ),
			'scopes_supported'                      => Scopes::all(),
			'response_types_supported'              => [ 'code' ],
			'response_modes_supported'              => [ 'query' ],
			'grant_types_supported'                 => [ 'authorization_code', 'refresh_token' ],
			'code_challenge_methods_supported'      => [ 'S256' ],
			'token_endpoint_auth_methods_supported' => [ 'none' ],
			'revocation_endpoint_auth_methods_supported' => [ 'none' ],
			'client_id_metadata_document_supported' => true,
		];

		if ( ! Settings::security()['dcr'] ) {
			unset( $metadata['registration_endpoint'] );
		}

		return $metadata;
	}

	/**
	 * Request paths (with the home path) served at `parse_request` priority 0,
	 * mapped to the document each one returns.
	 *
	 * @return array<string,string>
	 */
	public static function paths(): array {
		$home = self::home_path();

		return [
			$home . '/.well-known/oauth-protected-resource' . self::relative_path( OAuth::resource_url() ) => self::PROTECTED_RESOURCE,
			$home . '/.well-known/oauth-authorization-server' . self::relative_path( OAuth::issuer() ) => self::AUTHORIZATION_SERVER,
			$home . '/.well-known/openid-configuration' . self::relative_path( OAuth::issuer() ) => self::AUTHORIZATION_SERVER,
		];
	}

	/**
	 * URL of the protected resource metadata for the MCP endpoint (the
	 * `resource_metadata` of a `WWW-Authenticate` challenge).
	 */
	public static function protected_resource_url(): string {
		return home_url( '/.well-known/oauth-protected-resource' . self::relative_path( OAuth::resource_url() ) );
	}

	/**
	 * The bare protected resource path, served only if nothing else answered
	 * it first (another plugin may own it).
	 */
	public static function bare_path(): string {
		return self::home_path() . '/.well-known/oauth-protected-resource';
	}

	/**
	 * `parse_request` priority 0: serves one of paths() and exits.
	 */
	public static function maybe_serve(): void {
		self::send( self::response( self::request_method(), self::request_path() ) );
	}

	/**
	 * `parse_request` priority 99: serves the bare protected resource path.
	 */
	public static function maybe_serve_bare(): void {
		$path = self::request_path();
		if ( untrailingslashit( $path ) === self::bare_path() ) {
			self::send( self::response( self::request_method(), $path, true ) );
		}
	}

	/**
	 * The response for a request path, or null when the path isn't ours.
	 *
	 * @param bool $bare Also answer the bare protected resource path.
	 */
	public static function response( string $method, string $path, bool $bare = false ): ?\WP_HTTP_Response {
		$path  = untrailingslashit( $path );
		$paths = self::paths();
		if ( $bare ) {
			$paths[ self::bare_path() ] = self::PROTECTED_RESOURCE;
		}
		if ( '' === $path || ! isset( $paths[ $path ] ) ) {
			return null;
		}

		$headers = [
			'Cache-Control'                => 'no-store',
			'Access-Control-Allow-Origin'  => '*',
			'Access-Control-Allow-Methods' => 'GET, OPTIONS',
			'Access-Control-Allow-Headers' => '*',
			'X-Content-Type-Options'       => 'nosniff',
		];

		$method = strtoupper( $method );
		if ( 'OPTIONS' === $method ) {
			return new \WP_HTTP_Response( '', 204, $headers );
		}
		if ( 'GET' !== $method && 'HEAD' !== $method ) {
			return new \WP_HTTP_Response( '', 405, $headers + [ 'Allow' => 'GET, HEAD, OPTIONS' ] );
		}

		$document = self::PROTECTED_RESOURCE === $paths[ $path ] ? self::protected_resource_metadata() : self::authorization_server_metadata();

		return new \WP_HTTP_Response(
			(string) wp_json_encode( $document, JSON_UNESCAPED_SLASHES ),
			200,
			$headers + [ 'Content-Type' => 'application/json; charset=utf-8' ]
		);
	}

	/**
	 * Sends a response and ends the request; does nothing for null.
	 */
	private static function send( ?\WP_HTTP_Response $response ): void {
		if ( null === $response ) {
			return;
		}

		status_header( $response->get_status() );
		foreach ( $response->get_headers() as $name => $value ) {
			header( $name . ': ' . $value );
		}
		if ( 'HEAD' !== self::request_method() ) {
			echo $response->get_data(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON document.
		}
		exit;
	}

	/**
	 * Path of a URL relative to the home URL, without a trailing slash.
	 */
	private static function relative_path( string $url ): string {
		$path = untrailingslashit( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		$home = self::home_path();
		if ( '' !== $home && str_starts_with( $path, $home . '/' ) ) {
			$path = substr( $path, strlen( $home ) );
		}
		return $path;
	}

	private static function home_path(): string {
		return untrailingslashit( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ) );
	}

	private static function request_path(): string {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared only.
		return (string) wp_parse_url( (string) $uri, PHP_URL_PATH );
	}

	private static function request_method(): string {
		return isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
	}
}
