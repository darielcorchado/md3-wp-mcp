<?php
/**
 * PHPUnit bootstrap: loads the WordPress test suite and the plugin.
 */

$plugin_root = dirname( __DIR__ );

require_once $plugin_root . '/vendor/autoload.php';
require_once $plugin_root . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';

$wp_phpunit_dir = getenv( 'WP_PHPUNIT__DIR' );
if ( ! $wp_phpunit_dir ) {
	fwrite( STDERR, "WP_PHPUNIT__DIR is not set; is wp-phpunit installed?\n" );
	exit( 1 );
}

require_once $wp_phpunit_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () use ( $plugin_root ) {
		require $plugin_root . '/md3-wp-mcp.php';

		$polylang_fakes = __DIR__ . '/helpers/polylang-fakes.php';
		if ( file_exists( $polylang_fakes ) && ! function_exists( 'pll_set_post_language' ) ) {
			require_once $polylang_fakes;
		}

		\MD3\WPMCP\Plugin::activate();
	}
);

require_once $wp_phpunit_dir . '/includes/bootstrap.php';
require_once __DIR__ . '/helpers/class-test-case.php';
