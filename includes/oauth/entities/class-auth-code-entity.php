<?php
namespace MD3\WPMCP\OAuth\Entities;

use League\OAuth2\Server\Entities\AuthCodeEntityInterface;
use League\OAuth2\Server\Entities\Traits\AuthCodeTrait;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Entities\Traits\TokenEntityTrait;

defined( 'ABSPATH' ) || exit;

/**
 * An authorization code (60 s, single use).
 */
final class Auth_Code_Entity implements AuthCodeEntityInterface {

	use EntityTrait;
	use TokenEntityTrait;
	use AuthCodeTrait;
}
