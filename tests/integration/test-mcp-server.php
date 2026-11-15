<?php
use MD3\WPMCP\Abilities\Registry;
use MD3\WPMCP\Auth\Identity;
use MD3\WPMCP\Auth\Request_Context;
use MD3\WPMCP\Auth\Scopes;
use MD3\WPMCP\Mcp\Error_Handler;
use MD3\WPMCP\Mcp\Server;
use MD3\WPMCP\Settings;
use MD3\WPMCP\Tests\Test_Case;
use WP\MCP\Abilities\McpAbilityExposure;
use WP\MCP\Core\McpAdapter;
use WP\MCP\Domain\Utils\McpNameSanitizer;
use WP\MCP\Infrastructure\Observability\FailureReason;

class Test_Mcp_Server extends Test_Case {

	/**
	 * The adapter wires its REST routes once per process, on the first
	 * `rest_api_init`. Trigger it before the test hook backup so later tests keep the routes.
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();
		rest_get_server();
	}

	public function test_tools_list_contains_exactly_our_tools() {
		$this->as_mcp_user( Scopes::all() );
		$response = $this->mcp_call( 'tools/list' );
		$this->assertSame( 200, $response->get_status() );

		$names    = wp_list_pluck( $response->get_data()['result']['tools'], 'name' );
		$expected = array_map( fn( $n ) => McpNameSanitizer::sanitize_name( $n ), Registry::names() );
		$this->assertNotEmpty( $names );
		$this->assertEqualsCanonicalizing( $expected, $names );
	}

	public function test_cookie_user_without_oauth_identity_is_401() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$response = $this->mcp_call( 'tools/list' );
		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 'md3_mcp_unauthorized', $response->get_data()['code'] );
	}

	public function test_administrator_is_refused() {
		$this->as_mcp_user( Scopes::all(), 'administrator' );
		$response = $this->mcp_call( 'tools/list' );
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'md3_mcp_not_mcp_user', $response->get_data()['code'] );
	}

	public function test_other_editor_is_refused() {
		$this->as_mcp_user( Scopes::all() );
		$other = self::factory()->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $other );
		Request_Context::set( new Identity( $other, 'test-client', Scopes::all(), str_repeat( 'a', 64 ) ) );

		$response = $this->mcp_call( 'tools/list' );
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'md3_mcp_not_mcp_user', $response->get_data()['code'] );
	}

	public function test_no_mcp_user_configured_is_refused() {
		$this->as_mcp_user( Scopes::all() );
		$security                = Settings::security();
		$security['mcp_user_id'] = 0;
		update_option( Settings::OPT_SECURITY, $security );

		$response = $this->mcp_call( 'tools/list' );
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'md3_mcp_not_mcp_user', $response->get_data()['code'] );
	}

	public function test_rate_limit_returns_429() {
		$this->as_mcp_user( Scopes::all() );
		$security                   = Settings::security();
		$security['mcp_rate_limit'] = 2;
		update_option( Settings::OPT_SECURITY, $security );

		// The initialize request counts as the first hit.
		$this->assertSame( 200, $this->mcp_call( 'tools/list' )->get_status() );
		$response = $this->mcp_call( 'tools/list' );
		$this->assertSame( 429, $response->get_status() );
		$this->assertSame( 'md3_mcp_rate_limited', $response->get_data()['code'] );
	}

	public function test_tools_call_create_content_end_to_end() {
		$this->as_mcp_user( [ 'content:read', 'content:write' ] );
		$response = $this->mcp_call(
			'tools/call',
			[
				'name'      => McpNameSanitizer::sanitize_name( 'md3-wp-mcp/create-content' ),
				'arguments' => [ 'title' => 'Via MCP', 'content' => '<p>Hello</p>' ],
			]
		);
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'result', $data, wp_json_encode( $data ) );
		$this->assertEmpty( $data['result']['isError'] ?? false, wp_json_encode( $data ) );

		$posts = get_posts( [ 'title' => 'Via MCP', 'post_status' => 'draft' ] );
		$this->assertCount( 1, $posts );
	}

	public function test_denied_tools_call_is_an_mcp_error_not_a_fatal() {
		$id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$this->as_mcp_user( [ 'content:read', 'content:write' ] );
		$response = $this->mcp_call(
			'tools/call',
			[
				'name'      => McpNameSanitizer::sanitize_name( 'md3-wp-mcp/publish-content' ),
				'arguments' => [ 'id' => $id ],
			]
		);
		$data = $response->get_data();
		$this->assertTrue(
			isset( $data['error'] ) || true === ( $data['result']['isError'] ?? false ),
			wp_json_encode( $data )
		);
		$this->assertSame( 'draft', get_post_status( $id ) );
	}

	public function test_server_uses_the_quiet_error_handler() {
		$server = McpAdapter::instance()->get_server( Server::ID );
		$this->assertNotNull( $server );
		$this->assertInstanceOf( Error_Handler::class, $server->get_error_handler() );
	}

	public function test_error_handler_drops_expected_refusals_and_forwards_the_rest() {
		$log      = wp_tempnam( 'md3-mcp-error-log' );
		$previous = ini_set( 'error_log', $log );

		$handler = new Error_Handler();
		$handler->log( 'Permission callback returned WP_Error: Authentication is required.', [ 'HttpTransport::check_permission' ] );
		$handler->log(
			'Tool permission check failed',
			[
				'tool_name'      => 'md3-wp-mcp-publish-content',
				'error_code'     => 'md3_mcp_insufficient_scope',
				'failure_reason' => FailureReason::PERMISSION_CHECK_FAILED,
			]
		);
		$handler->log( 'Error in transport permission callback: boom', [ 'HttpTransport::check_permission' ] );

		ini_set( 'error_log', (string) $previous );
		$written = (string) file_get_contents( $log );
		unlink( $log );

		$this->assertStringNotContainsString( 'Permission callback returned WP_Error', $written );
		$this->assertStringNotContainsString( 'Tool permission check failed', $written );
		$this->assertStringContainsString( '[ERROR] Error in transport permission callback: boom', $written );
	}

	public function test_default_server_does_not_list_our_tools() {
		$this->as_mcp_user( Scopes::all() );

		foreach ( Registry::names() as $name ) {
			$this->assertFalse( McpAbilityExposure::is_public( wp_get_ability( $name ) ), $name );
		}

		$default = \WP\MCP\Core\McpAdapter::instance()->get_server( 'mcp-adapter-default-server' );
		if ( null === $default ) {
			$this->markTestSkipped( 'The adapter default server is not enabled.' );
		}
		$response = $this->mcp_call(
			'tools/call',
			[ 'name' => 'mcp-adapter-discover-abilities', 'arguments' => new stdClass() ],
			'/mcp/mcp-adapter-default-server'
		);
		$this->assertStringNotContainsString( 'md3-wp-mcp/', wp_json_encode( $response->get_data() ) );
	}
}
