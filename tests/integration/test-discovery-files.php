<?php
use MD3\WPMCP\Admin\Discovery_Files;
use MD3\WPMCP\OAuth\Discovery;
use MD3\WPMCP\Tests\Test_Case;

/**
 * Static discovery files: the in-memory ZIP and the admin-only download (MD3-32).
 */
class Test_Discovery_Files extends Test_Case {

	public function set_up() {
		parent::set_up();
		$this->set_permalink_structure( '/%postname%/' );
	}

	public function tear_down() {
		$_GET     = [];
		$_REQUEST = [];
		parent::tear_down();
	}

	public function test_zip_holds_the_folders_and_files() {
		$files = Discovery::static_files();
		$path  = wp_tempnam( 'md3-discovery' );
		file_put_contents( $path, Discovery_Files::zip( $files ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		$zip = new ZipArchive();
		$this->assertTrue( $zip->open( $path, ZipArchive::CHECKCONS ) );

		$names = [];
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$names[] = $zip->getNameIndex( $i );
		}
		$this->assertSame(
			[
				'.well-known/',
				'.well-known/oauth-protected-resource/',
				'.well-known/oauth-protected-resource/wp-json/',
				'.well-known/oauth-protected-resource/wp-json/md3-wp-mcp/',
				'.well-known/oauth-protected-resource/wp-json/md3-wp-mcp/mcp',
				'.well-known/oauth-authorization-server/',
				'.well-known/oauth-authorization-server/md3-mcp',
			],
			$names
		);

		foreach ( $files as $name => $content ) {
			$this->assertSame( $content, $zip->getFromName( $name ) );
			$this->assertSame( crc32( $content ), $zip->statName( $name )['crc'] );
		}
		$zip->close();
		unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	public function test_download_returns_each_file() {
		$files = array_values( Discovery::static_files() );

		$zip = Discovery_Files::download( 'zip' );
		$this->assertSame( 'md3-wp-mcp-discovery-files.zip', $zip['name'] );
		$this->assertSame( 'application/zip', $zip['type'] );
		$this->assertStringStartsWith( "PK\x03\x04", $zip['body'] );

		$this->assertSame(
			[
				'name' => 'mcp',
				'type' => 'application/octet-stream',
				'body' => $files[0],
			],
			Discovery_Files::download( 'resource' )
		);
		$this->assertSame(
			[
				'name' => 'md3-mcp',
				'type' => 'application/octet-stream',
				'body' => $files[1],
			],
			Discovery_Files::download( 'server' )
		);
		$this->assertNull( Discovery_Files::download( 'other' ) );
	}

	public function test_download_url_carries_the_action_file_and_nonce() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$url = Discovery_Files::url( 'zip' );
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

		$this->assertStringStartsWith( admin_url( 'admin-post.php' ), $url );
		$this->assertSame( Discovery_Files::ACTION, $query['action'] );
		$this->assertSame( 'zip', $query['file'] );
		$this->assertSame( 1, wp_verify_nonce( $query['_wpnonce'], Discovery_Files::ACTION ) );
	}

	public function test_download_handler_requires_manage_options_and_nonce() {
		$editor = self::factory()->user->create( [ 'role' => 'editor' ] );
		$admin  = self::factory()->user->create( [ 'role' => 'administrator' ] );

		// Each case: user, nonce, file, expected status.
		$cases = [
			[ $editor, true, 'zip', 403 ],
			[ $admin, false, 'zip', 403 ],
			[ $admin, true, 'nope', 400 ],
		];
		foreach ( $cases as [ $user, $valid_nonce, $file, $status ] ) {
			wp_set_current_user( $user );
			$_GET     = [
				'action'   => Discovery_Files::ACTION,
				'file'     => $file,
				'_wpnonce' => $valid_nonce ? wp_create_nonce( Discovery_Files::ACTION ) : 'bad',
			];
			$_REQUEST = $_GET;
			try {
				Discovery_Files::handle();
				$this->fail( 'The handler did not stop.' );
			} catch ( WPDieException $e ) {
				$this->assertSame( $status, $this->die_status, $file . ' for user ' . $user );
			}
		}
		Discovery_Files::register();
		$this->assertSame( 10, has_action( 'admin_post_' . Discovery_Files::ACTION, [ Discovery_Files::class, 'handle' ] ) );
	}

	/** @var int Status of the last wp_die(). */
	private int $die_status = 0;

	/**
	 * Records wp_die()'s status, which WPDieException doesn't carry.
	 */
	public function wp_die_handler( $message, $title, $args ) {
		$this->die_status = (int) ( $args['response'] ?? 500 );
		throw new WPDieException( is_string( $message ) ? $message : 'die' );
	}

	public function get_wp_die_handler( $handler ) {
		return [ $this, 'wp_die_handler' ];
	}
}
