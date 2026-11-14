<?php
use MD3\WPMCP\Abilities\Link_Translations;
use MD3\WPMCP\Abilities\Registry;
use MD3\WPMCP\Tests\Test_Case;

class Test_Media_Translation_Abilities extends Test_Case {

	const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

	private function writer(): int {
		return $this->as_mcp_user( [ 'content:read', 'content:write' ] );
	}

	private function attachment_count(): int {
		return (int) ( new WP_Query( [ 'post_type' => 'attachment', 'post_status' => 'any', 'fields' => 'ids', 'nopaging' => true ] ) )->found_posts;
	}

	public function test_upload_base64_png_and_set_alt() {
		$this->writer();
		$r = $this->run_ability( 'upload-media', [ 'base64' => self::PNG, 'filename' => 'dot.png', 'alt' => 'A dot', 'title' => 'Dot' ] );
		$this->assertNotWPError( $r );
		$this->assertSame( 'image/png', $r['mime'] );
		$this->assertSame( 'A dot', $r['alt'] );
		$this->assertSame( 'A dot', get_post_meta( $r['id'], '_wp_attachment_image_alt', true ) );
		$this->assertSame( 'Dot', get_post( $r['id'] )->post_title );
		$this->assertNotEmpty( $r['url'] );
	}

	public function test_upload_attaches_to_editable_post() {
		$this->writer();
		$post = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$r    = $this->run_ability( 'upload-media', [ 'base64' => self::PNG, 'filename' => 'dot.png', 'attach_to' => $post ] );
		$this->assertNotWPError( $r );
		$this->assertSame( $post, (int) get_post( $r['id'] )->post_parent );
	}

	public function test_upload_requires_exactly_one_source() {
		$this->writer();
		$both = $this->run_ability( 'upload-media', [ 'base64' => self::PNG, 'filename' => 'a.png', 'url' => 'https://example.com/a.png' ] );
		$this->assertSame( 'md3_mcp_bad_source', $both->get_error_code() );
		$none = $this->run_ability( 'upload-media', [ 'filename' => 'a.png' ] );
		$this->assertWPError( $none );
		$nofile = $this->run_ability( 'upload-media', [ 'base64' => self::PNG ] );
		$this->assertSame( 'md3_mcp_bad_source', $nofile->get_error_code() );
	}

	public function test_upload_rejects_non_image() {
		$this->writer();
		$before = $this->attachment_count();
		$r      = $this->run_ability( 'upload-media', [ 'base64' => base64_encode( 'hello' ), 'filename' => 'a.txt' ] );
		$this->assertSame( 'md3_mcp_bad_type', $r->get_error_code() );
		$this->assertSame( 415, $r->get_error_data()['status'] );
		$this->assertSame( $before, $this->attachment_count() );
	}

	public function test_upload_rejects_image_content_hiding_behind_non_image_name() {
		$this->writer();
		$r = $this->run_ability( 'upload-media', [ 'base64' => base64_encode( '<?php echo 1;' ), 'filename' => 'a.png' ] );
		$this->assertSame( 'md3_mcp_bad_type', $r->get_error_code() );
	}

	public function test_upload_rejects_oversize() {
		$this->writer();
		$r = $this->run_ability( 'upload-media', [ 'base64' => base64_encode( str_repeat( 'a', 10485761 ) ), 'filename' => 'big.png' ] );
		$this->assertSame( 'md3_mcp_too_large', $r->get_error_code() );
		$this->assertSame( 413, $r->get_error_data()['status'] );
	}

	public function test_upload_rejects_invalid_base64() {
		$this->writer();
		$r = $this->run_ability( 'upload-media', [ 'base64' => '!!!not base64!!!', 'filename' => 'a.png' ] );
		$this->assertSame( 'md3_mcp_bad_source', $r->get_error_code() );
	}

	public function test_upload_url_to_loopback_is_refused() {
		$this->writer();
		$before = $this->attachment_count();
		$r      = $this->run_ability( 'upload-media', [ 'url' => 'http://127.0.0.1/x.png' ] );
		$this->assertWPError( $r );
		$this->assertSame( $before, $this->attachment_count() );
	}

	public function test_upload_url_downloads_with_mocked_http() {
		$this->writer();
		$png = base64_decode( self::PNG );
		add_filter(
			'pre_http_request',
			static function ( $pre, $args, $url ) use ( $png ) {
				if ( 'https://images.example.com/dot.png' !== $url ) {
					return $pre;
				}
				if ( ! empty( $args['filename'] ) ) {
					file_put_contents( $args['filename'], $png );
				}
				return [
					'headers'  => [ 'content-type' => 'image/png' ],
					'body'     => '',
					'response' => [ 'code' => 200, 'message' => 'OK' ],
					'cookies'  => [],
					'filename' => $args['filename'] ?? null,
				];
			},
			10,
			3
		);
		$r = $this->run_ability( 'upload-media', [ 'url' => 'https://images.example.com/dot.png' ] );
		$this->assertNotWPError( $r );
		$this->assertSame( 'image/png', $r['mime'] );
	}

	public function test_upload_requires_upload_files_capability() {
		$this->as_mcp_user( [ 'content:read', 'content:write' ], 'subscriber' );
		$r = $this->run_ability( 'upload-media', [ 'base64' => self::PNG, 'filename' => 'a.png' ] );
		$this->assertSame( 'md3_mcp_forbidden', $r->get_error_code() );
	}

