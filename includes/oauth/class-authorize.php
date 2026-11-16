<?php
namespace MD3\WPMCP\OAuth;

use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\RequestTypes\AuthorizationRequestInterface;
use MD3\WPMCP\Auth\Scopes;
use MD3\WPMCP\OAuth\Entities\Scope_Entity;
use MD3\WPMCP\OAuth\Entities\User_Entity;
use MD3\WPMCP\Plugin;
use MD3\WPMCP\Rate_Limiter;
use MD3\WPMCP\Schema;
use MD3\WPMCP\Settings;
use Nyholm\Psr7\Response;

defined( 'ABSPATH' ) || exit;

/**
 * The authorize page, `/md3-mcp/authorize` (design §4.2, §4.5).
 *
 * GET validates the request, signs the user in through WordPress, checks
 * that they are the MCP user and shows the consent page. POST records the
 * decision and redirects back to the client.
 */
final class Authorize {

	const QUERY_VAR        = 'md3_mcp_authorize';
	const REWRITE_REGEX    = '^md3-mcp/authorize/?$';
	const CONSENT_PREFIX   = 'md3_mcp_consent_';
	const CONSENT_TTL      = 600;
	const RATE_LIMIT       = 30;
	const RATE_WINDOW      = 60;
	const OAUTH_PARAMETERS = [ 'response_type', 'client_id', 'redirect_uri', 'scope', 'state', 'code_challenge', 'code_challenge_method', 'resource' ];

	/**
	 * Adds the rewrite rule and query var, and handles the page on
	 * `parse_request` priority 1, before any theme output.
	 */
	public static function register(): void {
		add_action( 'init', [ self::class, 'add_rewrite_rule' ] );
		add_filter( 'query_vars', [ self::class, 'query_vars' ] );
		add_action( 'parse_request', [ self::class, 'maybe_handle' ], 1 );
	}

	/**
	 * Also called on activation, before rewrite rules are flushed.
	 */
	public static function add_rewrite_rule(): void {
		if ( ( $GLOBALS['wp_rewrite'] ?? null ) instanceof \WP_Rewrite ) {
			add_rewrite_rule( self::REWRITE_REGEX, 'index.php?' . self::QUERY_VAR . '=1', 'top' );
		}
	}

	/**
	 * @param string[] $vars Public query vars.
	 * @return string[]
	 */
	public static function query_vars( $vars ): array {
		$vars   = (array) $vars;
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * Answers the authorize page and ends the request.
	 */
	public static function maybe_handle( \WP $wp ): void {
		if ( empty( $wp->query_vars[ self::QUERY_VAR ] ) ) {
			return;
		}

		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'get';
		// phpcs:disable WordPress.Security.NonceVerification -- handle() verifies the consent nonce.
		$query = wp_unslash( $_GET );
		$post  = wp_unslash( $_POST );
		// phpcs:enable

		self::send( self::handle( $method, (array) $query, (array) $post ) );
	}

	/**
	 * Handles one request to the authorize page.
	 *
	 * GET checks, in order: the transport, the rate limit, the request itself
	 * (400 before anyone is asked to sign in), sign-in, then the MCP user.
	 *
	 * @param array<string,mixed> $query GET parameters.
	 * @param array<string,mixed> $post  POST parameters.
	 * @return \WP_HTTP_Response|\WP_Error The page or redirect, or an error
	 *         whose message is safe HTML and whose data has the HTTP status.
	 */
	public static function handle( string $method, array $query, array $post ) {
		if ( ! OAuth::transport_allowed() ) {
			return self::error( 403, esc_html__( 'Connecting an AI client requires HTTPS.', 'md3-wp-mcp' ) );
		}
		if ( ! Rate_Limiter::hit( 'authorize', Plugin::client_ip_hash(), self::RATE_LIMIT, self::RATE_WINDOW ) ) {
			return self::error( 429, esc_html__( 'Too many requests. Wait a minute and try again.', 'md3-wp-mcp' ) );
		}

		$method = strtoupper( $method );
		if ( 'POST' === $method ) {
			return self::decide( $post );
		}
		if ( 'GET' !== $method ) {
			return self::error( 405, esc_html__( 'Method not allowed.', 'md3-wp-mcp' ) );
		}

		return self::consent( $query );
	}

	/**
	 * Validates an authorization request with league (client, redirect URI,
	 * scopes, PKCE), plus:
	 * - `code_challenge_method` must be `S256`;
	 * - `resource` (RFC 8707) must be empty or the MCP endpoint, with or
	 *   without one trailing slash; otherwise `invalid_target`.
	 *
	 * @param array<string,mixed> $query Authorize query parameters.
	 * @return AuthorizationRequestInterface|\WP_Error Error data has `status` 400.
	 */
	public static function validate( array $query ) {
		$query = self::oauth_parameters( $query );
		foreach ( $query as $value ) {
			if ( ! is_string( $value ) ) {
				return new \WP_Error( 'invalid_request', __( 'Each parameter must have a single value.', 'md3-wp-mcp' ), [ 'status' => 400 ] );
			}
		}

		try {
			$auth = Server_Factory::authorization_server()->validateAuthorizationRequest( Psr7_Bridge::from_query( $query ) );
		} catch ( OAuthServerException $e ) {
			$message = trim( $e->getMessage() . ' ' . (string) $e->getHint() );
			return new \WP_Error( $e->getErrorType(), $message, [ 'status' => 400 ] );
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'server_error', __( 'The request could not be checked.', 'md3-wp-mcp' ), [ 'status' => 500 ] );
		}

		if ( 'S256' !== $auth->getCodeChallengeMethod() ) {
			return new \WP_Error( 'invalid_request', __( 'Code challenge method must be S256.', 'md3-wp-mcp' ), [ 'status' => 400 ] );
		}

		$resource = $query['resource'] ?? '';
		$expected = untrailingslashit( OAuth::resource_url() );
		if ( '' !== $resource && $expected !== $resource && $expected . '/' !== $resource ) {
			return new \WP_Error( 'invalid_target', __( 'This server only issues tokens for its MCP endpoint.', 'md3-wp-mcp' ), [ 'status' => 400 ] );
		}

		return $auth;
	}

