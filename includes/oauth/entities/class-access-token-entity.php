<?php
namespace MD3\WPMCP\OAuth\Entities;

use DateTimeImmutable;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Entities\Traits\AccessTokenTrait;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Entities\Traits\TokenEntityTrait;
use MD3\WPMCP\OAuth\OAuth;

defined( 'ABSPATH' ) || exit;

/**
 * A 1-hour access token, issued as an RS256 JWT.
 */
final class Access_Token_Entity implements AccessTokenEntityInterface {

	use AccessTokenTrait;
	use EntityTrait;
	use TokenEntityTrait;

	/**
	 * Builds the JWT. Unlike league's default, the audience is the MCP endpoint
	 * (RFC 8707) rather than the client; the client ID goes in `cid`.
	 */
	public function toString(): string {
		$this->initJwtConfiguration();

		$now = new DateTimeImmutable( '@' . time() );

		return $this->jwtConfiguration->builder()
			->issuedBy( OAuth::issuer() )
			->permittedFor( OAuth::resource_url() )
			->identifiedBy( $this->getIdentifier() )
			->issuedAt( $now )
			->canOnlyBeUsedAfter( $now )
			->expiresAt( new DateTimeImmutable( '@' . $this->getExpiryDateTime()->getTimestamp() ) )
			->relatedTo( (string) $this->getUserIdentifier() )
			->withClaim( 'scopes', array_map( static fn ( ScopeEntityInterface $scope ): string => $scope->getIdentifier(), $this->getScopes() ) )
			->withClaim( 'cid', $this->getClient()->getIdentifier() )
			->getToken( $this->jwtConfiguration->signer(), $this->jwtConfiguration->signingKey() )
			->toString();
	}
}
