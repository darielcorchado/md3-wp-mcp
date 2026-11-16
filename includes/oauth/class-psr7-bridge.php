<?php
namespace MD3\WPMCP\OAuth;

use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Converts between WordPress requests and responses and the PSR-7 messages
 * league/oauth2-server works with.
 */
final class Psr7_Bridge {

	/**
	 * A REST request (token or revoke endpoint) as a PSR-7 server request.
	 * The body is the form-encoded parameters.
	 */
	public static function from_rest( \WP_REST_Request $request ): ServerRequestInterface {
		$headers = [];
		foreach ( $request->get_headers() as $name => $values ) {
			$headers[ str_replace( '_', '-', $name ) ] = $values;
		}

		$psr = new ServerRequest(
			$request->get_method(),
			rest_url( ltrim( $request->get_route(), '/' ) ),
			$headers,
			$request->get_body(),
			'1.1',
			self::server_params()
		);

		return $psr
			->withQueryParams( (array) $request->get_query_params() )
			->withParsedBody( (array) $request->get_body_params() );
	}

	/**
	 * A PSR-7 response as a REST response. JSON bodies become data so the
	 * REST server encodes them; other bodies are passed as a string.
	 */
	public static function to_rest( ResponseInterface $response ): \WP_REST_Response {
		$body = (string) $response->getBody();
		$data = null;
		if ( '' !== $body ) {
			$decoded = str_contains( strtolower( $response->getHeaderLine( 'Content-Type' ) ), 'json' ) ? json_decode( $body, true ) : null;
			$data    = null !== $decoded ? $decoded : $body;
		}

		$rest = new \WP_REST_Response( $data, $response->getStatusCode() );
		foreach ( $response->getHeaders() as $name => $values ) {
			$rest->header( self::header_name( $name ), implode( ', ', $values ) );
		}

		return $rest;
	}

	/**
	 * The authorize page's query string as a PSR-7 GET request.
	 *
	 * @param array<string,mixed> $query Query parameters.
	 */
	public static function from_query( array $query ): ServerRequestInterface {
		$uri = OAuth::authorize_url() . ( $query ? '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 ) : '' );
		return ( new ServerRequest( 'GET', $uri, [], null, '1.1', self::server_params() ) )->withQueryParams( $query );
	}

	/**
	 * @return array<string,string>
	 */
	private static function server_params(): array {
		return [ 'REMOTE_ADDR' => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '' ];
	}

	/**
	 * `cache-control` → `Cache-Control`.
	 */
	private static function header_name( string $name ): string {
		return implode( '-', array_map( 'ucfirst', explode( '-', strtolower( $name ) ) ) );
	}
}