	/**
	 * Completes an authorization request and returns where to send the
	 * browser: the client's redirect URI with a code, or with
	 * `error=access_denied` when the user denied it.
	 *
	 * The granted scopes are those both requested and approved, plus
	 * `content:read`. On approval a DCR client is marked completed (so it is
	 * not pruned) and a CIMD client gets its `clients` row.
	 *
	 * @param array<string,mixed> $query           Authorize query parameters.
	 * @param string[]            $approved_scopes Scopes the user left checked.
	 * @return string|\WP_Error Redirect URL; the validation error when the
	 *         request is no longer valid (for example the client was removed);
	 *         or a 500 `server_error` when no redirect could be built.
	 */
	public static function complete( array $query, int $user_id, array $approved_scopes, bool $approved ) {
		$auth = self::validate( $query );
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}

		$requested = self::scope_ids( $auth->getScopes() );
		$granted   = array_intersect( $requested, array_filter( $approved_scopes, 'is_string' ) );
		$granted   = array_values( array_intersect( Scopes::all(), array_merge( [ Scopes::READ ], $granted ) ) );

		$auth->setScopes( array_map( static fn ( string $scope ): Scope_Entity => new Scope_Entity( $scope ), $granted ) );
		$auth->setUser( new User_Entity( $user_id ) );
		$auth->setAuthorizationApproved( $approved );

		try {
			$response = Server_Factory::authorization_server()->completeAuthorizationRequest( $auth, new Response() );
		} catch ( OAuthServerException $e ) {
			$response = $e->generateHttpResponse( new Response() );
		} catch ( \Throwable $e ) {
			return self::server_error();
		}

		// A server error (the code could not be stored…) has no redirect.
		$location = $response->getHeaderLine( 'Location' );
		if ( 302 !== $response->getStatusCode() || '' === $location ) {
			return self::server_error();
		}

		parse_str( (string) wp_parse_url( $location, PHP_URL_QUERY ), $params );
		if ( $approved && isset( $params['code'] ) ) {
			self::remember_client( $auth->getClient() );
		}

