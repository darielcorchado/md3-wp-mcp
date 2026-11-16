<?php
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\RequestTypes\AuthorizationRequestInterface;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Token\Parser;
use MD3\WPMCP\OAuth\Authorize;
use MD3\WPMCP\OAuth\Discovery;
use MD3\WPMCP\OAuth\OAuth;
use MD3\WPMCP\OAuth\Repositories\Access_Token_Repository;
use MD3\WPMCP\OAuth\Server_Factory;
use MD3\WPMCP\Schema;
use MD3\WPMCP\Settings;
use MD3\WPMCP\Tests\Test_Case;
use Nyholm\Psr7\ServerRequest;

class Test_Oauth_Endpoints extends Test_Case {

	const REDIRECT_URI = 'https://claude.ai/api/mcp/auth_callback';
	const CIMD_URL     = 'https://client.example.com/oauth/client.json';
	const VERIFIER     = 'verifier-0123456789-abcdefghijklmnopqrstuvwxyz-ABCDEFG';

	/** @var bool Keep REMOTE_ADDR as the test set it; otherwise each registration comes from a new IP. */
	private bool $pin_ip = false;

	/** @var int Source of distinct test IPs. */
	private int $ip_counter = 0;

	/** @var array<string,mixed> $_SERVER before the test. */
	private array $server_backup = [];

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
		$this->server_backup = $_SERVER;
	}

	public function tear_down() {
		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'wp_redirect' );
		$_SERVER = $this->server_backup;
		parent::tear_down();
	}

	// ---------------------------------------------------------------------
	// Discovery.
	// ---------------------------------------------------------------------

	public function test_as_metadata_matches_spec() {
		$m = Discovery::authorization_server_metadata();
		$this->assertSame( home_url( '/md3-mcp' ), $m['issuer'] );
		$this->assertSame( [ 'S256' ], $m['code_challenge_methods_supported'] );
		$this->assertSame( [ 'none' ], $m['token_endpoint_auth_methods_supported'] );
		$this->assertTrue( $m['client_id_metadata_document_supported'] );

		$this->assertSame( OAuth::authorize_url(), $m['authorization_endpoint'] );
		$this->assertSame( rest_url( 'md3-wp-mcp/v1/oauth/token' ), $m['token_endpoint'] );
		$this->assertSame( rest_url( 'md3-wp-mcp/v1/oauth/register' ), $m['registration_endpoint'] );
		$this->assertSame( rest_url( 'md3-wp-mcp/v1/oauth/revoke' ), $m['revocation_endpoint'] );
		$this->assertSame( [ 'code' ], $m['response_types_supported'] );
		$this->assertSame( [ 'authorization_code', 'refresh_token' ], $m['grant_types_supported'] );
		$this->assertSame( [ 'content:read', 'content:write', 'content:publish', 'content:delete' ], $m['scopes_supported'] );
	}

	public function test_protected_resource_metadata() {
		$this->assertSame(
			[
				'resource'                 => OAuth::resource_url(),
				'authorization_servers'    => [ OAuth::issuer() ],
				'scopes_supported'         => [ 'content:read', 'content:write', 'content:publish', 'content:delete' ],
				'bearer_methods_supported' => [ 'header' ],
				'resource_name'            => 'MD3 WP MCP',
			],
			Discovery::protected_resource_metadata()
		);
	}

	public function test_discovery_paths_and_responses() {
		$home     = untrailingslashit( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ) );
		$resource = (string) wp_parse_url( OAuth::resource_url(), PHP_URL_PATH );

		$paths = Discovery::paths();
		$this->assertSame(
			[
				$home . '/.well-known/oauth-protected-resource' . $resource,
				$home . '/.well-known/oauth-authorization-server/md3-mcp',
				$home . '/.well-known/openid-configuration/md3-mcp',
			],
			array_keys( $paths )
		);

		$response = Discovery::response( 'GET', $home . '/.well-known/oauth-authorization-server/md3-mcp' );
		$this->assertInstanceOf( WP_HTTP_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( Discovery::authorization_server_metadata(), json_decode( $response->get_data(), true ) );
		$headers = $response->get_headers();
		$this->assertSame( 'no-store', $headers['Cache-Control'] );
		$this->assertSame( '*', $headers['Access-Control-Allow-Origin'] );
		$this->assertStringStartsWith( 'application/json', $headers['Content-Type'] );

		$openid = Discovery::response( 'GET', $home . '/.well-known/openid-configuration/md3-mcp/' );
		$this->assertSame( Discovery::authorization_server_metadata(), json_decode( $openid->get_data(), true ) );

		$prm = Discovery::response( 'GET', $home . '/.well-known/oauth-protected-resource' . $resource );
		$this->assertSame( Discovery::protected_resource_metadata(), json_decode( $prm->get_data(), true ) );

		// The bare append form is not served at priority 0, only by the late handler.
		$this->assertNull( Discovery::response( 'GET', $home . '/.well-known/oauth-protected-resource' ) );
		$bare = Discovery::response( 'GET', $home . '/.well-known/oauth-protected-resource', true );
		$this->assertSame( Discovery::protected_resource_metadata(), json_decode( $bare->get_data(), true ) );

		$this->assertNull( Discovery::response( 'GET', $home . '/.well-known/oauth-authorization-server' ) );
		$this->assertNull( Discovery::response( 'GET', $home . '/sample-page/' ) );

		$preflight = Discovery::response( 'OPTIONS', $home . '/.well-known/oauth-authorization-server/md3-mcp' );
		$this->assertSame( 204, $preflight->get_status() );
		$this->assertSame( '*', $preflight->get_headers()['Access-Control-Allow-Origin'] );

		$post = Discovery::response( 'POST', $home . '/.well-known/oauth-authorization-server/md3-mcp' );
		$this->assertSame( 405, $post->get_status() );
	}

	public function test_hooks_are_registered() {
		$this->assertSame( 0, has_action( 'parse_request', [ Discovery::class, 'maybe_serve' ] ) );
		$this->assertSame( 99, has_action( 'parse_request', [ Discovery::class, 'maybe_serve_bare' ] ) );
		$this->assertSame( 1, has_action( 'parse_request', [ Authorize::class, 'maybe_handle' ] ) );
		$this->assertContains( 'md3_mcp_authorize', apply_filters( 'query_vars', [] ) );

		global $wp_rewrite;
		$this->assertArrayHasKey( '^md3-mcp/authorize/?$', $wp_rewrite->extra_rules_top );
		$this->assertSame( 'index.php?md3_mcp_authorize=1', $wp_rewrite->extra_rules_top['^md3-mcp/authorize/?$'] );

		$routes = rest_get_server()->get_routes();
		foreach ( [ 'register', 'token', 'revoke' ] as $route ) {
			$this->assertArrayHasKey( '/md3-wp-mcp/v1/oauth/' . $route, $routes );
			$this->assertTrue( OAuth::is_oauth_route( '/md3-wp-mcp/v1/oauth/' . $route ) );
		}
		$this->assertFalse( OAuth::is_oauth_route( '/md3-wp-mcp/mcp' ) );
		$this->assertFalse( OAuth::is_oauth_route( '/wp/v2/posts' ) );

		// Runs after core's rest_send_cors_headers (10), which reflects the Origin with credentials.
		$this->assertSame( 11, has_filter( 'rest_pre_serve_request', [ OAuth::class, 'send_cors_headers' ] ) );
		$this->assertSame( '*', $this->register( [ 'redirect_uris' => [ self::REDIRECT_URI ] ] )->get_headers()['Access-Control-Allow-Origin'] );
	}

	// ---------------------------------------------------------------------
	// Registration.
	// ---------------------------------------------------------------------

	public function test_registration_endpoint_hidden_and_refused_when_dcr_off() {
		Settings::update_security( [ 'dcr' => false ] );

		$this->assertArrayNotHasKey( 'registration_endpoint', Discovery::authorization_server_metadata() );

		$response = $this->register( [ 'redirect_uris' => [ self::REDIRECT_URI ] ] );
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'md3_mcp_dcr_disabled', $response->get_data()['error'] );
		$this->assertSame( 0, $this->client_count() );
	}

	public function test_register_creates_public_client() {
		$this->pin_ip           = true;
		$_SERVER['REMOTE_ADDR'] = '203.0.113.7';

		$response = $this->register(
			[
				'client_name'                => 'Claude',
				'redirect_uris'              => [ self::REDIRECT_URI, 'http://localhost:6274/oauth/callback' ],
				'token_endpoint_auth_method' => 'none',
				'grant_types'                => [ 'authorization_code', 'refresh_token' ],
				'response_types'             => [ 'code' ],
			]
		);

		$this->assertSame( 201, $response->get_status() );
		$data = $response->get_data();
		$this->assertMatchesRegularExpression( '/^md3_[A-Za-z0-9]{32}$/', $data['client_id'] );
		$this->assertSame( 'Claude', $data['client_name'] );
		$this->assertSame( [ self::REDIRECT_URI, 'http://localhost:6274/oauth/callback' ], $data['redirect_uris'] );
		$this->assertSame( 'none', $data['token_endpoint_auth_method'] );
		$this->assertSame( [ 'authorization_code', 'refresh_token' ], $data['grant_types'] );
		$this->assertSame( [ 'code' ], $data['response_types'] );
		$this->assertIsInt( $data['client_id_issued_at'] );
		$this->assertArrayNotHasKey( 'client_secret', $data );
		$this->assertSame( 'no-store', $response->get_headers()['Cache-Control'] );

		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'clients' ) . ' WHERE client_id = %s', $data['client_id'] ) );
		$this->assertSame( 'dcr', $row->type );
		$this->assertSame( '0', (string) $row->completed );
		$this->assertSame( 'Claude', $row->name );
		$this->assertSame( [ self::REDIRECT_URI, 'http://localhost:6274/oauth/callback' ], json_decode( $row->redirect_uris, true ) );
		$this->assertSame( \MD3\WPMCP\Plugin::client_ip_hash(), $row->created_ip_hash );

		// A client asking for a confidential method still gets a public client.
		$secret = $this->register( [ 'redirect_uris' => [ self::REDIRECT_URI ], 'token_endpoint_auth_method' => 'client_secret_basic' ] );
		$this->assertSame( 201, $secret->get_status() );
		$this->assertSame( 'none', $secret->get_data()['token_endpoint_auth_method'] );
		$this->assertArrayNotHasKey( 'client_secret', $secret->get_data() );
	}

	public function test_register_rejects_private_redirect_and_more_than_five() {
		$private = $this->register( [ 'redirect_uris' => [ 'https://10.0.0.5/cb' ] ] );
		$this->assertSame( 400, $private->get_status() );
		$this->assertSame( 'invalid_redirect_uri', $private->get_data()['error'] );

		$six = [];
		for ( $i = 1; $i <= 6; $i++ ) {
			$six[] = 'https://claude.ai/cb' . $i;
		}
		$too_many = $this->register( [ 'redirect_uris' => $six ] );
		$this->assertSame( 400, $too_many->get_status() );
		$this->assertSame( 'invalid_redirect_uri', $too_many->get_data()['error'] );

		$five = $this->register( [ 'redirect_uris' => array_slice( $six, 0, 5 ) ] );
		$this->assertSame( 201, $five->get_status() );

		foreach ( [ [], [ 'redirect_uris' => [] ], [ 'redirect_uris' => self::REDIRECT_URI ], [ 'redirect_uris' => [ [ 'x' ] ] ], [ 'redirect_uris' => [ 'https://a.com/cb#frag' ] ] ] as $body ) {
			$response = $this->register( $body );
			$this->assertSame( 400, $response->get_status(), wp_json_encode( $body ) );
			$this->assertSame( 'invalid_redirect_uri', $response->get_data()['error'] );
		}

		foreach (
			[
				[ 'client_name' => [ 'x' ] ],
				[ 'grant_types' => [ 'client_credentials' ] ],
				[ 'grant_types' => 'authorization_code' ],
				[ 'response_types' => [ 'token' ] ],
				[ 'scope' => 'admin' ],
			] as $extra
		) {
			$response = $this->register( [ 'redirect_uris' => [ self::REDIRECT_URI ] ] + $extra );
			$this->assertSame( 400, $response->get_status(), wp_json_encode( $extra ) );
			$this->assertSame( 'invalid_client_metadata', $response->get_data()['error'] );
		}

		$request = new WP_REST_Request( 'POST', '/md3-wp-mcp/v1/oauth/register' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( '"just a string"' );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'invalid_client_metadata', $response->get_data()['error'] );

		$this->assertSame( 1, $this->client_count() );
	}

	public function test_register_sanitizes_client_name() {
		$response = $this->register( [ 'redirect_uris' => [ self::REDIRECT_URI ], 'client_name' => '<script>alert(1)</script>Evil ' . str_repeat( 'x', 300 ) ] );
		$this->assertSame( 201, $response->get_status() );
		$name = $response->get_data()['client_name'];
		$this->assertStringNotContainsString( '<', $name );
		$this->assertLessThanOrEqual( 191, mb_strlen( $name ) );
	}

	public function test_register_rate_limited_after_thirty_per_ip_per_minute() {
		$this->pin_ip           = true;
		$_SERVER['REMOTE_ADDR'] = '198.51.100.1';
		for ( $i = 0; $i < 30; $i++ ) {
			$this->assertSame( 201, $this->register( [ 'redirect_uris' => [ self::REDIRECT_URI ] ] )->get_status() );
		}

		$limited = $this->register( [ 'redirect_uris' => [ self::REDIRECT_URI ] ] );
		$this->assertSame( 429, $limited->get_status() );
		$this->assertSame( 'md3_mcp_rate_limited', $limited->get_data()['error'] );
		$this->assertSame( '60', $limited->get_headers()['Retry-After'] ?? null );
		$this->assertSame( 30, $this->client_count() );

		// Another IP is not affected.
		$_SERVER['REMOTE_ADDR'] = '198.51.100.2';
		$this->assertSame( 201, $this->register( [ 'redirect_uris' => [ self::REDIRECT_URI ] ] )->get_status() );
	}

	public function test_register_prunes_unused_clients_before_refusing() {
		global $wpdb;
		for ( $i = 0; $i < 50; $i++ ) {
			$wpdb->insert(
				Schema::table( 'clients' ),
				[
					'client_id'     => 'md3_seed' . $i,
					'redirect_uris' => wp_json_encode( [ self::REDIRECT_URI ] ),
					'type'          => 'dcr',
					'completed'     => 1,
					'created_at'    => gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS ),
				]
			);
		}

		$response = $this->register( [ 'redirect_uris' => [ self::REDIRECT_URI ] ] );
		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( 1, $this->client_count() );
	}

	public function test_register_refused_over_fifty_clients() {
		global $wpdb;
		for ( $i = 0; $i < 50; $i++ ) {
			$wpdb->insert(
				Schema::table( 'clients' ),
				[
					'client_id'     => 'md3_seed' . $i,
					'redirect_uris' => wp_json_encode( [ self::REDIRECT_URI ] ),
					'type'          => 'dcr',
					'created_at'    => gmdate( 'Y-m-d H:i:s' ),
				]
			);
		}

		$response = $this->register( [ 'redirect_uris' => [ self::REDIRECT_URI ] ] );
		$this->assertSame( 429, $response->get_status() );
		$this->assertSame( 50, $this->client_count() );
	}

	// ---------------------------------------------------------------------
	// Authorize: validate and complete.
	// ---------------------------------------------------------------------

	public function test_validate_rejects_plain_pkce() {
		$client = $this->registered_client();

		$plain = Authorize::validate( $this->query( $client, [ 'code_challenge' => self::VERIFIER, 'code_challenge_method' => 'plain' ] ) );
		$this->assertWPError( $plain );
		$this->assertSame( 'invalid_request', $plain->get_error_code() );
		$this->assertSame( 400, $plain->get_error_data()['status'] );

		$missing = $this->query( $client );
		unset( $missing['code_challenge_method'] );
		$this->assertWPError( Authorize::validate( $missing ) );

		$no_challenge = $this->query( $client );
		unset( $no_challenge['code_challenge'], $no_challenge['code_challenge_method'] );
		$this->assertWPError( Authorize::validate( $no_challenge ) );

		$this->assertInstanceOf( AuthorizationRequestInterface::class, Authorize::validate( $this->query( $client ) ) );
	}

	public function test_validate_rejects_bad_requests() {
		$client = $this->registered_client();

		$this->assertSame( 'invalid_client', Authorize::validate( $this->query( 'md3_unknown' ) )->get_error_code() );
		$this->assertSame( 'invalid_client', Authorize::validate( $this->query( $client, [ 'redirect_uri' => 'https://evil.example.com/cb' ] ) )->get_error_code() );
		$this->assertSame( 'invalid_scope', Authorize::validate( $this->query( $client, [ 'scope' => 'content:read admin' ] ) )->get_error_code() );
		$this->assertWPError( Authorize::validate( $this->query( $client, [ 'response_type' => 'token' ] ) ) );
		$this->assertWPError( Authorize::validate( [] ) );
		$this->assertSame( 'invalid_request', Authorize::validate( $this->query( $client, [ 'state' => [ 'x' ] ] ) )->get_error_code() );
	}

	public function test_validate_resource_trailing_slash_ok_other_resource_rejected() {
		$client = $this->registered_client();

		$this->assertInstanceOf( AuthorizationRequestInterface::class, Authorize::validate( $this->query( $client, [ 'resource' => OAuth::resource_url() ] ) ) );
		$this->assertInstanceOf( AuthorizationRequestInterface::class, Authorize::validate( $this->query( $client, [ 'resource' => untrailingslashit( OAuth::resource_url() ) . '/' ] ) ) );
		$this->assertInstanceOf( AuthorizationRequestInterface::class, Authorize::validate( $this->query( $client, [ 'resource' => untrailingslashit( OAuth::resource_url() ) ] ) ) );
		$this->assertInstanceOf( AuthorizationRequestInterface::class, Authorize::validate( $this->query( $client, [ 'resource' => '' ] ) ) );

		foreach ( [ 'https://other/', rest_url( 'wp/v2/posts' ), OAuth::resource_url() . '/x', OAuth::resource_url() . '//' ] as $resource ) {
			$error = Authorize::validate( $this->query( $client, [ 'resource' => $resource ] ) );
			$this->assertWPError( $error, $resource );
			$this->assertSame( 'invalid_target', $error->get_error_code() );
			$this->assertSame( 400, $error->get_error_data()['status'] );
		}
	}

	public function test_localhost_redirect_matches_any_port() {
		$client = $this->registered_client( [ 'http://localhost:6274/oauth/callback' ] );

		$this->assertInstanceOf( AuthorizationRequestInterface::class, Authorize::validate( $this->query( $client, [ 'redirect_uri' => 'http://localhost:51234/oauth/callback' ] ) ) );
		$this->assertWPError( Authorize::validate( $this->query( $client, [ 'redirect_uri' => 'http://localhost:51234/other' ] ) ) );
		$this->assertWPError( Authorize::validate( $this->query( $client, [ 'redirect_uri' => 'http://127.0.0.1:6274/oauth/callback' ] ) ) );
	}

	public function test_full_code_flow_issues_tokens_with_approved_scopes_only() {
		$user_id = $this->mcp_user();
		$client  = $this->registered_client();
		$query   = $this->query( $client, [ 'scope' => 'content:read content:write content:publish' ] );

		$this->assertInstanceOf( AuthorizationRequestInterface::class, Authorize::validate( $query ) );
		$redirect = Authorize::complete( $query, $user_id, [ 'content:write', 'content:delete' ], true );
		$this->assertIsString( $redirect );
		$this->assertStringStartsWith( self::REDIRECT_URI . '?', $redirect );
		parse_str( (string) wp_parse_url( $redirect, PHP_URL_QUERY ), $params );
		$this->assertSame( 'xyz', $params['state'] );

		// The DCR client is now completed (no longer pruned).
		global $wpdb;
		$this->assertSame( '1', (string) $wpdb->get_var( $wpdb->prepare( 'SELECT completed FROM ' . Schema::table( 'clients' ) . ' WHERE client_id = %s', $client ) ) );

		$response = $this->token(
			[
				'grant_type'    => 'authorization_code',
				'client_id'     => $client,
				'code'          => $params['code'],
				'redirect_uri'  => self::REDIRECT_URI,
				'code_verifier' => self::VERIFIER,
			]
		);
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'Bearer', $data['token_type'] );
		$this->assertNotEmpty( $data['access_token'] );
		$this->assertNotEmpty( $data['refresh_token'] );
		$this->assertSame( 'content:read content:write', $data['scope'] );
		$this->assertSame( 'no-store', $response->get_headers()['Cache-Control'] );

		$claims = ( new Parser( new JoseEncoder() ) )->parse( $data['access_token'] )->claims();
		$this->assertSame( [ 'content:read', 'content:write' ], $claims->get( 'scopes' ) );
		$this->assertSame( (string) $user_id, $claims->get( 'sub' ) );

		// The code is single use.
		$again = $this->token(
			[
				'grant_type'    => 'authorization_code',
				'client_id'     => $client,
				'code'          => $params['code'],
				'redirect_uri'  => self::REDIRECT_URI,
				'code_verifier' => self::VERIFIER,
			]
		);
		$this->assertSame( 400, $again->get_status() );
		$this->assertSame( 'invalid_grant', $again->get_data()['error'] );
	}

	public function test_token_endpoint_rejects_wrong_verifier() {
		$user_id = $this->mcp_user();
		$client  = $this->registered_client();
		$query   = $this->query( $client );
		parse_str( (string) wp_parse_url( Authorize::complete( $query, $user_id, [ 'content:write' ], true ), PHP_URL_QUERY ), $params );

		$response = $this->token(
			[
				'grant_type'    => 'authorization_code',
				'client_id'     => $client,
				'code'          => $params['code'],
				'redirect_uri'  => self::REDIRECT_URI,
				'code_verifier' => str_repeat( 'z', 50 ),
			]
		);
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'invalid_grant', $response->get_data()['error'] );
		$this->assertArrayNotHasKey( 'access_token', $response->get_data() );
	}

	public function test_token_endpoint_rate_limited() {
		$_SERVER['REMOTE_ADDR'] = '198.51.100.9';
		for ( $i = 0; $i < 30; $i++ ) {
			// Unknown client: invalid_client, which league answers with 401.
			$this->assertSame( 401, $this->token( [ 'grant_type' => 'refresh_token', 'client_id' => 'md3_x', 'refresh_token' => 'nope' ] )->get_status() );
		}
		$limited = $this->token( [ 'grant_type' => 'refresh_token', 'client_id' => 'md3_x', 'refresh_token' => 'nope' ] );
		$this->assertSame( 429, $limited->get_status() );
		$this->assertSame( 'md3_mcp_rate_limited', $limited->get_data()['error'] );
	}

	public function test_deny_redirects_with_access_denied() {
		$user_id = $this->mcp_user();
		$client  = $this->registered_client();

		$redirect = Authorize::complete( $this->query( $client ), $user_id, [ 'content:write' ], false );
		$this->assertStringStartsWith( self::REDIRECT_URI . '?', $redirect );
		parse_str( (string) wp_parse_url( $redirect, PHP_URL_QUERY ), $params );
		$this->assertSame( 'access_denied', $params['error'] );
		$this->assertSame( 'xyz', $params['state'] );
		$this->assertArrayNotHasKey( 'code', $params );

		// A denied DCR client stays unfinished, so it gets pruned.
		global $wpdb;
		$this->assertSame( '0', (string) $wpdb->get_var( $wpdb->prepare( 'SELECT completed FROM ' . Schema::table( 'clients' ) . ' WHERE client_id = %s', $client ) ) );
	}

	public function test_complete_returns_a_server_error_when_the_code_cannot_be_stored() {
		global $wpdb;
		$user_id = $this->mcp_user();
		$client  = $this->registered_client();

		// The authorization code insert fails (a database fault).
		$codes  = Schema::table( 'auth_codes' );
		$break  = static fn ( $query ) => str_starts_with( ltrim( $query ), "INSERT INTO `{$codes}`" ) ? 'INSERT INTO md3_missing_table VALUES (1)' : $query;
		$errors = $wpdb->suppress_errors( true );
		add_filter( 'query', $break );
		$result = Authorize::complete( $this->query( $client ), $user_id, [ 'content:write' ], true );
		remove_filter( 'query', $break );
		$wpdb->suppress_errors( $errors );

		$this->assertWPError( $result );
		$this->assertSame( 'server_error', $result->get_error_code() );
		$this->assertSame( 500, $result->get_error_data()['status'] );
		// No code was issued, so the client stays unfinished.
		$this->assertSame( '0', (string) $wpdb->get_var( $wpdb->prepare( 'SELECT completed FROM ' . Schema::table( 'clients' ) . ' WHERE client_id = %s', $client ) ) );
	}

	public function test_complete_inserts_cimd_client_on_approval() {
		$user_id = $this->mcp_user();
		add_filter(
			'pre_http_request',
			static function () {
				return [
					'headers'  => [ 'content-type' => 'application/json' ],
					'body'     => wp_json_encode(
						[
							'client_id'     => self::CIMD_URL,
							'client_name'   => 'Example Client',
							'redirect_uris' => [ self::REDIRECT_URI ],
						]
					),
					'response' => [ 'code' => 200, 'message' => 'OK' ],
					'cookies'  => [],
					'filename' => null,
				];
			}
		);

		global $wpdb;
		$table = Schema::table( 'clients' );

		Authorize::complete( $this->query( self::CIMD_URL ), $user_id, [], false );
		$this->assertNull( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE client_id = %s", self::CIMD_URL ) ), 'nothing stored on deny' );

		$redirect = Authorize::complete( $this->query( self::CIMD_URL ), $user_id, [], true );
		$this->assertStringContainsString( 'code=', $redirect );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE client_id = %s", self::CIMD_URL ) );
		$this->assertSame( 'cimd', $row->type );
		$this->assertSame( '1', (string) $row->completed );
		$this->assertSame( 'Example Client', $row->name );
		$this->assertSame( [ self::REDIRECT_URI ], json_decode( $row->redirect_uris, true ) );

		// Approving again updates the same row.
		Authorize::complete( $this->query( self::CIMD_URL ), $user_id, [], true );
		$this->assertSame( '1', (string) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE client_id = %s", self::CIMD_URL ) ) );
	}

	// ---------------------------------------------------------------------
	// Authorize: request handling (GET consent page, POST decision).
	// ---------------------------------------------------------------------

	public function test_authorize_get_without_params_is_400_before_login() {
		wp_set_current_user( 0 );
		$this->fail_on_redirect();

		$result = Authorize::handle( 'GET', [], [] );
		$this->assertWPError( $result );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	public function test_authorize_get_redirects_to_login_when_signed_out() {
		$client = $this->registered_client();
		wp_set_current_user( 0 );
		$this->fail_on_redirect();
		$_SERVER['HTTP_HOST']   = WP_TESTS_DOMAIN;
		$_SERVER['REQUEST_URI'] = '/md3-mcp/authorize?client_id=' . $client;

		try {
			Authorize::handle( 'GET', $this->query( $client ), [] );
			$this->fail( 'no login redirect' );
		} catch ( RuntimeException $e ) {
			$this->assertStringContainsString( 'wp-login.php', $e->getMessage() );
			$this->assertStringContainsString( rawurlencode( '/md3-mcp/authorize' ), $e->getMessage() );
		}
	}

	public function test_authorize_get_requires_configured_mcp_user() {
		$client = $this->registered_client();
		wp_set_current_user( (int) self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$result = Authorize::handle( 'GET', $this->query( $client ), [] );
		$this->assertWPError( $result );
		$this->assertSame( 503, $result->get_error_data()['status'] );
		$this->assertStringContainsString( 'Settings &gt; MD3 WP MCP', $result->get_error_message() );
	}

	public function test_authorize_get_refuses_other_users_naming_the_mcp_user() {
		$mcp    = $this->mcp_user();
		$client = $this->registered_client();
		wp_set_current_user( (int) self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$query  = $this->query( $client );
		$result = Authorize::handle( 'GET', $query, [] );
		$this->assertWPError( $result );
		$this->assertSame( 403, $result->get_error_data()['status'] );
		$message = $result->get_error_message();
		$this->assertStringContainsString( esc_html( get_userdata( $mcp )->user_login ), $message );
		$this->assertStringContainsString( 'action=logout', $message );
		$this->assertStringContainsString( rawurlencode( OAuth::authorize_url() ), $message );
	}

	public function test_authorize_get_renders_escaped_consent_page() {
		$mcp    = $this->mcp_user();
		$client = $this->registered_client( [ self::REDIRECT_URI ], '<img src=x onerror=alert(1)>Evil "Client"' );
		wp_set_current_user( $mcp );

		// Registration strips tags; the view must escape on its own too.
		global $wpdb;
		$wpdb->update( Schema::table( 'clients' ), [ 'name' => '<img src=x onerror=alert(1)><script>alert(2)</script>"Evil"' ], [ 'client_id' => $client ] );

		$query  = $this->query( $client, [ 'scope' => 'content:read content:write content:publish' ] );
		$result = Authorize::handle( 'GET', $query, [] );
		$this->assertInstanceOf( WP_HTTP_Response::class, $result );
		$this->assertSame( 200, $result->get_status() );

		$headers = $result->get_headers();
		$this->assertSame( 'DENY', $headers['X-Frame-Options'] );
		$this->assertStringContainsString( "frame-ancestors 'none'", $headers['Content-Security-Policy'] );
		$this->assertStringContainsString( 'no-store', $headers['Cache-Control'] );
		$this->assertSame( 'no-referrer', $headers['Referrer-Policy'] );
		$this->assertStringStartsWith( 'text/html', $headers['Content-Type'] );

		$html = $result->get_data();
		$this->assertStringNotContainsString( '<img src=x', $html );
		$this->assertStringNotContainsString( '<script', $html );
		$this->assertStringContainsString( '&lt;img src=x onerror=alert(1)&gt;', $html );
		$this->assertStringContainsString( 'claude.ai', $html );
		$this->assertMatchesRegularExpression( '/<input[^>]*value="content:read"[^>]*checked[^>]*disabled|<input[^>]*value="content:read"[^>]*disabled[^>]*checked/', $html );
		$this->assertMatchesRegularExpression( '/<input[^>]*name="scopes\[\]"[^>]*value="content:write"[^>]*checked/', $html );
		$this->assertDoesNotMatchRegularExpression( '/<input[^>]*value="content:publish"[^>]*checked/', $html );
		$this->assertStringNotContainsString( 'value="content:delete"', $html, 'scopes not requested are not offered' );

		// The query is stored for the POST, bound to this user.
		preg_match( '/name="md3_mcp_token" value="([A-Za-z0-9]+)"/', $html, $m );
		$this->assertNotEmpty( $m[1] );
		$stored = get_transient( 'md3_mcp_consent_' . $m[1] );
		$this->assertSame( $mcp, $stored['user_id'] );
		$this->assertSame( $client, $stored['query']['client_id'] );
		$this->assertStringContainsString( 'name="_wpnonce"', $html );
	}

	public function test_authorize_post_approves_once_with_valid_nonce() {
		$mcp    = $this->mcp_user();
		$client = $this->registered_client();
		wp_set_current_user( $mcp );

		$token = $this->consent_token( $this->query( $client ) );

		// Bad nonce.
		$bad = Authorize::handle( 'POST', [], [ 'md3_mcp_token' => $token, '_wpnonce' => 'nope', 'decision' => 'approve' ] );
		$this->assertWPError( $bad );
		$this->assertSame( 403, $bad->get_error_data()['status'] );

		$post   = [
			'md3_mcp_token' => $token,
			'_wpnonce'      => wp_create_nonce( 'md3_mcp_consent_' . $token ),
			'decision'      => 'approve',
			'scopes'        => [ 'content:write' ],
		];
		$result = Authorize::handle( 'POST', [], $post );
		$this->assertInstanceOf( WP_HTTP_Response::class, $result );
		$this->assertSame( 302, $result->get_status() );
		$location = $result->get_headers()['Location'];
		$this->assertStringStartsWith( self::REDIRECT_URI . '?', $location );
		$this->assertStringContainsString( 'code=', $location );

		// Single use.
		$this->assertFalse( get_transient( 'md3_mcp_consent_' . $token ) );
		$replay = Authorize::handle( 'POST', [], $post );
		$this->assertWPError( $replay );
	}

	public function test_authorize_post_deny_and_wrong_user() {
		$mcp    = $this->mcp_user();
		$client = $this->registered_client();
		wp_set_current_user( $mcp );
		$token = $this->consent_token( $this->query( $client ) );
		$nonce = wp_create_nonce( 'md3_mcp_consent_' . $token );

		// Another signed-in user can't use the MCP user's consent.
		wp_set_current_user( (int) self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$other = Authorize::handle( 'POST', [], [ 'md3_mcp_token' => $token, '_wpnonce' => wp_create_nonce( 'md3_mcp_consent_' . $token ), 'decision' => 'approve' ] );
		$this->assertWPError( $other );
		$this->assertSame( 403, $other->get_error_data()['status'] );

		wp_set_current_user( $mcp );
		$denied = Authorize::handle( 'POST', [], [ 'md3_mcp_token' => $token, '_wpnonce' => $nonce, 'decision' => 'deny' ] );
		$this->assertSame( 302, $denied->get_status() );
		$this->assertStringContainsString( 'error=access_denied', $denied->get_headers()['Location'] );
	}

	public function test_authorize_rate_limited() {
		$_SERVER['REMOTE_ADDR'] = '198.51.100.20';
		wp_set_current_user( 0 );
		for ( $i = 0; $i < 30; $i++ ) {
			$this->assertSame( 400, Authorize::handle( 'GET', [], [] )->get_error_data()['status'] );
		}
		$this->assertSame( 429, Authorize::handle( 'GET', [], [] )->get_error_data()['status'] );
	}

	// ---------------------------------------------------------------------
	// Refresh and revoke.
	// ---------------------------------------------------------------------

	public function test_refresh_rotates_and_old_token_fails() {
		$tokens = $this->issue_token();

		$rotated = $this->token( [ 'grant_type' => 'refresh_token', 'client_id' => $tokens['client_id'], 'refresh_token' => $tokens['refresh_token'] ] );
		$this->assertSame( 200, $rotated->get_status() );
		$new = $rotated->get_data();
		$this->assertNotSame( $tokens['refresh_token'], $new['refresh_token'] );
		$this->assertSame( 'content:read content:write', $new['scope'] );

		$old = $this->token( [ 'grant_type' => 'refresh_token', 'client_id' => $tokens['client_id'], 'refresh_token' => $tokens['refresh_token'] ] );
		$this->assertSame( 400, $old->get_status() );
		$this->assertSame( 'invalid_grant', $old->get_data()['error'] );

		$this->assertFalse( $this->access_revoked( $new['access_token'] ) );
	}

	public function test_revoke_access_token_then_it_is_rejected() {
		$tokens = $this->issue_token();

		// Another client can't revoke it; still 200.
		$wrong = $this->revoke( [ 'token' => $tokens['access_token'], 'client_id' => 'md3_someoneelse' ] );
		$this->assertSame( 200, $wrong->get_status() );
		$this->assertFalse( $this->access_revoked( $tokens['access_token'] ) );

		$response = $this->revoke( [ 'token' => $tokens['access_token'], 'client_id' => $tokens['client_id'], 'token_type_hint' => 'access_token' ] );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'no-store', $response->get_headers()['Cache-Control'] );
		$this->assertTrue( $this->access_revoked( $tokens['access_token'] ) );

		try {
			$request = ( new ServerRequest( 'POST', OAuth::resource_url() ) )->withHeader( 'Authorization', 'Bearer ' . $tokens['access_token'] );
			Server_Factory::resource_server()->validateAuthenticatedRequest( $request );
			$this->fail( 'revoked token accepted' );
		} catch ( OAuthServerException $e ) {
			$this->assertSame( 401, $e->getHttpStatusCode() );
		}

		// Its refresh token went with it.
		$refresh = $this->token( [ 'grant_type' => 'refresh_token', 'client_id' => $tokens['client_id'], 'refresh_token' => $tokens['refresh_token'] ] );
		$this->assertSame( 400, $refresh->get_status() );

		// Unknown and garbage tokens are still 200.
		$this->assertSame( 200, $this->revoke( [ 'token' => 'garbage', 'client_id' => $tokens['client_id'] ] )->get_status() );
		$this->assertSame( 200, $this->revoke( [ 'token' => 'a.b.c', 'client_id' => $tokens['client_id'] ] )->get_status() );
		$this->assertSame( 400, $this->revoke( [ 'client_id' => $tokens['client_id'] ] )->get_status() );
	}

	public function test_revoke_refresh_token_revokes_pair_without_theft_signal() {
		$first  = $this->issue_token();
		$second = $this->issue_token();

		$this->assertSame( 200, $this->revoke( [ 'token' => $first['refresh_token'], 'client_id' => $second['client_id'] ] )->get_status() );
		$this->assertFalse( $this->access_revoked( $first['access_token'] ), 'other client cannot revoke' );

		$this->assertSame( 200, $this->revoke( [ 'token' => $first['refresh_token'], 'client_id' => $first['client_id'], 'token_type_hint' => 'refresh_token' ] )->get_status() );
		$this->assertTrue( $this->access_revoked( $first['access_token'] ) );
		$this->assertSame( 400, $this->token( [ 'grant_type' => 'refresh_token', 'client_id' => $first['client_id'], 'refresh_token' => $first['refresh_token'] ] )->get_status() );

		// Revoking twice is fine, and a later replay (well after the reuse grace) is not treated as theft.
		$this->assertSame( 200, $this->revoke( [ 'token' => $first['refresh_token'], 'client_id' => $first['client_id'] ] )->get_status() );
		global $wpdb;
		$this->assertSame( '0', (string) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( 'refresh_tokens' ) . ' WHERE rotated_at IS NOT NULL' ) );
		$this->assertFalse( $this->access_revoked( $second['access_token'] ) );
	}

	public function test_issue_token_helper() {
		$tokens = $this->issue_token( [ 'content:read', 'content:publish' ] );
		$this->assertStringStartsWith( 'md3_', $tokens['client_id'] );
		$claims = ( new Parser( new JoseEncoder() ) )->parse( $tokens['access_token'] )->claims();
		$this->assertSame( [ 'content:read', 'content:publish' ], $claims->get( 'scopes' ) );
		$this->assertSame( (string) Settings::mcp_user_id(), $claims->get( 'sub' ) );
		$this->assertNotEmpty( $tokens['refresh_token'] );
	}

	// ---------------------------------------------------------------------
	// Helpers.
	// ---------------------------------------------------------------------

	private function mcp_user(): int {
		$user_id                 = (int) self::factory()->user->create( [ 'role' => 'editor' ] );
		$security                = Settings::security();
		$security['mcp_user_id'] = $user_id;
		update_option( Settings::OPT_SECURITY, $security );
		return $user_id;
	}

	/**
	 * @param array<string,mixed> $body Registration body.
	 */
	private function register( array $body ): WP_REST_Response {
		if ( ! $this->pin_ip ) {
			$_SERVER['REMOTE_ADDR'] = '2001:db8::' . dechex( ++$this->ip_counter );
		}
		$request = new WP_REST_Request( 'POST', '/md3-wp-mcp/v1/oauth/register' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $body ) );
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * @param string[] $redirect_uris Redirect URIs.
	 */
	private function registered_client( array $redirect_uris = [ self::REDIRECT_URI ], string $name = 'Test client' ): string {
		$response = $this->register( [ 'redirect_uris' => $redirect_uris, 'client_name' => $name ] );
		$this->assertSame( 201, $response->get_status() );
		return $response->get_data()['client_id'];
	}

	private function client_count(): int {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( 'clients' ) );
	}

	/**
	 * @param array<string,mixed> $overrides Query overrides.
	 * @return array<string,mixed>
	 */
	private function query( string $client_id, array $overrides = [] ): array {
		return array_merge(
			[
				'response_type'         => 'code',
				'client_id'             => $client_id,
				'redirect_uri'          => self::REDIRECT_URI,
				'state'                 => 'xyz',
				'code_challenge'        => rtrim( strtr( base64_encode( hash( 'sha256', self::VERIFIER, true ) ), '+/', '-_' ), '=' ),
				'code_challenge_method' => 'S256',
			],
			$overrides
		);
	}

	/**
	 * Renders the consent page and returns its form token.
	 *
	 * @param array<string,mixed> $query Authorize query.
	 */
	private function consent_token( array $query ): string {
		$page = Authorize::handle( 'GET', $query, [] );
		$this->assertInstanceOf( WP_HTTP_Response::class, $page );
		preg_match( '/name="md3_mcp_token" value="([A-Za-z0-9]+)"/', $page->get_data(), $m );
		return $m[1];
	}

	/**
	 * @param array<string,string> $params Form parameters.
	 */
	private function token( array $params ): WP_REST_Response {
		return $this->form_post( '/md3-wp-mcp/v1/oauth/token', $params );
	}

	/**
	 * @param array<string,string> $params Form parameters.
	 */
	private function revoke( array $params ): WP_REST_Response {
		return $this->form_post( '/md3-wp-mcp/v1/oauth/revoke', $params );
	}

	/**
	 * @param array<string,string> $params Form parameters.
	 */
	private function form_post( string $route, array $params ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', $route );
		$request->set_header( 'Content-Type', 'application/x-www-form-urlencoded' );
		$request->set_body_params( $params );
		return rest_get_server()->dispatch( $request );
	}

	private function access_revoked( string $jwt ): bool {
		$jti = ( new Parser( new JoseEncoder() ) )->parse( $jwt )->claims()->get( 'jti' );
		return ( new Access_Token_Repository() )->isAccessTokenRevoked( $jti );
	}

	/**
	 * Turns any redirect into an exception carrying the location, so a
	 * redirect-then-exit never ends the test run.
	 */
	private function fail_on_redirect(): void {
		add_filter(
			'wp_redirect',
			static function ( $location ) {
				throw new RuntimeException( (string) $location );
			}
		);
	}
}
