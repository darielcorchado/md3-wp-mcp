<?php
namespace MD3\WPMCP\Uploads;

use MD3\WPMCP\Abilities\Ability;
use MD3\WPMCP\Abilities\Upload_Media;
use MD3\WPMCP\Audit_Log;
use MD3\WPMCP\OAuth\OAuth;
use MD3\WPMCP\OAuth\Tokens;
use MD3\WPMCP\Plugin;
use MD3\WPMCP\Rate_Limiter;
use MD3\WPMCP\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The one-time upload page, `/md3-mcp/upload/<token>`.
 *
 * The person opens the link from the AI chat and picks an image. The link
 * itself is the permission (a capability URL): signing in is not needed,
 * and a browser signed in as someone else (usually an administrator) does
 * not matter, because the upload always runs as the MCP user who made it.
 *
 * GET shows the form. POST checks the nonce, the file and the link, then
 * adds the image to the media library and uses up the link.
 */
final class Upload_Page {

	const PATH         = 'md3-mcp/upload/';
	const NONCE_PREFIX = 'md3_mcp_upload_';
	const RATE_LIMIT   = 30;
	const RATE_WINDOW  = 60;
	const LOG_TOOL     = 'upload-link';

	/**
	 * Handles the page on `parse_request` priority 1, before any theme
	 * output. The path is matched directly, so no rewrite rule (and no
	 * rewrite flush after an update) is needed.
	 */
	public static function register(): void {
		add_action( 'parse_request', [ self::class, 'maybe_handle' ], 1 );
	}

	public static function url( string $token ): string {
		return home_url( '/' . self::PATH . $token );
	}

	/**
	 * The token in a request path (relative to the home URL), or null when
	 * the path is not the upload page.
	 */
	public static function token_from_path( string $path ): ?string {
		return preg_match( '#^' . preg_quote( self::PATH, '#' ) . '([^/]+)/?$#', $path, $m ) ? $m[1] : null;
	}

	/**
	 * Answers the upload page and ends the request.
	 */
	public static function maybe_handle( \WP $wp ): void {
		$token = self::token_from_path( (string) $wp->request );
		if ( null === $token ) {
			return;
		}

		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'get';
		// phpcs:disable WordPress.Security.NonceVerification -- handle() verifies the nonce.
		$post  = wp_unslash( $_POST );
		$files = [];
		if ( isset( $_FILES['file'] ) && is_array( $_FILES['file'] ) ) {
			$file = $_FILES['file']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- checked in handle() and Upload_Media::prepare().
			// Only a file PHP received in this request, never a path from the form.
			if ( UPLOAD_ERR_OK !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) || is_uploaded_file( (string) ( $file['tmp_name'] ?? '' ) ) ) {
				$files['file'] = $file;
			}
		}
		// phpcs:enable

