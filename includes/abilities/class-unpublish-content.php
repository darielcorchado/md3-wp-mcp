<?php
namespace MD3\WPMCP\Abilities;

use MD3\WPMCP\Auth\Scopes;

defined( 'ABSPATH' ) || exit;

final class Unpublish_Content extends Ability {

	public function name(): string {
		return 'unpublish-content';
	}

	public function label(): string {
		return __( 'Unpublish content', 'md3-wp-mcp' );
	}

	public function description(): string {
		return __( 'Moves published or scheduled content back to draft.', 'md3-wp-mcp' );
	}

	public function scope(): string {
		return Scopes::PUBLISH;
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
			'properties'           => [ 'id' => [ 'type' => 'integer', 'minimum' => 1 ] ],
			'required'             => [ 'id' ],
			'additionalProperties' => false,
		];
	}

	public function output_schema(): array {
		return [ 'type' => 'object' ];
	}

	public function execute( array $input ): array|\WP_Error {
		$post = $this->load_post( (int) $input['id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$type = get_post_type_object( $post->post_type );
		if ( ! $type || ! current_user_can( 'edit_post', $post->ID ) || ! current_user_can( $type->cap->publish_posts ) ) {
			return new \WP_Error( 'md3_mcp_forbidden', __( 'You are not allowed to unpublish this content.', 'md3-wp-mcp' ), [ 'status' => 403 ] );
		}
		if ( 'trash' === $post->post_status ) {
			return new \WP_Error( 'md3_mcp_trashed', __( 'This content is in the trash.', 'md3-wp-mcp' ), [ 'status' => 409 ] );
		}

		$result = wp_update_post( [ 'ID' => $post->ID, 'post_status' => 'draft' ], true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return Content_Formatter::summary( get_post( $post->ID ) );
	}
}