	public function test_upload_attach_to_post_user_cannot_edit_is_forbidden() {
		$this->as_mcp_user( [ 'content:read', 'content:write' ], 'author' );
		$other = self::factory()->post->create( [ 'post_author' => self::factory()->user->create( [ 'role' => 'administrator' ] ), 'post_status' => 'publish' ] );
		$r     = $this->run_ability( 'upload-media', [ 'base64' => self::PNG, 'filename' => 'a.png', 'attach_to' => $other ] );
		$this->assertSame( 'md3_mcp_forbidden', $r->get_error_code() );
		$missing = $this->run_ability( 'upload-media', [ 'base64' => self::PNG, 'filename' => 'a.png', 'attach_to' => 999999 ] );
		$this->assertSame( 'md3_mcp_not_found', $missing->get_error_code() );
	}

	public function test_set_featured_image_sets_and_returns_summary() {
		$this->writer();
		$post = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$img  = $this->run_ability( 'upload-media', [ 'base64' => self::PNG, 'filename' => 'dot.png' ] );
		$r    = $this->run_ability( 'set-featured-image', [ 'id' => $post, 'media_id' => $img['id'] ] );
		$this->assertNotWPError( $r );
		$this->assertSame( $post, $r['id'] );
		$this->assertSame( $img['id'], (int) get_post_thumbnail_id( $post ) );
	}

	public function test_set_featured_image_rejects_non_image_attachment() {
		$this->writer();
		$post = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$pdf  = self::factory()->attachment->create( [ 'post_mime_type' => 'application/pdf', 'post_title' => 'doc' ] );
		$r    = $this->run_ability( 'set-featured-image', [ 'id' => $post, 'media_id' => $pdf ] );
		$this->assertSame( 'md3_mcp_bad_media', $r->get_error_code() );
		$this->assertSame( 0, (int) get_post_thumbnail_id( $post ) );
	}

	public function test_set_featured_image_on_published_post_needs_publish_scope() {
		$post = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		$this->writer();
		$result = ( new \MD3\WPMCP\Abilities\Set_Featured_Image() )->check_permission( [ 'id' => $post, 'media_id' => 1 ] );
		$this->assertSame( 'md3_mcp_insufficient_scope', $result->get_error_code() );
	}

	public function test_set_featured_image_on_others_post_is_forbidden() {
		$this->as_mcp_user( [ 'content:read', 'content:write' ], 'author' );
		$other = self::factory()->post->create( [ 'post_author' => self::factory()->user->create( [ 'role' => 'administrator' ] ), 'post_status' => 'draft' ] );
		$img   = $this->run_ability( 'upload-media', [ 'base64' => self::PNG, 'filename' => 'dot.png' ] );
		$r     = $this->run_ability( 'set-featured-image', [ 'id' => $other, 'media_id' => $img['id'] ] );
		$this->assertSame( 'md3_mcp_forbidden', $r->get_error_code() );
	}

	public function test_link_translations_sets_language_and_pairs() {
		$this->writer();
		$en = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$es = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$r  = $this->run_ability( 'link-translations', [ 'id' => $en, 'language' => 'en', 'translations' => [ 'es' => $es ] ] );
		$this->assertNotWPError( $r );
		$this->assertSame( $en, $r['id'] );
		$this->assertSame( 'en', $r['language'] );
		$this->assertSame( [ 'en' => $en, 'es' => $es ], $r['translations'] );
		$this->assertSame( 'es', pll_get_post_language( $es ) );
	}

	public function test_link_translations_merges_with_existing() {
		$this->writer();
		$en = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$es = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$this->run_ability( 'link-translations', [ 'id' => $en, 'language' => 'en', 'translations' => [ 'es' => $es ] ] );
		$r = $this->run_ability( 'link-translations', [ 'id' => $en, 'language' => 'en' ] );
		$this->assertSame( [ 'en' => $en, 'es' => $es ], $r['translations'] );
	}

	public function test_link_translations_rejects_unknown_language() {
		$this->writer();
		$en = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$r  = $this->run_ability( 'link-translations', [ 'id' => $en, 'language' => 'fr' ] );
		$this->assertSame( 'md3_mcp_bad_language', $r->get_error_code() );
		$r2 = $this->run_ability( 'link-translations', [ 'id' => $en, 'language' => 'en', 'translations' => [ 'fr' => $en ] ] );
		$this->assertSame( 'md3_mcp_bad_language', $r2->get_error_code() );
	}

	public function test_link_translations_rejects_other_type_and_uneditable() {
		$this->writer();
		$post = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$page = self::factory()->post->create( [ 'post_type' => 'page', 'post_status' => 'draft' ] );
		$r    = $this->run_ability( 'link-translations', [ 'id' => $post, 'language' => 'en', 'translations' => [ 'es' => $page ] ] );
		$this->assertSame( 'md3_mcp_bad_translation', $r->get_error_code() );
		$this->assertSame( '', get_post_meta( $post, '_fake_pll_lang', true ) );
	}

	public function test_get_content_returns_language_and_translations_from_polylang() {
		$this->writer();
		$en = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$es = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$this->run_ability( 'link-translations', [ 'id' => $en, 'language' => 'en', 'translations' => [ 'es' => $es ] ] );
		$r = $this->run_ability( 'get-content', [ 'id' => $en ] );
		$this->assertNotWPError( $r );
		$this->assertSame( 'en', $r['language'] );
		$this->assertSame( [ 'en' => $en, 'es' => $es ], $r['translations'] );
	}

	public function test_registry_lists_media_tools_and_link_translations_when_polylang_present() {
		$names = Registry::names();
		$this->assertContains( 'md3-wp-mcp/upload-media', $names );
		$this->assertContains( 'md3-wp-mcp/set-featured-image', $names );
		$this->assertContains( 'md3-wp-mcp/link-translations', $names );
		$this->assertInstanceOf( Link_Translations::class, wp_get_ability( 'md3-wp-mcp/link-translations' ) ? new Link_Translations() : null );
	}
}
