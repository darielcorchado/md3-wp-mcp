<?php
use MD3\WPMCP\Schema;
use MD3\WPMCP\Tests\Test_Case;

class Test_Activation extends Test_Case {

	public function test_activation_creates_all_tables() {
		global $wpdb;
		foreach ( [ 'clients', 'auth_codes', 'access_tokens', 'refresh_tokens', 'log' ] as $t ) {
			$this->assertSame( Schema::table( $t ), $wpdb->get_var( "SHOW TABLES LIKE '" . Schema::table( $t ) . "'" ) );
		}
		$this->assertSame( '1', get_option( 'md3_wp_mcp_db_version' ) );
	}

	public function test_activation_changes_no_roles() {
		$this->assertFalse( get_role( 'editor' )->has_cap( 'md3_mcp_connect' ) );
	}
}
