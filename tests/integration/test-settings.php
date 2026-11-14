<?php
use MD3\WPMCP\Settings;
use MD3\WPMCP\Tests\Test_Case;

class Test_Settings extends Test_Case {

	public function test_defaults() {
		$this->assertSame( [ 'post', 'page' ], Settings::post_types() );
		$this->assertSame( [ 'mcp_user_id' => 0, 'dcr' => true, 'mcp_rate_limit' => 120 ], Settings::security() );
		$this->assertSame( 0, Settings::mcp_user_id() );
	}

	public function test_mcp_user_must_be_a_non_admin_editor() {
		$editor = self::factory()->user->create( [ 'role' => 'editor' ] );
		$admin  = self::factory()->user->create( [ 'role' => 'administrator' ] );
		Settings::update_security( [ 'mcp_user_id' => $admin ] );
		$this->assertSame( 0, Settings::mcp_user_id() );
		Settings::update_security( [ 'mcp_user_id' => $editor ] );
		$this->assertSame( $editor, Settings::mcp_user_id() );
		get_user_by( 'id', $editor )->add_cap( 'manage_options' );
		$this->assertSame( 0, Settings::mcp_user_id() ); // Checked at read time.
	}

	public function test_default_author_falls_back_to_mcp_user() {
		$mcp = self::factory()->user->create( [ 'role' => 'editor' ] );
		Settings::update_security( [ 'mcp_user_id' => $mcp ] );
		$this->assertSame( $mcp, Settings::default_author_id() );
		$author = self::factory()->user->create( [ 'role' => 'author' ] );
		Settings::update_default_author( $author );
		$this->assertSame( $author, Settings::default_author_id() );
		wp_delete_user( $author );
		$this->assertSame( $mcp, Settings::default_author_id() );
		Settings::update_default_author( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$this->assertSame( 0, (int) get_option( 'md3_wp_mcp_default_author' ) );
	}

	public function test_update_post_types_drops_attachment_private_and_unknown_types() {
		register_post_type( 'secret', [ 'public' => false ] );
		Settings::update_post_types( [ 'post', 'attachment', 'secret', 'nope' ] );
		$this->assertSame( [ 'post' ], Settings::post_types() );
	}

	public function test_is_allowed_post_type_false_when_type_unregistered_later() {
		register_post_type( 'book', [ 'public' => true ] );
		Settings::update_post_types( [ 'post', 'book' ] );
		$this->assertTrue( Settings::is_allowed_post_type( 'book' ) );
		unregister_post_type( 'book' );
		$this->assertFalse( Settings::is_allowed_post_type( 'book' ) );
		$this->assertSame( [ 'post' ], Settings::post_types() );
	}

	public function test_update_security_casts_and_clamps() {
		Settings::update_security( [ 'dcr' => 0, 'mcp_rate_limit' => 99999 ] );
		$this->assertSame( [ 'mcp_user_id' => 0, 'dcr' => false, 'mcp_rate_limit' => 1000 ], Settings::security() );
		Settings::update_security( [ 'mcp_rate_limit' => 'abc' ] );
		$this->assertSame( 120, Settings::security()['mcp_rate_limit'] );
		Settings::update_security( [ 'mcp_rate_limit' => 0 ] );
		$this->assertSame( 1, Settings::security()['mcp_rate_limit'] );
	}
}
