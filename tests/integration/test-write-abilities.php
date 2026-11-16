<?php
use MD3\WPMCP\Abilities\Update_Content;
use MD3\WPMCP\Settings;
use MD3\WPMCP\Tests\Test_Case;

class Test_Write_Abilities extends Test_Case {

	public function test_create_content_saves_sanitized_draft_with_seo_and_brevo_off() {
		$this->as_mcp_user( [ 'content:read', 'content:write' ] );
		$r = $this->run_ability( 'create-content', [ 'title' => 'T', 'content' => '<p>x<script>1</script></p>', 'seo' => [ 'title' => 'S' ] ] );
		$this->assertNotWPError( $r );
		$p = get_post( $r['id'] );
		$this->assertSame( 'draft', $p->post_status );
		$this->assertStringNotContainsString( 'script', $p->post_content );
		$this->assertSame( 'S', get_post_meta( $p->ID, 'rank_math_title', true ) );
		$this->assertSame( '0', get_post_meta( $p->ID, 'sib_push_send_notification', true ) );
		$this->assertSame( '1', get_post_meta( $p->ID, 'sib_push_meta_box_present', true ) );
		$this->assertSame( $p->ID, $r['id'] );
	}

	public function test_create_content_rejects_publish_status_and_unknown_fields() {
		$this->as_mcp_user( [ 'content:read', 'content:write' ] );
		$this->assertWPError( $this->run_ability( 'create-content', [ 'title' => 'T', 'status' => 'publish' ] ) );
		$this->assertWPError( $this->run_ability( 'create-content', [ 'title' => 'T', 'meta' => [ 'a' => 'b' ] ] ) );
	}

	public function test_create_content_sets_polylang_language() {
		$this->as_mcp_user( [ 'content:read', 'content:write' ] );
		$r = $this->run_ability( 'create-content', [ 'title' => 'Hola', 'language' => 'es' ] );
		$this->assertNotWPError( $r );
		$this->assertSame( 'es', pll_get_post_language( $r['id'] ) );
		$this->assertSame( 'es', $r['language'] );
	}

	public function test_create_content_rejects_unknown_language_before_writing() {
		$this->as_mcp_user( [ 'content:read', 'content:write' ] );
		$before = wp_count_posts()->draft;
		$r      = $this->run_ability( 'create-content', [ 'title' => 'Bonjour', 'language' => 'fr' ] );
		$this->assertSame( 'md3_mcp_bad_language', $r->get_error_code() );
		$this->assertStringContainsString( 'en, es', $r->get_error_message() );
		wp_cache_flush();
		$this->assertSame( $before, wp_count_posts()->draft );
	}

	public function test_update_content_leaves_language_to_link_translations() {
		$id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$this->as_mcp_user( [ 'content:read', 'content:write' ] );
		$r = $this->run_ability( 'update-content', [ 'id' => $id, 'language' => 'es' ] );
		$this->assertSame( 'ability_invalid_input', $r->get_error_code() );
		$this->assertFalse( pll_get_post_language( $id ) );

		// The writer refuses it too, whatever the schema lets through.
		$this->assertSame( 'md3_mcp_unknown_field', \MD3\WPMCP\Abilities\Content_Writer::save( [ 'language' => 'es' ], get_post( $id ) )->get_error_code() );
	}

