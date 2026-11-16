<?php
namespace MD3\WPMCP\OAuth\Repositories;

use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Repositories\ScopeRepositoryInterface;
use MD3\WPMCP\Auth\Scopes;
use MD3\WPMCP\OAuth\Entities\Scope_Entity;

defined( 'ABSPATH' ) || exit;

/**
 * The four content scopes.
 */
final class Scope_Repository implements ScopeRepositoryInterface {

	public function getScopeEntityByIdentifier( string $identifier ): ?ScopeEntityInterface {
		return in_array( $identifier, Scopes::all(), true ) ? new Scope_Entity( $identifier ) : null;
	}

	/**
	 * Normalizes the granted scopes: canonical order, `content:read` always.
	 *
	 * @param ScopeEntityInterface[] $scopes Requested scopes.
	 * @return ScopeEntityInterface[]
	 */
	public function finalizeScopes( array $scopes, string $grant_type, ClientEntityInterface $client, string|null $user_id = null, ?string $auth_code_id = null ): array {
		$ids = array_map( static fn ( ScopeEntityInterface $scope ): string => $scope->getIdentifier(), $scopes );
		$ids = array_values( array_intersect( Scopes::all(), array_merge( [ Scopes::READ ], $ids ) ) );

		return array_map( static fn ( string $id ): Scope_Entity => new Scope_Entity( $id ), $ids );
	}
}
