<?php
namespace MD3\WPMCP\OAuth;

use MD3\WPMCP\Auth\Scopes;
use MD3\WPMCP\Plugin;
use MD3\WPMCP\Rate_Limiter;
use MD3\WPMCP\Schema;
use MD3\WPMCP\Settings;
use MD3\WPMCP\OAuth\Entities\Client_Entity;

defined( 'ABSPATH' ) || exit;

/**
 * Dynamic Client Registration (RFC 7591) for public clients (design §4.3).
 */
final class Registration {

	const MAX_CLIENTS       = 50;
	const MAX_REDIRECT_URIS = 5;
	const RATE_LIMIT        = 10;
	const RATE_WINDOW       = 3600;

	/**
	 * `POST /md3-wp-mcp/v1/oauth/register`.
	 *
	 * Every client is public: a requested `token_endpoint_auth_method`,
	 * `grant_types` or `response_types` is replaced by what the server
	 * supports (RFC 7591 §3.2.1), and no secret is issued.
	 */
	public static function handle( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! Settings::security()['dcr'] ) {
			return OAuth::error( 'md3_mcp_dcr_disabled', __( 'Dynamic client registration is turned off on this site.', 'md3-wp-mcp' ), 403 );
		}

		if ( ! Rate_Limiter::hit( 'register', Plugin::client_ip_hash(), self::RATE_LIMIT, self::RATE_WINDOW ) ) {
			return OAuth::rate_limited( self::RATE_WINDOW );
		}

		$metadata = $request->get_json_params();
		if ( ! is_array( $metadata ) || ( $metadata && array_is_list( $metadata ) ) ) {
			return self::invalid_metadata( __( 'The body must be a JSON object.', 'md3-wp-mcp' ) );
		}

		$redirect_uris = self::redirect_uris( $metadata['redirect_uris'] ?? null );
		if ( is_wp_error( $redirect_uris ) ) {
			return OAuth::error( 'invalid_redirect_uri', $redirect_uris->get_error_message(), 400 );
		}

		$invalid = self::check_metadata( $metadata );
		if ( null !== $invalid ) {
			return self::invalid_metadata( $invalid );
		}

		// Clients left behind by old connections don't count against the cap.
		if ( self::client_count() >= self::MAX_CLIENTS ) {
			Tokens::prune_clients();
		}
		if ( self::client_count() >= self::MAX_CLIENTS ) {
			return OAuth::error( 'md3_mcp_too_many_clients', __( 'This site has too many registered clients. Ask an administrator to remove unused connections.', 'md3-wp-mcp' ), 429 );
		}

		$name = self::client_name( $metadata['client_name'] ?? '', $redirect_uris[0] );

		global $wpdb;
		$client_id = 'md3_' . wp_generate_password( 32, false );
		$issued_at = time();
		$inserted  = $wpdb->insert(
			Schema::table( 'clients' ),
			[
				'client_id'       => $client_id,
				'name'            => $name,
				'redirect_uris'   => (string) wp_json_encode( $redirect_uris, JSON_UNESCAPED_SLASHES ),
				'type'            => 'dcr',
				'created_ip_hash' => Plugin::client_ip_hash(),
				'completed'       => 0,
				'created_at'      => Tokens::utc( $issued_at ),
			],
			[ '%s', '%s', '%s', '%s', '%s', '%d', '%s' ]
		);
		if ( false === $inserted ) {
			return OAuth::error( 'server_error', __( 'The client could not be registered.', 'md3-wp-mcp' ), 500 );
		}

		return OAuth::json(
			[
				'client_id'                  => $client_id,
				'client_id_issued_at'        => $issued_at,
				'client_name'                => $name,
				'redirect_uris'              => $redirect_uris,
				'grant_types'                => Client_Entity::GRANT_TYPES,
				'response_types'             => [ 'code' ],
				'token_endpoint_auth_method' => 'none',
			],
			201
		);
	}

	/**
	 * 1 to 5 distinct redirect URIs, each allowed by the policy.
	 *
	 * @param mixed $uris Submitted `redirect_uris`.
	 * @return string[]|\WP_Error
	 */
	private static function redirect_uris( $uris ) {
		if ( ! is_array( $uris ) || ! $uris || ! array_is_list( $uris ) ) {
			return new \WP_Error( 'invalid_redirect_uri', __( 'redirect_uris must be a non-empty list.', 'md3-wp-mcp' ) );
		}

		foreach ( $uris as $uri ) {
			if ( ! is_string( $uri ) || ! Redirect_Uri_Policy::allowed( $uri ) ) {
				return new \WP_Error( 'invalid_redirect_uri', __( 'A redirect URI is not allowed. Use https on a public host, http on localhost or 127.0.0.1, or an allowed app scheme.', 'md3-wp-mcp' ) );
			}
		}

		$uris = array_values( array_unique( $uris ) );
		if ( count( $uris ) > self::MAX_REDIRECT_URIS ) {
			/* translators: %d: maximum number of redirect URIs. */
			return new \WP_Error( 'invalid_redirect_uri', sprintf( __( 'A client may register at most %d redirect URIs.', 'md3-wp-mcp' ), self::MAX_REDIRECT_URIS ) );
		}

		return $uris;
	}

	/**
	 * Checks the optional metadata the server cares about.
	 *
	 * @param array<string,mixed> $metadata Submitted metadata.
	 * @return string|null Why the metadata is invalid, or null.
	 */
	private static function check_metadata( array $metadata ): ?string {
		if ( isset( $metadata['client_name'] ) && ! is_string( $metadata['client_name'] ) ) {
			return __( 'client_name must be a string.', 'md3-wp-mcp' );
		}

		if ( isset( $metadata['grant_types'] ) ) {
			$grants = $metadata['grant_types'];
			if ( ! is_array( $grants ) || array_filter( $grants, static fn ( $grant ) => ! in_array( $grant, Client_Entity::GRANT_TYPES, true ) ) || ! in_array( 'authorization_code', $grants, true ) ) {
				return __( 'Only the authorization_code and refresh_token grants are supported.', 'md3-wp-mcp' );
			}
		}

		if ( isset( $metadata['response_types'] ) && [ 'code' ] !== $metadata['response_types'] ) {
			return __( 'Only the code response type is supported.', 'md3-wp-mcp' );
		}

		if ( isset( $metadata['scope'] ) && ( ! is_string( $metadata['scope'] ) || null === Scopes::normalize( $metadata['scope'] ) ) ) {
			return __( 'Unknown scope.', 'md3-wp-mcp' );
		}

		return null;
	}

	/**
	 * Plain-text name of at most 191 characters, else the redirect host.
	 *
	 * @param mixed $name Submitted `client_name`.
	 */
	private static function client_name( $name, string $redirect_uri ): string {
		$name = is_string( $name ) ? sanitize_text_field( $name ) : '';
		if ( '' === $name ) {
			$name = (string) wp_parse_url( $redirect_uri, PHP_URL_HOST );
		}
		if ( '' === $name ) {
			$name = __( 'Unnamed client', 'md3-wp-mcp' );
		}
		return mb_substr( $name, 0, 191 );
	}

	private static function client_count(): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::table( 'clients' ) . ' WHERE type = %s', 'dcr' ) );
	}

	private static function invalid_metadata( string $description ): \WP_REST_Response {
		return OAuth::error( 'invalid_client_metadata', $description, 400 );
	}
}
