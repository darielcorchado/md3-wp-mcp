<?php
namespace MD3\WPMCP\Abilities;

use MD3\WPMCP\Auth\Scopes;
use MD3\WPMCP\Content_Sanitizer;

defined( 'ABSPATH' ) || exit;

final class Upload_Media extends Ability {

	const MAX_BYTES = 10485760;

	const MIMES = [
		'jpg|jpeg|jpe' => 'image/jpeg',
		'png'          => 'image/png',
		'webp'         => 'image/webp',
		'gif'          => 'image/gif',
	];

	public function name(): string {
		return 'upload-media';
	}

	public function label(): string {
		return __( 'Upload media', 'md3-wp-mcp' );
	}

	public function description(): string {
		return __( 'Adds an image (JPEG, PNG, WebP or GIF, up to 10 MB) to the media library, from a public URL or base64 data. Give exactly one of url or base64; base64 also needs filename.', 'md3-wp-mcp' );
	}

	public function scope(): string {
		return Scopes::WRITE;
	}

	/**
	 * Attaching to live content can change what visitors see (a gallery
	 * without IDs lists the attached images), so it needs the publish scope.
	 */
	protected function object_permission( array $input ): bool|\WP_Error {
		return $this->require_publish_if_live( (int) ( $input['attach_to'] ?? 0 ) );
	}

