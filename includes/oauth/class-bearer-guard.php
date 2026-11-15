<?php
namespace MD3\WPMCP\OAuth;

use MD3\WPMCP\Auth\Identity;
use MD3\WPMCP\Auth\Request_Context;
use MD3\WPMCP\Auth\Scopes;
use MD3\WPMCP\Mcp\Server;
use MD3\WPMCP\Schema;
use League\OAuth2\Server\Exception\OAuthServerException;
use Nyholm\Psr7\ServerRequest;

defined( 'ABSPATH' ) || exit;

/**
 * Turns an access token into the MCP user, on the MCP route only (design §4.7).
 */
final class Bearer_Guard {

	/** The MCP route, as WordPress matches it. */
	const ROUTE = '/' . Server::NAMESPACE . '/' . Server::ROUTE;

	/** Seconds a rate-limited MCP client is told to wait (the window). */
	const RETRY_AFTER = 60;

	/** Seconds between two `last_used_at` writes for one token. */
	const LAST_USED_INTERVAL = 60;

	/** Whether this request sent a bearer token to the MCP route that was refused. */
	private static bool $invalid_token = false;

	public static function register(): void {
		add_filter( 'determine_current_user', [ self::class, 'determine_current_user' ], 30 );
		add_filter( 'rest_authentication_errors', [ self::class, 'authentication_errors' ], 5 );
		add_filter( 'rest_request_before_callbacks', [ self::class, 'before_callbacks' ], 10, 3 );
		// After Server::restore_refusal (priority 10) has put our own error in place.
		add_filter( 'rest_request_after_callbacks', [ self::class, 'after_callbacks' ], 20, 3 );
		// Authentication errors never reach the route callbacks.
		add_filter( 'rest_post_dispatch', [ self::class, 'after_callbacks' ], 10, 3 );
	}

