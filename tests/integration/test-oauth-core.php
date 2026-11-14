<?php
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\Validator;
use League\OAuth2\Server\CryptKey;
use League\OAuth2\Server\Exception\OAuthServerException;
use MD3\WPMCP\OAuth\Cimd;
use MD3\WPMCP\OAuth\Entities\Client_Entity;
use MD3\WPMCP\OAuth\Entities\Scope_Entity;
use MD3\WPMCP\OAuth\Entities\User_Entity;
use MD3\WPMCP\OAuth\Keys;
use MD3\WPMCP\OAuth\OAuth;
use MD3\WPMCP\OAuth\Psr7_Bridge;
use MD3\WPMCP\OAuth\Redirect_Uri_Policy;
use MD3\WPMCP\OAuth\Repositories\Access_Token_Repository;
use MD3\WPMCP\OAuth\Repositories\Auth_Code_Repository;
use MD3\WPMCP\OAuth\Repositories\Client_Repository;
use MD3\WPMCP\OAuth\Repositories\Refresh_Token_Repository;
use MD3\WPMCP\OAuth\Repositories\Scope_Repository;
use MD3\WPMCP\OAuth\Server_Factory;
use MD3\WPMCP\OAuth\Tokens;
use MD3\WPMCP\Schema;
use MD3\WPMCP\Settings;
use MD3\WPMCP\Tests\Test_Case;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;

class Test_Oauth_Core extends Test_Case {

	const CIMD_URL     = 'https://client.example.com/oauth/client.json';
	const REDIRECT_URI = 'https://claude.ai/api/mcp/auth_callback';
	const VERIFIER     = 'verifier-0123456789-abcdefghijklmnopqrstuvwxyz-ABCDEFG';

	/** @var int Number of HTTP requests seen by the pre_http_request mock. */
	private int $http_calls = 0;

	/** @var array<string,mixed> Arguments of the last mocked HTTP request. */
	private array $http_args = [];

