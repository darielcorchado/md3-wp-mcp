<?php
use MD3\WPMCP\Audit_Log;
use MD3\WPMCP\Auth\Identity;
use MD3\WPMCP\Auth\Request_Context;
use MD3\WPMCP\Auth\Scopes;
use MD3\WPMCP\Content_Sanitizer;
use MD3\WPMCP\Plugin;
use MD3\WPMCP\Rate_Limiter;
use MD3\WPMCP\Schema;
use MD3\WPMCP\Tests\Test_Case;

class Test_Shared_Services extends Test_Case {

	public function tear_down() {
		Request_Context::clear();
		parent::tear_down();
	}

	public function test_normalize_scopes() {
		$this->assertSame( [ 'content:read', 'content:write' ], Scopes::normalize( '' ) );
		$this->assertSame( [ 'content:read', 'content:publish' ], Scopes::normalize( 'content:publish' ) );
		$this->assertSame( [ 'content:read', 'content:write', 'content:delete' ], Scopes::normalize( 'content:delete content:write content:delete' ) );
		$this->assertNull( Scopes::normalize( 'content:read admin' ) );
	}

	public function test_scope_catalog() {
		$this->assertSame( [ 'content:read', 'content:write', 'content:publish', 'content:delete' ], Scopes::all() );
		$this->assertSame( Scopes::all(), array_keys( Scopes::descriptions() ) );
	}

	public function test_request_context_holds_identity_and_checks_scopes() {
		$this->assertNull( Request_Context::get() );
		$this->assertFalse( Request_Context::has_scope( Scopes::READ ) );
		Request_Context::set( new Identity( 5, 'client-a', [ Scopes::READ ], 'hash' ) );
		$this->assertSame( 5, Request_Context::get()->user_id );
		$this->assertTrue( Request_Context::has_scope( Scopes::READ ) );
		$this->assertFalse( Request_Context::has_scope( Scopes::PUBLISH ) );
		Request_Context::clear();
		$this->assertNull( Request_Context::get() );
	}

	public function test_html_strips_scripts_handlers_and_iframes_but_keeps_block_comments() {
		$in  = "<!-- wp:paragraph --><p onclick=\"x()\">Hi<script>alert(1)</script></p><!-- /wp:paragraph -->"
			. '<!-- wp:md3/content-by-claude {"prompt":"x"} /--><iframe src="https://e.com"></iframe>';
		$out = Content_Sanitizer::html( $in );
		$this->assertStringContainsString( '<!-- wp:paragraph -->', $out );
		$this->assertStringContainsString( '<!-- wp:md3/content-by-claude {"prompt":"x"} /-->', $out );
		$this->assertStringNotContainsString( 'script', $out );
		$this->assertStringNotContainsString( 'onclick', $out );
		$this->assertStringNotContainsString( 'iframe', $out );
	}

	public function test_plain_and_slug() {
		$this->assertSame( 'Hello bold', Content_Sanitizer::plain( '  Hello <b>bold</b> ' ) );
		$this->assertSame( 'hello-world', Content_Sanitizer::slug( 'Hello World!' ) );
	}

	public function test_rate_limiter_blocks_after_limit_per_key() {
		for ( $i = 0; $i < 3; $i++ ) {
			$this->assertTrue( Rate_Limiter::hit( 't', 'a', 3 ) );
		}
		$this->assertFalse( Rate_Limiter::hit( 't', 'a', 3 ) );
		$this->assertTrue( Rate_Limiter::hit( 't', 'b', 3 ) );
	}

	public function test_client_ip_hash() {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
		$this->assertSame( hash( 'sha256', wp_salt( 'auth' ) . '203.0.113.9' ), Plugin::client_ip_hash() );
	}

	public function test_audit_log_records_identity_and_prunes_old_rows() {
		global $wpdb;
		Request_Context::set( new Identity( 7, 'client-x', [ Scopes::READ ], 'h' ) );
		Audit_Log::record( 'create-content', 'ok', 'post', 42, 'Created' );
		Audit_Log::record( 'list-content', 'error' );

		$res = Audit_Log::query( [ 'client_id' => 'client-x', 'user_id' => 7, 'tool' => 'create-content' ] );
		$this->assertSame( 1, $res['total'] );
		$row = $res['rows'][0];
		$this->assertSame( 7, (int) $row->user_id );
		$this->assertSame( 'client-x', $row->client_id );
		$this->assertSame( 42, (int) $row->object_id );
		$this->assertSame( 1, Audit_Log::query( [ 'result' => 'error' ] )['total'] );

		$wpdb->update( Schema::table( 'log' ), [ 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 91 * DAY_IN_SECONDS ) ], [ 'id' => $row->id ] );
		$this->assertSame( 1, Audit_Log::prune() );
		$this->assertSame( 1, Audit_Log::query( [] )['total'] );
	}

	public function test_audit_log_without_identity_uses_current_user() {
		$user = self::factory()->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $user );
		Audit_Log::record( 'get-content', 'ok' );
		$row = Audit_Log::query( [ 'tool' => 'get-content' ] )['rows'][0];
		$this->assertSame( $user, (int) $row->user_id );
		$this->assertSame( '', $row->client_id );
	}
}
