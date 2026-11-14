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

	public function test_malformed_or_lenient_dates_are_rejected() {
		$id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$this->as_mcp_user( [ 'content:read', 'content:publish' ] );
		foreach ( [ '0000-00-00', '0000-00-00 00:00', '2020-02-31 10:00', '2020-13-01 10:00', '2020-01-01 25:00', 'tomorrow', '+1 week', '@123', '   ', '1969-12-31 10:00', '2020-01-01' ] as $bad ) {
			$r = $this->run_ability( 'publish-content', [ 'id' => $id, 'date' => $bad ] );
			$this->assertWPError( $r, $bad );
			$this->assertSame( 'md3_mcp_bad_date', $r->get_error_code(), $bad );
		}
		$this->assertSame( 'draft', get_post( $id )->post_status );
	}

	public function test_far_future_date_is_scheduled() {
		$id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$this->as_mcp_user( [ 'content:read', 'content:publish' ] );
		$this->run_ability( 'publish-content', [ 'id' => $id, 'date' => '9999-12-31 23:59' ] );
		$this->assertSame( 'future', get_post( $id )->post_status );
	}

	public function test_iso_forms_with_z_seconds_and_compact_offset_are_accepted() {
		update_option( 'timezone_string', 'America/New_York' );
		$this->as_mcp_user( [ 'content:read', 'content:publish' ] );
		foreach ( [ '2099-12-01T09:00:00Z' => '2099-12-01 09:00:00', '2099-12-01 09:00:30' => '2099-12-01 14:00:30', '2099-12-01T09:00+0100' => '2099-12-01 08:00:00' ] as $in => $gmt ) {
			$id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
			$this->assertNotWPError( $this->run_ability( 'publish-content', [ 'id' => $id, 'date' => $in ] ), $in );
			$this->assertSame( $gmt, get_post( $id )->post_date_gmt, $in );
		}
	}

	public function test_dst_edge_times_in_site_timezone() {
		update_option( 'timezone_string', 'America/New_York' );
		$this->as_mcp_user( [ 'content:read', 'content:publish' ] );
		// Pinned PHP behaviour: a nonexistent local time (spring forward) is
		// moved forward an hour (02:30 -> 03:30 EDT = 07:30 UTC); an ambiguous one (fall back)
		// resolves to the first occurrence, EDT (01:30 -> 05:30 UTC).
		foreach ( [ '2027-03-14 02:30' => '2027-03-14 07:30:00', '2027-11-07 01:30' => '2027-11-07 05:30:00' ] as $in => $gmt ) {
			$id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
			$this->assertNotWPError( $this->run_ability( 'publish-content', [ 'id' => $id, 'date' => $in ] ), $in );
			$this->assertSame( $gmt, get_post( $id )->post_date_gmt, $in );
		}
	}

	public function test_trash_refuses_when_trash_is_disabled() {
		$id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		$this->as_mcp_user( [ 'content:read', 'content:delete' ] );
		add_filter( 'md3_mcp_trash_enabled', '__return_false' );
		$r = $this->run_ability( 'trash-content', [ 'id' => $id ] );
		remove_filter( 'md3_mcp_trash_enabled', '__return_false' );
		$this->assertSame( 'md3_mcp_trash_disabled', $r->get_error_code() );
		$this->assertNotNull( get_post( $id ) );
		$this->assertSame( 'publish', get_post( $id )->post_status );
	}

	public function test_lifecycle_refuses_disallowed_type_attachment_and_unauthorized_user() {
		register_post_type( 'md3_secret', [ 'public' => true ] );
		$secret = self::factory()->post->create( [ 'post_type' => 'md3_secret', 'post_status' => 'trash' ] );
		$attach = self::factory()->attachment->create( [ 'post_status' => 'inherit' ] );
		$this->as_mcp_user( [ 'content:read', 'content:publish', 'content:delete' ] );
		foreach ( [ 'publish-content', 'unpublish-content', 'trash-content', 'restore-content' ] as $name ) {
			$this->assertSame( 'md3_mcp_type_not_allowed', $this->run_ability( $name, [ 'id' => $secret ] )->get_error_code(), $name );
			$this->assertSame( 'md3_mcp_not_found', $this->run_ability( $name, [ 'id' => $attach ] )->get_error_code(), $name );
		}
		$this->assertSame( 'trash', get_post( $secret )->post_status );
		unregister_post_type( 'md3_secret' );

		$other = self::factory()->post->create( [ 'post_status' => 'publish', 'post_author' => self::factory()->user->create( [ 'role' => 'editor' ] ) ] );
		$trashed = self::factory()->post->create( [ 'post_status' => 'trash', 'post_author' => get_post( $other )->post_author ] );
		$this->as_mcp_user( [ 'content:read', 'content:publish', 'content:delete' ], 'author' );
		$this->assertSame( 'md3_mcp_forbidden', $this->run_ability( 'trash-content', [ 'id' => $other ] )->get_error_code() );
		$this->assertSame( 'md3_mcp_forbidden', $this->run_ability( 'restore-content', [ 'id' => $trashed ] )->get_error_code() );
		$this->assertSame( 'md3_mcp_forbidden', $this->run_ability( 'unpublish-content', [ 'id' => $other ] )->get_error_code() );
		$this->assertSame( 'publish', get_post( $other )->post_status );
		$this->assertSame( 'trash', get_post( $trashed )->post_status );
	}
}