		return $location;
	}

	private static function server_error(): \WP_Error {
		return new \WP_Error( 'server_error', __( 'The authorization could not be completed.', 'md3-wp-mcp' ), [ 'status' => 500 ] );
	}

	/**
	 * GET: validates, signs in, checks the MCP user and shows consent.
	 *
	 * @param array<string,mixed> $query GET parameters.
	 * @return \WP_HTTP_Response|\WP_Error
	 */
	private static function consent( array $query ) {
		$query = self::oauth_parameters( $query );
		$auth  = self::validate( $query );
		if ( is_wp_error( $auth ) ) {
			return self::error(
				(int) ( $auth->get_error_data()['status'] ?? 400 ),
				esc_html__( 'This connection request is not valid. Start connecting again from your AI app.', 'md3-wp-mcp' ) . '<br><code>' . esc_html( $auth->get_error_code() . ': ' . $auth->get_error_message() ) . '</code>'
			);
		}

		self::require_login();

		$mcp_user_id = Settings::mcp_user_id();
		if ( 0 === $mcp_user_id ) {
			return self::error( 503, esc_html__( 'No MCP user is set up yet. An administrator must choose the MCP user in Settings > MD3 WP MCP.', 'md3-wp-mcp' ) );
		}

		$user = wp_get_current_user();
		if ( $user->ID !== $mcp_user_id ) {
			$login = get_userdata( $mcp_user_id )->user_login;
			$self  = OAuth::authorize_url() . '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
			return self::error(
				403,
				sprintf(
					/* translators: %s: login name of the MCP user. */
					esc_html__( 'Only the MCP user can approve connections. Sign in as %s to connect.', 'md3-wp-mcp' ),
					'<strong>' . esc_html( $login ) . '</strong>'
				) . ' <a href="' . esc_url( wp_logout_url( $self ) ) . '">' . esc_html__( 'Log out and sign in as that user', 'md3-wp-mcp' ) . '</a>'
			);
		}

		$token = wp_generate_password( 32, false );
		set_transient(
			self::CONSENT_PREFIX . $token,
			[
				'user_id' => $user->ID,
				'query'   => $query,
			],
			self::CONSENT_TTL
		);

		return new \WP_HTTP_Response( self::render( $auth, $token, $user ), 200, self::page_headers() );
	}

	/**
	 * POST: checks the nonce, the stored request and the user, then completes.
	 *
	 * @param array<string,mixed> $post POST parameters.
	 * @return \WP_HTTP_Response|\WP_Error
	 */
	private static function decide( array $post ) {
		$expired = esc_html__( 'This approval page has expired. Start connecting again from your AI app.', 'md3-wp-mcp' );

		$token = $post['md3_mcp_token'] ?? '';
		if ( ! is_user_logged_in() || ! is_string( $token ) || ! preg_match( '/^[A-Za-z0-9]{32}$/', $token ) ) {
			return self::error( 403, $expired );
		}

		$nonce = $post['_wpnonce'] ?? '';
		if ( ! is_string( $nonce ) || ! wp_verify_nonce( $nonce, self::CONSENT_PREFIX . $token ) ) {
			return self::error( 403, $expired );
		}

		$user_id = get_current_user_id();
		$stored  = get_transient( self::CONSENT_PREFIX . $token );
		if ( ! is_array( $stored ) || ! is_array( $stored['query'] ?? null ) || (int) ( $stored['user_id'] ?? 0 ) !== $user_id || Settings::mcp_user_id() !== $user_id ) {
			return self::error( 403, $expired );
		}
		delete_transient( self::CONSENT_PREFIX . $token );

		$scopes   = isset( $post['scopes'] ) && is_array( $post['scopes'] ) ? $post['scopes'] : [];
		$location = self::complete( $stored['query'], $user_id, $scopes, 'approve' === ( $post['decision'] ?? '' ) );
		if ( is_wp_error( $location ) ) {
			return self::error( (int) ( $location->get_error_data()['status'] ?? 400 ), esc_html( $location->get_error_message() ) );
		}

		return new \WP_HTTP_Response(
			null,
			302,
			[
				'Location'        => $location,
				'Cache-Control'   => 'no-store',
				'Referrer-Policy' => 'no-referrer',
			]
		);
	}

	/**
	 * Sends the browser to the WordPress login when nobody is signed in.
	 *
	 * The front end only receives the `logged_in` cookie (the `auth` cookie is
	 * limited to /wp-admin), so auth_redirect() is told to check that one;
	 * otherwise every sign-in would loop back to the login form.
	 */
	private static function require_login(): void {
		if ( is_user_logged_in() ) {
			return;
		}

		$scheme = static fn (): string => 'logged_in';
		add_filter( 'auth_redirect_scheme', $scheme );
		auth_redirect();
		remove_filter( 'auth_redirect_scheme', $scheme );
	}

	/**
	 * Marks a DCR client completed, or stores an approved CIMD client.
	 */
	private static function remember_client( ClientEntityInterface $client ): void {
		global $wpdb;
		$table     = Schema::table( 'clients' );
		$client_id = $client->getIdentifier();
		$now       = Tokens::utc( time() );

		$type = $wpdb->get_var( $wpdb->prepare( "SELECT type FROM {$table} WHERE client_id = %s", $client_id ) );

		if ( 'dcr' === $type ) {
			$wpdb->update( $table, [ 'completed' => 1, 'last_used_at' => $now ], [ 'client_id' => $client_id, 'type' => 'dcr' ], [ '%d', '%s' ], [ '%s', '%s' ] );
			return;
		}

		if ( ! Cimd::is_cimd( $client_id ) || ( null !== $type && 'cimd' !== $type ) ) {
			return;
		}

		$row = [
			'name'          => mb_substr( (string) $client->getName(), 0, 191 ),
			'redirect_uris' => (string) wp_json_encode( array_values( (array) $client->getRedirectUri() ), JSON_UNESCAPED_SLASHES ),
			'completed'     => 1,
			'last_used_at'  => $now,
		];

		if ( null === $type ) {
			$wpdb->insert(
				$table,
				$row + [
					'client_id'       => $client_id,
					'type'            => 'cimd',
					'created_ip_hash' => Plugin::client_ip_hash(),
					'created_at'      => $now,
				],
				[ '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' ]
			);
		} else {
			$wpdb->update( $table, $row, [ 'client_id' => $client_id, 'type' => 'cimd' ], [ '%s', '%s', '%d', '%s' ], [ '%s', '%s' ] );
		}
	}

	/**
	 * The consent page HTML.
	 */
	private static function render( AuthorizationRequestInterface $auth, string $token, \WP_User $user ): string {
		$client    = $auth->getClient();
		$redirect  = $auth->getRedirectUri() ?? ( (array) $client->getRedirectUri() )[0] ?? '';
		$requested = self::scope_ids( $auth->getScopes() );

		$scopes = [];
		foreach ( Scopes::descriptions() as $scope => $label ) {
			if ( Scopes::READ !== $scope && ! in_array( $scope, $requested, true ) ) {
				continue;
			}
			$scopes[] = [
				'id'       => $scope,
				'label'    => $label,
				'checked'  => in_array( $scope, [ Scopes::READ, Scopes::WRITE ], true ),
				'disabled' => Scopes::READ === $scope,
			];
		}

		$view = [
			'client_name'    => (string) $client->getName(),
			'redirect_label' => self::redirect_label( $redirect ),
			'scopes'         => $scopes,
			'token'          => $token,
			'nonce_action'   => self::CONSENT_PREFIX . $token,
			'action_url'     => OAuth::authorize_url(),
			'user_login'     => $user->user_login,
			'site_name'      => get_bloginfo( 'name' ),
		];

		ob_start();
		( static function ( array $view ): void {
			require MD3_WP_MCP_DIR . 'includes/admin/views/consent.php';
		} )( $view );
		return (string) ob_get_clean();
	}

	/**
	 * Where the client will receive the code: the host (and port) for web and
	 * loopback redirects, `scheme://host` for app schemes.
	 */
	private static function redirect_label( string $uri ): string {
		$parts  = wp_parse_url( $uri );
		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		$host   = (string) ( $parts['host'] ?? '' );

		if ( 'https' === $scheme || 'http' === $scheme ) {
			return $host . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
		}
		return $scheme . '://' . $host;
	}

	/**
	 * Headers for the consent page: never cached, never framed, no referrer.
	 *
	 * @return array<string,string>
	 */
	private static function page_headers(): array {
		return [
			'Content-Type'            => 'text/html; charset=' . get_option( 'blog_charset' ),
			'Cache-Control'           => 'no-store, no-cache, must-revalidate, max-age=0',
			'Pragma'                  => 'no-cache',
			'X-Frame-Options'         => 'DENY',
			'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; frame-ancestors 'none'",
			'Referrer-Policy'         => 'no-referrer',
			'X-Content-Type-Options'  => 'nosniff',
		];
	}

	/**
	 * Sends a handle() result and ends the request.
	 *
	 * @param \WP_HTTP_Response|\WP_Error $result Result.
	 */
	private static function send( $result ): void {
		nocache_headers();

		if ( is_wp_error( $result ) ) {
			header( 'X-Frame-Options: DENY' );
			header( 'Referrer-Policy: no-referrer' );
			wp_die(
				$result->get_error_message(), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
				esc_html__( 'MD3 WP MCP', 'md3-wp-mcp' ),
				[ 'response' => (int) ( $result->get_error_data()['status'] ?? 400 ) ]
			);
		}

		$headers = $result->get_headers();
		if ( 302 === $result->get_status() ) {
			header( 'Referrer-Policy: no-referrer' );
			wp_redirect( $headers['Location'], 302, 'MD3 WP MCP' ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- the client's validated redirect URI.
			exit;
		}

		status_header( $result->get_status() );
		foreach ( $headers as $name => $value ) {
			header( $name . ': ' . $value );
		}
		echo $result->get_data(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rendered by the escaped consent view.
		exit;
	}

	/**
	 * A handle() error: the message is safe HTML.
	 */
	private static function error( int $status, string $html ): \WP_Error {
		return new \WP_Error( 'md3_mcp_authorize', $html, [ 'status' => $status ] );
	}

	/**
	 * Only the OAuth parameters of a query.
	 *
	 * @param array<string,mixed> $query Query parameters.
	 * @return array<string,mixed>
	 */
	private static function oauth_parameters( array $query ): array {
		return array_intersect_key( $query, array_flip( self::OAUTH_PARAMETERS ) );
	}

	/**
	 * @param ScopeEntityInterface[] $scopes Scope entities.
	 * @return string[]
	 */
	private static function scope_ids( array $scopes ): array {
		return array_map( static fn ( ScopeEntityInterface $scope ): string => $scope->getIdentifier(), $scopes );
	}
}
