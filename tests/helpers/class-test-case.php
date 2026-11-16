<?php
namespace MD3\WPMCP\Tests;

use MD3\WPMCP\Auth\Identity;
use MD3\WPMCP\Auth\Request_Context;
use MD3\WPMCP\OAuth\Authorize;
use MD3\WPMCP\Settings;
use WP_UnitTestCase;

/**
 * Base class for the plugin's integration tests.
 */
abstract class Test_Case extends WP_UnitTestCase {

	/** @var array<string,string> Mcp-Session-Id per route. */
	private array $mcp_sessions = [];

	/** @var int Source of distinct client IPs for issue_token() registrations. */
	private static int $token_ip_counter = 0;

	/** @var array{0: array<string,mixed>, 1: array<string,mixed>, 2: array<string,mixed>} $_SERVER, $_GET and $_POST before the test. */
	private array $superglobals = [ [], [], [] ];

	/** @var array<string,mixed> $wp->query_vars before the test. */
	private array $query_vars = [];

	public function set_up() {
		parent::set_up();
		// issue_token() and serve() change REMOTE_ADDR, REQUEST_URI, $_GET, $wp->query_vars…
		$this->superglobals = [ $_SERVER, $_GET, $_POST ];
		$this->query_vars   = $GLOBALS['wp']->query_vars;
	}

	public function tear_down() {
		$this->mcp_sessions = [];
		Request_Context::clear();
		[ $_SERVER, $_GET, $_POST ] = $this->superglobals;
		$GLOBALS['wp']->query_vars  = $this->query_vars;
		parent::tear_down();
	}

	/**
	 * Creates a user with the given role, makes them the MCP user and
	 * authenticates the request as an MCP identity carrying the given scopes.
	 *
	 * The ID is written straight to the option so tests can try roles that
	 * `Settings::update_security()` would refuse.
	 *
	 * @param string[] $scopes OAuth scopes.
	 * @param string   $role   WordPress role.
	 * @return int User ID.
	 */
	protected function as_mcp_user( array $scopes, string $role = 'editor' ): int {
		$user_id = (int) self::factory()->user->create( [ 'role' => $role ] );

		$security                = Settings::security();
		$security['mcp_user_id'] = $user_id;
		update_option( Settings::OPT_SECURITY, $security );

		wp_set_current_user( $user_id );
		Request_Context::set( new Identity( $user_id, 'test-client', $scopes, str_repeat( 'a', 64 ) ) );

		return $user_id;
	}

	/**
	 * Runs the real OAuth flow and returns its tokens: register a DCR client,
	 * validate the authorize request, approve it as the MCP user (created as
	 * an Editor when none is configured), then exchange the code with the
	 * PKCE verifier at the token endpoint.
	 *
	 * @param string[] $scopes Scopes to request and approve.
	 * @return array{access_token: string, refresh_token: string, client_id: string}
	 */
	protected function issue_token( array $scopes = [ 'content:read', 'content:write' ] ): array {
		$user_id = Settings::mcp_user_id();
		if ( 0 === $user_id ) {
			$user_id                 = (int) self::factory()->user->create( [ 'role' => 'editor' ] );
			$security                = Settings::security();
			$security['mcp_user_id'] = $user_id;
			update_option( Settings::OPT_SECURITY, $security );
		}

		$redirect_uri = 'https://claude.ai/api/mcp/auth_callback';
		$verifier     = wp_generate_password( 64, false );

		// Each registration comes from a new IP, so tests that issue many
		// tokens stay under the registration rate limit (30 per IP per minute).
		$_SERVER['REMOTE_ADDR'] = '2001:db8:7::' . dechex( ++self::$token_ip_counter );

		$register = new \WP_REST_Request( 'POST', '/md3-wp-mcp/v1/oauth/register' );
		$register->set_header( 'Content-Type', 'application/json' );
		$register->set_body( wp_json_encode( [ 'client_name' => 'phpunit', 'redirect_uris' => [ $redirect_uri ] ] ) );
		$registered = rest_get_server()->dispatch( $register );
		$this->assertSame( 201, $registered->get_status(), 'client registration' );
		$client_id = $registered->get_data()['client_id'];

		$query = [
			'response_type'         => 'code',
			'client_id'             => $client_id,
			'redirect_uri'          => $redirect_uri,
			'scope'                 => implode( ' ', $scopes ),
			'state'                 => 'phpunit',
			'code_challenge'        => rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' ),
			'code_challenge_method' => 'S256',
		];
		$this->assertNotWPError( Authorize::validate( $query ), 'authorize request' );

		$redirect = Authorize::complete( $query, $user_id, $scopes, true );
		$this->assertIsString( $redirect, 'authorize completion' );
		parse_str( (string) wp_parse_url( $redirect, PHP_URL_QUERY ), $params );
		$this->assertArrayHasKey( 'code', $params, 'authorization code' );

		$token = new \WP_REST_Request( 'POST', '/md3-wp-mcp/v1/oauth/token' );
		$token->set_header( 'Content-Type', 'application/x-www-form-urlencoded' );
		$token->set_body_params(
			[
				'grant_type'    => 'authorization_code',
				'client_id'     => $client_id,
				'code'          => $params['code'],
				'redirect_uri'  => $redirect_uri,
				'code_verifier' => $verifier,
			]
		);
		$response = rest_get_server()->dispatch( $token );
		$this->assertSame( 200, $response->get_status(), 'token exchange' );
		$data = $response->get_data();

		return [
			'access_token'  => $data['access_token'],
			'refresh_token' => $data['refresh_token'],
			'client_id'     => $client_id,
		];
	}

