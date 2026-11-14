<?php
namespace MD3\WPMCP\Tests;

use MD3\WPMCP\Auth\Identity;
use MD3\WPMCP\Auth\Request_Context;
use MD3\WPMCP\Settings;
use WP_UnitTestCase;

/**
 * Base class for the plugin's integration tests.
 */
abstract class Test_Case extends WP_UnitTestCase {

	/** @var array<string,string> Mcp-Session-Id per route. */
	private array $mcp_sessions = [];

	public function tear_down() {
		$this->mcp_sessions = [];
		Request_Context::clear();
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