	/**
	 * The Authorization header, wherever the web server put it.
	 */
	public static function authorization_header(): string {
		foreach ( [ 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION' ] as $key ) {
			if ( isset( $_SERVER[ $key ] ) && is_string( $_SERVER[ $key ] ) && '' !== $_SERVER[ $key ] ) {
				return trim( wp_unslash( $_SERVER[ $key ] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- a credential, validated as a JWT.
			}
		}

		if ( function_exists( 'getallheaders' ) ) {
			foreach ( (array) getallheaders() as $name => $value ) {
				if ( is_string( $name ) && 0 === strcasecmp( $name, 'Authorization' ) && is_string( $value ) ) {
					return trim( $value );
				}
			}
		}

		return '';
	}

	/**
	 * `determine_current_user` priority 30: a valid bearer token, with no user
	 * found by another method, becomes the token's user, only while WordPress
	 * serves the MCP REST route.
	 *
	 * Like core's Application Password gate: WP::init() evaluates the user
	 * before the route is known (no identity then, so `init`, `wp_loaded` and
	 * `parse_request` handlers never run as the MCP user), and
	 * WP_REST_Server::serve_request() clears that cached "nobody", so the
	 * user is evaluated again during the REST authentication check, with
	 * REST_REQUEST defined and the `rest_route` query var resolved.
	 *
	 * @param mixed $user User ID found so far, or false.
	 * @return mixed
	 */
	public static function determine_current_user( $user ) {
		// Every evaluation starts clean: a worker may serve many requests.
		Request_Context::clear();
		self::$invalid_token = false;

		if ( ! empty( $user ) ) {
			return $user;
		}

		if ( ! wp_is_serving_rest_request() ) {
			return $user;
		}

		$wp    = $GLOBALS['wp'] ?? null;
		$route = $wp instanceof \WP ? ( $wp->query_vars['rest_route'] ?? '' ) : '';
		if ( ! is_string( $route ) || ! self::is_mcp_route( $route ) ) {
			return $user;
		}

		$header = self::authorization_header();
		if ( ! preg_match( '/^Bearer(\s|$)/i', $header ) ) {
			return $user;
		}

		$identity = self::authenticate( $header );
		if ( null === $identity ) {
			self::$invalid_token = true;
			return $user;
		}

		Request_Context::set( $identity );

		return $identity->user_id;
	}

	/**
	 * `rest_authentication_errors` priority 5: a bearer token that was sent but
	 * refused is a 401 that no later method can turn into a success.
	 *
	 * @param mixed $result Error, true, or null.
	 * @return mixed
	 */
	public static function authentication_errors( $result ) {
		// Make sure the user (and so the token) has been evaluated for this request.
		get_current_user_id();

		if ( self::$invalid_token ) {
			return is_wp_error( $result ) ? $result : new \WP_Error(
				'md3_mcp_invalid_token',
				__( 'The access token is invalid, expired or revoked.', 'md3-wp-mcp' ),
				[ 'status' => 401 ]
			);
		}

		if ( null === $result && null !== Request_Context::get() ) {
			return true;
		}

		return $result;
	}

	/**
	 * `rest_request_before_callbacks`: an identity from our token is accepted
	 * on the MCP route only. This is the authoritative route check.
	 *
	 * @param mixed            $response Response so far.
	 * @param array            $handler  Route handler.
	 * @param \WP_REST_Request $request  Request.
	 * @return mixed
	 */
	public static function before_callbacks( $response, $handler, \WP_REST_Request $request ) {
		if ( null !== Request_Context::get() && ! self::is_mcp_route( $request->get_route() ) ) {
			return new \WP_Error(
				'md3_mcp_route_forbidden',
				__( 'This access token can only be used on the MCP endpoint.', 'md3-wp-mcp' ),
				[ 'status' => 403 ]
			);
		}

		return $response;
	}

	/**
	 * `rest_request_after_callbacks` and `rest_post_dispatch`: adds the
	 * `WWW-Authenticate` challenge to 401 and 403 answers of the MCP route,
	 * and turns a tool call refused for a missing scope into a 403 with an
	 * `insufficient_scope` challenge (the JSON-RPC body is kept). A rate
	 * limited MCP request gets `Retry-After`.
	 *
	 * @param mixed            $response Response or error.
	 * @param mixed            $handler  Route handler (or the REST server on `rest_post_dispatch`).
	 * @param \WP_REST_Request $request  Request.
	 * @return mixed
	 */
	public static function after_callbacks( $response, $handler, \WP_REST_Request $request ) {
		if ( ! self::is_mcp_route( $request->get_route() ) ) {
			return $response;
		}

		$status = self::status( $response );

		if ( 429 === $status && 'md3_mcp_rate_limited' === self::code( $response ) ) {
			$response = is_wp_error( $response ) ? rest_convert_error_to_response( $response ) : $response;
			if ( $response instanceof \WP_HTTP_Response ) {
				$response->header( 'Retry-After', (string) self::RETRY_AFTER );
			}
			return $response;
		}

		$denied = in_array( $status, [ 200, 403 ], true ) ? Request_Context::denied_scope() : null;
		if ( null === $denied && ! in_array( $status, [ 401, 403 ], true ) ) {
			return $response;
		}

		if ( is_wp_error( $response ) ) {
			$response = rest_convert_error_to_response( $response );
		}
		if ( ! $response instanceof \WP_HTTP_Response ) {
			return $response;
		}

		$metadata = 'resource_metadata="' . Discovery::protected_resource_url() . '"';

		if ( null !== $denied ) {
			$response->set_status( 403 );
			$response->header( 'WWW-Authenticate', 'Bearer error="insufficient_scope", scope="' . implode( ' ', self::step_up_scopes( $denied ) ) . '", ' . $metadata );
		} elseif ( self::$invalid_token ) {
			$response->header( 'WWW-Authenticate', 'Bearer ' . $metadata . ', error="invalid_token"' );
		} else {
			$response->header( 'WWW-Authenticate', 'Bearer ' . $metadata );
		}

		return $response;
	}

	/**
	 * Validates signature, time claims, revocation (including the MCP user
	 * check) and audience, then builds the identity from the token's row.
	 */
	private static function authenticate( string $header ): ?Identity {
		try {
			$validated = Server_Factory::resource_server()->validateAuthenticatedRequest(
				new ServerRequest( 'POST', OAuth::resource_url(), [ 'Authorization' => $header ] )
			);
		} catch ( OAuthServerException $e ) {
			// A bad, expired or revoked token: answered with a 401.
			return null;
		} catch ( \Throwable $e ) {
			// A key or database fault: make it visible, never with the token.
			error_log( 'MD3 WP MCP: access token validation failed: ' . get_class( $e ) . ': ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return null;
		}

		// League exposes the first `aud` as the client ID; it is the resource here.
		if ( OAuth::resource_url() !== $validated->getAttribute( 'oauth_client_id' ) ) {
			return null;
		}

		$jti = $validated->getAttribute( 'oauth_access_token_id' );
		if ( ! is_string( $jti ) || '' === $jti ) {
			return null;
		}
		$hash = hash( 'sha256', $jti );

		global $wpdb;
		$table = Schema::table( 'access_tokens' );
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT client_id, user_id, scopes, last_used_at FROM {$table} WHERE id_hash = %s AND revoked = 0", $hash ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name.
		);
		if ( ! $row || (string) $row->user_id !== (string) $validated->getAttribute( 'oauth_user_id' ) ) {
			return null;
		}

		$stale = Tokens::utc( time() - self::LAST_USED_INTERVAL );
		if ( null === $row->last_used_at || $row->last_used_at <= $stale ) {
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET last_used_at = %s WHERE id_hash = %s AND ( last_used_at IS NULL OR last_used_at <= %s )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name.
					Tokens::utc( time() ),
					$hash,
					$stale
				)
			);
		}

		$scopes = array_values( array_intersect( Scopes::all(), explode( ' ', (string) $row->scopes ) ) );

		return new Identity( (int) $row->user_id, (string) $row->client_id, $scopes, $hash );
	}

	/**
	 * The scopes to ask for on a step-up: those already granted plus the
	 * missing one, so the new token doesn't lose any.
	 *
	 * @return string[]
	 */
	private static function step_up_scopes( string $denied ): array {
		$granted = null !== Request_Context::get() ? Request_Context::get()->scopes : [];
		return array_values( array_intersect( Scopes::all(), array_merge( $granted, [ $denied ] ) ) );
	}

	private static function is_mcp_route( string $route ): bool {
		return self::ROUTE === untrailingslashit( $route );
	}

	/**
	 * Error code of a response or error, '' when there is none.
	 *
	 * @param mixed $response Response or error.
	 */
	private static function code( $response ): string {
		if ( is_wp_error( $response ) ) {
			return (string) $response->get_error_code();
		}
		$data = $response instanceof \WP_HTTP_Response ? $response->get_data() : null;
		return is_array( $data ) && is_string( $data['code'] ?? null ) ? $data['code'] : '';
	}

	/**
	 * HTTP status of a response or error.
	 *
	 * @param mixed $response Response or error.
	 */
	private static function status( $response ): int {
		if ( is_wp_error( $response ) ) {
			$data = $response->get_error_data();
			return is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 500;
		}
		return $response instanceof \WP_HTTP_Response ? $response->get_status() : 200;
	}
}
