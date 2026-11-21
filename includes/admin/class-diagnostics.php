<?php
namespace MD3\WPMCP\Admin;

use MD3\WPMCP\OAuth\Discovery;
use MD3\WPMCP\OAuth\Keys;
use MD3\WPMCP\OAuth\OAuth;

defined( 'ABSPATH' ) || exit;

/**
 * Loopback checks that the site's web server lets the OAuth flow through
 * (design §8): no page cache, no redirects, and the Authorization header
 * reaching PHP.
 */
final class Diagnostics {

	const TRANSIENT = 'md3_mcp_diag';
	const ROUTE     = '/diagnostics/auth-echo';

	/** Firewall and server blocks these checks can't see (docs/troubleshooting.md). */
	const GUIDE_URL = 'https://github.com/darielcorchado/md3-wp-mcp/blob/master/docs/troubleshooting.md';

	/** The developer's contact page, tagged with the plugin as the source. */
	const CONTACT_URL = 'https://www.md3digitalsolutions.com/contact/?utm_source=md3-wp-mcp';

	public static function register(): void {
		add_action( 'rest_api_init', [ self::class, 'register_routes' ] );
	}

	public static function register_routes(): void {
		register_rest_route(
			OAuth::REST_NAMESPACE,
			self::ROUTE,
			[
				'methods'             => 'GET',
				'callback'            => static fn () => OAuth::json( [ 'received' => true ] ),
				'permission_callback' => [ self::class, 'permission' ],
			]
		);
	}

	/**
	 * True only when the request carries `Bearer <nonce>` for the nonce `run()`
	 * stored in the last 60 seconds. Nothing else is revealed.
	 */
	public static function permission( \WP_REST_Request $request ): bool {
		$nonce = get_transient( self::TRANSIENT );
		$given = (string) $request->get_header( 'authorization' );
		if ( ! is_string( $nonce ) || '' === $nonce || ! str_starts_with( $given, 'Bearer ' ) ) {
			return false;
		}
		return hash_equals( $nonce, substr( $given, 7 ) );
	}

	/**
	 * @return array<array{check: string, ok: bool, detail: string, fix: string}>
	 */
	public static function run(): array {
		$nonce = wp_generate_password( 32, false );
		set_transient( self::TRANSIENT, $nonce, MINUTE_IN_SECONDS );
		$oauth_on = OAuth::transport_allowed();

		$rows = [
			self::check_keys(),
			self::check_protected_resource( $oauth_on ),
			self::check_authorization_server( $oauth_on ),
			self::check_unauthenticated_mcp(),
			self::check_authorization_header( $nonce ),
			self::check_authorize_page(),
		];

		delete_transient( self::TRANSIENT );
		return $rows;
	}

	private static function check_keys(): array {
		$check = __( 'OAuth keys load', 'md3-wp-mcp' );
		try {
			Keys::get();
		} catch ( \Throwable $e ) {
			return self::row( $check, false, $e->getMessage() );
		}
		return self::row( $check, true, __( 'Loaded.', 'md3-wp-mcp' ) );
	}

	private static function check_protected_resource( bool $oauth_on ): array {
		return self::check_document( __( 'Protected resource metadata is served', 'md3-wp-mcp' ), 0, $oauth_on );
	}

	private static function check_authorization_server( bool $oauth_on ): array {
		return self::check_document( __( 'Authorization server metadata is served', 'md3-wp-mcp' ), 1, $oauth_on );
	}

	/**
	 * Fetches one of Discovery::static_files() at its URL and judges it.
	 */
	private static function check_document( string $check, int $index, bool $oauth_on ): array {
		$urls  = array_values( Discovery::static_file_urls() );
		$files = array_values( Discovery::static_files() );
		return self::judge_document( $check, self::request( 'GET', $urls[ $index ] ), $files[ $index ], $oauth_on );
	}

	/**
	 * Judges a discovery document by its content: WordPress serving it, or a
	 * static file with the same content, both pass whatever the headers. A 404
	 * offers the static files (`fix` = missing), but only while OAuth is on,
	 * since the files would otherwise advertise endpoints that don't run;
	 * different content offers fresh ones (`fix` = stale).
	 *
	 * @param array<string,mixed>|string $response wp_remote_request() response, or an error message.
	 * @param string                     $expected The JSON the plugin expects.
	 * @return array{check: string, ok: bool, detail: string, fix: string}
	 */
	public static function judge_document( string $check, $response, string $expected, bool $oauth_on ): array {
		if ( is_string( $response ) ) {
			return self::row( $check, false, $response );
		}

		$code = self::status( $response );
		if ( 404 === $code && ! $oauth_on ) {
			return self::row( $check, false, __( "HTTP 404: OAuth is off because the site isn't served over HTTPS, so the plugin doesn't serve this document.", 'md3-wp-mcp' ) );
		}
		if ( 404 === $code ) {
			return self::row( $check, false, __( "HTTP 404: the web server doesn't pass this address to WordPress.", 'md3-wp-mcp' ), 'missing' );
		}
		if ( 200 !== $code ) {
			/* translators: %d: HTTP status. */
			return self::row( $check, false, sprintf( __( 'HTTP %d.', 'md3-wp-mcp' ), $code ) );
		}

		// An editor may have saved the uploaded file with a byte order mark.
		$served = json_decode( (string) preg_replace( '/^\xEF\xBB\xBF/', '', (string) wp_remote_retrieve_body( $response ) ), true );
		if ( ! is_array( $served ) ) {
			return self::row( $check, false, __( 'HTTP 200, but not a JSON document. Something else answers this address (a cache or a firewall challenge page).', 'md3-wp-mcp' ) );
		}
		if ( self::normalize( $served ) !== self::normalize( (array) json_decode( $expected, true ) ) ) {
			return self::row( $check, false, __( 'HTTP 200, but the content is out of date: the site address, Automatic registration or the plugin changed, the file was edited, or a cache serves an old copy.', 'md3-wp-mcp' ), 'stale' );
		}

		$type = (string) wp_remote_retrieve_header( $response, 'content-type' );
		$ctrl = (string) wp_remote_retrieve_header( $response, 'cache-control' );
		if ( str_contains( $type, 'json' ) && str_contains( $ctrl, 'no-store' ) ) {
			return self::row( $check, true, __( 'HTTP 200, served by WordPress.', 'md3-wp-mcp' ) );
		}
		/* translators: 1: Content-Type, 2: Cache-Control. */
		return self::row( $check, true, sprintf( __( 'HTTP 200, a static file or cached copy with the expected content (Content-Type "%1$s", Cache-Control "%2$s").', 'md3-wp-mcp' ), $type, $ctrl ) );
	}

