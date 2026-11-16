<?php
namespace MD3\WPMCP\OAuth;

use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\UnencryptedToken;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\Validator;
use League\OAuth2\Server\Exception\OAuthServerException;
use MD3\WPMCP\Plugin;
use MD3\WPMCP\Rate_Limiter;
use Nyholm\Psr7\Response;

defined( 'ABSPATH' ) || exit;

/**
 * The token endpoint (OAuth 2.1) and the revocation endpoint (RFC 7009).
 */
final class Token_Endpoint {

	const RATE_LIMIT  = 30;
	const RATE_WINDOW = 60;

	/**
	 * `POST /md3-wp-mcp/v1/oauth/token`: the authorization code and refresh
	 * token grants, answered by league.
	 */
	public static function token( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! Rate_Limiter::hit( 'token', Plugin::client_ip_hash(), self::RATE_LIMIT, self::RATE_WINDOW ) ) {
			return OAuth::rate_limited( self::RATE_WINDOW );
		}

		try {
			$psr = Server_Factory::authorization_server()->respondToAccessTokenRequest( Psr7_Bridge::from_rest( $request ), new Response() );
		} catch ( OAuthServerException $e ) {
			$psr = $e->generateHttpResponse( new Response() );
		} catch ( \Throwable $e ) {
			return OAuth::error( 'server_error', __( 'The token could not be issued.', 'md3-wp-mcp' ), 500 );
		}

		$response = Psr7_Bridge::to_rest( $psr );
		foreach ( OAuth::json( null )->get_headers() as $name => $value ) {
			$response->header( $name, $value );
		}
		return $response;
	}

	/**
	 * `POST /md3-wp-mcp/v1/oauth/revoke` (RFC 7009).
	 *
	 * Accepts an access token (a JWT signed by this site) or a refresh token
	 * (decrypted with this site's key) and revokes it with its pair, but only
	 * when it belongs to `client_id`. The answer is 200 whether or not the
	 * token was known, so it reveals nothing.
	 */
	public static function revoke( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! Rate_Limiter::hit( 'revoke', Plugin::client_ip_hash(), self::RATE_LIMIT, self::RATE_WINDOW ) ) {
			return OAuth::rate_limited( self::RATE_WINDOW );
		}

		$token     = $request->get_param( 'token' );
		$client_id = $request->get_param( 'client_id' );
		if ( ! is_string( $token ) || '' === $token || ! is_string( $client_id ) || '' === $client_id ) {
			return OAuth::error( 'invalid_request', __( 'The token and client_id parameters are required.', 'md3-wp-mcp' ), 400 );
		}

		$jti = self::access_token_id( $token );
		if ( null !== $jti ) {
			Tokens::revoke_by_access_hash( hash( 'sha256', $jti ), $client_id );
		} else {
			$refresh_id = self::refresh_token_id( $token, $client_id );
			if ( null !== $refresh_id ) {
				Tokens::revoke_by_refresh_hash( hash( 'sha256', $refresh_id ), $client_id );
			}
		}

		return OAuth::json( new \stdClass() );
	}

	/**
	 * The `jti` of a JWT signed with this site's key (expired or not), or null.
	 */
	private static function access_token_id( string $token ): ?string {
		if ( 2 !== substr_count( $token, '.' ) ) {
			return null;
		}

		try {
			$jwt = ( new Parser( new JoseEncoder() ) )->parse( $token );
			$key = InMemory::plainText( Keys::get()['public']->getKeyContents() );
			if ( ! $jwt instanceof UnencryptedToken || ! ( new Validator() )->validate( $jwt, new SignedWith( new Sha256(), $key ) ) ) {
				return null;
			}
			$jti = $jwt->claims()->get( 'jti' );
		} catch ( \Throwable $e ) {
			return null;
		}

		return is_string( $jti ) && '' !== $jti ? $jti : null;
	}

	/**
	 * The ID inside a refresh token issued to this client, or null.
	 */
	private static function refresh_token_id( string $token, string $client_id ): ?string {
		$payload = Server_Factory::decrypt( $token );
		$data    = null === $payload ? null : json_decode( $payload, true );

		if ( ! is_array( $data ) || ( $data['client_id'] ?? null ) !== $client_id || ! is_string( $data['refresh_token_id'] ?? null ) ) {
			return null;
		}

		return $data['refresh_token_id'];
	}
}
