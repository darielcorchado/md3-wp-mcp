<?php
namespace MD3\WPMCP\OAuth\Entities;

use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\Traits\ClientTrait;
use League\OAuth2\Server\Entities\Traits\EntityTrait;

defined( 'ABSPATH' ) || exit;

/**
 * A public OAuth client (DCR or CIMD). Every client authenticates with PKCE
 * and an exact redirect URI match; none has a secret.
 */
final class Client_Entity implements ClientEntityInterface {

	use EntityTrait;
	use ClientTrait;

	const GRANT_TYPES = [ 'authorization_code', 'refresh_token' ];

	/**
	 * @param string[] $redirect_uris Registered redirect URIs.
	 */
	public function __construct( string $client_id, string $name, array $redirect_uris ) {
		$this->setIdentifier( $client_id );
		$this->name           = $name;
		$this->redirectUri    = array_values( $redirect_uris );
		$this->isConfidential = false;
	}

	/**
	 * Only the authorization code and refresh token grants (league checks this).
	 */
	public function supportsGrantType( string $grant_type ): bool {
		return in_array( $grant_type, self::GRANT_TYPES, true );
	}
}
