<?php
use League\OAuth2\Server\CryptKey;
use MD3\WPMCP\Auth\Request_Context;
use MD3\WPMCP\OAuth\Bearer_Guard;
use MD3\WPMCP\OAuth\Discovery;
use MD3\WPMCP\OAuth\Entities\Client_Entity;
use MD3\WPMCP\OAuth\Entities\Scope_Entity;
use MD3\WPMCP\OAuth\Keys;
use MD3\WPMCP\OAuth\OAuth;
use MD3\WPMCP\OAuth\Repositories\Access_Token_Repository;
use MD3\WPMCP\OAuth\Tokens;
use MD3\WPMCP\Schema;
use MD3\WPMCP\Settings;
use MD3\WPMCP\Tests\Test_Case;
use WP\MCP\Domain\Utils\McpNameSanitizer;

class Test_Bearer_Guard extends Test_Case {

	const MCP_PATH = '/wp-json/md3-wp-mcp/mcp';

	/**
	 * The MCP adapter initializes once per process on the first
	 * `rest_api_init` (with a deprecation notice for the bundled copy).
	 * Trigger it outside any test.
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();
		rest_get_server();
	}

	public function set_up() {
		parent::set_up();
		// Production sites use pretty permalinks: the MCP URL is /wp-json/md3-wp-mcp/mcp.
		$this->set_permalink_structure( '/%postname%/' );
	}

	// ---------------------------------------------------------------------
	// Header and path helpers.
	// ---------------------------------------------------------------------

	public function test_authorization_header_sources() {
		unset( $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] );
		$this->assertSame( '', Bearer_Guard::authorization_header() );

		$_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = 'Bearer from-redirect';
		$this->assertSame( 'Bearer from-redirect', Bearer_Guard::authorization_header() );

		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer direct';
		$this->assertSame( 'Bearer direct', Bearer_Guard::authorization_header() );
	}

	public function test_no_identity_before_the_rest_route_is_resolved() {
		// WP::init() evaluates the current user before `init`, `wp_loaded` and
		// `parse_request`: the route is not known yet, so no identity, even for
		// a valid token sent to the MCP URL.
		$tokens                        = $this->issue_token();
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $tokens['access_token'];
		$_SERVER['REQUEST_URI']        = self::MCP_PATH;
		$_SERVER['SCRIPT_FILENAME']    = ABSPATH . 'index.php';
		$GLOBALS['wp']->query_vars     = [];

		$this->assertFalse( Bearer_Guard::determine_current_user( false ) );
		$this->assertNull( Request_Context::get() );

		$_SERVER['REQUEST_URI'] = '/?rest_route=/md3-wp-mcp/mcp';
		$_GET                   = [ 'rest_route' => '/md3-wp-mcp/mcp' ];
		$this->assertFalse( Bearer_Guard::determine_current_user( false ) );
		$this->assertNull( Request_Context::get() );
	}

	public function test_no_identity_when_the_resolved_route_is_not_mcp() {
		$tokens                        = $this->issue_token();
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $tokens['access_token'];
		// Even when the URL path is the MCP endpoint: the resolved route decides.
		$_SERVER['REQUEST_URI']     = self::MCP_PATH;
		$_SERVER['SCRIPT_FILENAME'] = ABSPATH . 'index.php';

		foreach ( [ '/wp/v2/posts', '/md3-wp-mcp/mcp-other', '/md3-wp-mcp/mcp/extra', '/md3-wp-mcp/v1/oauth/token', '/', [ '/md3-wp-mcp/mcp' ] ] as $route ) {
			$GLOBALS['wp']->query_vars = [ 'rest_route' => $route ];
			$this->assertFalse( Bearer_Guard::determine_current_user( false ), wp_json_encode( $route ) );
			$this->assertNull( Request_Context::get(), wp_json_encode( $route ) );
		}
	}

	public function test_identity_on_the_mcp_route_in_any_layout() {
		$tokens                        = $this->issue_token();
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $tokens['access_token'];
		// Bedrock / WordPress in its own directory: index.php is not in ABSPATH,
		// and the URL path carries the core subdirectory.
		$_SERVER['SCRIPT_FILENAME'] = '/srv/www/site/web/index.php';
		$_SERVER['REQUEST_URI']     = '/wp/wp-json/md3-wp-mcp/mcp';

		foreach ( [ '/md3-wp-mcp/mcp', '/md3-wp-mcp/mcp/' ] as $route ) {
			Request_Context::clear();
			$GLOBALS['wp']->query_vars = [ 'rest_route' => $route ];
			$this->assertSame( Settings::mcp_user_id(), Bearer_Guard::determine_current_user( false ), $route );
			$this->assertNotNull( Request_Context::get(), $route );
			$this->assertSame( $tokens['client_id'], Request_Context::get()->client_id, $route );
		}
	}

	public function test_identity_appears_only_once_rest_serving_starts() {
		$tokens                        = $this->issue_token();
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $tokens['access_token'];
		$_SERVER['REQUEST_URI']        = self::MCP_PATH;
		$_SERVER['SCRIPT_FILENAME']    = ABSPATH . 'index.php';

		// WP::init(): no route yet. Plugins' `init` handlers see nobody.
		$GLOBALS['wp']->query_vars = [];
		$GLOBALS['current_user']   = null;
		$this->assertSame( 0, get_current_user_id() );
		$this->assertNull( Request_Context::get() );

		// WP::parse_request() resolves the route, then
		// WP_REST_Server::serve_request() clears the cached "nobody".
		$GLOBALS['wp']->query_vars = [ 'rest_route' => '/md3-wp-mcp/mcp' ];
		$GLOBALS['current_user']   = null;
		$this->assertSame( Settings::mcp_user_id(), get_current_user_id() );
		$this->assertNotNull( Request_Context::get() );
	}

	public function test_identity_is_refused_on_internal_requests_to_other_routes() {
		// An identity from our token, then a sub-request to another route
		// (rest_do_request, `_embed`…): the route check refuses it.
		$tokens                        = $this->issue_token( [ 'content:read', 'content:write', 'content:publish' ] );
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $tokens['access_token'];
		$GLOBALS['wp']->query_vars     = [ 'rest_route' => '/md3-wp-mcp/mcp' ];
		$GLOBALS['current_user']       = null;
		$this->assertSame( Settings::mcp_user_id(), get_current_user_id() );

		$request = new WP_REST_Request( 'POST', '/wp/v2/posts' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( [ 'title' => 'Smuggled route', 'status' => 'publish' ] ) );
		$response = rest_do_request( $request );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'md3_mcp_route_forbidden', $response->get_data()['code'] );
		$this->assertCount( 0, get_posts( [ 'title' => 'Smuggled route', 'post_status' => 'any' ] ) );
	}

	public function test_parse_request_hook_is_gone() {
		$this->assertFalse( method_exists( Bearer_Guard::class, 'confine_to_mcp_route' ) );
		$this->assertFalse( method_exists( Bearer_Guard::class, 'is_mcp_path' ) );
	}

	public function test_scope_denial_never_rewrites_an_unrelated_status() {
		$this->as_mcp_user( [ 'content:read' ] );
		Request_Context::deny_scope( 'content:publish' );
		$request = new WP_REST_Request( 'POST', '/md3-wp-mcp/mcp' );

		$server_error = Bearer_Guard::after_callbacks( new WP_REST_Response( null, 500 ), [], $request );
		$this->assertSame( 500, $server_error->get_status() );
		$this->assertArrayNotHasKey( 'WWW-Authenticate', $server_error->get_headers() );

		$limited = Bearer_Guard::after_callbacks( new WP_Error( 'md3_mcp_rate_limited', 'x', [ 'status' => 429 ] ), [], $request );
		$this->assertSame( 429, is_wp_error( $limited ) ? $limited->get_error_data()['status'] : $limited->get_status() );

		$ok = Bearer_Guard::after_callbacks( new WP_REST_Response( [ 'jsonrpc' => '2.0' ], 200 ), [], $request );
		$this->assertSame( 403, $ok->get_status() );
		$this->assertStringStartsWith( 'Bearer error="insufficient_scope"', $ok->get_headers()['WWW-Authenticate'] );
	}

	// ---------------------------------------------------------------------
	// Valid tokens.
	// ---------------------------------------------------------------------

	public function test_valid_token_on_mcp_route_sets_identity() {
		$tokens                        = $this->issue_token( [ 'content:read', 'content:write' ] );
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $tokens['access_token'];

		$response = $this->mcp_request( 'initialize', $this->initialize_params() );

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertNotEmpty( $response->get_headers()['Mcp-Session-Id'] ?? '' );
		$this->assertSame( Settings::mcp_user_id(), get_current_user_id() );

		$identity = Request_Context::get();
		$this->assertNotNull( $identity );
		$this->assertSame( Settings::mcp_user_id(), $identity->user_id );
		// The real client, not the token's audience.
		$this->assertSame( $tokens['client_id'], $identity->client_id );
		$this->assertSame( [ 'content:read', 'content:write' ], $identity->scopes );
		$this->assertSame( hash( 'sha256', $this->jti( $tokens['access_token'] ) ), $identity->token_hash );
		$this->assertArrayNotHasKey( 'WWW-Authenticate', $response->get_headers() );
	}

	public function test_header_via_redirect_http_authorization_is_read() {
		$tokens = $this->issue_token();
		unset( $_SERVER['HTTP_AUTHORIZATION'] );
		$_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = 'Bearer ' . $tokens['access_token'];

		$response = $this->mcp_request( 'initialize', $this->initialize_params() );

		$this->assertSame( 200, $response->get_status() );
		$this->assertNotNull( Request_Context::get() );
		$this->assertSame( $tokens['client_id'], Request_Context::get()->client_id );
	}

	public function test_plain_permalinks_use_the_rest_route_parameter() {
		$this->set_permalink_structure( '' );
		$this->assertStringContainsString( '?rest_route=/md3-wp-mcp/mcp', OAuth::resource_url() );

		$tokens                        = $this->issue_token();
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $tokens['access_token'];

		$this->assertSame( 200, $this->mcp_request( 'initialize', $this->initialize_params() )->get_status() );
		$this->assertSame( $tokens['client_id'], Request_Context::get()->client_id );
	}

	public function test_scheme_is_case_insensitive() {
		$tokens                        = $this->issue_token();
		$_SERVER['HTTP_AUTHORIZATION'] = 'bearer ' . $tokens['access_token'];

		$this->assertSame( 200, $this->mcp_request( 'initialize', $this->initialize_params() )->get_status() );
		$this->assertNotNull( Request_Context::get() );
	}

	public function test_end_to_end_register_consent_token_initialize_tools_call() {
		// Register (DCR) → consent → token exchange, all through the real endpoints.
		$tokens                        = $this->issue_token( [ 'content:read', 'content:write' ] );
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $tokens['access_token'];

		$init = $this->mcp_request( 'initialize', $this->initialize_params() );
		$this->assertSame( 200, $init->get_status() );
		$session = (string) $init->get_headers()['Mcp-Session-Id'];

		$list = $this->mcp_request( 'tools/list', [], $session );
		$this->assertSame( 200, $list->get_status() );
		$this->assertContains( McpNameSanitizer::sanitize_name( 'md3-wp-mcp/create-content' ), wp_list_pluck( $list->get_data()['result']['tools'], 'name' ) );

		$call = $this->mcp_request(
			'tools/call',
			[
				'name'      => McpNameSanitizer::sanitize_name( 'md3-wp-mcp/create-content' ),
				'arguments' => [ 'title' => 'Bearer end to end', 'content' => '<p>Hello</p>' ],
			],
			$session
		);
		$this->assertSame( 200, $call->get_status(), wp_json_encode( $call->get_data() ) );
		$this->assertEmpty( $call->get_data()['result']['isError'] ?? false, wp_json_encode( $call->get_data() ) );

		$posts = get_posts( [ 'title' => 'Bearer end to end', 'post_status' => 'draft' ] );
		$this->assertCount( 1, $posts );
		$this->assertSame( Settings::mcp_user_id(), (int) $posts[0]->post_author );

		// The audit log names the real client.
		global $wpdb;
		$client = $wpdb->get_var( $wpdb->prepare( 'SELECT client_id FROM ' . Schema::table( 'log' ) . ' WHERE tool = %s ORDER BY id DESC LIMIT 1', 'create-content' ) );
		$this->assertSame( $tokens['client_id'], $client );
	}

	public function test_last_used_at_is_updated_at_most_once_per_minute() {
		$tokens                        = $this->issue_token();
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $tokens['access_token'];
		$hash                          = hash( 'sha256', $this->jti( $tokens['access_token'] ) );

		$this->assertNull( $this->last_used_at( $hash ) );

		$this->mcp_request( 'initialize', $this->initialize_params() );
		$first = $this->last_used_at( $hash );
		$this->assertNotNull( $first );
		$this->assertEqualsWithDelta( time(), strtotime( $first . ' UTC' ), 5 );

		// 30 seconds ago: too recent to write again.
		$recent = Tokens::utc( time() - 30 );
		$this->set_last_used_at( $hash, $recent );
		$this->mcp_request( 'initialize', $this->initialize_params() );
		$this->assertSame( $recent, $this->last_used_at( $hash ) );

		// Two minutes ago: refreshed.
		$this->set_last_used_at( $hash, Tokens::utc( time() - 120 ) );
		$this->mcp_request( 'initialize', $this->initialize_params() );
		$this->assertEqualsWithDelta( time(), strtotime( $this->last_used_at( $hash ) . ' UTC' ), 5 );
	}

	// ---------------------------------------------------------------------
	// Missing and invalid tokens.
	// ---------------------------------------------------------------------

	public function test_missing_header_returns_401_with_resource_metadata_challenge() {
		unset( $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] );

		$response = $this->mcp_request( 'initialize', $this->initialize_params() );

		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 'md3_mcp_unauthorized', $response->get_data()['code'] );
		$header = $response->get_headers()['WWW-Authenticate'] ?? '';
		$this->assertSame( 'Bearer resource_metadata="' . home_url( '/.well-known/oauth-protected-resource/wp-json/md3-wp-mcp/mcp' ) . '"', $header );
		$this->assertSame( Discovery::protected_resource_url(), home_url( '/.well-known/oauth-protected-resource/wp-json/md3-wp-mcp/mcp' ) );
	}

	public function test_expired_and_revoked_tokens_return_invalid_token() {
		$repo = new Access_Token_Repository();

		// Revoked.
		$revoked = $this->issue_token()['access_token'];
		$repo->revokeAccessToken( $this->jti( $revoked ) );
		$this->assert_invalid_token( $revoked, 'revoked' );

		// Expired in the database.
		$expired_row = $this->issue_token()['access_token'];
		global $wpdb;
		$wpdb->update( Schema::table( 'access_tokens' ), [ 'expires_at' => Tokens::utc( time() - 10 ) ], [ 'id_hash' => hash( 'sha256', $this->jti( $expired_row ) ) ] );
		$this->assert_invalid_token( $expired_row, 'expired row' );

		// Expired JWT (exp in the past) whose row is still live.
		$this->assert_invalid_token( $this->mint( [ 'exp' => '-1 minute' ] ), 'expired jwt' );

		// The MCP user changed after the token was issued.
		$other_user = $this->issue_token()['access_token'];
		$security   = Settings::security();
		$original   = $security['mcp_user_id'];
		$security['mcp_user_id'] = (int) self::factory()->user->create( [ 'role' => 'editor' ] );
		update_option( Settings::OPT_SECURITY, $security );
		$this->assert_invalid_token( $other_user, 'mcp user changed' );
		$security['mcp_user_id'] = $original;
		update_option( Settings::OPT_SECURITY, $security );
	}

	public function test_wrong_audience_returns_invalid_token() {
		$this->assert_invalid_token( $this->mint( [ 'aud' => 'https://elsewhere.example/wp-json/md3-wp-mcp/mcp' ] ), 'wrong audience' );
	}

	public function test_malformed_and_foreign_tokens_return_invalid_token() {
		$this->issue_token();

		$this->assert_invalid_token( 'not-a-jwt', 'garbage' );
		$this->assert_invalid_token( 'a.b.c', 'three garbage segments' );
		$this->assert_invalid_token( '', 'empty bearer' );

		// Signed with another key.
		$other = openssl_pkey_new( [ 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA ] );
		openssl_pkey_export( $other, $pem );
		$this->assert_invalid_token( $this->mint( [ 'key' => new CryptKey( $pem, null, false ) ] ), 'foreign key' );
	}

	public function test_key_faults_are_logged_without_the_token_and_refused() {
		$tokens = $this->issue_token();
		$log    = wp_tempnam( 'md3-mcp-guard-log' );
		$ini    = ini_set( 'error_log', $log );

		// A token the resource server refuses is expected: nothing is logged.
		$this->assert_invalid_token( 'not-a-jwt', 'garbage' );
		$this->assertSame( '', (string) file_get_contents( $log ) );

		// The key can't be read (a server fault): logged, still refused.
		$stored = get_option( Keys::OPT_PRIVATE );
		update_option( Keys::OPT_PRIVATE, 'not a key', false );
		$this->assert_invalid_token( $tokens['access_token'], 'unreadable key' );
		update_option( Keys::OPT_PRIVATE, $stored, false );

		ini_set( 'error_log', (string) $ini );
		$written = (string) file_get_contents( $log );
		unlink( $log );

		$this->assertStringContainsString( 'MD3 WP MCP: access token validation failed: RuntimeException: ', $written );
		$this->assertStringNotContainsString( $tokens['access_token'], $written );
	}

	public function test_invalid_token_wins_over_other_authentication() {
		// A later method that follows the filter contract (as Application
		// Passwords and cookies do) keeps our error.
		$well_behaved = static fn ( $result ) => $result ?? true;
		add_filter( 'rest_authentication_errors', $well_behaved, 50 );
		$this->assert_invalid_token( 'not-a-jwt', 'later success' );
		remove_filter( 'rest_authentication_errors', $well_behaved, 50 );

		// One that overwrites it still gets no identity: the transport refuses.
		add_filter( 'rest_authentication_errors', '__return_true', 50 );
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer not-a-jwt';
		$response                      = $this->mcp_request( 'initialize', $this->initialize_params() );
		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 0, get_current_user_id() );
		$this->assertStringContainsString( 'error="invalid_token"', $response->get_headers()['WWW-Authenticate'] ?? '' );
	}

	// ---------------------------------------------------------------------
	// The token only works on the MCP route; nothing else works there.
	// ---------------------------------------------------------------------

	public function test_token_is_useless_on_wp_v2_routes() {
		$tokens                        = $this->issue_token( [ 'content:read', 'content:write', 'content:publish' ] );
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $tokens['access_token'];

		$request = new WP_REST_Request( 'POST', '/wp/v2/posts' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( [ 'title' => 'Bearer on wp v2', 'status' => 'publish' ] ) );
		$response = $this->serve( $request );

		$this->assertSame( 401, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( 0, get_current_user_id() );
		$this->assertNull( Request_Context::get() );
		$this->assertCount( 0, get_posts( [ 'title' => 'Bearer on wp v2', 'post_status' => 'any' ] ) );
	}

	public function test_application_password_on_mcp_route_is_rejected() {
		$user_id                 = (int) self::factory()->user->create( [ 'role' => 'editor' ] );
		$security                = Settings::security();
		$security['mcp_user_id'] = $user_id;
		update_option( Settings::OPT_SECURITY, $security );

		list( $password ) = WP_Application_Passwords::create_new_application_password( $user_id, [ 'name' => 'phpunit' ] );
		add_filter( 'application_password_is_api_request', '__return_true' );

		$login                         = get_userdata( $user_id )->user_login;
		$_SERVER['PHP_AUTH_USER']      = $login;
		$_SERVER['PHP_AUTH_PW']        = $password;
		$_SERVER['HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode( $login . ':' . $password );

		$response = $this->mcp_request( 'initialize', $this->initialize_params() );

		// The Application Password did authenticate the user…
		$this->assertSame( $user_id, get_current_user_id() );
		// …but the MCP route only takes our access token.
		$this->assertNull( Request_Context::get() );
		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 'md3_mcp_unauthorized', $response->get_data()['code'] );
		$this->assertStringStartsWith( 'Bearer resource_metadata="', $response->get_headers()['WWW-Authenticate'] ?? '' );
	}

	public function test_bearer_is_ignored_when_a_user_is_already_determined() {
		$tokens                        = $this->issue_token();
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $tokens['access_token'];
		$cookie_user                   = (int) self::factory()->user->create( [ 'role' => 'editor' ] );
		add_filter( 'determine_current_user', static fn () => $cookie_user, 10 );

		$response = $this->mcp_request( 'initialize', $this->initialize_params() );

		$this->assertNull( Request_Context::get() );
		$this->assertSame( 401, $response->get_status() );
	}

	public function test_request_context_is_reset_on_every_request() {
		$tokens                        = $this->issue_token();
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $tokens['access_token'];
		$this->assertSame( 200, $this->mcp_request( 'initialize', $this->initialize_params() )->get_status() );
		$this->assertNotNull( Request_Context::get() );

		// Next request on the same worker: no header.
		unset( $_SERVER['HTTP_AUTHORIZATION'] );
		$response = $this->mcp_request( 'initialize', $this->initialize_params() );
		$this->assertNull( Request_Context::get() );
		$this->assertSame( 401, $response->get_status() );

		// Next request: the bearer header, but on another path.
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $tokens['access_token'];
		$this->serve( new WP_REST_Request( 'GET', '/wp/v2/types' ) );
		$this->assertNull( Request_Context::get() );
		$this->assertSame( 0, get_current_user_id() );
	}

	// ---------------------------------------------------------------------
	// Scope challenge.
	// ---------------------------------------------------------------------

	public function test_insufficient_scope_challenge_names_scope() {
		$id                            = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$tokens                        = $this->issue_token( [ 'content:read', 'content:write' ] );
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $tokens['access_token'];

		$init    = $this->mcp_request( 'initialize', $this->initialize_params() );
		$session = (string) $init->get_headers()['Mcp-Session-Id'];

		$response = $this->mcp_request(
			'tools/call',
			[
				'name'      => McpNameSanitizer::sanitize_name( 'md3-wp-mcp/publish-content' ),
				'arguments' => [ 'id' => $id ],
			],
			$session
		);

		$this->assertSame( 403, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$header = $response->get_headers()['WWW-Authenticate'] ?? '';
		$this->assertStringStartsWith( 'Bearer error="insufficient_scope", scope="', $header );
		$this->assertMatchesRegularExpression( '/scope="[^"]*\bcontent:publish\b[^"]*"/', $header );
		// The scopes already granted are kept, so a step-up doesn't lose them.
		$this->assertStringContainsString( 'scope="content:read content:write content:publish"', $header );
		$this->assertStringContainsString( 'resource_metadata="' . Discovery::protected_resource_url() . '"', $header );
		// The JSON-RPC body still tells the client what happened.
		$this->assertTrue( $response->get_data()['result']['isError'] ?? false, wp_json_encode( $response->get_data() ) );
		$this->assertSame( 'draft', get_post_status( $id ) );
	}

	public function test_editing_live_content_without_publish_scope_asks_for_step_up() {
		$id                            = self::factory()->post->create( [ 'post_status' => 'publish', 'post_title' => 'Live post' ] );
		$tokens                        = $this->issue_token( [ 'content:read', 'content:write' ] );
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $tokens['access_token'];

		$session = (string) $this->mcp_request( 'initialize', $this->initialize_params() )->get_headers()['Mcp-Session-Id'];

		$calls = [
			'md3-wp-mcp/update-content'     => [ 'id' => $id, 'title' => 'Changed' ],
			'md3-wp-mcp/set-featured-image' => [ 'id' => $id, 'media_id' => 999999 ],
		];
		foreach ( $calls as $tool => $arguments ) {
			$response = $this->mcp_request( 'tools/call', [ 'name' => McpNameSanitizer::sanitize_name( $tool ), 'arguments' => $arguments ], $session );

			$this->assertSame( 403, $response->get_status(), $tool . ' ' . wp_json_encode( $response->get_data() ) );
			$header = $response->get_headers()['WWW-Authenticate'] ?? '';
			$this->assertStringStartsWith( 'Bearer error="insufficient_scope", scope="content:read content:write content:publish"', $header, $tool );
		}
		$this->assertSame( 'Live post', get_post( $id )->post_title );
	}

	public function test_allowed_tools_call_has_no_scope_challenge() {
		$tokens                        = $this->issue_token( [ 'content:read', 'content:write' ] );
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $tokens['access_token'];

		$init     = $this->mcp_request( 'initialize', $this->initialize_params() );
		$response = $this->mcp_request(
			'tools/call',
			[
				'name'      => McpNameSanitizer::sanitize_name( 'md3-wp-mcp/list-post-types' ),
				'arguments' => new stdClass(),
			],
			(string) $init->get_headers()['Mcp-Session-Id']
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayNotHasKey( 'WWW-Authenticate', $response->get_headers() );
	}

	// ---------------------------------------------------------------------
	// Helpers.
	// ---------------------------------------------------------------------

	private function assert_invalid_token( string $token, string $label ): void {
		$_SERVER['HTTP_AUTHORIZATION'] = rtrim( 'Bearer ' . $token );

		$response = $this->mcp_request( 'initialize', $this->initialize_params() );

		$this->assertSame( 401, $response->get_status(), $label );
		$this->assertSame( 'md3_mcp_invalid_token', $response->get_data()['code'], $label );
		$this->assertSame(
			'Bearer resource_metadata="' . Discovery::protected_resource_url() . '", error="invalid_token"',
			$response->get_headers()['WWW-Authenticate'] ?? '',
			$label
		);
		$this->assertSame( 0, get_current_user_id(), $label );
		$this->assertNull( Request_Context::get(), $label );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function initialize_params(): array {
		return [
			'protocolVersion' => '2025-11-25',
			'capabilities'    => new stdClass(),
			'clientInfo'      => [ 'name' => 'phpunit', 'version' => '1' ],
		];
	}

	/**
	 * A JSON-RPC request to the MCP route, served like a real HTTP request.
	 *
	 * @param array<string,mixed> $params Method params.
	 */
	private function mcp_request( string $method, array $params, string $session = '' ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/md3-wp-mcp/mcp' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'Accept', 'application/json, text/event-stream' );
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

		return $this->serve( $request );
	}

