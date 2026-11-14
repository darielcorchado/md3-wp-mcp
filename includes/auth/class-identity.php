<?php
namespace MD3\WPMCP\Auth;

defined( 'ABSPATH' ) || exit;

/**
 * The authenticated OAuth identity for the current request.
 */
final readonly class Identity {

	/**
	 * @param int      $user_id    WordPress user the token acts as.
	 * @param string   $client_id  OAuth client.
	 * @param string[] $scopes     Granted scopes.
	 * @param string   $token_hash Hash of the access token.
	 */
	public function __construct(
		public int $user_id,
		public string $client_id,
		public array $scopes,
		public string $token_hash
	) {}
}
