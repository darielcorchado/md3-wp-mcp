<?php
namespace MD3\WPMCP\Abilities;

use MD3\WPMCP\Auth\Scopes;
use MD3\WPMCP\Content_Sanitizer;

defined( 'ABSPATH' ) || exit;

/**
 * Changes the alt text, title, caption or description of an image in the
 * media library. Never the file itself.
 */
final class Update_Media extends Ability {

	/** Input field => where it is stored (post field, or the alt meta). */
	const FIELDS = [
		'alt'         => '_wp_attachment_image_alt',
		'title'       => 'post_title',
		'caption'     => 'post_excerpt',
		'description' => 'post_content',
	];

	public function name(): string {
		return 'update-media';
	}

	public function label(): string {
		return __( 'Update media', 'md3-wp-mcp' );
	}

	public function description(): string {
		return __( 'Changes the alt text, title, caption or description of an image in the media library. Only the fields sent change; an empty string clears one. Images already placed in post content keep their own alt text and caption: change those with update-content. Changing an image used by live content needs the publish scope.', 'md3-wp-mcp' );
	}

	public function scope(): string {
		return Scopes::WRITE;
	}

	public function annotations(): array {
		return [
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => true,
		];
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'properties'           => [
				'id'          => [ 'type' => 'integer', 'minimum' => 1, 'description' => 'ID of an image in the media library, as list-media returns it.' ],
				'alt'         => [ 'type' => 'string', 'description' => 'Alt text: what the image shows, for people who can\'t see it.' ],
				'title'       => [ 'type' => 'string' ],
				'caption'     => [ 'type' => 'string' ],
				'description' => [ 'type' => 'string' ],
			],
			'required'             => [ 'id' ],
			'additionalProperties' => false,
		];
	}

	public function output_schema(): array {
		return [ 'type' => 'object' ];
	}

	/**
	 * An image shown on live content (its featured image, or attached to
	 * it) changes what visitors see, so it needs the publish scope.
	 */
	protected function object_permission( array $input ): bool|\WP_Error {
		$id = (int) ( $input['id'] ?? 0 );
		return $id > 0 && self::used_by_live_content( $id ) ? $this->require_scope( Scopes::PUBLISH ) : true;
	}

	/**
	 * Whether an attachment is the featured image of, or attached to,
	 * published, scheduled or private content.
	 */
	public static function used_by_live_content( int $id ): bool {
		global $wpdb;

		$parent = (int) wp_get_post_parent_id( $id );
		if ( $parent > 0 && in_array( get_post_status( $parent ), self::LIVE_STATUSES, true ) ) {
			return true;
		}

		$statuses = implode( ', ', array_fill( 0, count( self::LIVE_STATUSES ), '%s' ) );
		return null !== $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders only.
				"SELECT 1 FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id
				WHERE m.meta_key = '_thumbnail_id' AND m.meta_value = %s AND p.post_status IN ({$statuses}) LIMIT 1",
				array_merge( [ (string) $id ], self::LIVE_STATUSES )
			)
		);
	}

	public function execute( array $input ): array|\WP_Error {
		$id = (int) $input['id'];
		if ( 'attachment' !== get_post_type( $id ) || ! wp_attachment_is_image( $id ) ) {
			return new \WP_Error( 'md3_mcp_not_found', __( 'Image not found in the media library.', 'md3-wp-mcp' ), [ 'status' => 404 ] );
		}
		if ( ! current_user_can( 'edit_post', $id ) ) {
			return new \WP_Error( 'md3_mcp_forbidden', __( 'You are not allowed to edit this image.', 'md3-wp-mcp' ), [ 'status' => 403 ] );
		}

		$changes = [];
		foreach ( self::FIELDS as $field => $target ) {
			if ( isset( $input[ $field ] ) ) {
				$changes[ $target ] = Content_Sanitizer::plain( (string) $input[ $field ] );
			}
		}
		if ( ! $changes ) {
			return new \WP_Error( 'md3_mcp_no_changes', __( 'Send at least one of alt, title, caption or description.', 'md3-wp-mcp' ), [ 'status' => 400 ] );
		}

		if ( array_key_exists( self::FIELDS['alt'], $changes ) ) {
			update_post_meta( $id, self::FIELDS['alt'], wp_slash( $changes[ self::FIELDS['alt'] ] ) );
			unset( $changes[ self::FIELDS['alt'] ] );
		}
		if ( $changes ) {
			$updated = wp_update_post( wp_slash( [ 'ID' => $id ] + $changes ), true );
			if ( is_wp_error( $updated ) ) {
				return $updated;
			}
		}

		return Content_Formatter::media( $id );
	}
}
