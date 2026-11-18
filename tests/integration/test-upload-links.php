<?php
use MD3\WPMCP\Abilities\Create_Upload_Link;
use MD3\WPMCP\Abilities\Get_Upload;
use MD3\WPMCP\Abilities\List_Media;
use MD3\WPMCP\Auth\Identity;
use MD3\WPMCP\Auth\Request_Context;
use MD3\WPMCP\Cleanup;
use MD3\WPMCP\OAuth\Tokens;
use MD3\WPMCP\Schema;
use MD3\WPMCP\Settings;
use MD3\WPMCP\Tests\Test_Case;
use MD3\WPMCP\Uploads\Upload_Links;
use MD3\WPMCP\Uploads\Upload_Page;

/**
 * create-upload-link, the upload page, get-upload and list-media.
 */
class Test_Upload_Links extends Test_Case {

	const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

	/** @var string[] Temp files to remove. */
	private array $tmp_files = [];

	public function tear_down() {
		foreach ( $this->tmp_files as $file ) {
			if ( file_exists( $file ) ) {
				unlink( $file );
			}
		}
		parent::tear_down();
	}

	/**
	 * Acts as the MCP user over a live connection of `test-client`.
	 *
	 * @param string[] $scopes Scopes.
	 */
	private function connect( array $scopes = [ 'content:read', 'content:write' ], string $role = 'editor' ): int {
		global $wpdb;
		$user_id = $this->as_mcp_user( $scopes, $role );
		$wpdb->insert(
			Schema::table( 'access_tokens' ),
			[
				'id_hash'    => hash( 'sha256', wp_generate_password( 32, false ) ),
				'client_id'  => 'test-client',
				'user_id'    => $user_id,
				'scopes'     => implode( ' ', $scopes ),
				'created_at' => Tokens::utc( time() ),
				'expires_at' => Tokens::utc( time() + HOUR_IN_SECONDS ),
			]
		);
		return $user_id;
	}

	/**
	 * Creates a link through the tool and returns its result plus the token.
	 *
	 * @param array<string,mixed> $input Tool input.
	 * @return array<string,mixed>
	 */
	private function create_link( array $input = [] ): array {
		$result = $this->run_ability( 'create-upload-link', $input );
		$this->assertNotWPError( $result );
		$this->assertMatchesRegularExpression( '#/md3-mcp/upload/([A-Za-z0-9]{43})$#', $result['url'] );
		preg_match( '#/md3-mcp/upload/([A-Za-z0-9]{43})$#', $result['url'], $m );
		$result['token'] = $m[1];
		return $result;
	}

	/**
	 * A file entry as PHP puts it in $_FILES.
	 *
	 * @return array{file: array<string,mixed>}
	 */
	private function file( string $contents, string $name = 'dot.png' ): array {
		$tmp = wp_tempnam( 'md3-test' );
		file_put_contents( $tmp, $contents );
		$this->tmp_files[] = $tmp;
		return [
			'file' => [
				'name'     => $name,
				'type'     => 'image/png',
				'tmp_name' => $tmp,
				'error'    => UPLOAD_ERR_OK,
				'size'     => strlen( $contents ),
			],
		];
	}

	/**
	 * Opens the page in a browser, then posts a file with the page's nonce.
	 *
	 * @param array<string,mixed>|null $files Files, a PNG by default.
	 * @return \WP_HTTP_Response|\WP_Error
	 */
	private function browser_upload( string $token, ?array $files = null ) {
		$page = Upload_Page::handle( 'GET', $token, [], [] );
		$this->assertInstanceOf( WP_HTTP_Response::class, $page, is_wp_error( $page ) ? $page->get_error_message() : '' );
		preg_match( '/name="_wpnonce" value="([^"]+)"/', $page->get_data(), $m );
		$this->assertNotEmpty( $m[1] ?? '' );

		return Upload_Page::handle( 'POST', $token, [ '_wpnonce' => $m[1] ], $files ?? $this->file( base64_decode( self::PNG ) ) );
	}

	/**
	 * Acts as the MCP connection again (the page runs in a browser, without one).
	 */
	private function back_to_mcp( int $user_id, string $client_id = 'test-client' ): void {
		wp_set_current_user( $user_id );
		Request_Context::set( new Identity( $user_id, $client_id, [ 'content:read', 'content:write' ], str_repeat( 'a', 64 ) ) );
	}