	/**
	 * What the rows ask for: `stale` if any document is out of date, else
	 * `missing` if any is missing, else ''.
	 *
	 * @param array<array{fix?: string}> $rows run() results.
	 */
	public static function fix( array $rows ): string {
		$fixes = array_column( $rows, 'fix' );
		foreach ( [ 'stale', 'missing' ] as $fix ) {
			if ( in_array( $fix, $fixes, true ) ) {
				return $fix;
			}
		}
		return '';
	}

	/**
	 * Decoded JSON with object keys sorted, so key order doesn't matter but list order does.
	 *
	 * @param array<mixed> $value Decoded JSON.
	 * @return array<mixed>
	 */
	private static function normalize( array $value ): array {
		foreach ( $value as $key => $item ) {
			if ( is_array( $item ) ) {
				$value[ $key ] = self::normalize( $item );
			}
		}
		if ( ! array_is_list( $value ) ) {
			ksort( $value, SORT_STRING );
		}
		return $value;
	}

	private static function check_unauthenticated_mcp(): array {
		$check    = __( 'The MCP endpoint refuses requests without a token', 'md3-wp-mcp' );
		$response = self::request(
			'POST',
			OAuth::resource_url(),
			[
				'headers' => [ 'Content-Type' => 'application/json' ],
				'body'    => '{"jsonrpc":"2.0","id":1,"method":"ping"}',
			]
		);
		if ( is_string( $response ) ) {
			return self::row( $check, false, $response );
		}

		$code      = self::status( $response );
		$challenge = (string) wp_remote_retrieve_header( $response, 'www-authenticate' );
		$ok        = 401 === $code && '' !== $challenge;

		/* translators: 1: HTTP status, 2: whether a WWW-Authenticate header was sent (yes/no). */
		return self::row( $check, $ok, sprintf( __( 'HTTP %1$d, WWW-Authenticate header: %2$s.', 'md3-wp-mcp' ), $code, '' !== $challenge ? __( 'yes', 'md3-wp-mcp' ) : __( 'no', 'md3-wp-mcp' ) ) );
	}

	private static function check_authorization_header( string $nonce ): array {
		$check    = __( 'The Authorization header reaches PHP', 'md3-wp-mcp' );
		$response = self::request(
			'GET',
			rest_url( OAuth::REST_NAMESPACE . self::ROUTE ),
			[ 'headers' => [ 'Authorization' => 'Bearer ' . $nonce ] ]
		);
		if ( is_string( $response ) ) {
			return self::row( $check, false, $response );
		}

		$code = self::status( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$ok   = 200 === $code && is_array( $body ) && true === ( $body['received'] ?? false );

		return self::row(
			$check,
			$ok,
			$ok
				? __( 'Received.', 'md3-wp-mcp' )
				/* translators: %d: HTTP status. */
				: sprintf( __( 'Not received (HTTP %d). The web server strips the Authorization header; pass it to PHP.', 'md3-wp-mcp' ), $code )
		);
	}

	private static function check_authorize_page(): array {
		$check    = __( 'The authorize page is reachable and not cached or redirected', 'md3-wp-mcp' );
		$response = self::request( 'GET', OAuth::authorize_url() );
		if ( is_string( $response ) ) {
			return self::row( $check, false, $response );
		}
		$code = self::status( $response );

		/* translators: %d: HTTP status. */
		return self::row( $check, 400 === $code, sprintf( __( 'HTTP %d without parameters (400 expected).', 'md3-wp-mcp' ), $code ) );
	}

	/**
	 * A loopback request that follows no redirects.
	 *
	 * @param array<string,mixed> $args wp_remote_request() arguments.
	 * @return array<string,mixed>|string The response, or an error message.
	 */
	private static function request( string $method, string $url, array $args = [] ) {
		$response = wp_remote_request(
			$url,
			$args + [
				'method'      => $method,
				'timeout'     => 10,
				'redirection' => 0,
				'sslverify'   => apply_filters( 'https_local_ssl_verify', false ),
			]
		);
		return is_wp_error( $response ) ? $response->get_error_message() : $response;
	}

	/**
	 * @param array<string,mixed> $response Response.
	 */
	private static function status( array $response ): int {
		return (int) wp_remote_retrieve_response_code( $response );
	}

	/**
	 * @param string $fix For the discovery documents: '', 'missing' or 'stale'.
	 * @return array{check: string, ok: bool, detail: string, fix: string}
	 */
	private static function row( string $check, bool $ok, string $detail, string $fix = '' ): array {
		return [
			'check'  => $check,
			'ok'     => $ok,
			'detail' => $detail,
			'fix'    => $fix,
		];
	}
}