	public function tear_down() {
		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'md3_wp_mcp_redirect_schemes' );
		parent::tear_down();
	}

	// ---------------------------------------------------------------------
	// Redirect URI policy, URLs and transport.
	// ---------------------------------------------------------------------

	public function test_redirect_policy() {
		$this->assertTrue( Redirect_Uri_Policy::allowed( 'https://claude.ai/api/mcp/auth_callback' ) );
		$this->assertTrue( Redirect_Uri_Policy::allowed( 'http://127.0.0.1:33418/callback' ) );
		$this->assertFalse( Redirect_Uri_Policy::allowed( 'http://evil.com/cb' ) );
		$this->assertFalse( Redirect_Uri_Policy::allowed( 'https://10.0.0.5/cb' ) );
		$this->assertFalse( Redirect_Uri_Policy::allowed( 'https://a.com/cb#x' ) );
		$this->assertFalse( Redirect_Uri_Policy::allowed( 'javascript:alert(1)' ) );
	}

	public function test_redirect_policy_edge_cases() {
		$this->assertTrue( Redirect_Uri_Policy::allowed( 'http://localhost:6274/oauth/callback' ) );
		$this->assertTrue( Redirect_Uri_Policy::allowed( 'http://[::1]:8080/cb' ) );
		$this->assertTrue( Redirect_Uri_Policy::allowed( 'claude://oauth/callback' ) );
		$this->assertTrue( Redirect_Uri_Policy::allowed( 'cursor://anysphere.cursor-mcp/oauth/callback' ) );
		$this->assertTrue( Redirect_Uri_Policy::allowed( 'https://chatgpt.com/connector_platform_oauth_redirect' ) );

		$this->assertFalse( Redirect_Uri_Policy::allowed( '' ) );
		$this->assertFalse( Redirect_Uri_Policy::allowed( 'https://a.com/cb#' ) );
		$this->assertFalse( Redirect_Uri_Policy::allowed( 'https://*.a.com/cb' ) );
		$this->assertFalse( Redirect_Uri_Policy::allowed( 'https://a.com/*' ) );
		$this->assertFalse( Redirect_Uri_Policy::allowed( 'https://localhost/cb' ) );
		$this->assertFalse( Redirect_Uri_Policy::allowed( 'https://192.168.1.10/cb' ) );
		$this->assertFalse( Redirect_Uri_Policy::allowed( 'https://127.0.0.1/cb' ) );
		$this->assertFalse( Redirect_Uri_Policy::allowed( 'https://[fd00::1]/cb' ) );
		$this->assertFalse( Redirect_Uri_Policy::allowed( 'https://intranet/cb' ) );
		$this->assertFalse( Redirect_Uri_Policy::allowed( 'https://user:pass@a.com/cb' ) );
		$this->assertFalse( Redirect_Uri_Policy::allowed( 'http://localhost.evil.com/cb' ) );
		$this->assertFalse( Redirect_Uri_Policy::allowed( 'http://127.0.0.2/cb' ) );
		$this->assertFalse( Redirect_Uri_Policy::allowed( 'data:text/html,hi' ) );
		$this->assertFalse( Redirect_Uri_Policy::allowed( 'myapp://cb' ) );
		$this->assertFalse( Redirect_Uri_Policy::allowed( "https://a.com/c b" ) );

		add_filter(
			'md3_wp_mcp_redirect_schemes',
			static function ( $schemes ) {
				$schemes[] = 'myapp';
				return $schemes;
			}
		);
		$this->assertTrue( Redirect_Uri_Policy::allowed( 'myapp://cb' ) );
	}

	public function test_urls_and_transport() {
		$this->assertSame( rest_url( 'md3-wp-mcp/mcp' ), OAuth::resource_url() );
		$this->assertSame( home_url( '/md3-mcp' ), OAuth::issuer() );
		$this->assertSame( home_url( '/md3-mcp/authorize' ), OAuth::authorize_url() );

		$https = $_SERVER['HTTPS'] ?? null;

		$_SERVER['HTTPS'] = 'on';
		$this->assertTrue( OAuth::transport_allowed() );

		unset( $_SERVER['HTTPS'] );
		$this->assertSame( 'local' === wp_get_environment_type(), OAuth::transport_allowed() );

		if ( null !== $https ) {
			$_SERVER['HTTPS'] = $https;
		}
	}

	// ---------------------------------------------------------------------
	// Keys.
	// ---------------------------------------------------------------------

	public function test_keys_are_generated_once_and_not_autoloaded() {
		global $wpdb;
		delete_option( Keys::OPT_PRIVATE );
		delete_option( Keys::OPT_ENCRYPTION );

		$first = Keys::get();
		$this->assertInstanceOf( CryptKey::class, $first['private'] );
		$this->assertInstanceOf( CryptKey::class, $first['public'] );
		$this->assertIsString( $first['encryption'] );
		$this->assertNotSame( '', $first['encryption'] );
		$this->assertStringContainsString( 'PRIVATE KEY', $first['private']->getKeyContents() );
		$this->assertStringContainsString( 'PUBLIC KEY', $first['public']->getKeyContents() );

		$details = openssl_pkey_get_details( openssl_pkey_get_private( $first['private']->getKeyContents() ) );
		$this->assertSame( OPENSSL_KEYTYPE_RSA, $details['type'] );
		$this->assertSame( 2048, $details['bits'] );

		$second = Keys::get();
		$this->assertSame( $first['private']->getKeyContents(), $second['private']->getKeyContents() );
		$this->assertSame( $first['public']->getKeyContents(), $second['public']->getKeyContents() );
		$this->assertSame( $first['encryption'], $second['encryption'] );

		foreach ( [ Keys::OPT_PRIVATE, Keys::OPT_ENCRYPTION ] as $option ) {
			$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", $option ) );
			$this->assertContains( $autoload, [ 'off', 'no' ], $option );
		}
	}

	public function test_rotate_replaces_keys_and_revokes_everything() {
		$user_id = $this->make_mcp_user();
		$client  = $this->insert_client();
		$before  = Keys::get();
		$token   = $this->issue_access_token( $client, $user_id );

		Keys::rotate();

		$after = Keys::get();
		$this->assertNotSame( $before['private']->getKeyContents(), $after['private']->getKeyContents() );
		$this->assertNotSame( $before['encryption'], $after['encryption'] );
		$this->assertTrue( ( new Access_Token_Repository() )->isAccessTokenRevoked( $token->getIdentifier() ) );
	}

	// ---------------------------------------------------------------------
	// CIMD.
	// ---------------------------------------------------------------------

	public function test_cimd_detection() {
		$this->assertTrue( Cimd::is_cimd( self::CIMD_URL ) );
		$this->assertFalse( Cimd::is_cimd( 'https://client.example.com' ) );
		$this->assertFalse( Cimd::is_cimd( 'https://client.example.com/' ) );
		$this->assertFalse( Cimd::is_cimd( 'http://client.example.com/client.json' ) );
		$this->assertFalse( Cimd::is_cimd( 'https://client.example.com/client.json#frag' ) );
		$this->assertFalse( Cimd::is_cimd( 'https://user@client.example.com/client.json' ) );
		$this->assertFalse( Cimd::is_cimd( 'dcr-0123456789abcdef' ) );
	}

	public function test_cimd_rejects_doc_whose_client_id_differs() {
		$this->mock_cimd(
			[
				'client_id'     => 'https://other.example.com/client.json',
				'client_name'   => 'Other',
				'redirect_uris' => [ self::REDIRECT_URI ],
			]
		);

		$this->assertNull( Cimd::resolve( self::CIMD_URL ) );
		$this->assertSame( 1, $this->http_calls );
		$this->assertFalse( get_transient( 'md3_mcp_cimd_' . md5( self::CIMD_URL ) ) );
	}

	public function test_cimd_rejects_invalid_redirect_uris_and_bad_responses() {
		$this->mock_cimd(
			[
				'client_id'     => self::CIMD_URL,
				'redirect_uris' => [ self::REDIRECT_URI, 'http://evil.com/cb' ],
			]
		);
		$this->assertNull( Cimd::resolve( self::CIMD_URL ) );

		$this->mock_cimd( [ 'client_id' => self::CIMD_URL, 'redirect_uris' => [] ] );
		$this->assertNull( Cimd::resolve( self::CIMD_URL ) );

		$this->mock_cimd( [ 'client_id' => self::CIMD_URL, 'redirect_uris' => [ self::REDIRECT_URI ] ], 404 );
		$this->assertNull( Cimd::resolve( self::CIMD_URL ) );

		$this->mock_cimd( 'not json' );
		$this->assertNull( Cimd::resolve( self::CIMD_URL ) );

		$this->assertFalse( get_transient( 'md3_mcp_cimd_' . md5( self::CIMD_URL ) ) );
	}

	public function test_cimd_resolves_and_caches_valid_doc() {
		$this->mock_cimd(
			[
				'client_id'     => self::CIMD_URL,
				'client_name'   => '<b>Example</b> Client',
				'redirect_uris' => [ self::REDIRECT_URI, 'http://127.0.0.1/callback' ],
			]
		);

		$doc = Cimd::resolve( self::CIMD_URL );
		$this->assertSame(
			[
				'client_id'     => self::CIMD_URL,
				'client_name'   => 'Example Client',
				'redirect_uris' => [ self::REDIRECT_URI, 'http://127.0.0.1/callback' ],
			],
			$doc
		);
		$this->assertSame( 5, $this->http_args['timeout'] );
		$this->assertSame( 65536, $this->http_args['limit_response_size'] );
		$this->assertTrue( $this->http_args['reject_unsafe_urls'] );

		$this->assertSame( $doc, Cimd::resolve( self::CIMD_URL ) );
		$this->assertSame( 1, $this->http_calls, 'second resolve is served from the transient' );
		$this->assertSame( $doc, get_transient( 'md3_mcp_cimd_' . md5( self::CIMD_URL ) ) );
	}

	// ---------------------------------------------------------------------
	// Clients and scopes.
	// ---------------------------------------------------------------------

	public function test_client_repository_reads_db_then_cimd() {
		$repo = new Client_Repository();

		$this->insert_client( 'dcr-abc', [ 'http://localhost:1234/cb' ], 'My CLI' );
		$client = $repo->getClientEntity( 'dcr-abc' );
		$this->assertInstanceOf( Client_Entity::class, $client );
		$this->assertSame( 'dcr-abc', $client->getIdentifier() );
		$this->assertSame( 'My CLI', $client->getName() );
		$this->assertSame( [ 'http://localhost:1234/cb' ], $client->getRedirectUri() );
		$this->assertFalse( $client->isConfidential() );

		$this->assertNull( $repo->getClientEntity( 'dcr-missing' ) );

		$this->mock_cimd(
			[
				'client_id'     => self::CIMD_URL,
				'client_name'   => 'Example',
				'redirect_uris' => [ self::REDIRECT_URI ],
			]
		);
		$cimd = $repo->getClientEntity( self::CIMD_URL );
		$this->assertSame( self::CIMD_URL, $cimd->getIdentifier() );
		$this->assertSame( 'Example', $cimd->getName() );
		$this->assertSame( [ self::REDIRECT_URI ], $cimd->getRedirectUri() );
		$this->assertFalse( $cimd->isConfidential() );

		$this->assertTrue( $repo->validateClient( 'dcr-abc', null, 'authorization_code' ) );
		$this->assertTrue( $repo->validateClient( 'dcr-abc', null, 'refresh_token' ) );
		$this->assertFalse( $repo->validateClient( 'dcr-abc', null, 'client_credentials' ) );
		$this->assertFalse( $repo->validateClient( 'dcr-abc', null, 'password' ) );
		$this->assertFalse( $repo->validateClient( 'dcr-abc', null, null ) );
		$this->assertFalse( $repo->validateClient( 'dcr-missing', null, 'authorization_code' ) );

		$this->assertTrue( $client->supportsGrantType( 'refresh_token' ) );
		$this->assertFalse( $client->supportsGrantType( 'client_credentials' ) );
	}

	public function test_scope_repository() {
		$repo = new Scope_Repository();
		$this->assertSame( 'content:publish', $repo->getScopeEntityByIdentifier( 'content:publish' )->getIdentifier() );
		$this->assertNull( $repo->getScopeEntityByIdentifier( 'admin' ) );

		$final = $repo->finalizeScopes( [ new Scope_Entity( 'content:delete' ) ], 'authorization_code', new Client_Entity( 'c', 'C', [ self::REDIRECT_URI ] ), '1' );
		$this->assertSame( [ 'content:read', 'content:delete' ], array_map( static fn ( $s ) => $s->getIdentifier(), $final ) );
	}

	// ---------------------------------------------------------------------
	// Access tokens.
	// ---------------------------------------------------------------------

	public function test_access_token_jwt_has_resource_audience_and_client_claim() {
		$user_id = $this->make_mcp_user();
		$client  = $this->insert_client( 'dcr-jwt' );
		$token   = $this->issue_access_token( $client, $user_id, [ 'content:read', 'content:write' ] );
		$jwt     = $token->toString();

		$parsed = ( new Parser( new JoseEncoder() ) )->parse( $jwt );
		$claims = $parsed->claims();
		$this->assertSame( [ OAuth::resource_url() ], $claims->get( 'aud' ) );
		$this->assertSame( 'dcr-jwt', $claims->get( 'cid' ) );
		$this->assertSame( (string) $user_id, $claims->get( 'sub' ) );
		$this->assertSame( $token->getIdentifier(), $claims->get( 'jti' ) );
		$this->assertSame( [ 'content:read', 'content:write' ], $claims->get( 'scopes' ) );
		$this->assertSame( OAuth::issuer(), $claims->get( 'iss' ) );
		$this->assertTrue( $claims->has( 'iat' ) && $claims->has( 'nbf' ) && $claims->has( 'exp' ) );
		$this->assertSame( 'RS256', $parsed->headers()->get( 'alg' ) );

		$public = Keys::get()['public']->getKeyContents();
		$this->assertTrue( ( new Validator() )->validate( $parsed, new SignedWith( new Sha256(), InMemory::plainText( $public ) ) ) );

		// The league resource server accepts it and exposes the audience.
		$request   = ( new ServerRequest( 'POST', OAuth::resource_url() ) )->withHeader( 'Authorization', 'Bearer ' . $jwt );
		$validated = Server_Factory::resource_server()->validateAuthenticatedRequest( $request );
		$this->assertSame( OAuth::resource_url(), $validated->getAttribute( 'oauth_client_id' ) );
		$this->assertSame( $token->getIdentifier(), $validated->getAttribute( 'oauth_access_token_id' ) );
		$this->assertSame( (string) $user_id, $validated->getAttribute( 'oauth_user_id' ) );

		// The row keeps the real client ID for the bearer guard.
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'access_tokens' ) . ' WHERE id_hash = %s', hash( 'sha256', $token->getIdentifier() ) ) );
		$this->assertSame( 'dcr-jwt', $row->client_id );
		$this->assertSame( $user_id, (int) $row->user_id );
		$this->assertSame( 'content:read content:write', $row->scopes );
	}

	public function test_access_token_revoked_when_mcp_user_changes_or_becomes_admin() {
		$repo    = new Access_Token_Repository();
		$user_id = $this->make_mcp_user();
		$client  = $this->insert_client();
		$token   = $this->issue_access_token( $client, $user_id );
		$id      = $token->getIdentifier();
		$refresh = $this->issue_refresh_token( $token )->getIdentifier();

		$this->assertFalse( $repo->isAccessTokenRevoked( $id ) );
		$this->assertFalse( ( new Refresh_Token_Repository() )->isRefreshTokenRevoked( $refresh ) );

		// A different MCP user.
		$other = $this->make_mcp_user();
		$this->assertTrue( $repo->isAccessTokenRevoked( $id ) );
		$this->assertTrue( ( new Refresh_Token_Repository() )->isRefreshTokenRevoked( $refresh ), 'refresh refused too' );
		$this->set_mcp_user( $user_id );
		$this->assertFalse( $repo->isAccessTokenRevoked( $id ) );

		// The MCP user gains manage_options.
		$user = get_userdata( $user_id );
		$user->add_cap( 'manage_options' );
		$this->assertTrue( $repo->isAccessTokenRevoked( $id ) );
		$user->remove_cap( 'manage_options' );
		$this->assertFalse( $repo->isAccessTokenRevoked( $id ) );

		// The MCP user is deleted.
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $user_id, $other );
		$this->assertTrue( $repo->isAccessTokenRevoked( $id ) );

		// Unknown and explicitly revoked tokens.
		$this->assertTrue( $repo->isAccessTokenRevoked( 'unknown-token-id' ) );
		$this->set_mcp_user( $other );
		$id2 = $this->issue_access_token( $client, $other )->getIdentifier();
		$this->assertFalse( $repo->isAccessTokenRevoked( $id2 ) );
		$repo->revokeAccessToken( $id2 );
		$this->assertTrue( $repo->isAccessTokenRevoked( $id2 ) );
	}

	// ---------------------------------------------------------------------
	// Refresh token reuse (Review Focus 3).
	// ---------------------------------------------------------------------

	public function test_rotated_refresh_token_within_grace_does_not_revoke_connection() {
		$user_id = $this->make_mcp_user();
		$client  = $this->insert_client();
		$repo    = new Refresh_Token_Repository();
		$access  = new Access_Token_Repository();

		$refresh = $this->issue_refresh_token( $this->issue_access_token( $client, $user_id ) );
		$live    = $this->issue_access_token( $client, $user_id );

		$this->assertFalse( $repo->isRefreshTokenRevoked( $refresh->getIdentifier() ) );
		$repo->revokeRefreshToken( $refresh->getIdentifier() );
		$this->set_rotated_at( $refresh->getIdentifier(), time() - 5 );

		$this->assertTrue( $repo->isRefreshTokenRevoked( $refresh->getIdentifier() ) );
		$this->assertFalse( $access->isAccessTokenRevoked( $live->getIdentifier() ) );
	}

	public function test_rotated_refresh_token_after_grace_revokes_connection() {
		$user_id = $this->make_mcp_user();
		$client  = $this->insert_client( 'dcr-reuse' );
		$bystand = $this->insert_client( 'dcr-other' );
		$repo    = new Refresh_Token_Repository();
		$access  = new Access_Token_Repository();

		$refresh       = $this->issue_refresh_token( $this->issue_access_token( $client, $user_id ) );
		$live          = $this->issue_access_token( $client, $user_id );
		$live_refresh  = $this->issue_refresh_token( $live );
		$other_access  = $this->issue_access_token( $bystand, $user_id );
		$other_refresh = $this->issue_refresh_token( $other_access );

		$repo->revokeRefreshToken( $refresh->getIdentifier() );
		$this->set_rotated_at( $refresh->getIdentifier(), time() - 60 );

		$this->assertTrue( $repo->isRefreshTokenRevoked( $refresh->getIdentifier() ) );
		$this->assertTrue( $access->isAccessTokenRevoked( $live->getIdentifier() ) );
		$this->assertTrue( $repo->isRefreshTokenRevoked( $live_refresh->getIdentifier() ) );

		// Another client's connection is untouched.
		$this->assertFalse( $access->isAccessTokenRevoked( $other_access->getIdentifier() ) );
		$this->assertFalse( $repo->isRefreshTokenRevoked( $other_refresh->getIdentifier() ) );
	}

	public function test_full_flow_issues_rotates_and_detects_refresh_reuse() {
		$user_id = $this->make_mcp_user();
		$this->insert_client( 'dcr-flow', [ self::REDIRECT_URI ] );
		$server = Server_Factory::authorization_server();

		$tokens = $this->authorize_and_exchange( $server, 'dcr-flow', $user_id, 'content:publish' );
		$this->assertSame( 'Bearer', $tokens['token_type'] );
		$this->assertLessThanOrEqual( 3600, $tokens['expires_in'] );
		$this->assertGreaterThan( 3500, $tokens['expires_in'] );
		$claims = ( new Parser( new JoseEncoder() ) )->parse( $tokens['access_token'] )->claims();
		$this->assertSame( [ 'content:read', 'content:publish' ], $claims->get( 'scopes' ) );
		$this->assertSame( 'dcr-flow', $claims->get( 'cid' ) );

		// Rotate once.
		$rotated = $this->refresh( $server, 'dcr-flow', $tokens['refresh_token'] );
		$this->assertNotSame( $tokens['refresh_token'], $rotated['refresh_token'] );
		$access  = new Access_Token_Repository();
		$old_jti = $claims->get( 'jti' );
		$new_jti = ( new Parser( new JoseEncoder() ) )->parse( $rotated['access_token'] )->claims()->get( 'jti' );
		$this->assertTrue( $access->isAccessTokenRevoked( $old_jti ), 'the replaced access token is revoked' );
		$this->assertFalse( $access->isAccessTokenRevoked( $new_jti ) );

		// Retry with the rotated token right away: invalid_grant, connection kept.
		$this->assertSame( 'invalid_grant', $this->refresh_error( $server, 'dcr-flow', $tokens['refresh_token'] ) );
		$this->assertFalse( $access->isAccessTokenRevoked( $new_jti ) );

		// Reuse after the grace window: the whole connection is revoked.
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . Schema::table( 'refresh_tokens' ) . ' SET rotated_at = %s WHERE rotated_at IS NOT NULL', gmdate( 'Y-m-d H:i:s', time() - 60 ) ) );
		$this->assertSame( 'invalid_grant', $this->refresh_error( $server, 'dcr-flow', $tokens['refresh_token'] ) );
		$this->assertTrue( $access->isAccessTokenRevoked( $new_jti ) );
		$this->assertSame( 'invalid_grant', $this->refresh_error( $server, 'dcr-flow', $rotated['refresh_token'] ) );
	}

	public function test_auth_code_is_single_use_and_requires_s256() {
		$user_id = $this->make_mcp_user();
		$this->insert_client( 'dcr-code', [ self::REDIRECT_URI ] );
		$server = Server_Factory::authorization_server();

		// Plain PKCE is refused.
		try {
			$server->validateAuthorizationRequest(
				Psr7_Bridge::from_query(
					[
						'response_type'         => 'code',
						'client_id'             => 'dcr-code',
						'redirect_uri'          => self::REDIRECT_URI,
						'code_challenge'        => self::VERIFIER,
						'code_challenge_method' => 'plain',
					]
				)
			);
			$this->fail( 'plain PKCE accepted' );
		} catch ( OAuthServerException $e ) {
			$this->assertSame( 'invalid_request', $e->getErrorType() );
		}

		$code = $this->authorize( $server, 'dcr-code', $user_id, '' );
		$this->exchange( $server, 'dcr-code', $code );

		try {
			$this->exchange( $server, 'dcr-code', $code );
			$this->fail( 'auth code reused' );
		} catch ( OAuthServerException $e ) {
			$this->assertSame( 'invalid_grant', $e->getErrorType() );
		}
	}

	// ---------------------------------------------------------------------
	// Storage.
	// ---------------------------------------------------------------------

	public function test_tokens_are_stored_hashed() {
		global $wpdb;
		$user_id = $this->make_mcp_user();
		$client  = $this->insert_client( 'dcr-hash', [ self::REDIRECT_URI ] );

		$code = ( new Auth_Code_Repository() )->getNewAuthCode();
		$code->setIdentifier( 'rawcode' . bin2hex( random_bytes( 8 ) ) );
		$code->setClient( $client );
		$code->setUserIdentifier( (string) $user_id );
		$code->setExpiryDateTime( new DateTimeImmutable( '+1 minute' ) );
		( new Auth_Code_Repository() )->persistNewAuthCode( $code );

		$access  = $this->issue_access_token( $client, $user_id );
		$refresh = $this->issue_refresh_token( $access );

		// Tokens from a real flow too.
		$server = Server_Factory::authorization_server();
		$tokens = $this->authorize_and_exchange( $server, 'dcr-hash', $user_id, '' );
		$jti    = ( new Parser( new JoseEncoder() ) )->parse( $tokens['access_token'] )->claims()->get( 'jti' );

		$dump = '';
		foreach ( [ 'auth_codes', 'access_tokens', 'refresh_tokens' ] as $table ) {
			$dump .= wp_json_encode( $wpdb->get_results( 'SELECT * FROM ' . Schema::table( $table ), ARRAY_A ) );
		}

		foreach ( [ $code->getIdentifier(), $access->getIdentifier(), $refresh->getIdentifier(), $jti ] as $raw ) {
			$this->assertStringNotContainsString( $raw, $dump );
		}
		$this->assertStringContainsString( hash( 'sha256', $code->getIdentifier() ), $dump );
		$this->assertStringContainsString( hash( 'sha256', $access->getIdentifier() ), $dump );
		$this->assertStringContainsString( hash( 'sha256', $refresh->getIdentifier() ), $dump );
		$this->assertStringContainsString( hash( 'sha256', $jti ), $dump );
		$this->assertStringNotContainsString( $tokens['access_token'], $dump );
		$this->assertStringNotContainsString( $tokens['refresh_token'], $dump );
	}

	// ---------------------------------------------------------------------
	// Tokens service.
	// ---------------------------------------------------------------------

	public function test_connections_and_revocation() {
		$user_id = $this->make_mcp_user();
		$a       = $this->insert_client( 'dcr-a', [ self::REDIRECT_URI ], 'Client A' );
		$b       = $this->insert_client( 'dcr-b', [ self::REDIRECT_URI ], 'Client B' );

		$a_token = $this->issue_access_token( $a, $user_id, [ 'content:read', 'content:write' ] );
		$this->issue_refresh_token( $a_token );
		$b_token = $this->issue_access_token( $b, $user_id, [ 'content:read' ] );

		$connections = Tokens::connections();
		$this->assertCount( 2, $connections );
		$by_client = array_column( $connections, null, 'client_id' );
		$this->assertSame( 'Client A', $by_client['dcr-a']['client_name'] );
		$this->assertSame( $user_id, $by_client['dcr-a']['user_id'] );
		$this->assertSame( [ 'content:read', 'content:write' ], $by_client['dcr-a']['scopes'] );
		$this->assertNotEmpty( $by_client['dcr-a']['created_at'] );
		$this->assertArrayHasKey( 'last_used_at', $by_client['dcr-a'] );
		$this->assertSame( [ 'content:read' ], $by_client['dcr-b']['scopes'] );

		$this->assertCount( 2, Tokens::connections( $user_id ) );
		$this->assertCount( 0, Tokens::connections( $user_id + 1000 ) );

		$this->assertSame( 2, Tokens::revoke_connection( 'dcr-a', $user_id ) );
		$this->assertSame( [ 'dcr-b' ], array_column( Tokens::connections(), 'client_id' ) );
		$this->assertTrue( ( new Access_Token_Repository() )->isAccessTokenRevoked( $a_token->getIdentifier() ) );

		$this->assertSame( 1, Tokens::revoke_all() );
		$this->assertSame( [], Tokens::connections() );
		$this->assertTrue( ( new Access_Token_Repository() )->isAccessTokenRevoked( $b_token->getIdentifier() ) );
	}

	public function test_prune_removes_expired_rows_and_stale_clients() {
		global $wpdb;
		$user_id = $this->make_mcp_user();
		$client  = $this->insert_client( 'dcr-live' );

		$expired = $this->issue_access_token( $client, $user_id );
		$wpdb->update( Schema::table( 'access_tokens' ), [ 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 10 ) ], [ 'id_hash' => hash( 'sha256', $expired->getIdentifier() ) ] );

		// Expired access token still referenced by a live refresh token is kept (connection scopes).
		$kept = $this->issue_access_token( $client, $user_id );
		$this->issue_refresh_token( $kept );
		$wpdb->update( Schema::table( 'access_tokens' ), [ 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 10 ) ], [ 'id_hash' => hash( 'sha256', $kept->getIdentifier() ) ] );

		$this->issue_access_token( $client, $user_id );

		$old_refresh = $this->issue_refresh_token( $this->issue_access_token( $client, $user_id ) );
		$wpdb->update( Schema::table( 'refresh_tokens' ), [ 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 10 ) ], [ 'id_hash' => hash( 'sha256', $old_refresh->getIdentifier() ) ] );

		$wpdb->insert(
			Schema::table( 'auth_codes' ),
			[ 'id_hash' => hash( 'sha256', 'old-code' ), 'client_id' => 'dcr-live', 'user_id' => $user_id, 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 10 ) ]
		);

		$this->insert_client( 'dcr-stale', [ self::REDIRECT_URI ], 'Stale', 0, time() - DAY_IN_SECONDS - 60 );
		$this->insert_client( 'dcr-fresh', [ self::REDIRECT_URI ], 'Fresh', 0, time() - 60 );
		$this->insert_client( 'dcr-done', [ self::REDIRECT_URI ], 'Done', 1, time() - 2 * DAY_IN_SECONDS );

		$this->assertSame( 4, Tokens::prune() );

		$this->assertSame( '0', $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( 'auth_codes' ) ) );
		$this->assertSame( '1', $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( 'refresh_tokens' ) ) );
		$this->assertSame( '3', $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( 'access_tokens' ) ) );
		$this->assertNull( $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Schema::table( 'clients' ) . ' WHERE client_id = %s', 'dcr-stale' ) ) );
		$this->assertNotNull( $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Schema::table( 'clients' ) . ' WHERE client_id = %s', 'dcr-fresh' ) ) );
		$this->assertNotNull( $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Schema::table( 'clients' ) . ' WHERE client_id = %s', 'dcr-done' ) ) );
	}

	// ---------------------------------------------------------------------
	// PSR-7 bridge.
	// ---------------------------------------------------------------------

	public function test_psr7_bridge() {
		$rest = new WP_REST_Request( 'POST', '/md3-wp-mcp/v1/oauth/token' );
		$rest->set_header( 'Content-Type', 'application/x-www-form-urlencoded' );
		$rest->set_header( 'Authorization', 'Basic abc' );
		$rest->set_body_params( [ 'grant_type' => 'refresh_token', 'client_id' => 'x' ] );
		$rest->set_query_params( [ 'q' => '1' ] );

		$psr = Psr7_Bridge::from_rest( $rest );
		$this->assertSame( 'POST', $psr->getMethod() );
		$this->assertSame( [ 'grant_type' => 'refresh_token', 'client_id' => 'x' ], $psr->getParsedBody() );
		$this->assertSame( [ 'q' => '1' ], $psr->getQueryParams() );
		$this->assertSame( 'Basic abc', $psr->getHeaderLine( 'Authorization' ) );
		$this->assertSame( 'application/x-www-form-urlencoded', $psr->getHeaderLine( 'Content-Type' ) );

		$query = Psr7_Bridge::from_query( [ 'response_type' => 'code', 'client_id' => 'x' ] );
		$this->assertSame( 'GET', $query->getMethod() );
		$this->assertSame( [ 'response_type' => 'code', 'client_id' => 'x' ], $query->getQueryParams() );

		$json = ( new Response( 400, [ 'Content-Type' => 'application/json', 'Cache-Control' => 'no-store' ] ) );
		$json->getBody()->write( '{"error":"invalid_grant"}' );
		$out = Psr7_Bridge::to_rest( $json );
		$this->assertInstanceOf( WP_REST_Response::class, $out );
		$this->assertSame( 400, $out->get_status() );
		$this->assertSame( [ 'error' => 'invalid_grant' ], $out->get_data() );
		$this->assertSame( 'no-store', $out->get_headers()['Cache-Control'] );

		$redirect = Psr7_Bridge::to_rest( new Response( 302, [ 'Location' => 'https://claude.ai/cb?code=1' ] ) );
		$this->assertSame( 302, $redirect->get_status() );
		$this->assertSame( 'https://claude.ai/cb?code=1', $redirect->get_headers()['Location'] );
	}

	// ---------------------------------------------------------------------
	// Helpers.
	// ---------------------------------------------------------------------

	private function make_mcp_user(): int {
		$user_id = (int) self::factory()->user->create( [ 'role' => 'editor' ] );
		$this->set_mcp_user( $user_id );
		return $user_id;
	}

	private function set_mcp_user( int $user_id ): void {
		$security                = Settings::security();
		$security['mcp_user_id'] = $user_id;
		update_option( Settings::OPT_SECURITY, $security );
	}

	/**
	 * @param string[] $redirect_uris Redirect URIs.
	 */
	private function insert_client( string $client_id = 'dcr-test', array $redirect_uris = [ self::REDIRECT_URI ], string $name = 'Test client', int $completed = 1, ?int $created = null ): Client_Entity {
		global $wpdb;
		$wpdb->insert(
			Schema::table( 'clients' ),
			[
				'client_id'     => $client_id,
				'name'          => $name,
				'redirect_uris' => wp_json_encode( $redirect_uris ),
				'type'          => 'dcr',
				'completed'     => $completed,
				'created_at'    => gmdate( 'Y-m-d H:i:s', $created ?? time() ),
			]
		);
		return new Client_Entity( $client_id, $name, $redirect_uris );
	}

	/**
	 * @param string[] $scopes Scope identifiers.
	 */
	private function issue_access_token( Client_Entity $client, int $user_id, array $scopes = [ 'content:read' ] ) {
		$repo  = new Access_Token_Repository();
		$token = $repo->getNewToken( $client, array_map( static fn ( $s ) => new Scope_Entity( $s ), $scopes ), (string) $user_id );
		$token->setIdentifier( bin2hex( random_bytes( 20 ) ) );
		$token->setExpiryDateTime( new DateTimeImmutable( '+1 hour' ) );
		$token->setPrivateKey( Keys::get()['private'] );
		$repo->persistNewAccessToken( $token );
		return $token;
	}

	private function issue_refresh_token( $access_token ) {
		$repo    = new Refresh_Token_Repository();
		$refresh = $repo->getNewRefreshToken();
		$refresh->setIdentifier( bin2hex( random_bytes( 20 ) ) );
		$refresh->setExpiryDateTime( new DateTimeImmutable( '+30 days' ) );
		$refresh->setAccessToken( $access_token );
		$repo->persistNewRefreshToken( $refresh );
		return $refresh;
	}

	private function set_rotated_at( string $refresh_id, int $timestamp ): void {
		global $wpdb;
		$wpdb->update(
			Schema::table( 'refresh_tokens' ),
			[ 'rotated_at' => gmdate( 'Y-m-d H:i:s', $timestamp ) ],
			[ 'id_hash' => hash( 'sha256', $refresh_id ) ]
		);
	}

	/**
	 * @param array<string,mixed>|string $doc Document (array) or raw body.
	 */
	private function mock_cimd( $doc, int $code = 200 ): void {
		remove_all_filters( 'pre_http_request' );
		$this->http_calls = 0;
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( $doc, $code ) {
				$this->assertSame( self::CIMD_URL, $url );
				++$this->http_calls;
				$this->http_args = $args;
				return [
					'headers'  => [ 'content-type' => 'application/json' ],
					'body'     => is_string( $doc ) ? $doc : wp_json_encode( $doc ),
					'response' => [ 'code' => $code, 'message' => 'OK' ],
					'cookies'  => [],
					'filename' => null,
				];
			},
			10,
			3
		);
	}

	private function authorize( $server, string $client_id, int $user_id, string $scope ): string {
		$query = [
			'response_type'         => 'code',
			'client_id'             => $client_id,
			'redirect_uri'          => self::REDIRECT_URI,
			'state'                 => 'xyz',
			'code_challenge'        => rtrim( strtr( base64_encode( hash( 'sha256', self::VERIFIER, true ) ), '+/', '-_' ), '=' ),
			'code_challenge_method' => 'S256',
		];
		if ( '' !== $scope ) {
			$query['scope'] = $scope;
		}

		$auth_request = $server->validateAuthorizationRequest( Psr7_Bridge::from_query( $query ) );
		$auth_request->setUser( new User_Entity( $user_id ) );
		$auth_request->setAuthorizationApproved( true );

		$response = Psr7_Bridge::to_rest( $server->completeAuthorizationRequest( $auth_request, new Response() ) );
		$location = $response->get_headers()['Location'];
		$this->assertStringStartsWith( self::REDIRECT_URI . '?', $location );
		parse_str( (string) wp_parse_url( $location, PHP_URL_QUERY ), $params );
		$this->assertSame( 'xyz', $params['state'] );

		return $params['code'];
	}

	/**
	 * @return array<string,mixed> Token response.
	 */
	private function exchange( $server, string $client_id, string $code ): array {
		return $this->token_request(
			$server,
			[
				'grant_type'    => 'authorization_code',
				'client_id'     => $client_id,
				'code'          => $code,
				'redirect_uri'  => self::REDIRECT_URI,
				'code_verifier' => self::VERIFIER,
			]
		);
	}

	/**
	 * @return array<string,mixed> Token response.
	 */
	private function authorize_and_exchange( $server, string $client_id, int $user_id, string $scope ): array {
		return $this->exchange( $server, $client_id, $this->authorize( $server, $client_id, $user_id, $scope ) );
	}

	/**
	 * @return array<string,mixed> Token response.
	 */
	private function refresh( $server, string $client_id, string $refresh_token ): array {
		return $this->token_request(
			$server,
			[
				'grant_type'    => 'refresh_token',
				'client_id'     => $client_id,
				'refresh_token' => $refresh_token,
			]
		);
	}

	private function refresh_error( $server, string $client_id, string $refresh_token ): string {
		try {
			$this->refresh( $server, $client_id, $refresh_token );
		} catch ( OAuthServerException $e ) {
			return $e->getErrorType();
		}
		return 'no error';
	}

	/**
	 * @param array<string,string> $params Form parameters.
	 * @return array<string,mixed> Token response.
	 */
	private function token_request( $server, array $params ): array {
		$rest = new WP_REST_Request( 'POST', '/md3-wp-mcp/v1/oauth/token' );
		$rest->set_header( 'Content-Type', 'application/x-www-form-urlencoded' );
		$rest->set_body_params( $params );

		$response = Psr7_Bridge::to_rest( $server->respondToAccessTokenRequest( Psr7_Bridge::from_rest( $rest ), new Response() ) );
		$this->assertSame( 200, $response->get_status() );
		return $response->get_data();
	}
}
