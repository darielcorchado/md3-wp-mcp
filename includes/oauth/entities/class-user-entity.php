<?php
namespace MD3\WPMCP\OAuth\Entities;

use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Entities\UserEntityInterface;

defined( 'ABSPATH' ) || exit;

/**
 * The WordPress user who approved an authorization request.
 */
final class User_Entity implements UserEntityInterface {

	use EntityTrait;

	public function __construct( int $user_id ) {
		$this->setIdentifier( (string) $user_id );
	}
}
