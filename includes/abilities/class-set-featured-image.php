<?php
namespace MD3\WPMCP\Abilities;

use MD3\WPMCP\Auth\Request_Context;
use MD3\WPMCP\Auth\Scopes;

defined( 'ABSPATH' ) || exit;

final class Set_Featured_Image extends Ability {

	public function name(): string {
		return 'set-featured-image';
	}

	public function label(): string {
		return __( 'Set featured image', 'md3-wp-mcp' );
	}

	public function description(): string {
		return __( 'Sets the featured image of a post or page to an image from the media library. Changing live content needs the publish scope.', 'md3-wp-mcp' );
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
				'id'       => [ 'type' => 'integer', 'minimum' => 1 ],
				'media_id' => [ 'type' => 'integer', 'minimum' => 1, 'description' => 'ID of an image in the media library.' ],
			],
			'required'             => [ 'id', 'media_id' ],
			'additionalProperties' => false,
		];
	}

	public function output_schema(): array {
		return [ 'type' => 'object' ];
	}

	/**
	 * Same rule as update-content: live content needs the publish scope.
	 */
	protected function object_permission( array $input ): bool|\WP_Error {
		$post = isset( $input['id'] ) ? get_post( (int) $input['id'] ) : null;
		if ( $post && in_array( $post->post_status, [ 'publish', 'future', 'private' ], true ) && ! Request_Context::has_scope( Scopes::PUBLISH ) ) {
			return new \WP_Error(
				'md3_mcp_insufficient_scope',
				__( 'Editing published content needs the content:publish scope.', 'md3-wp-mcp' ),
				[ 'status' => 403 ]
			);
		}
		return true;
	}

	public function execute( array $input ): array|\WP_Error {
		$post = $this->load_post( (int) $input['id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return new \WP_Error( 'md3_mcp_forbidden', __( 'You are not allowed to edit this content.', 'md3-wp-mcp' ), [ 'status' => 403 ] );
		}
		$media_id = (int) ( $input['media_id'] ?? 0 );
		if ( ! Content_Writer::is_image_attachment( $media_id ) ) {
			return new \WP_Error( 'md3_mcp_bad_media', __( 'media_id must be the ID of an image in the media library.', 'md3-wp-mcp' ), [ 'status' => 400 ] );
		}

		set_post_thumbnail( $post->ID, $media_id );
		return Content_Formatter::summary( get_post( $post->ID ) );
	}
}
