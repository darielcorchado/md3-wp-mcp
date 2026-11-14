<?php
namespace MD3\WPMCP\OAuth\Repositories;

use League\OAuth2\Server\Entities\RefreshTokenEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Exception\UniqueTokenIdentifierConstraintViolationException;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;
use MD3\WPMCP\OAuth\Entities\Refresh_Token_Entity;
use MD3\WPMCP\OAuth\Tokens;
use MD3\WPMCP\Schema;
use MD3\WPMCP\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Refresh tokens, stored as the SHA-256 of their ID. The token itself is the
 * encrypted payload league hands to the client.
 */
final class Refresh_Token_Repository implements RefreshTokenRepositoryInterface {

	/**
	 * Seconds after rotation during which a replayed refresh token is only
	 * refused (a client retrying after a network error), not treated as theft.
	 */
	const REUSE_GRACE = 10;

	public function getNewRefreshToken(): ?RefreshTokenEntityInterface {
		return new Refresh_Token_Entity();
	}

	public function persistNewRefreshToken( RefreshTokenEntityInterface $token ): void {
		global $wpdb;
		$access = $token->getAccessToken();

		$inserted = $wpdb->insert(
			Schema::table( 'refresh_tokens' ),
			[
				'id_hash'           => hash( 'sha256', $token->getIdentifier() ),
				'access_token_hash' => hash( 'sha256', $access->getIdentifier() ),
				'client_id'         => $access->getClient()->getIdentifier(),
				'user_id'           => (int) $access->getUserIdentifier(),
				'expires_at'        => Tokens::utc( $token->getExpiryDateTime()->getTimestamp() ),
				'revoked'           => 0,
			],
			[ '%s', '%s', '%s', '%d', '%s', '%d' ]
		);

		if ( false === $inserted ) {
			if ( str_contains( (string) $wpdb->last_error, 'Duplicate' ) ) {
				throw UniqueTokenIdentifierConstraintViolationException::create();
			}
			throw OAuthServerException::serverError( 'Could not store the refresh token.' );
		}
	}

	/**
	 * Marks the token as rotated. Called by league when the token is used.
	 *
	 * Only one of two concurrent refreshes with the same token can rotate it;
	 * the other is refused before any new token is issued.
	 *
	 * @throws OAuthServerException When the token was already revoked.
	 */
	public function revokeRefreshToken( string $token_id ): void {
		global $wpdb;

		$rotated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . Schema::table( 'refresh_tokens' ) . ' SET revoked = 1, rotated_at = %s WHERE id_hash = %s AND revoked = 0',
				Tokens::utc( time() ),
				hash( 'sha256', $token_id )
			)
		);

		if ( 1 !== $rotated ) {
			throw OAuthServerException::invalidRefreshToken( 'Token has been revoked' );
		}
	}

	/**
	 * A revoked token is refused. If it was rotated more than REUSE_GRACE
	 * seconds ago, presenting it again counts as reuse (a copied token) and
	 * the whole connection is revoked (design §4.6, Review Focus 3).
	 */
	public function isRefreshTokenRevoked( string $token_id ): bool {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT client_id, user_id, expires_at, revoked, rotated_at FROM ' . Schema::table( 'refresh_tokens' ) . ' WHERE id_hash = %s',
				hash( 'sha256', $token_id )
			)
		);

		if ( ! $row ) {
			return true;
		}

		if ( 1 === (int) $row->revoked ) {
			$rotated_at = null === $row->rotated_at ? null : strtotime( $row->rotated_at . ' UTC' );
			if ( null !== $rotated_at && time() - $rotated_at > self::REUSE_GRACE ) {
				Tokens::revoke_connection( $row->client_id, (int) $row->user_id );
			}
			return true;
		}

		if ( $row->expires_at <= Tokens::utc( time() ) ) {
			return true;
		}

		$mcp_user = Settings::mcp_user_id();
		return 0 === $mcp_user || (int) $row->user_id !== $mcp_user;
	}
}
