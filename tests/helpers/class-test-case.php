<?php
namespace MD3\WPMCP\Tests;

use MD3\WPMCP\Auth\Identity;
use MD3\WPMCP\Auth\Request_Context;
use MD3\WPMCP\Settings;
use WP_UnitTestCase;

/**
 * Base class for the plugin's integration tests.
 */
abstract class Test_Case extends WP_UnitTestCase {

	public function tear_down() {
		Request_Context::clear();
		parent::tear_down();
	}

	/**
	 * Creates a user with the given role, makes them the MCP user and
	 * authenticates the request as an MCP identity carrying the given scopes.
	 *
	 * The ID is written straight to the option so tests can try roles that
	 * `Settings::update_security()` would refuse.
	 *
	 * @param string[] $scopes OAuth scopes.
	 * @param string   $role   WordPress role.
	 * @return int User ID.
	 */
	protected function as_mcp_user( array $scopes, string $role = 'editor' ): int {
		$user_id = (int) self::factory()->user->create( [ 'role' => $role ] );

		$security                = Settings::security();
		$security['mcp_user_id'] = $user_id;
		update_option( Settings::OPT_SECURITY, $security );

		wp_set_current_user( $user_id );
		Request_Context::set( new Identity( $user_id, 'test-client', $scopes, str_repeat( 'a', 64 ) ) );

		return $user_id;
	}

	/**
	 * Runs an ability through the Abilities API, as an MCP call would.
	 *
	 * @param array<string,mixed> $input Tool input.
	 * @return mixed Ability result or WP_Error.
	 */
	protected function run_ability( string $short_name, array $input ) {
		return wp_get_ability( 'md3-wp-mcp/' . $short_name )->execute( $input );
	}
}
