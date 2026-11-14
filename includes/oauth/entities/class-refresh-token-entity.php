<?php
namespace MD3\WPMCP\OAuth\Entities;

use League\OAuth2\Server\Entities\RefreshTokenEntityInterface;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Entities\Traits\RefreshTokenTrait;

defined( 'ABSPATH' ) || exit;

/**
 * A refresh token (30 days, replaced on every use).
 */
final class Refresh_Token_Entity implements RefreshTokenEntityInterface {

	use EntityTrait;
	use RefreshTokenTrait;
}
