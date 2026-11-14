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
				'attach_to' => [ 'type' => 'integer', 'minimum' => 1, 'description' => 'ID of a post or page to attach the image to.' ],
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
			$post = $this->load_post( (int) $input['attach_to'] );
			if ( is_wp_error( $post ) ) {
				return $post;
			}
			if ( ! current_user_can( 'edit_post', $post->ID ) ) {
				return new \WP_Error( 'md3_mcp_forbidden', __( 'You are not allowed to edit this content.', 'md3-wp-mcp' ), [ 'status' => 403 ] );
			}
			$parent = $post->ID;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp = $has_url ? $this->download( (string) $input['url'] ) : $this->decode( (string) $input['base64'] );
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}

		$name = sanitize_file_name( wp_basename( (string) ( $input['filename'] ?? wp_parse_url( (string) $input['url'], PHP_URL_PATH ) ) ) );

		$size = (int) filesize( $tmp );
		if ( $size > self::MAX_BYTES ) {
			wp_delete_file( $tmp );
			return self::error( 'md3_mcp_too_large', __( 'The file is larger than 10 MB.', 'md3-wp-mcp' ), 413 );
		}

		$check = wp_check_filetype_and_ext( $tmp, $name, self::MIMES );
		if ( empty( $check['type'] ) || ! in_array( $check['type'], self::MIMES, true ) ) {
			wp_delete_file( $tmp );
			return self::error( 'md3_mcp_bad_type', __( 'Only JPEG, PNG, WebP and GIF images are accepted.', 'md3-wp-mcp' ), 415 );
		}
		if ( ! empty( $check['proper_filename'] ) ) {
			$name = $check['proper_filename'];
		}

		$post_data = [];
		if ( isset( $input['title'] ) ) {
			$post_data['post_title'] = Content_Sanitizer::plain( (string) $input['title'] );
		}
		if ( isset( $input['caption'] ) ) {
			$post_data['post_excerpt'] = Content_Sanitizer::plain( (string) $input['caption'] );
		}

		$restrict = static fn(): array => self::MIMES;
		add_filter( 'upload_mimes', $restrict, 99 );
		$id = media_handle_sideload( [ 'name' => $name, 'tmp_name' => $tmp ], $parent, null, $post_data );
		remove_filter( 'upload_mimes', $restrict, 99 );

		if ( is_wp_error( $id ) ) {
			wp_delete_file( $tmp );
			return $id;
		}

		$alt = isset( $input['alt'] ) ? Content_Sanitizer::plain( (string) $input['alt'] ) : '';
		if ( '' !== $alt ) {
			update_post_meta( $id, '_wp_attachment_image_alt', wp_slash( $alt ) );
		}

		$meta = wp_get_attachment_metadata( $id );
		return [
			'id'     => (int) $id,
			'url'    => (string) wp_get_attachment_url( $id ),
			'mime'   => (string) get_post_mime_type( $id ),
			'width'  => (int) ( $meta['width'] ?? 0 ),
			'height' => (int) ( $meta['height'] ?? 0 ),
			'alt'    => $alt,
		];
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
