<?php
namespace MD3\WPMCP\OAuth;

use DateInterval;
use Defuse\Crypto\Crypto;
use Defuse\Crypto\Exception\BadFormatException;
use Defuse\Crypto\Key;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Grant\AuthCodeGrant;
use League\OAuth2\Server\Grant\RefreshTokenGrant;
use League\OAuth2\Server\RequestTypes\AuthorizationRequestInterface;
use League\OAuth2\Server\ResourceServer;
use League\OAuth2\Server\ResponseTypes\BearerTokenResponse;
use MD3\WPMCP\Auth\Scopes;
use MD3\WPMCP\OAuth\Repositories\Access_Token_Repository;
use MD3\WPMCP\OAuth\Repositories\Auth_Code_Repository;
use MD3\WPMCP\OAuth\Repositories\Client_Repository;
use MD3\WPMCP\OAuth\Repositories\Refresh_Token_Repository;
use MD3\WPMCP\OAuth\Repositories\Scope_Repository;
use Psr\Http\Message\ServerRequestInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the league authorization and resource servers.
 */
final class Server_Factory {

	const AUTH_CODE_TTL     = 'PT1M';
	const ACCESS_TOKEN_TTL  = 'PT1H';
	const REFRESH_TOKEN_TTL = 'P30D';

	/**
	 * Authorization code grant with PKCE `S256` only, and the refresh token
	 * grant. Refresh tokens are replaced on every use. Token responses carry
	 * the granted `scope`.
	 */
	public static function authorization_server(): AuthorizationServer {
		$keys = Keys::get();

		$response_type = new class() extends BearerTokenResponse {
			/**
			 * @return array<string,string>
			 */
			protected function getExtraParams( AccessTokenEntityInterface $access_token ): array {
				$scopes = array_map( static fn ( ScopeEntityInterface $scope ): string => $scope->getIdentifier(), $access_token->getScopes() );
				return [ 'scope' => implode( ' ', $scopes ) ];
			}
		};

		$server = new AuthorizationServer(
			new Client_Repository(),
			new Access_Token_Repository(),
			new Scope_Repository(),
			$keys['private'],
			self::encryption_key( $keys['encryption'] ),
			$response_type
		);
		// Must be set before the grants are enabled: they copy it.
		$server->setDefaultScope( Scopes::READ . ' ' . Scopes::WRITE );

		$refresh_tokens = new Refresh_Token_Repository();

		$auth_code = new class( new Auth_Code_Repository(), $refresh_tokens, new DateInterval( self::AUTH_CODE_TTL ) ) extends AuthCodeGrant {
			/**
			 * Refuses PKCE methods other than S256 (league also accepts `plain`).
			 */
			public function validateAuthorizationRequest( ServerRequestInterface $request ): AuthorizationRequestInterface {
				$method = $request->getQueryParams()['code_challenge_method'] ?? null;
				if ( 'S256' !== $method ) {
					throw OAuthServerException::invalidRequest( 'code_challenge_method', 'Code challenge method must be `S256`.' );
				}
				return parent::validateAuthorizationRequest( $request );
			}

			/**
			 * Exact match, except that a loopback redirect (`localhost`,
			 * `127.0.0.1`, `[::1]`) may use any port (RFC 8252); league
			 * allows that only for the IP literals.
			 */
			protected function validateRedirectUri( string $redirect_uri, ClientEntityInterface $client, ServerRequestInterface $request ): void {
				if ( Redirect_Uri_Policy::matches( $redirect_uri, (array) $client->getRedirectUri() ) ) {
					return;
				}
				parent::validateRedirectUri( $redirect_uri, $client, $request );
			}
		};
		$auth_code->setRefreshTokenTTL( new DateInterval( self::REFRESH_TOKEN_TTL ) );
		$server->enableGrantType( $auth_code, new DateInterval( self::ACCESS_TOKEN_TTL ) );

		$refresh = new RefreshTokenGrant( $refresh_tokens );
		$refresh->setRefreshTokenTTL( new DateInterval( self::REFRESH_TOKEN_TTL ) );
		$server->enableGrantType( $refresh, new DateInterval( self::ACCESS_TOKEN_TTL ) );

		return $server;
	}

	/**
	 * Validates signature, time claims and revocation of a bearer JWT. The
	 * audience is checked by the bearer guard.
	 */
	public static function resource_server(): ResourceServer {
		return new ResourceServer( new Access_Token_Repository(), Keys::get()['public'] );
	}

	/**
	 * Decrypts a payload league encrypted (a refresh token or an auth code).
	 *
	 * @return string|null Null when it can't be decrypted with this site's key.
	 */
	public static function decrypt( string $payload ): ?string {
		$key = self::encryption_key( Keys::get()['encryption'] );
		try {
			return $key instanceof Key ? Crypto::decrypt( $payload, $key ) : Crypto::decryptWithPassword( $payload, $key );
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * A Defuse ASCII-safe key is used as a key; any other string (a custom
	 * MD3_WP_MCP_ENCRYPTION_KEY) as a password, which is slower but works.
	 */
	private static function encryption_key( string $encryption ): Key|string {
		try {
			return Key::loadFromAsciiSafeString( $encryption );
		} catch ( BadFormatException $e ) {
			return $encryption;
		}
	}
}
