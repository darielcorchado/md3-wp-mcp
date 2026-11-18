<?php
use MD3\WPMCP\Plugin;
use MD3\WPMCP\Schema;
use MD3\WPMCP\Tests\Test_Case;

class Test_Activation extends Test_Case {

	public function test_activation_creates_all_tables() {
		global $wpdb;
		foreach ( [ 'clients', 'auth_codes', 'access_tokens', 'refresh_tokens', 'log', 'uploads' ] as $t ) {
			$this->assertSame( Schema::table( $t ), $wpdb->get_var( "SHOW TABLES LIKE '" . Schema::table( $t ) . "'" ) );
		}
		$this->assertSame( '2', get_option( 'md3_wp_mcp_db_version' ) );
	}

	public function test_activation_changes_no_roles() {
		$this->assertFalse( get_role( 'editor' )->has_cap( 'md3_mcp_connect' ) );
	}

	public function test_cleanup_is_rescheduled_when_missing() {
		$this->assertSame( 10, has_action( 'admin_init', [ Plugin::class, 'schedule_cleanup' ] ) );

		wp_clear_scheduled_hook( Plugin::CLEANUP_HOOK );
		$this->assertFalse( wp_next_scheduled( Plugin::CLEANUP_HOOK ) );

		Plugin::schedule_cleanup();
		$next = wp_next_scheduled( Plugin::CLEANUP_HOOK );
		$this->assertEqualsWithDelta( time() + HOUR_IN_SECONDS, (int) $next, 5 );
		$this->assertSame( 'daily', wp_get_schedule( Plugin::CLEANUP_HOOK ) );

		// Already scheduled: left alone.
		Plugin::schedule_cleanup();
		$this->assertSame( $next, wp_next_scheduled( Plugin::CLEANUP_HOOK ) );
	}

	public function test_missing_vendor_folder_keeps_the_plugin_inactive_with_a_notice() {
		// Load a copy of the main file, without vendor/, in a separate PHP process.
		$dir = get_temp_dir() . 'md3-wp-mcp-novendor-' . wp_generate_password( 8, false ) . '/';
		wp_mkdir_p( $dir );
		copy( dirname( __DIR__, 2 ) . '/md3-wp-mcp.php', $dir . 'md3-wp-mcp.php' );
		$runner = $dir . 'runner.php';
		file_put_contents(
			$runner,
			'<?php
			define( "ABSPATH", __DIR__ . "/" );
			$GLOBALS["hooks"] = [];
			function plugin_dir_path( $f ) { return dirname( $f ) . "/"; }
			function wp_register_ability() {}
			function add_action( $h, $cb ) { $GLOBALS["hooks"][ $h ][] = $cb; }
			function register_activation_hook( $f, $cb ) {}
			function register_deactivation_hook( $f, $cb ) {}
			function esc_html__( $t ) { return $t; }
			require __DIR__ . "/md3-wp-mcp.php";
			echo md3_wp_mcp_requirements_met() ? "met\n" : "not met\n";
			md3_wp_mcp_load();
			foreach ( $GLOBALS["hooks"]["admin_notices"] ?? [] as $cb ) { $cb(); }'
		);

		$output = shell_exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $runner ) . ' 2>&1' );
		unlink( $runner );
		unlink( $dir . 'md3-wp-mcp.php' );
		rmdir( $dir );

		$this->assertStringStartsWith( "not met\n", (string) $output );
		$this->assertStringContainsString( "or the plugin's vendor folder is missing: install the release build", (string) $output );
		$this->assertStringNotContainsString( 'Fatal', (string) $output );

		// In this checkout vendor/ exists.
		$this->assertTrue( md3_wp_mcp_requirements_met() );
	}
}