	/**
	 * Serves a REST request the way a real HTTP request reaches
	 * WP_REST_Server::serve_request(): WP::init() evaluates the current user
	 * before the route is known, WP::parse_request() sets the `rest_route`
	 * query var, serve_request() clears a cached "nobody", then
	 * authentication errors, dispatch, error conversion and
	 * `rest_post_dispatch`. Set the Authorization header in $_SERVER first.
	 */
	protected function serve( \WP_REST_Request $request ): \WP_REST_Response {
		$server = rest_get_server();

		$url                       = rest_url( ltrim( $request->get_route(), '/' ) );
		$query                     = (string) wp_parse_url( $url, PHP_URL_QUERY );
		$_SERVER['REQUEST_URI']    = (string) wp_parse_url( $url, PHP_URL_PATH ) . ( '' !== $query ? '?' . $query : '' );
		$_SERVER['REQUEST_METHOD'] = $request->get_method();
		$_POST                     = [];
		parse_str( $query, $_GET );

		// WP::init(): no route yet.
		$GLOBALS['wp']->query_vars = [];
		$GLOBALS['current_user']   = null;
		wp_get_current_user();

		// WP::parse_request(), then WP_REST_Server::serve_request().
		$GLOBALS['wp']->query_vars = [ 'rest_route' => $request->get_route() ];
		if ( ! wp_get_current_user()->exists() ) {
			$GLOBALS['current_user'] = null;
		}

		$result = $server->check_authentication();
		if ( ! is_wp_error( $result ) ) {
			$result = $server->dispatch( $request );
		}
		$result = rest_ensure_response( $result );
		if ( is_wp_error( $result ) ) {
			$result = rest_convert_error_to_response( $result );
		}
		$result = apply_filters( 'rest_post_dispatch', rest_ensure_response( $result ), $server, $request );

		// Normalize nested objects so tests can read the payload as arrays.
		$result->set_data( json_decode( wp_json_encode( $result->get_data() ), true ) );

		return $result;
	}

	/**
	 * Runs an ability through the Abilities API, as an MCP call would.
	 *
	 * @param array<string,mixed> $input Tool input.
	 * @return mixed Ability result or WP_Error.
	 */
	protected function run_ability( string $short_name, array $input ) {
		return wp_get_ability( 'md3-wp-mcp/' . $short_name )->execute( $input );
	}

	/**
	 * Sends a JSON-RPC 2.0 request to an MCP server through the REST server.
	 *
	 * Runs `initialize` once per route and keeps its `Mcp-Session-Id`.
	 *
	 * @param array<string,mixed> $params Method params.
	 */
	protected function mcp_call( string $method, array $params = [], string $route = '/md3-wp-mcp/mcp' ): \WP_REST_Response {
		if ( ! isset( $this->mcp_sessions[ $route ] ) ) {
			$init = $this->mcp_post(
				$route,
				'initialize',
				[
					'protocolVersion' => '2025-11-25',
					'capabilities'    => new \stdClass(),
					'clientInfo'      => [ 'name' => 'phpunit', 'version' => '1' ],
				]
			);
			$this->mcp_sessions[ $route ] = (string) ( $init->get_headers()['Mcp-Session-Id'] ?? '' );
			if ( '' === $this->mcp_sessions[ $route ] ) {
				return $init;
			}
		}

		return $this->mcp_post( $route, $method, $params, $this->mcp_sessions[ $route ] );
	}

	private function mcp_post( string $route, string $method, array $params, string $session = '' ): \WP_REST_Response {
		$request = new \WP_REST_Request( 'POST', $route );
		$request->set_header( 'Content-Type', 'application/json' );
		if ( '' !== $session ) {
			$request->set_header( 'Mcp-Session-Id', $session );
			$request->set_header( 'MCP-Protocol-Version', '2025-11-25' );
		}
		$request->set_body(
			wp_json_encode(
				[
					'jsonrpc' => '2.0',
					'id'      => 1,
					'method'  => $method,
					'params'  => (object) $params,
				]
			)
		);

		$response = rest_get_server()->dispatch( $request );
		// Normalize nested objects so tests can read the payload as arrays.
		$response->set_data( json_decode( wp_json_encode( $response->get_data() ), true ) );

		return $response;
	}
}
