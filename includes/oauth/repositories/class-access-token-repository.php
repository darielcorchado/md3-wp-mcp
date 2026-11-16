<?php
namespace MD3\WPMCP\OAuth\Repositories;

use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Exception\UniqueTokenIdentifierConstraintViolationException;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;
use MD3\WPMCP\OAuth\Entities\Access_Token_Entity;
use MD3\WPMCP\OAuth\Tokens;
use MD3\WPMCP\Schema;
use MD3\WPMCP\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Access tokens, stored as the SHA-256 of their `jti` with the real client ID.
 */
final class Access_Token_Repository implements AccessTokenRepositoryInterface {

	/**
	 * @param ScopeEntityInterface[] $scopes Granted scopes.
	 */
	public function getNewToken( ClientEntityInterface $client, array $scopes, string|null $user_id = null ): AccessTokenEntityInterface {
		$token = new Access_Token_Entity();
		$token->setClient( $client );
		foreach ( $scopes as $scope ) {
			$token->addScope( $scope );
		}
		if ( null !== $user_id ) {
			$token->setUserIdentifier( $user_id );
		}
		return $token;
	}

	public function persistNewAccessToken( AccessTokenEntityInterface $token ): void {
		global $wpdb;

		$scopes = array_map( static fn ( ScopeEntityInterface $scope ): string => $scope->getIdentifier(), $token->getScopes() );

		$inserted = $wpdb->insert(
			Schema::table( 'access_tokens' ),
			[
				'id_hash'    => hash( 'sha256', $token->getIdentifier() ),
				'client_id'  => $token->getClient()->getIdentifier(),
				'user_id'    => (int) $token->getUserIdentifier(),
				'scopes'     => implode( ' ', $scopes ),
				'created_at' => Tokens::utc( time() ),
				'expires_at' => Tokens::utc( $token->getExpiryDateTime()->getTimestamp() ),
				'revoked'    => 0,
			],
			[ '%s', '%s', '%d', '%s', '%s', '%s', '%d' ]
		);

		if ( false === $inserted ) {
			if ( str_contains( (string) $wpdb->last_error, 'Duplicate' ) ) {
				throw UniqueTokenIdentifierConstraintViolationException::create();
			}
			throw OAuthServerException::serverError( 'Could not store the access token.' );
		}
	}

	public function revokeAccessToken( string $token_id ): void {
		global $wpdb;
		$wpdb->update( Schema::table( 'access_tokens' ), [ 'revoked' => 1 ], [ 'id_hash' => hash( 'sha256', $token_id ) ] );
	}

	/**
	 * Revoked when unknown, revoked, expired, or when its user is no longer
	 * the MCP user (changed, deleted, or gained `manage_options`).
	 */
	public function isAccessTokenRevoked( string $token_id ): bool {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT user_id, expires_at, revoked FROM ' . Schema::table( 'access_tokens' ) . ' WHERE id_hash = %s',
				hash( 'sha256', $token_id )
			)
		);

		if ( ! $row || 1 === (int) $row->revoked || $row->expires_at <= Tokens::utc( time() ) ) {
			return true;
		}

		$mcp_user = Settings::mcp_user_id();
		return 0 === $mcp_user || (int) $row->user_id !== $mcp_user;
	}
}