	public function annotations(): array {
		return [
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => false,
		];
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'properties'           => [
				'url'       => [ 'type' => 'string', 'description' => 'Public http(s) URL of the image. Local and private hosts are refused.' ],
				'base64'    => [ 'type' => 'string', 'description' => 'Base64-encoded file contents. Needs filename.' ],
				'filename'  => [ 'type' => 'string', 'description' => 'File name with extension, such as hero.png. Required with base64.' ],
				'alt'       => [ 'type' => 'string' ],
				'title'     => [ 'type' => 'string' ],
				'caption'   => [ 'type' => 'string' ],
				'attach_to' => [ 'type' => 'integer', 'minimum' => 1, 'description' => 'ID of a post or page to attach the image to. Attaching to live content needs the publish scope.' ],
			],
			'additionalProperties' => false,
		];
	}

	public function output_schema(): array {
		return [ 'type' => 'object' ];
	}

	public function execute( array $input ): array|\WP_Error {
		if ( ! current_user_can( 'upload_files' ) ) {
			return new \WP_Error( 'md3_mcp_forbidden', __( 'You are not allowed to upload files.', 'md3-wp-mcp' ), [ 'status' => 403 ] );
		}

		$has_url    = isset( $input['url'] ) && '' !== $input['url'];
		$has_base64 = isset( $input['base64'] ) && '' !== $input['base64'];
		if ( $has_url === $has_base64 ) {
			return self::error( 'md3_mcp_bad_source', __( 'Give exactly one of url or base64.', 'md3-wp-mcp' ) );
		}
		if ( $has_base64 && empty( $input['filename'] ) ) {
			return self::error( 'md3_mcp_bad_source', __( 'filename is required with base64.', 'md3-wp-mcp' ) );
		}

		$parent = 0;
		if ( ! empty( $input['attach_to'] ) ) {
			$parent = self::resolve_parent( (int) $input['attach_to'] );
			if ( is_wp_error( $parent ) ) {
				return $parent;
			}
		}

		self::load_admin_includes();

		$tmp = $has_url ? $this->download( (string) $input['url'] ) : $this->decode( (string) $input['base64'] );
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}

		$name = sanitize_file_name( wp_basename( (string) ( $input['filename'] ?? wp_parse_url( (string) $input['url'], PHP_URL_PATH ) ) ) );
		$name = self::prepare( $tmp, $name );
		if ( is_wp_error( $name ) ) {
			return $name;
		}

		return self::insert( $tmp, $name, $input, $parent );
	}

	/**
	 * The post an upload is attached to: one this tool may work with and the
	 * current user can edit.
	 *
	 * @return int|\WP_Error Post ID.
	 */
	public static function resolve_parent( int $id ): int|\WP_Error {
		$post = self::load_post( $id );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return new \WP_Error( 'md3_mcp_forbidden', __( 'You are not allowed to edit this content.', 'md3-wp-mcp' ), [ 'status' => 403 ] );
		}
		return $post->ID;
	}

	/**
	 * Checks a temp file before it is added: the size, and that its real
	 * content is a JPEG, PNG, WebP or GIF image. The temp file is deleted when
	 * it is refused.
	 *
	 * @param string $tmp  Temp file path.
	 * @param string $name Sanitized file name; may lack an extension.
	 * @return string|\WP_Error The file name to store it under. Error data
	 *         has the HTTP status (413 too large, 415 wrong type).
	 */
	public static function prepare( string $tmp, string $name ): string|\WP_Error {
		self::load_admin_includes();

		$size = (int) filesize( $tmp );
		if ( $size > self::MAX_BYTES ) {
			wp_delete_file( $tmp );
			return self::error( 'md3_mcp_too_large', __( 'The file is larger than 10 MB.', 'md3-wp-mcp' ), 413 );
		}

		// A URL without an extension (a CDN or resizer link): name it from its content.
		if ( empty( wp_check_filetype( $name, self::MIMES )['type'] ) ) {
			$mime = wp_get_image_mime( $tmp );
			if ( in_array( $mime, self::MIMES, true ) ) {
				$extensions = [
					'image/jpeg' => 'jpg',
					'image/png'  => 'png',
					'image/webp' => 'webp',
					'image/gif'  => 'gif',
				];
				$name = ( pathinfo( $name, PATHINFO_FILENAME ) ?: 'image' ) . '.' . $extensions[ $mime ];
			}
		}

		$check = wp_check_filetype_and_ext( $tmp, $name, self::MIMES );
		if ( empty( $check['type'] ) || ! in_array( $check['type'], self::MIMES, true ) ) {
			wp_delete_file( $tmp );
			return self::error( 'md3_mcp_bad_type', __( 'Only JPEG, PNG, WebP and GIF images are accepted.', 'md3-wp-mcp' ), 415 );
		}

		// The sniffing above only reads the header, so "GIF89a<?php …" passes
		// it. The image must also decode. Without an editor for the type
		// (no GD or Imagick support), the header check stands alone.
		$editor = wp_get_image_editor( $tmp, [ 'mime_type' => $check['type'] ] );
		if ( is_wp_error( $editor ) && 'image_no_editor' !== $editor->get_error_code() ) {
			wp_delete_file( $tmp );
			return self::error( 'md3_mcp_bad_type', __( 'Only JPEG, PNG, WebP and GIF images are accepted.', 'md3-wp-mcp' ), 415 );
		}

		return ! empty( $check['proper_filename'] ) ? $check['proper_filename'] : $name;
	}

	/**
	 * Adds a checked temp file (see prepare()) to the media library as the
	 * current user.
	 *
	 * @param string              $tmp     Temp file path.
	 * @param string              $name    File name from prepare().
	 * @param array<string,mixed> $details Optional `alt`, `title` and `caption`.
	 * @param int                 $parent  Post to attach to, or 0.
	 * @return array<string,mixed>|\WP_Error Content_Formatter::media().
	 */
	public static function insert( string $tmp, string $name, array $details, int $parent ): array|\WP_Error {
		self::load_admin_includes();

		$post_data = [];
		if ( isset( $details['title'] ) ) {
			$post_data['post_title'] = Content_Sanitizer::plain( (string) $details['title'] );
		}
		if ( isset( $details['caption'] ) ) {
			$post_data['post_excerpt'] = Content_Sanitizer::plain( (string) $details['caption'] );
		}

		$restrict = static fn(): array => self::MIMES;
		add_filter( 'upload_mimes', $restrict, 99 );
		$id = media_handle_sideload( [ 'name' => $name, 'tmp_name' => $tmp ], $parent, null, $post_data );
		remove_filter( 'upload_mimes', $restrict, 99 );

		if ( is_wp_error( $id ) ) {
			wp_delete_file( $tmp );
			return $id;
		}

		$alt = isset( $details['alt'] ) ? Content_Sanitizer::plain( (string) $details['alt'] ) : '';
		if ( '' !== $alt ) {
			update_post_meta( $id, '_wp_attachment_image_alt', wp_slash( $alt ) );
		}

		return Content_Formatter::media( (int) $id );
	}

	private static function load_admin_includes(): void {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
	}

	/**
	 * Downloads a URL to a temp file. download_url() uses wp_safe_remote_get,
	 * which refuses local and private hosts.
	 *
	 * @return string|\WP_Error Temp file path.
	 */
	private function download( string $url ): string|\WP_Error {
		if ( ! preg_match( '#^https?://#i', $url ) ) {
			return self::error( 'md3_mcp_bad_source', __( 'url must start with http:// or https://.', 'md3-wp-mcp' ) );
		}

		$limit = static function ( array $args ): array {
			$args['limit_response_size'] = self::MAX_BYTES + 1;
			return $args;
		};
		add_filter( 'http_request_args', $limit, 99 );
		$tmp = download_url( $url, 30 );
		remove_filter( 'http_request_args', $limit, 99 );

		if ( is_wp_error( $tmp ) ) {
			// download_url() removes its own temp file on failure.
			return new \WP_Error( 'md3_mcp_download_failed', $tmp->get_error_message(), [ 'status' => 400 ] );
		}
		return $tmp;
	}

	/**
	 * Decodes base64 into a temp file.
	 *
	 * @return string|\WP_Error Temp file path.
	 */
	private function decode( string $base64 ): string|\WP_Error {
		// Refuse before decoding what could not fit in the limit.
		if ( strlen( $base64 ) > (int) ceil( self::MAX_BYTES * 4 / 3 ) + 4 ) {
			return self::error( 'md3_mcp_too_large', __( 'The file is larger than 10 MB.', 'md3-wp-mcp' ), 413 );
		}
		$data = base64_decode( $base64, true );
		if ( false === $data || '' === $data ) {
			return self::error( 'md3_mcp_bad_source', __( 'base64 is not valid base64 data.', 'md3-wp-mcp' ) );
		}
		if ( strlen( $data ) > self::MAX_BYTES ) {
			return self::error( 'md3_mcp_too_large', __( 'The file is larger than 10 MB.', 'md3-wp-mcp' ), 413 );
		}

		$tmp = wp_tempnam( 'md3-mcp-upload' );
		if ( ! $tmp ) {
			return self::error( 'md3_mcp_upload_failed', __( 'Could not create a temporary file.', 'md3-wp-mcp' ), 500 );
		}
		if ( false === file_put_contents( $tmp, $data ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			wp_delete_file( $tmp );
			return self::error( 'md3_mcp_upload_failed', __( 'Could not write the temporary file.', 'md3-wp-mcp' ), 500 );
		}
		return $tmp;
	}

	private static function error( string $code, string $message, int $status = 400 ): \WP_Error {
		return new \WP_Error( $code, $message, [ 'status' => $status ] );
	}
}