	public function test_update_published_post_requires_publish_scope() {
		$id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		$this->as_mcp_user( [ 'content:read', 'content:write' ] );
		$result = ( new Update_Content() )->check_permission( [ 'id' => $id, 'title' => 'N' ] );
		$this->assertSame( 'md3_mcp_insufficient_scope', $result->get_error_code() );
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );
		$this->assertWPError( $this->run_ability( 'update-content', [ 'id' => $id, 'title' => 'N' ] ) );
		$this->assertNotSame( 'N', get_post( $id )->post_title );
	}

	public function test_update_published_post_with_publish_scope_works() {
		$id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		$this->as_mcp_user( [ 'content:read', 'content:write', 'content:publish' ] );
		$r = $this->run_ability( 'update-content', [ 'id' => $id, 'title' => 'N' ] );
		$this->assertNotWPError( $r );
		$this->assertSame( 'N', get_post( $id )->post_title );
		$this->assertSame( 'publish', get_post( $id )->post_status );
	}

	public function test_update_only_changes_given_fields_and_keeps_a_revision() {
		$id = self::factory()->post->create( [ 'post_title' => 'Old', 'post_content' => '<p>keep</p>', 'post_status' => 'draft' ] );
		$this->as_mcp_user( [ 'content:read', 'content:write' ] );
		$r = $this->run_ability( 'update-content', [ 'id' => $id, 'title' => 'New' ] );
		$this->assertNotWPError( $r );
		$p = get_post( $id );
		$this->assertSame( 'New', $p->post_title );
		$this->assertSame( '<p>keep</p>', $p->post_content );
		$this->assertGreaterThanOrEqual( 1, count( wp_get_post_revisions( $id ) ) );
	}

	public function test_update_missing_or_unauthorized_post() {
		$this->as_mcp_user( [ 'content:read', 'content:write' ] );
		$this->assertSame( 'md3_mcp_not_found', $this->run_ability( 'update-content', [ 'id' => 999999, 'title' => 'x' ] )->get_error_code() );
		$this->as_mcp_user( [ 'content:read', 'content:write' ], 'subscriber' );
		$id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$this->assertSame( 'md3_mcp_forbidden', $this->run_ability( 'update-content', [ 'id' => $id, 'title' => 'x' ] )->get_error_code() );
	}

	public function test_update_content_strips_scripts_even_with_unfiltered_html() {
		$id      = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$user_id = $this->as_mcp_user( [ 'content:read', 'content:write' ] );
		get_user_by( 'id', $user_id )->add_cap( 'unfiltered_html' );
		$this->assertTrue( current_user_can( 'unfiltered_html' ) );

		$r = $this->run_ability(
			'update-content',
			[
				'id'      => $id,
				'content' => '<p>Body</p><script>alert(1)</script>',
				'excerpt' => 'Short<script>alert(2)</script>',
			]
		);
		$this->assertNotWPError( $r );

		$post = get_post( $id );
		$this->assertStringContainsString( '<p>Body</p>', $post->post_content );
		$this->assertStringNotContainsString( '<script', $post->post_content );
		$this->assertStringNotContainsString( '<script', $post->post_excerpt );
		$this->assertStringContainsString( 'Short', $post->post_excerpt );
	}

	public function test_update_refuses_trashed_content() {
		$id = self::factory()->post->create( [ 'post_status' => 'draft', 'post_title' => 'Binned' ] );
		wp_trash_post( $id );
		$this->as_mcp_user( [ 'content:read', 'content:write' ] );

		foreach ( [ [ 'status' => 'draft' ], [ 'title' => 'Edited' ] ] as $change ) {
			$result = $this->run_ability( 'update-content', [ 'id' => $id ] + $change );
			$this->assertWPError( $result );
			$this->assertSame( 'md3_mcp_trashed', $result->get_error_code() );
			$this->assertSame( 409, $result->get_error_data()['status'] );
		}
		$this->assertSame( 'trash', get_post_status( $id ) );
		$this->assertSame( 'Binned', get_post( $id )->post_title );
	}

	public function test_page_template_must_exist() {
		$this->as_mcp_user( [ 'content:read', 'content:write' ] );
		$r = $this->run_ability( 'create-content', [ 'type' => 'page', 'title' => 'P', 'template' => 'nope.php' ] );
		$this->assertWPError( $r );
		$this->assertSame( 'md3_mcp_bad_template', $r->get_error_code() );
		$this->assertNotWPError( $this->run_ability( 'create-content', [ 'type' => 'page', 'title' => 'P', 'template' => 'default' ] ) );
	}

	public function test_hierarchical_fields_rejected_on_posts() {
		$this->as_mcp_user( [ 'content:read', 'content:write' ] );
		$this->assertWPError( $this->run_ability( 'create-content', [ 'title' => 'P', 'menu_order' => 3 ] ) );
	}

	public function test_author_role_cannot_create_page() {
		$this->as_mcp_user( [ 'content:read', 'content:write' ], 'author' );
		$this->assertWPError( $this->run_ability( 'create-content', [ 'type' => 'page', 'title' => 'P' ] ) );
	}

	public function test_create_content_credits_configured_author_and_mcp_user_does_the_work() {
		$mcp    = $this->as_mcp_user( [ 'content:read', 'content:write' ] );
		$author = self::factory()->user->create( [ 'role' => 'author' ] );
		Settings::update_default_author( $author );
		$r = $this->run_ability( 'create-content', [ 'title' => 'T', 'content' => '<p>x</p>' ] );
		$this->assertSame( $author, (int) get_post( $r['id'] )->post_author );
		$this->run_ability( 'update-content', [ 'id' => $r['id'], 'title' => 'T2' ] );
		$this->assertSame( $author, (int) get_post( $r['id'] )->post_author );
		$revisions = wp_get_post_revisions( $r['id'] );
		$this->assertSame( $mcp, (int) reset( $revisions )->post_author );
	}

	public function test_create_content_falls_back_to_mcp_user_when_author_deleted() {
		$mcp    = $this->as_mcp_user( [ 'content:read', 'content:write' ] );
		$author = self::factory()->user->create( [ 'role' => 'author' ] );
		Settings::update_default_author( $author );
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $author );
		$r = $this->run_ability( 'create-content', [ 'title' => 'T' ] );
		$this->assertSame( $mcp, (int) get_post( $r['id'] )->post_author );
	}

	public function test_author_input_is_rejected() {
		$mcp = $this->as_mcp_user( [ 'content:read', 'content:write' ] );
		$r = $this->run_ability( 'create-content', [ 'title' => 'T', 'author' => 1 ] );
		$this->assertWPError( $r );
		$this->assertSame( 'ability_invalid_input', $r->get_error_code() );
		$id = self::factory()->post->create( [ 'post_author' => 1 ] );
		$this->assertWPError( $this->run_ability( 'update-content', [ 'id' => $id, 'author' => $mcp ] ) );
		$this->assertSame( 1, (int) get_post( $id )->post_author );
	}

	public function test_writer_rejects_unknown_key_with_its_own_code() {
		$this->as_mcp_user( [ 'content:read', 'content:write' ] );
		$r = \MD3\WPMCP\Abilities\Content_Writer::save( [ 'title' => 'T', 'author' => 1 ], null );
		$this->assertSame( 'md3_mcp_unknown_field', $r->get_error_code() );
	}

	public function test_terms_and_featured_media() {
		$this->as_mcp_user( [ 'content:read', 'content:write' ] );
		$cat   = self::factory()->category->create();
		$media = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$r     = $this->run_ability( 'create-content', [ 'title' => 'T', 'terms' => [ 'category' => [ $cat ] ], 'featured_media' => $media ] );
		$this->assertNotWPError( $r );
		$this->assertContains( $cat, wp_get_post_categories( $r['id'] ) );
		$this->assertSame( $media, (int) get_post_thumbnail_id( $r['id'] ) );

		$bad = $this->run_ability( 'create-content', [ 'title' => 'T', 'terms' => [ 'category' => [ 999999 ] ] ] );
		$this->assertSame( 'md3_mcp_bad_term', $bad->get_error_code() );
		$bad = $this->run_ability( 'create-content', [ 'title' => 'T', 'terms' => [ 'nav_menu' => [ 1 ] ] ] );
		$this->assertWPError( $bad );
		$bad = $this->run_ability( 'create-content', [ 'title' => 'T', 'featured_media' => $r['id'] ] );
		$this->assertWPError( $bad );
	}

	public function test_terms_need_the_assign_terms_capability() {
		register_taxonomy( 'md3_restricted', 'post', [ 'public' => true, 'capabilities' => [ 'assign_terms' => 'manage_options' ] ] );
		$term = self::factory()->term->create( [ 'taxonomy' => 'md3_restricted' ] );
		$this->as_mcp_user( [ 'content:read', 'content:write' ] );

		$r = $this->run_ability( 'create-content', [ 'title' => 'Restricted terms', 'terms' => [ 'md3_restricted' => [ $term ] ] ] );
		$this->assertWPError( $r );
		$this->assertSame( 'md3_mcp_forbidden', $r->get_error_code() );
		$this->assertSame( 403, $r->get_error_data()['status'] );
		$this->assertCount( 0, get_posts( [ 'title' => 'Restricted terms', 'post_status' => 'any' ] ) );

		$id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$r  = $this->run_ability( 'update-content', [ 'id' => $id, 'terms' => [ 'md3_restricted' => [ $term ] ] ] );
		$this->assertSame( 'md3_mcp_forbidden', $r->get_error_code() );
		$this->assertSame( [], wp_get_object_terms( $id, 'md3_restricted', [ 'fields' => 'ids' ] ) );

		unregister_taxonomy( 'md3_restricted' );
	}

	public function test_create_term_in_post_category() {
		$this->as_mcp_user( [ 'content:read', 'content:write' ] );
		$r = $this->run_ability( 'create-term', [ 'taxonomy' => 'category', 'name' => 'Brand New' ] );
		$this->assertNotWPError( $r );
		$this->assertSame( 'Brand New', get_term( $r['id'], 'category' )->name );
		$this->assertSame( 'brand-new', $r['slug'] );
		$this->assertWPError( $this->run_ability( 'create-term', [ 'taxonomy' => 'nav_menu', 'name' => 'x' ] ) );
	}

	public function test_create_term_requires_capability() {
		$this->as_mcp_user( [ 'content:read', 'content:write' ], 'author' );
		$this->assertWPError( $this->run_ability( 'create-term', [ 'taxonomy' => 'category', 'name' => 'Nope' ] ) );
	}

	public function test_write_tools_require_write_scope() {
		$this->as_mcp_user( [ 'content:read' ] );
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );
		$this->assertWPError( $this->run_ability( 'create-content', [ 'title' => 'T' ] ) );
	}
}
