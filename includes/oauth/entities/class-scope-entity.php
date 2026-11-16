<?php
namespace MD3\WPMCP\OAuth\Entities;

use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Entities\Traits\ScopeTrait;

defined( 'ABSPATH' ) || exit;

/**
 * One of the scopes in Auth\Scopes.
 */
final class Scope_Entity implements ScopeEntityInterface {

	use EntityTrait;
	use ScopeTrait;

	public function __construct( string $scope ) {
		$this->setIdentifier( $scope );
	}
}
