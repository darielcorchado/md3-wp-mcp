<?php
namespace MD3\WPMCP\OAuth;

defined( 'ABSPATH' ) || exit;

/**
 * Client ID Metadata Documents: a client whose ID is an HTTPS URL that serves
 * its own metadata (design §4.3).
 */
final class Cimd {

	const TRANSIENT_PREFIX = 'md3_mcp_cimd_';
	const MAX_BYTES        = 65536;
	const TIMEOUT          = 5;

	/**
	 * Whether the client ID is a CIMD URL: HTTPS with a path, and no
	 * credentials, fragment or dot segments.
	 */
	public static function is_cimd( string $client_id ): bool {
		if ( strlen( $client_id ) > 191 || preg_match( '/[\s\x00-\x1f\x7f#]/', $client_id ) ) {
			return false;
		}

		$parts = wp_parse_url( $client_id );
		if ( ! is_array( $parts ) || 'https' !== strtolower( $parts['scheme'] ?? '' ) || empty( $parts['host'] ) ) {
			return false;
		}
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return false;
		}

		$path = $parts['path'] ?? '';
		if ( '' === $path || '/' === $path ) {
			return false;
		}

		return ! array_intersect( [ '.', '..' ], explode( '/', $path ) );
	}

	/**
	 * Fetches (or reads from cache) the metadata document for a CIMD client.
	 *
	 * @return array{client_id: string, client_name: string, redirect_uris: string[]}|null
	 *         Null when the URL isn't a CIMD URL, the fetch fails, or the
	 *         document is invalid. Only valid documents are cached.
	 */
	public static function resolve( string $url ): ?array {
		if ( ! self::is_cimd( $url ) ) {
			return null;
		}

		$key    = self::TRANSIENT_PREFIX . md5( $url );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		// wp_safe_remote_get refuses hosts that resolve to private addresses.
		$response = wp_safe_remote_get(
			$url,
			[
				'timeout'             => self::TIMEOUT,
				'limit_response_size' => self::MAX_BYTES,
				'redirection'         => 0,
				'headers'             => [ 'Accept' => 'application/json' ],
			]
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$doc = self::validate( $url, json_decode( (string) wp_remote_retrieve_body( $response ), true ) );
		if ( null !== $doc ) {
			set_transient( $key, $doc, DAY_IN_SECONDS );
		}

		return $doc;
	}

	/**
	 * @param mixed $doc Decoded document.
	 * @return array{client_id: string, client_name: string, redirect_uris: string[]}|null
	 */
	private static function validate( string $url, $doc ): ?array {
		if ( ! is_array( $doc ) || ( $doc['client_id'] ?? null ) !== $url ) {
			return null;
		}

		$uris = $doc['redirect_uris'] ?? null;
		if ( ! is_array( $uris ) || ! $uris || ! array_is_list( $uris ) ) {
			return null;
		}
		foreach ( $uris as $uri ) {
			if ( ! is_string( $uri ) || ! Redirect_Uri_Policy::allowed( $uri ) ) {
				return null;
			}
		}

		$name = is_string( $doc['client_name'] ?? null ) ? sanitize_text_field( $doc['client_name'] ) : '';
		if ( '' === $name ) {
			$name = (string) wp_parse_url( $url, PHP_URL_HOST );
		}

		return [
			'client_id'     => $url,
			'client_name'   => mb_substr( $name, 0, 191 ),
			'redirect_uris' => array_values( array_unique( $uris ) ),
		];
	}
}
