<?php
use MD3\WPMCP\Admin\Diagnostics;
use MD3\WPMCP\Admin\Profile_Connections;
use MD3\WPMCP\Admin\Settings_Page;
use MD3\WPMCP\Audit_Log;
use MD3\WPMCP\Cleanup;
use MD3\WPMCP\OAuth\Keys;
use MD3\WPMCP\OAuth\Tokens;
use MD3\WPMCP\Schema;
use MD3\WPMCP\Settings;
use MD3\WPMCP\Tests\Test_Case;

/**
 * Thrown by the wp_redirect filter so a handler's redirect-and-exit can be observed.
 */
class Md3_Admin_Redirect extends Exception {}

class Test_Admin extends Test_Case {

	/**
	 * The MCP adapter initializes once per process on the first `rest_api_init`
	 * (with a deprecation notice for the bundled copy). Trigger it outside any test.
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();
		rest_get_server();
	}

	public function set_up() {
		parent::set_up();
		add_filter(
			'wp_redirect',
			static function ( $location ) {
				throw new Md3_Admin_Redirect( (string) $location );
			}
		);
	}

	/**
	 * Calls an admin-post handler and returns the redirect location.
	 *
	 * @param array<string,mixed> $post Submitted fields.
	 */
	private function submit( string $action, array $post, ?string $nonce_action = null ): string {
		$_POST                   = $post;
		$_POST['_wpnonce']       = wp_create_nonce( $nonce_action ?? $action );
		$_REQUEST                = $_POST;
		$_SERVER['REQUEST_METHOD'] = 'POST';
		try {
			Settings_Page::dispatch( $action );
		} catch ( Md3_Admin_Redirect $redirect ) {
			return $redirect->getMessage();
		}
		$this->fail( 'The handler did not redirect.' );
	}

