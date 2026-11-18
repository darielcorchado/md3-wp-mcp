<?php
use MD3\WPMCP\Abilities\Update_Media;
use MD3\WPMCP\Schema;
use MD3\WPMCP\Tests\Test_Case;

/**
 * update-media: alt text, title, caption and description of an image.
 */
class Test_Update_Media extends Test_Case {

	private function writer( string $role = 'editor' ): int {
		return $this->as_mcp_user( [ 'content:read', 'content:write' ], $role );
	}

	private function publisher(): int {
		return $this->as_mcp_user( [ 'content:read', 'content:write', 'content:publish' ] );
	}

	private function image( array $args = [] ): int {
		return (int) self::factory()->attachment->create( $args + [ 'post_mime_type' => 'image/png', 'post_title' => 'Old', 'file' => '2026/11/dog.png' ] );
	}

	private function permission( int $id ) {
		return ( new Update_Media() )->check_permission( [ 'id' => $id, 'alt' => 'x' ] );
	}

	public function test_updates_only_the_fields_sent() {
		$this->writer();
		$img = $this->image();

		$result = $this->run_ability( 'update-media', [ 'id' => $img, 'alt' => 'A dog', 'caption' => 'In the park' ] );

		$this->assertNotWPError( $result );
		$this->assertSame( $img, $result['id'] );
		$this->assertSame( 'A dog', $result['alt'] );
		$this->assertSame( 'A dog', get_post_meta( $img, '_wp_attachment_image_alt', true ) );
		$this->assertSame( 'In the park', get_post( $img )->post_excerpt );
		$this->assertSame( 'Old', get_post( $img )->post_title );
	}

	public function test_updates_title_and_description() {
		$this->writer();
		$img = $this->image();

		$this->assertNotWPError( $this->run_ability( 'update-media', [ 'id' => $img, 'title' => 'Dog', 'description' => 'A brown dog' ] ) );
		$this->assertSame( 'Dog', get_post( $img )->post_title );
		$this->assertSame( 'A brown dog', get_post( $img )->post_content );
	}

	public function test_fields_are_saved_as_plain_text() {
		$this->writer();
		$img = $this->image();

		$this->run_ability( 'update-media', [ 'id' => $img, 'alt' => '<script>alert(1)</script>Dog', 'title' => '<b>Hi</b>', 'caption' => '<img src=x onerror=alert(1)>Cap' ] );
		$this->assertSame( 'Dog', get_post_meta( $img, '_wp_attachment_image_alt', true ) );
		$this->assertSame( 'Hi', get_post( $img )->post_title );
		$this->assertSame( 'Cap', get_post( $img )->post_excerpt );
	}

	public function test_an_empty_alt_clears_it() {
		$this->writer();
		$img = $this->image();
		update_post_meta( $img, '_wp_attachment_image_alt', 'x' );

		$this->assertNotWPError( $this->run_ability( 'update-media', [ 'id' => $img, 'alt' => '' ] ) );
		$this->assertSame( '', get_post_meta( $img, '_wp_attachment_image_alt', true ) );
	}

	public function test_needs_at_least_one_field() {
		$this->writer();
		$result = $this->run_ability( 'update-media', [ 'id' => $this->image() ] );
		$this->assertSame( 'md3_mcp_no_changes', $result->get_error_code() );
	}

	public function test_only_images_can_be_updated() {
		$this->writer();
		$post = self::factory()->post->create( [ 'post_title' => 'Post' ] );
		$pdf  = $this->image( [ 'post_mime_type' => 'application/pdf', 'file' => '2026/11/doc.pdf' ] );

		foreach ( [ $post, $pdf, 999999 ] as $id ) {
			$result = $this->run_ability( 'update-media', [ 'id' => $id, 'title' => 'Changed' ] );
			$this->assertSame( 'md3_mcp_not_found', $result->get_error_code(), (string) $id );
		}
		$this->assertSame( 'Post', get_post( $post )->post_title );
		$this->assertSame( 'Old', get_post( $pdf )->post_title );
	}

	public function test_a_non_image_never_asks_for_the_publish_scope() {
		$parent = self::factory()->post->create( [ 'post_type' => 'page', 'post_status' => 'publish' ] );
		$child  = self::factory()->post->create( [ 'post_type' => 'page', 'post_status' => 'draft', 'post_parent' => $parent ] );
		$this->writer();

		$this->assertTrue( $this->permission( $child ) );
		$this->assertNull( \MD3\WPMCP\Auth\Request_Context::denied_scope() );
		$this->assertSame( 'md3_mcp_not_found', $this->run_ability( 'update-media', [ 'id' => $child, 'alt' => 'x' ] )->get_error_code() );
	}

	public function test_needs_the_write_scope() {
		$img = $this->image();
		$this->as_mcp_user( [ 'content:read' ] );
		$this->assertSame( 'md3_mcp_insufficient_scope', $this->permission( $img )->get_error_code() );
	}

	public function test_an_image_on_live_content_needs_the_publish_scope() {
		foreach ( [ 'publish', 'future', 'private' ] as $status ) {
			$img  = $this->image();
			$args = [ 'post_status' => $status ];
			if ( 'future' === $status ) {
				$args['post_date'] = gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS );
			}
			set_post_thumbnail( self::factory()->post->create( $args ), $img );

			$this->writer();
			$this->assertSame( 'md3_mcp_insufficient_scope', $this->permission( $img )->get_error_code(), $status );
			$this->publisher();
			$this->assertTrue( $this->permission( $img ), $status );
		}

		$attached = $this->image( [ 'post_parent' => self::factory()->post->create( [ 'post_status' => 'publish' ] ) ] );
		$this->writer();
		$this->assertSame( 'md3_mcp_insufficient_scope', $this->permission( $attached )->get_error_code(), 'attached' );
	}

	public function test_an_image_on_draft_or_trashed_content_needs_only_write() {
		foreach ( [ 'draft', 'trash' ] as $status ) {
			$img = $this->image();
			set_post_thumbnail( self::factory()->post->create( [ 'post_status' => $status ] ), $img );
			$this->writer();
			$this->assertTrue( $this->permission( $img ), $status );
		}
		$this->writer();
		$this->assertTrue( $this->permission( $this->image() ), 'unused' );
	}

	public function test_needs_edit_rights_on_the_image() {
		$img = $this->image( [ 'post_author' => self::factory()->user->create( [ 'role' => 'administrator' ] ) ] );
		$this->writer( 'contributor' );
		$this->assertSame( 'md3_mcp_forbidden', $this->run_ability( 'update-media', [ 'id' => $img, 'alt' => 'x' ] )->get_error_code() );
		$this->assertSame( '', get_post_meta( $img, '_wp_attachment_image_alt', true ) );
	}

	public function test_is_registered_and_logged() {
		global $wpdb;
		$this->writer();
		$img = $this->image();
		$this->assertNotNull( wp_get_ability( 'md3-wp-mcp/update-media' ) );

		$this->run_ability( 'update-media', [ 'id' => $img, 'alt' => 'x' ] );
		$row = $wpdb->get_row( 'SELECT result, object_id FROM ' . Schema::table( 'log' ) . " WHERE tool = 'update-media' ORDER BY id DESC LIMIT 1" );
		$this->assertSame( 'ok', $row->result );
		$this->assertSame( $img, (int) $row->object_id );
	}
}
