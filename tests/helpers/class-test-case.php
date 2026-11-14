<?php
namespace MD3\WPMCP\Tests;

use WP_UnitTestCase;

/**
 * Base class for the plugin's integration tests.
 */
abstract class Test_Case extends WP_UnitTestCase {

	/**
	 * Creates a user with the given role and (once Task 3 lands) authenticates
	 * the request as an MCP identity carrying the given scopes.
	 *
	 * @param string[] $scopes OAuth scopes.
	 * @param string   $role   WordPress role.
	 * @return int User ID.
	 */
	protected function as_mcp_user( array $scopes, string $role = 'editor' ): int {
		return (int) self::factory()->user->create( [ 'role' => $role ] );
	}
}
