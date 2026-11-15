<?php
namespace MD3\WPMCP\OAuth;

use MD3\WPMCP\Auth\Identity;
use MD3\WPMCP\Auth\Request_Context;
use MD3\WPMCP\Auth\Scopes;
use MD3\WPMCP\Mcp\Server;
use MD3\WPMCP\Schema;
use Nyholm\Psr7\ServerRequest;

defined( 'ABSPATH' ) || exit;

/**
 * Turns an access token into the MCP user, on the MCP route only (design §4.7).
 */
final class Bearer_Guard {

	/** The MCP route, as WordPress matches it. */
	const ROUTE = '/' . Server::NAMESPACE . '/' . Server::ROUTE;

	/** Seconds between two `last_used_at` writes for one token. */
	const LAST_USED_INTERVAL = 60;

	/** Whether this request sent a bearer token to the MCP route that was refused. */
	private static bool $invalid_token = false;

	public static function register(): void {
		add_filter( 'determine_current_user', [ self::class, 'determine_current_user' ], 30 );
		// Before the authorize page (1) and rest_api_loaded (10).
		add_action( 'parse_request', [ self::class, 'confine_to_mcp_route' ], 0 );
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
	 * Whether a request targets the MCP endpoint, the way WP::parse_request()
	 * resolves the route:
	 * - a `rest_route` parameter wins over the path, from the POST body first,
	 *   then the query string. It must be `/md3-wp-mcp/mcp`, and the path must
	 *   be the front controller (`/` or `/index.php` under the home path) or
	 *   the MCP URL path;
	 * - otherwise the raw path must be the path of `rest_url('md3-wp-mcp/mcp')`.
	 *
	 * The path is compared as sent (no URL parsing), and a path starting with
	 * `//` is refused. Whether the script is the front controller is checked by
	 * the caller.
	 *
	 * @param array<string,mixed> $get  Query parameters.
	 * @param array<string,mixed> $post POST body parameters.
	 */
	public static function is_mcp_path( string $request_uri, array $get, array $post = [] ): bool {
		$path = explode( '?', $request_uri, 2 )[0];
		if ( '' === $path || '/' !== $path[0] || str_starts_with( $path, '//' ) ) {
			return false;
		}
		$path = untrailingslashit( $path );

		$resource       = OAuth::resource_url();
		$resource_query = (string) wp_parse_url( $resource, PHP_URL_QUERY );
		$resource_path  = untrailingslashit( (string) wp_parse_url( $resource, PHP_URL_PATH ) );

		if ( array_key_exists( 'rest_route', $post ) || array_key_exists( 'rest_route', $get ) ) {
			$route = array_key_exists( 'rest_route', $post ) ? $post['rest_route'] : $get['rest_route'];
			if ( ! is_string( $route ) || self::ROUTE !== untrailingslashit( $route ) ) {
				return false;
			}

			$home = untrailingslashit( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ) );
			$ok   = [ $home, $home . '/index.php' ];
			if ( '' === $resource_query ) {
				$ok[] = $resource_path;
			}
			return in_array( $path, $ok, true );
		}

		// Plain permalinks: the resource URL is `/?rest_route=…`, so the path alone says nothing.
		if ( str_contains( $resource_query, 'rest_route' ) ) {
			return false;
		}

		return $resource_path === $path;
	}

	/**
	 * `determine_current_user` priority 30: a valid bearer token on the MCP
	 * path, with no user found by another method, becomes the token's user.
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

		$header = self::authorization_header();
		if ( ! preg_match( '/^Bearer(\s|$)/i', $header ) ) {
			return $user;
		}

		if ( ! self::is_front_controller() ) {
			return $user;
		}

		$request_uri = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared only.
		if ( ! self::is_mcp_path( $request_uri, wp_unslash( $_GET ), wp_unslash( $_POST ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- routing only.
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
	 * `parse_request` priority 0: once WordPress has resolved the route, an
	 * identity from our token survives only on the MCP route. Any other
	 * front-end request (a page, a feed, another REST route) loses it.
	 */
	public static function confine_to_mcp_route( \WP $wp ): void {
		if ( null === Request_Context::get() ) {
			return;
		}

		$route = $wp->query_vars['rest_route'] ?? '';
		if ( is_string( $route ) && self::ROUTE === untrailingslashit( $route ) ) {
			return;
		}

		Request_Context::clear();
		wp_set_current_user( 0 );
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
	 * `insufficient_scope` challenge (the JSON-RPC body is kept).
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
		} catch ( \Throwable $e ) {
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

	/**
	 * Whether this is a front-end request to WordPress's front controller.
	 * Admin, AJAX, cron and XML-RPC requests, and other PHP entry points
	 * (admin-post.php, wp-comments-post.php…) never parse a REST route, so
	 * they never get an identity from our token.
	 */
	private static function is_front_controller(): bool {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) ) {
			return false;
		}

		$script = isset( $_SERVER['SCRIPT_FILENAME'] ) && is_string( $_SERVER['SCRIPT_FILENAME'] ) ? wp_unslash( $_SERVER['SCRIPT_FILENAME'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared only.
		if ( '' === $script ) {
			return false;
		}

		$script = realpath( $script );
		$index  = realpath( ABSPATH . 'index.php' );

		return false !== $script && false !== $index && $script === $index;
	}

	private static function is_mcp_route( string $route ): bool {
		return self::ROUTE === untrailingslashit( $route );
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
