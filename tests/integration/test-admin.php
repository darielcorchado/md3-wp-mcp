<?php
use MD3\WPMCP\Admin\Diagnostics;
use MD3\WPMCP\Admin\Profile_Connections;
use MD3\WPMCP\Admin\Settings_Page;
use MD3\WPMCP\Audit_Log;
use MD3\WPMCP\Cleanup;
use MD3\WPMCP\OAuth\Discovery;
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

	public function test_security_actions_refuse_non_administrators() {
		$this->issue_token();
		$mcp      = Settings::mcp_user_id();
		$security = Settings::security();
		$stored   = get_option( Keys::OPT_PRIVATE );

		foreach ( [ 'editor', 'author' ] as $role ) {
			wp_set_current_user( self::factory()->user->create( [ 'role' => $role ] ) );

			foreach ( [ 'md3_mcp_revoke_all', 'md3_mcp_rotate_keys', 'md3_mcp_save_security' ] as $action ) {
				$_POST = $_REQUEST = [ 'mcp_user_id' => 0, 'dcr' => '', 'mcp_rate_limit' => 1, '_wpnonce' => wp_create_nonce( $action ) ];
				try {
					Settings_Page::dispatch( $action );
					$this->fail( $role . ' must be refused ' . $action );
				} catch ( WPDieException $e ) {
					$this->assertStringContainsString( 'not allowed', $e->getMessage(), $action );
				}
			}
		}

		// Nothing changed.
		$this->assertCount( 1, Tokens::connections() );
		$this->assertSame( $security, Settings::security() );
		$this->assertSame( $mcp, Settings::mcp_user_id() );
		$this->assertSame( $stored, get_option( Keys::OPT_PRIVATE ) );
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

		$location = $this->submit( 'md3_mcp_rotate_keys', [] );
		$this->assertSame( [], Tokens::connections() );
		$this->assertStringContainsString( 'md3_notice=rotated&', $location . '&' );
	}

	public function test_rotating_pinned_keys_says_they_were_not_changed() {
		// Keys from wp-config.php: Keys::pinned() is true and rotate_keys() redirects with this notice.
		$this->assertFalse( Keys::pinned() );
		$this->assertSame(
			'Keys are set in wp-config.php and were not changed; every connection was revoked.',
			Settings_Page::notice_messages()['rotated_pinned'] ?? null
		);
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

	/**
	 * Gives an issued connection a CIMD-style client ID with percent-encoding and `&`.
	 */
	private function rename_client( string $from, string $to ): void {
		global $wpdb;
		foreach ( [ 'clients', 'auth_codes', 'access_tokens', 'refresh_tokens' ] as $table ) {
			$wpdb->update( Schema::table( $table ), [ 'client_id' => $to ], [ 'client_id' => $from ] );
		}
	}

	public function test_revoke_from_connections_tab_keeps_odd_client_ids_intact() {
		$this->as_admin();
		$a  = $this->issue_token();
		$id = 'https://app.example.com/oauth/client%2Fmeta.json?a=1&b=%20x';
		$this->rename_client( $a['client_id'], $id );
		$this->issue_token();

		$location = $this->submit( 'md3_mcp_revoke', [ 'client_id' => $id, 'user_id' => Settings::mcp_user_id() ] );
		$this->assertStringContainsString( 'md3_notice=revoked', $location );
		$this->assertCount( 1, Tokens::connections() );
		$this->assertNotSame( $id, Tokens::connections()[0]['client_id'] );
	}

	public function test_revoke_that_matches_nothing_says_so() {
		$this->as_admin();
		$this->issue_token();

		$location = $this->submit( 'md3_mcp_revoke', [ 'client_id' => 'no-such-client', 'user_id' => Settings::mcp_user_id() ] );
		$this->assertStringContainsString( 'md3_notice=not_revoked', $location );
		$this->assertCount( 1, Tokens::connections() );
		$this->assertArrayHasKey( 'not_revoked', Settings_Page::notice_messages() );
	}

	public function test_profile_revoke_link_round_trips_odd_client_ids() {
		$a  = $this->issue_token();
		$id = 'https://app.example.com/oauth/client%2Fmeta.json?a=1&b=%20x';
		$this->rename_client( $a['client_id'], $id );
		$mcp = Settings::mcp_user_id();
		wp_set_current_user( $mcp );

		ob_start();
		Profile_Connections::render( get_userdata( $mcp ) );
		$html = (string) ob_get_clean();
		$this->assertSame( 1, preg_match( '/href="([^"]*md3_mcp_revoke[^"]*)"/', $html, $m ) );
		parse_str( (string) wp_parse_url( html_entity_decode( $m[1] ), PHP_URL_QUERY ), $query );
		$this->assertSame( $id, $query['client_id'] );
		$this->assertSame( 'profile', $query['return'] );

		// Follow the link: PHP decodes the query string into $_GET / $_REQUEST.
		$_GET     = $query;
		$_REQUEST = $query;
		try {
			Settings_Page::dispatch( 'md3_mcp_revoke' );
			$this->fail( 'The handler did not redirect.' );
		} catch ( Md3_Admin_Redirect $redirect ) {
			$this->assertStringContainsString( 'md3_notice=revoked', $redirect->getMessage() );
		}
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

	/**
	 * The content a discovery URL should serve.
	 */
	private static function expected_document( string $url ): string {
		return Discovery::static_files()[ (string) array_search( $url, Discovery::static_file_urls(), true ) ];
	}

	/**
	 * Stubs the loopback requests: `$discovery( $url )` answers the two discovery
	 * documents; the MCP endpoint, the auth echo and the authorize page pass.
	 */
	private function stub_loopback( callable $discovery ): void {
		add_filter(
			'pre_http_request',
			static function ( $pre, $args, $url ) use ( $discovery ) {
				if ( str_contains( $url, '/.well-known/' ) ) {
					return $discovery( $url );
				}
				if ( 'POST' === ( $args['method'] ?? '' ) ) {
					return [ 'response' => [ 'code' => 401 ], 'headers' => [ 'www-authenticate' => 'Bearer resource_metadata="x"' ], 'body' => '' ];
				}
				if ( str_contains( $url, 'diagnostics/auth-echo' ) ) {
					return [ 'response' => [ 'code' => 200 ], 'headers' => [], 'body' => '{"received":true}' ];
				}
				return [ 'response' => [ 'code' => 400 ], 'headers' => [], 'body' => '' ];
			},
			10,
			3
		);
	}

	public function test_diagnostics_reports_each_check() {
		$this->set_permalink_structure( '/%postname%/' );
		$calls = [];
		add_filter(
			'pre_http_request',
			static function ( $pre, $args, $url ) use ( &$calls ) {
				$calls[] = [ $args['method'] ?? 'GET', $url ];
				if ( str_contains( $url, 'oauth-protected-resource' ) ) {
					return [ 'response' => [ 'code' => 200 ], 'headers' => [ 'content-type' => 'application/json', 'cache-control' => 'no-store' ], 'body' => self::expected_document( $url ) ];
				}
				if ( str_contains( $url, 'oauth-authorization-server' ) ) {
					return [ 'response' => [ 'code' => 200 ], 'headers' => [ 'content-type' => 'application/json', 'cache-control' => 'no-store' ], 'body' => self::expected_document( $url ) ];
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
		$this->assertSame( [ '', '', '', '', '', '' ], array_column( $rows, 'fix' ) );
		$this->assertStringContainsString( 'served by WordPress', $rows[1]['detail'] );
		$this->assertCount( 5, $calls );

		// Unreadable keys fail the first check.
		$stored = get_option( Keys::OPT_PRIVATE );
		update_option( Keys::OPT_PRIVATE, 'not a key', false );
		$rows = Diagnostics::run();
		update_option( Keys::OPT_PRIVATE, $stored, false );
		$this->assertFalse( $rows[0]['ok'] );
		$this->assertStringContainsString( 'not a readable RSA key', $rows[0]['detail'] );
	}

	public function test_discovery_404_offers_the_files() {
		$this->set_permalink_structure( '/%postname%/' );
		$this->stub_loopback( static fn () => [ 'response' => [ 'code' => 404 ], 'headers' => [ 'content-type' => 'text/html' ], 'body' => '<html>Not Found</html>' ] );

		$rows = Diagnostics::run();

		$this->assertSame( [ true, false, false, true, true, true ], array_column( $rows, 'ok' ) );
		$this->assertSame( [ '', 'missing', 'missing', '', '', '' ], array_column( $rows, 'fix' ) );
		$this->assertStringContainsString( 'HTTP 404', $rows[1]['detail'] );
		$this->assertSame( 'missing', Diagnostics::fix( $rows ) );
	}

	public function test_discovery_404_without_oauth_offers_nothing() {
		$row = Diagnostics::judge_document( 'Check', [ 'response' => [ 'code' => 404 ], 'headers' => [], 'body' => '' ], '{}', false );
		$this->assertFalse( $row['ok'] );
		$this->assertSame( '', $row['fix'] );
		$this->assertStringContainsString( 'HTTPS', $row['detail'] );
		$this->assertStringNotContainsString( 'web server', $row['detail'] );
	}

	public function test_static_file_with_other_headers_passes() {
		$this->set_permalink_structure( '/%postname%/' );
		$this->stub_loopback(
			static fn ( $url ) => [
				'response' => [ 'code' => 200 ],
				'headers'  => [ 'content-type' => 'application/octet-stream', 'cache-control' => 'public, no-cache' ],
				'body'     => str_replace( "\n", "\r\n", self::expected_document( $url ) ),
			]
		);

		$rows = Diagnostics::run();

		$this->assertSame( [ true, true, true, true, true, true ], array_column( $rows, 'ok' ) );
		$this->assertStringContainsString( 'static file', $rows[1]['detail'] );
		$this->assertStringContainsString( 'application/octet-stream', $rows[1]['detail'] );
		$this->assertSame( '', Diagnostics::fix( $rows ) );
	}

	public function test_differing_content_is_stale() {
		$this->set_permalink_structure( '/%postname%/' );
		$this->stub_loopback(
			static function ( $url ) {
				$document = json_decode( self::expected_document( $url ), true );
				if ( isset( $document['issuer'] ) ) {
					$document['issuer'] = 'https://old.example.com/md3-mcp';
				}
				return [ 'response' => [ 'code' => 200 ], 'headers' => [], 'body' => (string) wp_json_encode( $document ) ];
			}
		);

		$rows = Diagnostics::run();

		$this->assertSame( [ true, true, false, true, true, true ], array_column( $rows, 'ok' ) );
		$this->assertSame( 'stale', $rows[2]['fix'] );
		$this->assertStringContainsString( 'out of date', $rows[2]['detail'] );
		$this->assertSame( 'stale', Diagnostics::fix( $rows ) );
	}

	public function test_non_json_200_and_other_errors_offer_nothing() {
		$responses = [
			[ 'response' => [ 'code' => 200 ], 'headers' => [ 'content-type' => 'text/html' ], 'body' => '<html>Checking your browser</html>' ],
			[ 'response' => [ 'code' => 403 ], 'headers' => [], 'body' => 'Forbidden' ],
			'cURL error 28: Operation timed out',
		];
		foreach ( $responses as $response ) {
			$row = Diagnostics::judge_document( 'Check', $response, '{"a":1}', true );
			$this->assertFalse( $row['ok'] );
			$this->assertSame( '', $row['fix'] );
		}
		$this->assertStringContainsString( 'not a JSON document', Diagnostics::judge_document( 'Check', $responses[0], '{"a":1}', true )['detail'] );
		$this->assertStringContainsString( 'HTTP 403', Diagnostics::judge_document( 'Check', $responses[1], '{"a":1}', true )['detail'] );
		$this->assertSame( 'cURL error 28: Operation timed out', Diagnostics::judge_document( 'Check', $responses[2], '{"a":1}', true )['detail'] );
	}

	public function test_a_byte_order_mark_still_matches() {
		$row = Diagnostics::judge_document( 'Check', [ 'response' => [ 'code' => 200 ], 'headers' => [], 'body' => "\xEF\xBB\xBF{\"a\":1}" ], '{"a":1}', true );
		$this->assertTrue( $row['ok'] );
	}

	public function test_stale_detail_names_a_cached_copy() {
		$row = Diagnostics::judge_document( 'Check', [ 'response' => [ 'code' => 200 ], 'headers' => [], 'body' => '{"a":2}' ], '{"a":1}', true );
		$this->assertSame( 'stale', $row['fix'] );
		$this->assertStringContainsString( 'cache', $row['detail'] );
		$this->assertStringContainsString( 'plugin', $row['detail'] );
	}

	public function test_no_files_offered_with_plain_permalinks() {
		$this->set_permalink_structure( '' );
		$data = Settings_Page::discovery_files_data( [ self::diag_row( false, 'missing' ) ], true );
		$this->assertFalse( $data['show'] );
		$this->assertSame( '', $data['show_url'] );
	}

	public function test_no_files_offered_while_oauth_is_off() {
		$this->set_permalink_structure( '/%postname%/' );
		$_GET['md3_static'] = '1';
		$data               = Settings_Page::discovery_files_data( [ self::diag_row( false, 'missing' ) ], false );
		unset( $_GET['md3_static'] );
		$this->assertFalse( $data['show'] );
		$this->assertSame( '', $data['show_url'] );

		$this->assertTrue( Settings_Page::discovery_files_data( [ self::diag_row( false, 'missing' ) ], true )['show'] );
	}

	public function test_matching_ignores_key_order_but_not_list_order() {
		$expected = '{"a":1,"b":["x","y"]}';
		$ok       = static fn ( string $body ) => Diagnostics::judge_document( 'Check', [ 'response' => [ 'code' => 200 ], 'headers' => [], 'body' => $body ], $expected, true )['ok'];
		$this->assertTrue( $ok( '{"b":["x","y"],"a":1}' ) );
		$this->assertFalse( $ok( '{"a":1,"b":["y","x"]}' ) );
		$this->assertFalse( $ok( '{"a":"1","b":["x","y"]}' ) );
	}

	/**
	 * Renders the Security tab with these diagnostics results (null: none yet).
	 *
	 * @param array<array<string,mixed>>|null $rows Diagnostics rows.
	 */
	private function render_security( ?array $rows ): string {
		$user = $this->as_admin();
		if ( null !== $rows ) {
			set_transient( 'md3_mcp_diag_results_' . $user, $rows, 600 );
		}
		ob_start();
		Settings_Page::render_page( 'security' );
		return (string) ob_get_clean();
	}

	private static function diag_row( bool $ok, string $fix = '' ): array {
		return [ 'check' => 'Check', 'ok' => $ok, 'detail' => 'Detail', 'fix' => $fix ];
	}

	public function test_files_box_shown_only_when_needed() {
		$this->set_permalink_structure( '/%postname%/' );
		$box  = 'id="md3-discovery-files"';
		$zip  = 'action=md3_mcp_download_discovery&#038;file=zip';
		$urls = array_values( Discovery::static_file_urls() );

		$html = $this->render_security( null );
		$this->assertStringNotContainsString( $box, $html );
		$this->assertStringContainsString( 'md3_static=1', $html );

		$html = $this->render_security( [ self::diag_row( true ), self::diag_row( false, 'missing' ), self::diag_row( false, 'missing' ) ] );
		$this->assertStringContainsString( $box, $html );
		$this->assertStringContainsString( $zip, $html );
		$this->assertStringContainsString( 'file=resource', $html );
		$this->assertStringContainsString( 'file=server', $html );
		$this->assertStringContainsString( '_wpnonce=', $html );
		$this->assertStringContainsString( esc_html( $urls[0] ), $html );
		$this->assertStringContainsString( esc_html( $urls[1] ), $html );
		$this->assertStringContainsString( 'Check again', $html );
		$this->assertStringNotContainsString( 'md3_static=1', $html );

		$passing = [ self::diag_row( true ), self::diag_row( true ), self::diag_row( true ) ];
		$this->assertStringNotContainsString( $box, $this->render_security( $passing ) );

		$_GET['md3_static'] = '1';
		$html               = $this->render_security( $passing );
		unset( $_GET['md3_static'] );
		$this->assertStringContainsString( $box, $html );
		$this->assertStringContainsString( $zip, $html );
	}

	public function test_stale_files_say_so() {
		$this->set_permalink_structure( '/%postname%/' );
		$html = $this->render_security( [ self::diag_row( true ), self::diag_row( false, 'stale' ) ] );
		$this->assertStringContainsString( 'out of date', $html );
		$this->assertStringContainsString( 'purge', $html );
		$this->assertStringContainsString( 'after updating the plugin', $html );
		$this->assertStringContainsString( 'action=md3_mcp_download_discovery', $html );
	}

	public function test_changing_dcr_warns_about_static_files() {
		$this->as_admin();
		Settings::update_security( [ 'dcr' => true ] );

		$location = $this->submit( 'md3_mcp_save_security', [ 'mcp_rate_limit' => '60' ] );
		$this->assertStringContainsString( 'md3_notice=saved_dcr', $location );

		$location = $this->submit( 'md3_mcp_save_security', [ 'mcp_rate_limit' => '60' ] );
		$this->assertStringContainsString( 'md3_notice=saved&', $location . '&' );

		$_GET['md3_notice'] = 'saved_dcr';
		ob_start();
		Settings_Page::render_page( 'security' );
		$html = (string) ob_get_clean();
		unset( $_GET['md3_notice'] );
		$this->assertStringContainsString( 'notice-warning', $html );
		$this->assertStringContainsString( 'download them again', $html );
	}

	public function test_diagnostics_links_the_troubleshooting_guide() {
		$this->assertSame( 'https://github.com/darielcorchado/md3-wp-mcp/blob/master/docs/troubleshooting.md', Diagnostics::GUIDE_URL );
		$html = $this->render_security( null );
		$this->assertStringContainsString( '<a href="' . esc_url( Diagnostics::GUIDE_URL ) . '" target="_blank" rel="noopener noreferrer">troubleshooting guide</a>', $html );
		$this->assertSame( 'https://www.md3digitalsolutions.com/contact/?utm_source=md3-wp-mcp', Diagnostics::CONTACT_URL );
		$this->assertStringContainsString( '<a href="' . esc_url( Diagnostics::CONTACT_URL ) . '" target="_blank" rel="noopener noreferrer">contact the developer</a>', $html );
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
		update_option( 'md3_wp_mcp_post_types', [ 'post' ] );
		update_option( 'md3_wp_mcp_default_author', 3 );
		update_option( 'md3_wp_mcp_db_version', '1' );
		update_option( 'md3_wp_mcp_encryption_key', 'e', false );
		// Other plugins' cache entries survive.
		wp_cache_set( 'kept', 'yes', 'md3_unrelated_group' );

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

		foreach ( [ 'clients', 'auth_codes', 'access_tokens', 'refresh_tokens', 'log', 'uploads' ] as $t ) {
			$this->assertContains( 'DROP TABLE IF EXISTS `' . Schema::table( $t ) . '`', $dropped, $t );
		}
		$this->assertCount( 6, $dropped );
		$this->assertFalse( get_option( 'md3_wp_mcp_security' ) );
		foreach ( [ 'md3_wp_mcp_post_types', 'md3_wp_mcp_default_author', 'md3_wp_mcp_security', 'md3_wp_mcp_db_version', 'md3_wp_mcp_private_key', 'md3_wp_mcp_encryption_key' ] as $option ) {
			$this->assertFalse( get_option( $option ), $option );
		}
		$this->assertSame( 'yes', wp_cache_get( 'kept', 'md3_unrelated_group' ) );
		$this->assertFalse( get_transient( 'md3_mcp_rl_x' ) );
		$this->assertSame( 1, (int) get_transient( 'unrelated_transient' ) );
		$this->assertFalse( wp_next_scheduled( 'md3_wp_mcp_cleanup' ) );
		$this->assertNotNull( get_role( 'editor' ) );
	}
}