	/**
	 * A token for the MCP user, stored like a real one, with some JWT
	 * properties overridden: `exp` (relative time), `aud`, `key` (signing key).
	 *
	 * @param array<string,mixed> $overrides Overrides.
	 */
	private function mint( array $overrides ): string {
		$client = new Client_Entity( 'md3_mint_' . wp_generate_password( 8, false ), 'Mint', [ 'https://claude.ai/api/mcp/auth_callback' ] );
		$repo   = new Access_Token_Repository();
		$token  = $repo->getNewToken( $client, [ new Scope_Entity( 'content:read' ) ], (string) Settings::mcp_user_id() );
		$token->setIdentifier( bin2hex( random_bytes( 20 ) ) );
		$token->setExpiryDateTime( new DateTimeImmutable( '+1 hour' ) );
		$token->setPrivateKey( $overrides['key'] ?? Keys::get()['private'] );
		$repo->persistNewAccessToken( $token );

		if ( isset( $overrides['exp'] ) ) {
			$token->setExpiryDateTime( new DateTimeImmutable( $overrides['exp'] ) );
		}

		if ( isset( $overrides['aud'] ) ) {
			$aud    = $overrides['aud'];
			$filter = static fn ( $url ) => str_contains( $url, 'md3-wp-mcp/mcp' ) ? $aud : $url;
			add_filter( 'rest_url', $filter );
			$jwt = $token->toString();
			remove_filter( 'rest_url', $filter );
			$this->assertNotSame( OAuth::resource_url(), $aud );
			return $jwt;
		}

		return $token->toString();
	}

	private function jti( string $jwt ): string {
		$payload = json_decode( base64_decode( strtr( explode( '.', $jwt )[1], '-_', '+/' ) ), true );
		return (string) $payload['jti'];
	}

	private function last_used_at( string $hash ): ?string {
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare( 'SELECT last_used_at FROM ' . Schema::table( 'access_tokens' ) . ' WHERE id_hash = %s', $hash ) );
	}

	private function set_last_used_at( string $hash, string $value ): void {
		global $wpdb;
		$wpdb->update( Schema::table( 'access_tokens' ), [ 'last_used_at' => $value ], [ 'id_hash' => $hash ] );
	}
}
