<?php
namespace MD3\WPMCP\OAuth\Repositories;

use League\OAuth2\Server\Entities\AuthCodeEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Exception\UniqueTokenIdentifierConstraintViolationException;
use League\OAuth2\Server\Repositories\AuthCodeRepositoryInterface;
use MD3\WPMCP\OAuth\Entities\Auth_Code_Entity;
use MD3\WPMCP\OAuth\Tokens;
use MD3\WPMCP\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Authorization codes, stored as the SHA-256 of their ID. The rest of the
 * code (scopes, PKCE challenge, redirect URI) travels encrypted in the code.
 */
final class Auth_Code_Repository implements AuthCodeRepositoryInterface {

	public function getNewAuthCode(): AuthCodeEntityInterface {
		return new Auth_Code_Entity();
	}

	public function persistNewAuthCode( AuthCodeEntityInterface $code ): void {
		global $wpdb;

		$inserted = $wpdb->insert(
			Schema::table( 'auth_codes' ),
			[
				'id_hash'    => hash( 'sha256', $code->getIdentifier() ),
				'client_id'  => $code->getClient()->getIdentifier(),
				'user_id'    => (int) $code->getUserIdentifier(),
				'expires_at' => Tokens::utc( $code->getExpiryDateTime()->getTimestamp() ),
				'revoked'    => 0,
			],
			[ '%s', '%s', '%d', '%s', '%d' ]
		);

		if ( false === $inserted ) {
			if ( str_contains( (string) $wpdb->last_error, 'Duplicate' ) ) {
				throw UniqueTokenIdentifierConstraintViolationException::create();
			}
			throw OAuthServerException::serverError( 'Could not store the authorization code.' );
		}
	}

	public function revokeAuthCode( string $code_id ): void {
		global $wpdb;
		$wpdb->update( Schema::table( 'auth_codes' ), [ 'revoked' => 1 ], [ 'id_hash' => hash( 'sha256', $code_id ) ] );
	}

	/**
	 * Claims the code atomically: the first exchange flips `revoked` and gets
	 * `false`; every other one (concurrent or later) gets `true`. A code that
	 * then fails PKCE is spent too.
	 */
	public function isAuthCodeRevoked( string $code_id ): bool {
		global $wpdb;

		$claimed = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . Schema::table( 'auth_codes' ) . ' SET revoked = 1 WHERE id_hash = %s AND revoked = 0 AND expires_at > %s',
				hash( 'sha256', $code_id ),
				Tokens::utc( time() )
			)
		);

		return 1 !== $claimed;
	}
}
