<?php
use MD3\WPMCP\Abilities\Publish_Content;
use MD3\WPMCP\Tests\Test_Case;

class Test_Lifecycle_Abilities extends Test_Case {

	public function test_publish_requires_publish_scope() {
		$id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$this->as_mcp_user( [ 'content:read', 'content:write' ] );
		$result = ( new Publish_Content() )->check_permission( [ 'id' => $id ] );
		$this->assertSame( 'md3_mcp_insufficient_scope', $result->get_error_code() );
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );
		$this->assertWPError( $this->run_ability( 'publish-content', [ 'id' => $id ] ) );
		$this->assertSame( 'draft', get_post( $id )->post_status );
	}

	public function test_publish_now_writes_brevo_opt_out() {
		$id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$this->as_mcp_user( [ 'content:read', 'content:publish' ] );
		$r = $this->run_ability( 'publish-content', [ 'id' => $id ] );
		$this->assertNotWPError( $r );
		$this->assertSame( 'publish', $r['status'] );
		$this->assertSame( 'publish', get_post( $id )->post_status );
		$this->assertSame( '0', get_post_meta( $id, 'sib_push_send_notification', true ) );
		$this->assertSame( '1', get_post_meta( $id, 'sib_push_meta_box_present', true ) );
	}

	public function test_schedule_without_offset_uses_site_timezone() {
		update_option( 'timezone_string', 'America/New_York' );
		$id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$this->as_mcp_user( [ 'content:read', 'content:publish' ] );
		$this->run_ability( 'publish-content', [ 'id' => $id, 'date' => '2099-12-01 09:00' ] );
		$p = get_post( $id );
		$this->assertSame( 'future', $p->post_status );
		$this->assertSame( '2099-12-01 09:00:00', $p->post_date );
		$this->assertSame( '2099-12-01 14:00:00', $p->post_date_gmt );
	}

	public function test_schedule_with_offset_is_honoured() {
		update_option( 'timezone_string', 'America/New_York' );
		$id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$this->as_mcp_user( [ 'content:read', 'content:publish' ] );
		$this->run_ability( 'publish-content', [ 'id' => $id, 'date' => '2099-12-01T09:00:00+00:00' ] );
		$p = get_post( $id );
		$this->assertSame( 'future', $p->post_status );
		$this->assertSame( '2099-12-01 09:00:00', $p->post_date_gmt );
		$this->assertSame( '2099-12-01 04:00:00', $p->post_date );
	}

	public function test_past_date_publishes_immediately_with_that_date() {
		update_option( 'timezone_string', 'America/New_York' );
		$id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$this->as_mcp_user( [ 'content:read', 'content:publish' ] );
		$r = $this->run_ability( 'publish-content', [ 'id' => $id, 'date' => '2020-01-02 10:00' ] );
		$this->assertNotWPError( $r );
		$p = get_post( $id );
		$this->assertSame( 'publish', $p->post_status );
		$this->assertSame( '2020-01-02 10:00:00', $p->post_date );
		$this->assertSame( '2020-01-02 15:00:00', $p->post_date_gmt );
	}

	public function test_invalid_date_is_rejected() {
		$id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$this->as_mcp_user( [ 'content:read', 'content:publish' ] );
		$r = $this->run_ability( 'publish-content', [ 'id' => $id, 'date' => 'not a date' ] );
		$this->assertSame( 'md3_mcp_bad_date', $r->get_error_code() );
		$this->assertSame( 'draft', get_post( $id )->post_status );
	}

	public function test_publish_now_on_scheduled_post_publishes_now() {
		$id = self::factory()->post->create( [ 'post_status' => 'future', 'post_date' => '2099-01-01 10:00:00' ] );
		$this->as_mcp_user( [ 'content:read', 'content:publish' ] );
		$this->run_ability( 'publish-content', [ 'id' => $id ] );
		$this->assertSame( 'publish', get_post( $id )->post_status );
	}

	public function test_unpublish_moves_published_and_scheduled_to_draft() {
		$pub    = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		$future = self::factory()->post->create( [ 'post_status' => 'future', 'post_date' => '2099-01-01 10:00:00' ] );
		$this->as_mcp_user( [ 'content:read', 'content:publish' ] );
		$this->assertSame( 'draft', $this->run_ability( 'unpublish-content', [ 'id' => $pub ] )['status'] );
		$this->assertSame( 'draft', $this->run_ability( 'unpublish-content', [ 'id' => $future ] )['status'] );
		$this->assertSame( 'draft', get_post( $future )->post_status );
	}

	public function test_trash_and_restore_never_delete() {
		$id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		$this->as_mcp_user( [ 'content:read', 'content:delete' ] );
		$this->assertSame( 'trash', $this->run_ability( 'trash-content', [ 'id' => $id ] )['status'] );
		$this->assertSame( 'trash', get_post( $id )->post_status );
		$this->assertSame( 'draft', $this->run_ability( 'restore-content', [ 'id' => $id ] )['status'] );
		$this->assertSame( 'draft', get_post( $id )->post_status );
		$this->assertNotNull( get_post( $id ) );
	}

	public function test_trash_and_restore_need_delete_scope() {
		$id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$this->as_mcp_user( [ 'content:read', 'content:write', 'content:publish' ] );
		$this->assertSame( 'md3_mcp_insufficient_scope', ( new \MD3\WPMCP\Abilities\Trash_Content() )->check_permission( [ 'id' => $id ] )->get_error_code() );
		$this->assertSame( 'md3_mcp_insufficient_scope', ( new \MD3\WPMCP\Abilities\Restore_Content() )->check_permission( [ 'id' => $id ] )->get_error_code() );
	}

	public function test_trash_is_marked_destructive() {
		$this->assertTrue( ( new \MD3\WPMCP\Abilities\Trash_Content() )->annotations()['destructive'] );
	}

	public function test_restore_rejects_content_that_is_not_trashed() {
		$id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$this->as_mcp_user( [ 'content:read', 'content:delete' ] );
		$this->assertSame( 'md3_mcp_not_trashed', $this->run_ability( 'restore-content', [ 'id' => $id ] )->get_error_code() );
	}

	public function test_contributor_cannot_publish_even_with_scope() {
		$id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$uid = $this->as_mcp_user( [ 'content:read', 'content:publish' ], 'contributor' );
		wp_update_post( [ 'ID' => $id, 'post_author' => $uid ] );
		$r = $this->run_ability( 'publish-content', [ 'id' => $id ] );
		$this->assertSame( 'md3_mcp_forbidden', $r->get_error_code() );
		$this->assertSame( 'draft', get_post( $id )->post_status );
	}

	public function test_missing_post_is_not_found() {
		$this->as_mcp_user( [ 'content:read', 'content:publish', 'content:delete' ] );
		foreach ( [ 'publish-content', 'unpublish-content', 'trash-content', 'restore-content' ] as $name ) {
			$this->assertSame( 'md3_mcp_not_found', $this->run_ability( $name, [ 'id' => 999999 ] )->get_error_code() );
		}
	}
}