	/**
	 * Leaves the MCP request: a browser with no identity, signed in as $user_id.
	 */
	private function as_browser( int $user_id = 0 ): void {
		Request_Context::clear();
		wp_set_current_user( $user_id );
	}

	private function attachment_count(): int {
		return (int) ( new WP_Query( [ 'post_type' => 'attachment', 'post_status' => 'any', 'fields' => 'ids', 'nopaging' => true ] ) )->found_posts;
	}

	private function log_messages( string $tool ): array {
		global $wpdb;
		return $wpdb->get_col( $wpdb->prepare( 'SELECT CONCAT(result, ":", message) FROM ' . Schema::table( 'log' ) . ' WHERE tool = %s ORDER BY id', $tool ) );
	}

	// ---------------------------------------------------------------------
	// create-upload-link
	// ---------------------------------------------------------------------

	public function test_create_upload_link_returns_a_one_time_url_and_stores_only_its_hash() {
		global $wpdb;
		$user_id = $this->connect();

		$link = $this->create_link( [ 'alt' => 'A dot', 'title' => 'Dot' ] );

		$this->assertStringStartsWith( home_url( '/md3-mcp/upload/' ), $link['url'] );
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9]{20}$/', $link['upload_id'] );
		$this->assertSame( 600, $link['expires_in'] );
		$this->assertSame( 'waiting', $link['status'] );
		$this->assertStringContainsString( 'get-upload', $link['instructions'] );

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'uploads' ) . ' WHERE id = %s', $link['upload_id'] ), ARRAY_A );
		$this->assertSame( hash( 'sha256', $link['token'] ), $row['token_hash'] );
		$this->assertStringNotContainsString( $link['token'], wp_json_encode( $row ) );
		$this->assertSame( 'test-client', $row['client_id'] );
		$this->assertSame( $user_id, (int) $row['user_id'] );
		$this->assertEqualsWithDelta( time() + 600, strtotime( $row['expires_at'] . ' UTC' ), 5 );
	}

	public function test_create_upload_link_needs_the_write_scope() {
		$this->connect( [ 'content:read' ] );
		$result = ( new Create_Upload_Link() )->check_permission( [] );
		$this->assertWPError( $result );
		$this->assertSame( 'md3_mcp_insufficient_scope', $result->get_error_code() );
	}

	public function test_create_upload_link_attached_to_live_content_needs_the_publish_scope() {
		$live  = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		$draft = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$this->connect();
		$ability = new Create_Upload_Link();

		$this->assertSame( 'md3_mcp_insufficient_scope', $ability->check_permission( [ 'attach_to' => $live ] )->get_error_code() );
		$this->assertTrue( $ability->check_permission( [ 'attach_to' => $draft ] ) );

		$this->connect( [ 'content:read', 'content:write', 'content:publish' ] );
		$this->assertTrue( $ability->check_permission( [ 'attach_to' => $live ] ) );
	}

	public function test_create_upload_link_checks_attach_to_and_upload_rights() {
		$this->connect();
		$this->assertSame( 'md3_mcp_not_found', $this->run_ability( 'create-upload-link', [ 'attach_to' => 999999 ] )->get_error_code() );

		$this->connect( [ 'content:read', 'content:write' ], 'contributor' );
		$this->assertSame( 'md3_mcp_forbidden', $this->run_ability( 'create-upload-link', [] )->get_error_code() );
	}

	public function test_create_upload_link_is_rate_limited_per_connection() {
		$this->connect();
		for ( $i = 0; $i < Upload_Links::CREATE_LIMIT; $i++ ) {
			$this->assertNotWPError( $this->run_ability( 'create-upload-link', [] ) );
		}
		$refused = $this->run_ability( 'create-upload-link', [] );
		$this->assertSame( 'md3_mcp_rate_limited', $refused->get_error_code() );
		$this->assertSame( 429, $refused->get_error_data()['status'] );
	}

	// ---------------------------------------------------------------------
	// The upload page
	// ---------------------------------------------------------------------

	public function test_upload_page_shows_only_an_upload_form_with_safe_headers() {
		$this->connect();
		$link = $this->create_link();
		$this->as_browser();

		$page = Upload_Page::handle( 'GET', $link['token'], [], [] );
		$this->assertInstanceOf( WP_HTTP_Response::class, $page );
		$this->assertSame( 200, $page->get_status() );

		$headers = $page->get_headers();
		$this->assertSame( 'DENY', $headers['X-Frame-Options'] );
		$this->assertStringContainsString( 'no-store', $headers['Cache-Control'] );
		$this->assertStringContainsString( "frame-ancestors 'none'", $headers['Content-Security-Policy'] );
		$this->assertSame( 'noindex, nofollow', $headers['X-Robots-Tag'] );
		$this->assertSame( 'no-referrer', $headers['Referrer-Policy'] );

		$html = $page->get_data();
		$this->assertStringContainsString( '<meta name="robots" content="noindex, nofollow">', $html );
		$this->assertStringContainsString( 'enctype="multipart/form-data"', $html );
		$this->assertStringContainsString( 'name="_wpnonce"', $html );
		$this->assertMatchesRegularExpression( '/<input[^>]*type="file"[^>]*name="file"/', $html );
		$this->assertStringNotContainsString( '<script', $html );
		$this->assertSame( 1, substr_count( $html, '<form' ) );
	}

	public function test_browser_upload_adds_the_image_as_the_mcp_user_and_get_upload_returns_it() {
		$admin   = (int) self::factory()->user->create( [ 'role' => 'administrator' ] );
		$post    = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$user_id = $this->connect();
		$link    = $this->create_link( [ 'alt' => 'A dot', 'title' => 'Dot', 'caption' => 'Small <b>dot</b>', 'attach_to' => $post ] );

		$waiting = $this->run_ability( 'get-upload', [ 'upload_id' => $link['upload_id'] ] );
		$this->assertSame( 'waiting', $waiting['status'] );
		$this->assertArrayNotHasKey( 'media', $waiting );

		// The browser is signed in as an administrator: the upload still runs as the MCP user.
		$this->as_browser( $admin );
		$done = $this->browser_upload( $link['token'] );
		$this->assertInstanceOf( WP_HTTP_Response::class, $done, is_wp_error( $done ) ? $done->get_error_message() : '' );
		$this->assertSame( 200, $done->get_status() );
		$this->assertStringContainsString( 'go back to', $done->get_data() );
		$this->assertSame( $admin, get_current_user_id(), 'the browser user is restored' );

		$this->back_to_mcp( $user_id );
		$result = $this->run_ability( 'get-upload', [ 'upload_id' => $link['upload_id'] ] );
		$this->assertNotWPError( $result );
		$this->assertSame( 'uploaded', $result['status'] );
		$media = $result['media'];
		$this->assertSame( 'image/png', $media['mime'] );
		$this->assertSame( 'A dot', $media['alt'] );
		$this->assertNotEmpty( $media['url'] );

		$attachment = get_post( $media['id'] );
		$this->assertSame( $user_id, (int) $attachment->post_author );
		$this->assertSame( $post, (int) $attachment->post_parent );
		$this->assertSame( 'Dot', $attachment->post_title );
		$this->assertSame( 'Small dot', $attachment->post_excerpt );

		// The AI can now use it.
		$featured = $this->run_ability( 'set-featured-image', [ 'id' => $post, 'media_id' => $media['id'] ] );
		$this->assertNotWPError( $featured );
		$this->assertSame( $media['id'], (int) get_post_thumbnail_id( $post ) );

		$this->assertSame( [ 'ok:used' ], $this->log_messages( 'upload-link' ) );
		$this->assertSame( [ 'ok:' ], $this->log_messages( 'create-upload-link' ) );
	}

	public function test_a_link_works_once() {
		$this->connect();
		$link = $this->create_link();
		$this->as_browser();

		$this->assertInstanceOf( WP_HTTP_Response::class, $this->browser_upload( $link['token'] ) );
		$count = $this->attachment_count();

		$again = Upload_Page::handle( 'GET', $link['token'], [], [] );
		$this->assertWPError( $again );
		$this->assertSame( 410, $again->get_error_data()['status'] );
		$this->assertStringContainsString( 'already been used', $again->get_error_message() );

		$post = Upload_Page::handle( 'POST', $link['token'], [ '_wpnonce' => wp_create_nonce( 'md3_mcp_upload_' . $link['upload_id'] ) ], $this->file( base64_decode( self::PNG ) ) );
		$this->assertSame( 410, $post->get_error_data()['status'] );
		$this->assertSame( $count, $this->attachment_count() );
	}

	public function test_an_expired_link_is_refused_and_reported() {
		global $wpdb;
		$user_id = $this->connect();
		$link    = $this->create_link();
		$wpdb->update( Schema::table( 'uploads' ), [ 'expires_at' => Tokens::utc( time() - 1 ) ], [ 'id' => $link['upload_id'] ] );
		$this->as_browser();
		$before = $this->attachment_count();

		$get = Upload_Page::handle( 'GET', $link['token'], [], [] );
		$this->assertSame( 410, $get->get_error_data()['status'] );
		$this->assertStringContainsString( 'expired', $get->get_error_message() );

		$post = Upload_Page::handle( 'POST', $link['token'], [ '_wpnonce' => wp_create_nonce( 'md3_mcp_upload_' . $link['upload_id'] ) ], $this->file( base64_decode( self::PNG ) ) );
		$this->assertSame( 410, $post->get_error_data()['status'] );
		$this->assertSame( $before, $this->attachment_count() );

		$this->back_to_mcp( $user_id );
		$this->assertSame( 'expired', $this->run_ability( 'get-upload', [ 'upload_id' => $link['upload_id'] ] )['status'] );
		$this->assertContains( 'denied:expired', $this->log_messages( 'upload-link' ) );
	}

	public function test_an_unknown_token_is_not_found() {
		$this->as_browser();
		foreach ( [ str_repeat( 'a', 43 ), 'short', str_repeat( '%', 43 ) ] as $token ) {
			$result = Upload_Page::handle( 'GET', $token, [], [] );
			$this->assertWPError( $result, $token );
			$this->assertSame( 404, $result->get_error_data()['status'], $token );
		}
	}

	public function test_a_post_without_a_valid_nonce_uploads_nothing_and_keeps_the_link() {
		$user_id = $this->connect();
		$link    = $this->create_link();
		$this->as_browser();
		$before = $this->attachment_count();

		foreach ( [ [], [ '_wpnonce' => 'nope' ], [ '_wpnonce' => wp_create_nonce( 'md3_mcp_upload_other' ) ] ] as $post ) {
			$result = Upload_Page::handle( 'POST', $link['token'], $post, $this->file( base64_decode( self::PNG ) ) );
			$this->assertWPError( $result );
			$this->assertSame( 403, $result->get_error_data()['status'] );
		}
		$this->assertSame( $before, $this->attachment_count() );

		$this->back_to_mcp( $user_id );
		$this->assertSame( 'waiting', $this->run_ability( 'get-upload', [ 'upload_id' => $link['upload_id'] ] )['status'] );
	}

	public function test_only_real_images_are_accepted_and_a_refused_file_keeps_the_link() {
		$user_id = $this->connect();
		$link    = $this->create_link();
		$this->as_browser();
		$before = $this->attachment_count();

		$bad = [
			'php named png' => $this->file( '<?php echo 1;', 'dot.png' ),
			'svg'           => $this->file( '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>', 'dot.svg' ),
			'html'          => $this->file( '<html><script>alert(1)</script></html>', 'dot.html' ),
			'gif header'    => $this->file( "GIF89a<?php echo 1; ?>\n", 'dot.php' ),
		];
		foreach ( $bad as $label => $files ) {
			$result = $this->browser_upload( $link['token'], $files );
			$this->assertInstanceOf( WP_HTTP_Response::class, $result, $label );
			$this->assertSame( 415, $result->get_status(), $label );
			$this->assertStringContainsString( 'Only JPEG, PNG, WebP and GIF', $result->get_data(), $label );
		}

		$missing = $this->browser_upload( $link['token'], [] );
		$this->assertSame( 400, $missing->get_status() );

		$this->assertSame( $before, $this->attachment_count() );
		$this->back_to_mcp( $user_id );
		$this->assertSame( 'waiting', $this->run_ability( 'get-upload', [ 'upload_id' => $link['upload_id'] ] )['status'] );

		// The same link still takes a good file.
		$this->as_browser();
		$this->assertSame( 200, $this->browser_upload( $link['token'] )->get_status() );
	}

	public function test_a_file_over_the_size_limit_is_refused() {
		$this->connect();
		$link = $this->create_link();
		$this->as_browser();
		$before = $this->attachment_count();

		$result = $this->browser_upload( $link['token'], $this->file( str_repeat( 'a', 10485761 ), 'big.png' ) );
		$this->assertSame( 413, $result->get_status() );

		$partial              = $this->file( base64_decode( self::PNG ) );
		$partial['file']['error'] = UPLOAD_ERR_INI_SIZE;
		$this->assertSame( 413, $this->browser_upload( $link['token'], $partial )->get_status() );

		$this->assertSame( $before, $this->attachment_count() );
	}

	public function test_a_link_of_a_revoked_connection_is_refused() {
		$user_id = $this->connect();
		$link    = $this->create_link();
		Tokens::revoke_connection( 'test-client', $user_id );
		$this->as_browser();

		$result = Upload_Page::handle( 'GET', $link['token'], [], [] );
		$this->assertSame( 403, $result->get_error_data()['status'] );
		$this->assertContains( 'denied:connection', $this->log_messages( 'upload-link' ) );
	}

	public function test_a_link_is_refused_once_the_mcp_user_changes() {
		$this->connect();
		$link = $this->create_link();

		$security                = Settings::security();
		$security['mcp_user_id'] = (int) self::factory()->user->create( [ 'role' => 'editor' ] );
		update_option( Settings::OPT_SECURITY, $security );
		$this->as_browser();

		$this->assertSame( 403, Upload_Page::handle( 'GET', $link['token'], [], [] )->get_error_data()['status'] );
	}

	public function test_attaching_to_content_that_went_live_needs_a_link_made_with_publish() {
		$post = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$this->connect();
		$link = $this->create_link( [ 'attach_to' => $post ] );
		wp_publish_post( $post );
		$this->as_browser();
		$before = $this->attachment_count();

		$result = $this->browser_upload( $link['token'] );
		$this->assertSame( 403, $result->get_status() );
		$this->assertSame( $before, $this->attachment_count() );
	}

	public function test_get_upload_of_another_connection_is_not_found() {
		$user_id = $this->connect();
		$link    = $this->create_link();

		$this->back_to_mcp( $user_id, 'other-client' );
		$result = $this->run_ability( 'get-upload', [ 'upload_id' => $link['upload_id'] ] );
		$this->assertSame( 'md3_mcp_not_found', $result->get_error_code() );
		$this->assertSame( 'md3_mcp_not_found', $this->run_ability( 'get-upload', [ 'upload_id' => 'nope' ] )->get_error_code() );
	}

	public function test_get_upload_needs_only_the_read_scope() {
		$this->connect( [ 'content:read' ] );
		$this->assertTrue( ( new Get_Upload() )->check_permission( [ 'upload_id' => 'x' ] ) );
		Request_Context::set( new Identity( get_current_user_id(), 'test-client', [], str_repeat( 'a', 64 ) ) );
		$this->assertSame( 'md3_mcp_insufficient_scope', ( new Get_Upload() )->check_permission( [ 'upload_id' => 'x' ] )->get_error_code() );
	}

	public function test_upload_page_is_rate_limited_per_ip() {
		$this->as_browser();
		for ( $i = 0; $i < Upload_Page::RATE_LIMIT; $i++ ) {
			$this->assertSame( 404, Upload_Page::handle( 'GET', 'short', [], [] )->get_error_data()['status'] );
		}
		$this->assertSame( 429, Upload_Page::handle( 'GET', 'short', [], [] )->get_error_data()['status'] );
	}

	public function test_cleanup_logs_expired_links_once_and_later_deletes_them() {
		global $wpdb;
		$this->connect();
		$expired = $this->create_link();
		$fresh   = $this->create_link();
		$table   = Schema::table( 'uploads' );
		$wpdb->update( $table, [ 'expires_at' => Tokens::utc( time() - 60 ) ], [ 'id' => $expired['upload_id'] ] );
		Request_Context::clear();

		Cleanup::run();
		Cleanup::run();
		$this->assertSame( [ 'expired:expired' ], $this->log_messages( 'upload-link' ) );
		$this->assertSame( 'expired', $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$table} WHERE id = %s", $expired['upload_id'] ) ) );
		$this->assertSame( 'waiting', $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$table} WHERE id = %s", $fresh['upload_id'] ) ) );

		$wpdb->update( $table, [ 'expires_at' => Tokens::utc( time() - DAY_IN_SECONDS - 60 ) ], [ 'id' => $expired['upload_id'] ] );
		Cleanup::run();
		$this->assertNull( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE id = %s", $expired['upload_id'] ) ) );
		$this->assertNotNull( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE id = %s", $fresh['upload_id'] ) ) );
	}

	public function test_handler_is_hooked_on_parse_request() {
		$this->assertSame( 1, has_action( 'parse_request', [ Upload_Page::class, 'maybe_handle' ] ) );
		$this->assertSame( 'abc', Upload_Page::token_from_path( 'md3-mcp/upload/abc' ) );
		$this->assertSame( 'abc', Upload_Page::token_from_path( 'md3-mcp/upload/abc/' ) );
		$this->assertNull( Upload_Page::token_from_path( 'md3-mcp/authorize' ) );
		$this->assertNull( Upload_Page::token_from_path( 'blog/md3-mcp/upload/abc' ) );
	}

	public function test_the_path_reaches_the_handler_without_a_rewrite_rule() {
		$this->set_permalink_structure( '/%postname%/' );
		$token = str_repeat( 'b', 43 );
		$link  = wp_parse_url( Upload_Page::url( $token ) );

		$_SERVER['REQUEST_URI'] = $link['path'];
		remove_action( 'parse_request', [ Upload_Page::class, 'maybe_handle' ], 1 );
		$GLOBALS['wp']->parse_request();
		add_action( 'parse_request', [ Upload_Page::class, 'maybe_handle' ], 1 );

		$this->assertSame( $token, Upload_Page::token_from_path( $GLOBALS['wp']->request ) );
	}

	// ---------------------------------------------------------------------
	// list-media
	// ---------------------------------------------------------------------

	public function test_list_media_finds_images_by_title_and_file_name() {
		$this->connect( [ 'content:read' ] );
		$hero = self::factory()->attachment->create( [ 'post_mime_type' => 'image/jpeg', 'post_title' => 'Team photo', 'file' => '2026/11/office-hero.jpg' ] );
		update_post_meta( $hero, '_wp_attachment_image_alt', 'The team' );
		self::factory()->attachment->create( [ 'post_mime_type' => 'image/png', 'post_title' => 'Logo', 'file' => '2026/11/logo.png' ] );
		self::factory()->attachment->create( [ 'post_mime_type' => 'application/pdf', 'post_title' => 'Team brochure', 'file' => '2026/11/team.pdf' ] );

		$by_title = $this->run_ability( 'list-media', [ 'search' => 'Team' ] );
		$this->assertNotWPError( $by_title );
		$this->assertSame( [ $hero ], array_column( $by_title['media'], 'id' ), 'only images' );
		$this->assertSame( 'The team', $by_title['media'][0]['alt'] );
		$this->assertSame( 'Team photo', $by_title['media'][0]['title'] );
		$this->assertSame( 'office-hero.jpg', $by_title['media'][0]['filename'] );
		$this->assertSame( 1, $by_title['total'] );

		$by_file = $this->run_ability( 'list-media', [ 'search' => 'office-hero' ] );
		$this->assertSame( [ $hero ], array_column( $by_file['media'], 'id' ) );

		$all = $this->run_ability( 'list-media', [ 'per_page' => 1 ] );
		$this->assertCount( 1, $all['media'] );
		$this->assertSame( 2, $all['total'] );
		$this->assertSame( 2, $all['pages'] );
	}

	public function test_list_media_needs_read_scope_and_upload_rights() {
		$this->connect( [ 'content:read' ] );
		$this->assertTrue( ( new List_Media() )->check_permission( [] ) );

		$this->connect( [ 'content:read' ], 'contributor' );
		$this->assertSame( 'md3_mcp_forbidden', $this->run_ability( 'list-media', [] )->get_error_code() );
	}
}