	private function as_admin(): int {
		$id = (int) self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $id );
		return $id;
	}

	public function test_save_content_requires_manage_options_and_nonce() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$_POST = $_REQUEST = [ 'post_types' => [ 'page' ], '_wpnonce' => wp_create_nonce( 'md3_mcp_save_content' ) ];
		try {
			Settings_Page::dispatch( 'md3_mcp_save_content' );
			$this->fail( 'An editor must be refused.' );
		} catch ( WPDieException $e ) {
			$this->assertSame( [ 'post', 'page' ], Settings::post_types() );
		}

		$this->as_admin();
		$_POST = $_REQUEST = [ 'post_types' => [ 'page' ], '_wpnonce' => 'bad' ];
		try {
			Settings_Page::dispatch( 'md3_mcp_save_content' );
			$this->fail( 'A bad nonce must be refused.' );
		} catch ( WPDieException $e ) {
			$this->assertSame( [ 'post', 'page' ], Settings::post_types() );
		}

		$location = $this->submit( 'md3_mcp_save_content', [ 'post_types' => [ 'page' ] ] );
		$this->assertSame( [ 'page' ], Settings::post_types() );
		$this->assertStringContainsString( 'page=md3-wp-mcp', $location );
		$this->assertStringContainsString( 'tab=content', $location );
	}

	public function test_save_content_stores_default_author() {
		$this->as_admin();
		$author = self::factory()->user->create( [ 'role' => 'author' ] );
		$this->submit( 'md3_mcp_save_content', [ 'post_types' => [ 'post' ], 'default_author' => $author ] );
		$this->assertSame( $author, (int) get_option( 'md3_wp_mcp_default_author' ) );

		$subscriber = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$this->submit( 'md3_mcp_save_content', [ 'post_types' => [ 'post' ], 'default_author' => $subscriber ] );
		$this->assertSame( 0, (int) get_option( 'md3_wp_mcp_default_author' ) );
	}

	public function test_save_security_ignores_non_editor_mcp_user() {
		$this->as_admin();
		$editor = self::factory()->user->create( [ 'role' => 'editor' ] );
		$this->submit( 'md3_mcp_save_security', [ 'mcp_user_id' => $editor, 'dcr' => '1', 'mcp_rate_limit' => '50' ] );
		$this->assertSame( [ 'mcp_user_id' => $editor, 'dcr' => true, 'mcp_rate_limit' => 50 ], Settings::security() );

		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$this->submit( 'md3_mcp_save_security', [ 'mcp_user_id' => $admin, 'mcp_rate_limit' => '5000' ] );
		$this->assertSame( [ 'mcp_user_id' => 0, 'dcr' => false, 'mcp_rate_limit' => 1000 ], Settings::security() );
	}

	public function test_changing_the_mcp_user_revokes_every_connection() {
		$this->as_admin();
		$this->issue_token();
		$old = Settings::mcp_user_id();

		// Saving the same MCP user keeps the connections.
		$this->submit( 'md3_mcp_save_security', [ 'mcp_user_id' => $old, 'dcr' => '1', 'mcp_rate_limit' => '120' ] );
		$this->assertCount( 1, Tokens::connections() );

		$new = self::factory()->user->create( [ 'role' => 'editor' ] );
		$this->submit( 'md3_mcp_save_security', [ 'mcp_user_id' => $new, 'dcr' => '1', 'mcp_rate_limit' => '120' ] );
		$this->assertSame( $new, Settings::mcp_user_id() );
		$this->assertSame( [], Tokens::connections() );
	}

	public function test_rotate_keys_revokes_every_connection() {
		$this->as_admin();
		$this->issue_token();
		$this->issue_token();
		$this->assertCount( 2, Tokens::connections() );

		$this->submit( 'md3_mcp_rotate_keys', [] );
		$this->assertSame( [], Tokens::connections() );
	}

	public function test_revoke_all_and_single_revoke() {
		$this->as_admin();
		$a = $this->issue_token();
		$this->issue_token();
		$user_id = Settings::mcp_user_id();

		$this->submit( 'md3_mcp_revoke', [ 'client_id' => $a['client_id'], 'user_id' => $user_id ] );
		$this->assertCount( 1, Tokens::connections() );

		$this->submit( 'md3_mcp_revoke_all', [] );
		$this->assertSame( [], Tokens::connections() );
	}

	public function test_mcp_user_may_revoke_own_connection_only() {
		$token   = $this->issue_token();
		$mcp     = Settings::mcp_user_id();
		$other   = $this->issue_token();
		wp_set_current_user( $mcp );

		$this->submit( 'md3_mcp_revoke', [ 'client_id' => $token['client_id'], 'user_id' => $mcp ] );
		$this->assertCount( 1, Tokens::connections() );

		// Another user's ID is refused for a non-admin.
		$_POST = $_REQUEST = [ 'client_id' => $other['client_id'], 'user_id' => $mcp + 1, '_wpnonce' => wp_create_nonce( 'md3_mcp_revoke' ) ];
		$this->expectException( WPDieException::class );
		Settings_Page::dispatch( 'md3_mcp_revoke' );
	}

	public function test_profile_connections_only_for_the_mcp_user() {
		$this->issue_token();
		$mcp = Settings::mcp_user_id();

		ob_start();
		Profile_Connections::render( get_userdata( $mcp ) );
		$this->assertStringContainsString( 'md3_mcp_revoke', ob_get_clean() );

		ob_start();
		Profile_Connections::render( get_userdata( self::factory()->user->create( [ 'role' => 'editor' ] ) ) );
		$this->assertSame( '', ob_get_clean() );
	}

	public function test_missing_mcp_user_notice_and_invalid_author_warning() {
		$admin = $this->as_admin();
		ob_start();
		Settings_Page::render_page( 'content' );
		$this->assertStringContainsString( 'notice-warning', ob_get_clean() );

		$editor = self::factory()->user->create( [ 'role' => 'editor' ] );
		Settings::update_security( [ 'mcp_user_id' => $editor ] );
		update_option( Settings::OPT_DEFAULT_AUTHOR, 999999 );
		ob_start();
		Settings_Page::render_page( 'content' );
		$html = ob_get_clean();
		$this->assertStringContainsString( 'falls back to the MCP user', $html );
		foreach ( [ 'content', 'security', 'connections', 'log', 'connect' ] as $tab ) {
			ob_start();
			Settings_Page::render_page( $tab );
			$this->assertNotSame( '', ob_get_clean(), $tab );
		}
		$this->assertGreaterThan( 0, $admin );
	}

	public function test_cleanup_prunes_expired_tokens_and_old_log() {
		global $wpdb;
		$this->issue_token();
		$wpdb->query( 'UPDATE ' . Schema::table( 'access_tokens' ) . " SET expires_at = '2000-01-01 00:00:00'" );
		$wpdb->query( 'UPDATE ' . Schema::table( 'refresh_tokens' ) . " SET expires_at = '2000-01-01 00:00:00'" );
		Audit_Log::record( 'old', 'ok' );
		$wpdb->query( 'UPDATE ' . Schema::table( 'log' ) . " SET created_at = '2000-01-01 00:00:00'" );
		Audit_Log::record( 'new', 'ok' );

		Cleanup::run();

		$this->assertSame( 0, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( 'access_tokens' ) ) );
		$this->assertSame( 1, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( 'log' ) ) );
		$this->assertNotFalse( has_action( 'md3_wp_mcp_cleanup', [ Cleanup::class, 'run' ] ) );
	}

	public function test_diagnostics_reports_each_check() {
		$calls = [];
		add_filter(
			'pre_http_request',
			static function ( $pre, $args, $url ) use ( &$calls ) {
				$calls[] = [ $args['method'] ?? 'GET', $url ];
				if ( str_contains( $url, 'oauth-protected-resource' ) ) {
					return [ 'response' => [ 'code' => 200 ], 'headers' => [ 'content-type' => 'application/json', 'cache-control' => 'no-store' ], 'body' => '{}' ];
				}
				if ( str_contains( $url, 'oauth-authorization-server' ) ) {
					return [ 'response' => [ 'code' => 200 ], 'headers' => [], 'body' => '{}' ];
				}
				if ( 'POST' === ( $args['method'] ?? '' ) ) {
					return [ 'response' => [ 'code' => 401 ], 'headers' => [ 'www-authenticate' => 'Bearer resource_metadata="x"' ], 'body' => '' ];
				}
				if ( str_contains( $url, 'diagnostics/auth-echo' ) ) {
					return [ 'response' => [ 'code' => 200 ], 'headers' => [], 'body' => '{"received":true}' ];
				}
				return [ 'response' => [ 'code' => 302 ], 'headers' => [], 'body' => '' ]; // Authorize: a redirect, so a failure.
			},
			10,
			3
		);

		$rows = Diagnostics::run();

		$this->assertCount( 6, $rows );
		foreach ( $rows as $row ) {
			$this->assertIsBool( $row['ok'] );
			$this->assertNotSame( '', $row['check'] );
			$this->assertIsString( $row['detail'] );
		}
		$this->assertSame( 'OAuth keys load', $rows[0]['check'] );
		$this->assertSame( [ true, true, true, true, true, false ], array_column( $rows, 'ok' ) );
		$this->assertCount( 5, $calls );

		// Unreadable keys fail the first check.
		$stored = get_option( Keys::OPT_PRIVATE );
		update_option( Keys::OPT_PRIVATE, 'not a key', false );
		$rows = Diagnostics::run();
		update_option( Keys::OPT_PRIVATE, $stored, false );
		$this->assertFalse( $rows[0]['ok'] );
		$this->assertStringContainsString( 'not a readable RSA key', $rows[0]['detail'] );
	}

	public function test_auth_echo_route_compares_the_bearer_to_the_nonce() {
		set_transient( 'md3_mcp_diag', 'secret-nonce', 60 );

		$request = new WP_REST_Request( 'GET', '/md3-wp-mcp/v1/diagnostics/auth-echo' );
		$request->set_header( 'Authorization', 'Bearer secret-nonce' );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [ 'received' => true ], $response->get_data() );

		foreach ( [ 'Bearer wrong', '' ] as $header ) {
			$request = new WP_REST_Request( 'GET', '/md3-wp-mcp/v1/diagnostics/auth-echo' );
			if ( '' !== $header ) {
				$request->set_header( 'Authorization', $header );
			}
			$this->assertGreaterThanOrEqual( 401, rest_get_server()->dispatch( $request )->get_status() );
		}

		delete_transient( 'md3_mcp_diag' );
		$request = new WP_REST_Request( 'GET', '/md3-wp-mcp/v1/diagnostics/auth-echo' );
		$request->set_header( 'Authorization', 'Bearer ' );
		$this->assertGreaterThanOrEqual( 401, rest_get_server()->dispatch( $request )->get_status() );
	}

	public function test_uninstall_removes_tables_and_options() {
		global $wpdb;
		update_option( 'md3_wp_mcp_security', [ 'x' => 1 ] );
		update_option( 'md3_wp_mcp_private_key', 'k', false );
		set_transient( 'md3_mcp_rl_x', 1, 60 );
		set_transient( 'unrelated_transient', 1, 60 );
		wp_schedule_event( time() + 60, 'daily', 'md3_wp_mcp_cleanup' );

		// Dropping real tables would break every later test: record the DROP statements and neutralize them.
		$dropped = [];
		add_filter(
			'query',
			static function ( $query ) use ( &$dropped ) {
				if ( str_starts_with( ltrim( $query ), 'DROP TABLE' ) ) {
					$dropped[] = $query;
				}
				return $query;
			},
			1
		);
		add_filter( 'query', static fn ( $query ) => str_contains( $query, 'DROP' ) ? 'SELECT 1' : $query, 99 );

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'md3-wp-mcp/md3-wp-mcp.php' );
		}
		include dirname( __DIR__, 2 ) . '/uninstall.php';

		foreach ( [ 'clients', 'auth_codes', 'access_tokens', 'refresh_tokens', 'log' ] as $t ) {
			$this->assertContains( 'DROP TABLE IF EXISTS `' . Schema::table( $t ) . '`', $dropped, $t );
		}
		$this->assertCount( 5, $dropped );
		$this->assertFalse( get_option( 'md3_wp_mcp_security' ) );
		$this->assertFalse( get_option( 'md3_wp_mcp_private_key' ) );
		$this->assertFalse( get_transient( 'md3_mcp_rl_x' ) );
		$this->assertSame( 1, (int) get_transient( 'unrelated_transient' ) );
		$this->assertFalse( wp_next_scheduled( 'md3_wp_mcp_cleanup' ) );
		$this->assertNotNull( get_role( 'editor' ) );
	}
}