		self::send( self::handle( $method, $token, (array) $post, $files ) );
	}

	/**
	 * Handles one request to the upload page.
	 *
	 * @param array<string,mixed> $post  POST parameters.
	 * @param array<string,mixed> $files Uploaded files, as in $_FILES.
	 * @return \WP_HTTP_Response|\WP_Error The page, or an error whose message
	 *         is safe HTML and whose data has the HTTP status.
	 */
	public static function handle( string $method, string $token, array $post, array $files ) {
		if ( ! OAuth::transport_allowed() ) {
			return self::error( 403, esc_html__( 'Uploading requires HTTPS.', 'md3-wp-mcp' ) );
		}
		if ( ! Rate_Limiter::hit( 'upload_page', Plugin::client_ip_hash(), self::RATE_LIMIT, self::RATE_WINDOW ) ) {
			return self::error( 429, esc_html__( 'Too many requests. Wait a minute and try again.', 'md3-wp-mcp' ) );
		}

		$method = strtoupper( $method );
		if ( 'GET' !== $method && 'POST' !== $method ) {
			return self::error( 405, esc_html__( 'Method not allowed.', 'md3-wp-mcp' ) );
		}

		$row = Upload_Links::find_by_token( $token );
		if ( ! $row ) {
			return self::error( 404, esc_html__( 'This upload link is not valid. Ask the AI for a new one.', 'md3-wp-mcp' ) );
		}

		$refusal = self::refusal( $row );
		if ( $refusal ) {
			return $refusal;
		}

		if ( 'GET' === $method ) {
			return self::page( $row, 200 );
		}

		// The nonce belongs to the browser's own session: check it before
		// acting as the MCP user.
		$nonce = $post['_wpnonce'] ?? '';
		if ( ! is_string( $nonce ) || ! wp_verify_nonce( $nonce, self::NONCE_PREFIX . $row->id ) ) {
			return self::error( 403, esc_html__( 'This page has expired. Open the upload link again.', 'md3-wp-mcp' ) );
		}

		$previous = get_current_user_id();
		wp_set_current_user( (int) $row->user_id );
		try {
			return self::upload( $row, $files['file'] ?? null );
		} finally {
			wp_set_current_user( $previous );
		}
	}

	/**
	 * Why a link can't be used now, or null when it can. Refusals are logged.
	 *
	 * @return \WP_Error|null
	 */
	private static function refusal( object $row ): ?\WP_Error {
		$state = Upload_Links::state( $row );

		// An upload in progress counts as used, until it expires.
		if ( Upload_Links::UPLOADED === $state || ( Upload_Links::UPLOADING === $row->status && Upload_Links::EXPIRED !== $state ) ) {
			self::log( $row, 'denied', 'used' );
			return self::error( 410, esc_html__( 'This upload link has already been used. Ask the AI for a new one if you need to add another image.', 'md3-wp-mcp' ) );
		}
		if ( Upload_Links::EXPIRED === $state ) {
			self::log( $row, 'denied', 'expired' );
			return self::error( 410, esc_html__( 'This upload link has expired. Ask the AI for a new one.', 'md3-wp-mcp' ) );
		}
		// The MCP user changed, or the connection was revoked or ran out.
		if ( Settings::mcp_user_id() !== (int) $row->user_id || ! Tokens::is_live_connection( (string) $row->client_id, (int) $row->user_id ) ) {
			self::log( $row, 'denied', 'connection' );
			return self::error( 403, esc_html__( 'This upload link no longer works because the AI connection that made it was closed.', 'md3-wp-mcp' ) );
		}
		return null;
	}

	/**
	 * POST, as the MCP user: checks the file, then uses up the link.
	 *
	 * @param mixed $file The `file` entry of $_FILES.
	 * @return \WP_HTTP_Response|\WP_Error
	 */
	private static function upload( object $row, $file ) {
		if ( ! current_user_can( 'upload_files' ) ) {
			return self::page( $row, 403, __( 'The MCP user is not allowed to upload files.', 'md3-wp-mcp' ) );
		}

		$error = is_array( $file ) ? (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) : UPLOAD_ERR_NO_FILE;
		if ( UPLOAD_ERR_NO_FILE === $error ) {
			return self::page( $row, 400, __( 'Choose an image to upload.', 'md3-wp-mcp' ) );
		}
		if ( UPLOAD_ERR_INI_SIZE === $error || UPLOAD_ERR_FORM_SIZE === $error ) {
			return self::page( $row, 413, __( 'The file is larger than 10 MB.', 'md3-wp-mcp' ) );
		}
		if ( UPLOAD_ERR_OK !== $error || empty( $file['tmp_name'] ) ) {
			return self::page( $row, 400, __( 'The file could not be received. Try again.', 'md3-wp-mcp' ) );
		}

		$parent = 0;
		if ( (int) $row->attach_to > 0 ) {
			$parent = Upload_Media::resolve_parent( (int) $row->attach_to );
			if ( is_wp_error( $parent ) ) {
				return self::page( $row, (int) ( $parent->get_error_data()['status'] ?? 400 ), $parent->get_error_message() );
			}
			// The post went live after the link was made: attaching to it
			// needs a link made with the publish scope.
			$post = get_post( $parent );
			if ( ! $row->can_publish && $post && in_array( $post->post_status, Ability::LIVE_STATUSES, true ) ) {
				return self::page( $row, 403, __( 'The content this image goes with is now published, and this link was not made with permission to change published content.', 'md3-wp-mcp' ) );
			}
		}

		$name = Upload_Media::prepare( (string) $file['tmp_name'], sanitize_file_name( wp_basename( (string) ( $file['name'] ?? '' ) ) ) );
		if ( is_wp_error( $name ) ) {
			return self::page( $row, (int) ( $name->get_error_data()['status'] ?? 400 ), $name->get_error_message() );
		}

		if ( ! Upload_Links::claim( (string) $row->id ) ) {
			wp_delete_file( (string) $file['tmp_name'] );
			return self::error( 410, esc_html__( 'This upload link has already been used or has expired. Ask the AI for a new one.', 'md3-wp-mcp' ) );
		}

		$media = Upload_Media::insert( (string) $file['tmp_name'], $name, Upload_Links::details( $row ), $parent );
		if ( is_wp_error( $media ) ) {
			Upload_Links::release( (string) $row->id );
			self::log( $row, 'error', $media->get_error_code() );
			return self::page( $row, 500, __( 'The image could not be saved. Try again.', 'md3-wp-mcp' ) );
		}

		Upload_Links::complete( (string) $row->id, (int) $media['id'] );
		self::log( $row, 'ok', 'used', (int) $media['id'] );

		return self::render( [ 'done' => true ], 200 );
	}

	/**
	 * The form, with an optional error message.
	 */
	private static function page( object $row, int $status, string $message = '' ): \WP_HTTP_Response {
		return self::render(
			[
				'done'         => false,
				'error'        => $message,
				'nonce_action' => self::NONCE_PREFIX . $row->id,
				'expires_at'   => strtotime( $row->expires_at . ' UTC' ),
			],
			$status
		);
	}

	/**
	 * @param array<string,mixed> $view View data.
	 */
	private static function render( array $view, int $status ): \WP_HTTP_Response {
		$view += [
			'error'        => '',
			'nonce_action' => '',
			'expires_at'   => 0,
			'site_name'    => get_bloginfo( 'name' ),
			'max_mb'       => (int) ( Upload_Media::MAX_BYTES / MB_IN_BYTES ),
		];

		ob_start();
		( static function ( array $view ): void {
			require MD3_WP_MCP_DIR . 'includes/admin/views/upload.php';
		} )( $view );

		return new \WP_HTTP_Response( (string) ob_get_clean(), $status, self::page_headers() );
	}

	/**
	 * Never cached, indexed or framed; no referrer, so the token can't leak.
	 *
	 * @return array<string,string>
	 */
	private static function page_headers(): array {
		return [
			'Content-Type'            => 'text/html; charset=' . get_option( 'blog_charset' ),
			'Cache-Control'           => 'no-store, no-cache, must-revalidate, max-age=0',
			'Pragma'                  => 'no-cache',
			'X-Frame-Options'         => 'DENY',
			'X-Robots-Tag'            => 'noindex, nofollow',
			'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'",
			'Referrer-Policy'         => 'no-referrer',
			'X-Content-Type-Options'  => 'nosniff',
		];
	}

	private static function log( object $row, string $result, string $message, ?int $attachment_id = null ): void {
		Audit_Log::record_for( (int) $row->user_id, (string) $row->client_id, self::LOG_TOOL, $result, null === $attachment_id ? '' : 'attachment', $attachment_id, $message );
	}

	/**
	 * Sends a handle() result and ends the request.
	 *
	 * @param \WP_HTTP_Response|\WP_Error $result Result.
	 */
	private static function send( $result ): void {
		nocache_headers();

		if ( is_wp_error( $result ) ) {
			header( 'X-Frame-Options: DENY' );
			header( 'X-Robots-Tag: noindex, nofollow' );
			header( 'Referrer-Policy: no-referrer' );
			wp_die(
				$result->get_error_message(), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
				esc_html__( 'MD3 WP MCP', 'md3-wp-mcp' ),
				[ 'response' => (int) ( $result->get_error_data()['status'] ?? 400 ) ]
			);
		}

		status_header( $result->get_status() );
		foreach ( $result->get_headers() as $name => $value ) {
			header( $name . ': ' . $value );
		}
		echo $result->get_data(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rendered by the escaped upload view.
		exit;
	}

	/**
	 * A handle() error: the message is safe HTML.
	 */
	private static function error( int $status, string $html ): \WP_Error {
		return new \WP_Error( 'md3_mcp_upload', $html, [ 'status' => $status ] );
	}
}
