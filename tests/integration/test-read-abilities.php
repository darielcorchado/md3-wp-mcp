<?php
use MD3\WPMCP\Abilities\Registry;
use MD3\WPMCP\Tests\Test_Case;

class Test_Read_Abilities extends Test_Case {

	public function test_abilities_are_not_public_on_default_mcp_server() {
		foreach ( Registry::names() as $n ) {
			$meta = wp_get_ability( $n )->get_meta();
			$this->assertFalse( $meta['mcp']['public'] );
			$this->assertSame( 'tool', $meta['mcp']['type'] );
		}
	}

	public function test_list_content_requires_read_scope() {
		$this->as_mcp_user( [] );
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );
		$this->assertWPError( $this->run_ability( 'list-content', [] ) );
	}

	public function test_denied_call_is_logged() {
		$this->as_mcp_user( [] );
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );
		$this->run_ability( 'list-content', [] );
		$rows = \MD3\WPMCP\Audit_Log::query( [ 'tool' => 'list-content', 'result' => 'denied' ] );
		$this->assertSame( 1, $rows['total'] );
	}

	public function test_denied_call_logs_the_object() {
		$id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$this->as_mcp_user( [] );
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );
		$this->run_ability( 'get-content', [ 'id' => $id ] );
		$this->run_ability( 'list-content', [ 'type' => 'page' ] );

		global $wpdb;
		$table = \MD3\WPMCP\Schema::table( 'log' );
		$get   = $wpdb->get_row( $wpdb->prepare( "SELECT object_type, object_id FROM {$table} WHERE tool = %s AND result = 'denied'", 'get-content' ) );
		$this->assertSame( (string) $id, (string) $get->object_id );
		$list = $wpdb->get_row( $wpdb->prepare( "SELECT object_type, object_id FROM {$table} WHERE tool = %s AND result = 'denied'", 'list-content' ) );
		$this->assertSame( 'page', $list->object_type );
		$this->assertNull( $list->object_id );
	}

	public function test_list_content_returns_drafts_and_respects_per_page_cap() {
		self::factory()->post->create_many( 3, [ 'post_status' => 'draft' ] );
		$this->as_mcp_user( [ 'content:read' ] );
		$r = $this->run_ability( 'list-content', [ 'status' => 'draft', 'per_page' => 2 ] );
		$this->assertCount( 2, $r['items'] );
		$this->assertSame( 3, $r['total'] );
		$this->assertSame( 2, $r['pages'] );
		$this->assertWPError( $this->run_ability( 'list-content', [ 'per_page' => 51 ] ) );
	}

	public function test_list_content_any_excludes_trash_and_rejects_disallowed_type() {
		$trashed = self::factory()->post->create( [ 'post_status' => 'trash' ] );
		$draft   = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$this->as_mcp_user( [ 'content:read' ] );
		$ids = wp_list_pluck( $this->run_ability( 'list-content', [] )['items'], 'id' );
		$this->assertContains( $draft, $ids );
		$this->assertNotContains( $trashed, $ids );
		$this->assertSame( 'md3_mcp_type_not_allowed', $this->run_ability( 'list-content', [ 'type' => 'attachment' ] )->get_error_code() );
	}

	public function test_list_content_filters_by_term_and_search() {
		$cat = self::factory()->category->create();
		$in  = self::factory()->post->create( [ 'post_title' => 'Zebra crossing', 'post_category' => [ $cat ] ] );
		self::factory()->post->create( [ 'post_title' => 'Other' ] );
		$this->as_mcp_user( [ 'content:read' ] );
		$r = $this->run_ability( 'list-content', [ 'taxonomy' => 'category', 'term_id' => $cat ] );
		$this->assertSame( [ $in ], wp_list_pluck( $r['items'], 'id' ) );
		$r = $this->run_ability( 'list-content', [ 'search' => 'Zebra' ] );
		$this->assertSame( [ $in ], wp_list_pluck( $r['items'], 'id' ) );
	}

	public function test_get_content_on_attachment_is_not_found() {
		$id = self::factory()->attachment->create();
		$this->as_mcp_user( [ 'content:read' ] );
		$this->assertSame( 'md3_mcp_not_found', $this->run_ability( 'get-content', [ 'id' => $id ] )->get_error_code() );
	}

	public function test_get_content_on_disallowed_type_is_forbidden() {
		register_post_type( 'book', [ 'public' => true ] );
		$id = self::factory()->post->create( [ 'post_type' => 'book' ] );
		$this->as_mcp_user( [ 'content:read' ] );
		$this->assertSame( 'md3_mcp_type_not_allowed', $this->run_ability( 'get-content', [ 'id' => $id ] )->get_error_code() );
	}

	public function test_get_content_requires_edit_capability() {
		$id = self::factory()->post->create();
		$this->as_mcp_user( [ 'content:read' ], 'subscriber' );
		$this->assertSame( 'md3_mcp_forbidden', $this->run_ability( 'get-content', [ 'id' => $id ] )->get_error_code() );
	}

	public function test_get_content_includes_seo_fields_and_raw_blocks() {
		$blocks = "<!-- wp:paragraph -->\n<p>Hello</p>\n<!-- /wp:paragraph -->";
		$id     = self::factory()->post->create( [ 'post_content' => $blocks ] );
		update_post_meta( $id, 'rank_math_title', 'SEO title' );
		update_post_meta( $id, 'rank_math_description', 'SEO description' );
		update_post_meta( $id, 'rank_math_focus_keyword', 'keyword' );
		$this->as_mcp_user( [ 'content:read' ] );

		$r = $this->run_ability( 'get-content', [ 'id' => $id ] );
		$this->assertSame( $blocks, $r['content'] );
		$this->assertSame(
			[ 'title' => 'SEO title', 'description' => 'SEO description', 'focus_keyword' => 'keyword' ],
			$r['seo']
		);
		$this->assertNull( $r['featured_image'] );
		$this->assertSame( [], $r['translations'] );
		$this->assertSame( $id, $r['id'] );
		$this->assertArrayHasKey( 'preview_link', $r );
	}

	public function test_list_content_term_id_without_taxonomy_is_refused() {
		$this->as_mcp_user( [ 'content:read' ] );
		$cat = self::factory()->category->create();
		$r   = $this->run_ability( 'list-content', [ 'term_id' => $cat ] );
		$this->assertWPError( $r );
		$this->assertSame( 'md3_mcp_invalid_taxonomy', $r->get_error_code() );
		$this->assertSame( 400, $r->get_error_data()['status'] );
	}

	public function test_list_post_types_only_returns_allowed_types() {
		register_post_type( 'book', [ 'public' => true ] );
		$this->as_mcp_user( [ 'content:read' ] );
		$r = $this->run_ability( 'list-post-types', [] );
		$this->assertSame( [ 'post', 'page' ], wp_list_pluck( $r['post_types'], 'name' ) );
	}

	public function test_list_terms_requires_taxonomy_of_an_allowed_type() {
		$cat = self::factory()->category->create( [ 'name' => 'News' ] );
		$this->as_mcp_user( [ 'content:read' ] );
		$r = $this->run_ability( 'list-terms', [ 'taxonomy' => 'category', 'search' => 'News' ] );
		$this->assertSame( $cat, $r['terms'][0]['id'] );
		$this->assertSame( 'md3_mcp_invalid_taxonomy', $this->run_ability( 'list-terms', [ 'taxonomy' => 'nav_menu' ] )->get_error_code() );
	}
}
