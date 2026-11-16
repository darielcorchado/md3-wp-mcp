<?php
namespace MD3\WPMCP\OAuth;

defined( 'ABSPATH' ) || exit;

/**
 * OAuth 2.1 URLs, the transport requirement and boot wiring.
 */
final class OAuth {

	/** REST namespace of the registration, token and revocation endpoints. */
	const REST_NAMESPACE = 'md3-wp-mcp/v1';

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
	 * URL of one of the OAuth REST endpoints (`register`, `token`, `revoke`).
	 */
	public static function endpoint_url( string $endpoint ): string {
		return rest_url( self::REST_NAMESPACE . '/oauth/' . $endpoint );
	}

	/**
	 * OAuth requires HTTPS, except on local development sites.
	 */
	public static function transport_allowed(): bool {
		return is_ssl() || 'local' === wp_get_environment_type();
	}

	/**
	 * Wires the OAuth endpoints when the transport is allowed.
	 *
	 * The authorize rewrite rule is registered on every request, so a rewrite
	 * flush never drops it; its handler checks the transport itself.
	 */
	public static function boot(): void {
		Authorize::register();

		if ( ! self::transport_allowed() ) {
			return;
		}

		add_action( 'parse_request', [ Discovery::class, 'maybe_serve' ], 0 );
		add_action( 'parse_request', [ Discovery::class, 'maybe_serve_bare' ], 99 );
		add_action( 'rest_api_init', [ self::class, 'register_routes' ] );
		Bearer_Guard::register();
		add_filter( 'rest_pre_serve_request', [ self::class, 'send_cors_headers' ], 11, 3 );
	}

	/**
	 * Registration, token and revocation endpoints. They are public: each
	 * handler does its own checks.
	 */
	public static function register_routes(): void {
		$routes = [
			'register' => [ Registration::class, 'handle' ],
			'token'    => [ Token_Endpoint::class, 'token' ],
			'revoke'   => [ Token_Endpoint::class, 'revoke' ],
		];

		foreach ( $routes as $endpoint => $callback ) {
			register_rest_route(
				self::REST_NAMESPACE,
				'/oauth/' . $endpoint,
				[
					'methods'             => 'POST',
					'callback'            => $callback,
					'permission_callback' => '__return_true',
				]
			);
		}
	}

	/**
	 * A JSON response for an OAuth endpoint: never cached, readable from any
	 * origin (no credentials are involved).
	 *
	 * @param mixed $data Response body.
	 */
	public static function json( $data, int $status = 200 ): \WP_REST_Response {
		$response = new \WP_REST_Response( $data, $status );
		$response->header( 'Cache-Control', 'no-store' );
		$response->header( 'Pragma', 'no-cache' );
		$response->header( 'Access-Control-Allow-Origin', '*' );
		return $response;
	}

	/**
	 * An OAuth error response (`error`, `error_description`).
	 */
	public static function error( string $code, string $description, int $status ): \WP_REST_Response {
		return self::json(
			[
				'error'             => $code,
				'error_description' => $description,
			],
			$status
		);
	}

	/**
	 * A 429 for a rate-limited OAuth endpoint.
	 */
	public static function rate_limited( int $retry_after ): \WP_REST_Response {
		$response = self::error( 'md3_mcp_rate_limited', __( 'Too many requests. Try again shortly.', 'md3-wp-mcp' ), 429 );
		$response->header( 'Retry-After', (string) $retry_after );
		return $response;
	}

	/**
	 * WordPress answers any REST request that has an Origin with that origin
	 * and `Access-Control-Allow-Credentials: true`. The OAuth endpoints never
	 * use cookies, so they allow every origin without credentials instead.
	 *
	 * @param bool              $served  Whether the request was already served.
	 * @param \WP_HTTP_Response $result  Response.
	 * @param \WP_REST_Request  $request Request.
	 * @return bool
	 */
	public static function send_cors_headers( $served, $result, $request ) {
		if ( $request instanceof \WP_REST_Request && self::is_oauth_route( $request->get_route() ) && ! headers_sent() ) {
			header( 'Access-Control-Allow-Origin: *' );
			header_remove( 'Access-Control-Allow-Credentials' );
		}
		return $served;
	}

	public static function is_oauth_route( string $route ): bool {
		return str_starts_with( $route, '/' . self::REST_NAMESPACE . '/oauth/' );
	}
}
