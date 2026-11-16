<?php
use MD3\WPMCP\Tests\Test_Case;

class Test_I18n extends Test_Case {

	public function tear_down() {
		restore_previous_locale();
		unload_textdomain( 'md3-wp-mcp', true );
		parent::tear_down();
	}

	public function test_spanish_translation_loads_for_consent_page_and_admin_strings() {
		unload_textdomain( 'md3-wp-mcp', true );
		switch_to_locale( 'es_ES' );
		// The test checkout is not under wp-content/plugins, so load the compiled file by path.
		$this->assertTrue( load_textdomain( 'md3-wp-mcp', MD3_WP_MCP_DIR . 'languages/md3-wp-mcp-es_ES.mo' ) );

		// Consent page.
		$this->assertSame( 'Conectar un cliente de IA', __( 'Connect an AI client', 'md3-wp-mcp' ) );
		$this->assertSame( 'Aprobar', __( 'Approve', 'md3-wp-mcp' ) );
		// Admin settings.
		$this->assertSame( 'Usuario MCP', __( 'MCP user', 'md3-wp-mcp' ) );
		$this->assertSame( 'Autor del contenido nuevo', __( 'Author for new content', 'md3-wp-mcp' ) );
		// Placeholders survive.
		$this->assertSame( 'Claude quiere trabajar con el contenido de MD3.', sprintf( __( '%1$s wants to work with the content of %2$s.', 'md3-wp-mcp' ), 'Claude', 'MD3' ) );
	}

	public function test_textdomain_is_loaded_on_init() {
		$this->assertNotFalse( has_action( 'init', 'md3_wp_mcp_load_textdomain' ) );
	}

	public function test_english_is_untouched_in_the_default_locale() {
		unload_textdomain( 'md3-wp-mcp', true );
		$this->assertSame( 'Approve', __( 'Approve', 'md3-wp-mcp' ) );
	}
}
