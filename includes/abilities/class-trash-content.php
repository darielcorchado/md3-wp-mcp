<?php
namespace MD3\WPMCP\Abilities;

use MD3\WPMCP\Auth\Scopes;

defined( 'ABSPATH' ) || exit;

final class Trash_Content extends Ability {

	public function name(): string {
		return 'trash-content';
	}

	public function label(): string {
		return __( 'Trash content', 'md3-wp-mcp' );
	}

	public function description(): string {
		return __( 'Moves a post or page to the trash. It can be restored; nothing is permanently deleted.', 'md3-wp-mcp' );
	}

	public function scope(): string {
		return Scopes::DELETE;
	}

	public function annotations(): array {
		return [
			'readonly'    => false,
			'destructive' => true,
			'idempotent'  => false,
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
		if ( ! current_user_can( 'delete_post', $post->ID ) ) {
			return new \WP_Error( 'md3_mcp_forbidden', __( 'You are not allowed to trash this content.', 'md3-wp-mcp' ), [ 'status' => 403 ] );
		}
		if ( 'trash' === $post->post_status ) {
			return new \WP_Error( 'md3_mcp_trashed', __( 'This content is already in the trash.', 'md3-wp-mcp' ), [ 'status' => 409 ] );
		}

		// With the trash disabled core's wp_trash_post() deletes permanently.
		if ( ! apply_filters( 'md3_mcp_trash_enabled', defined( 'EMPTY_TRASH_DAYS' ) ? EMPTY_TRASH_DAYS > 0 : true ) ) {
			return new \WP_Error( 'md3_mcp_trash_disabled', __( 'The trash is disabled on this site, so content cannot be trashed safely.', 'md3-wp-mcp' ), [ 'status' => 409 ] );
		}

		if ( ! wp_trash_post( $post->ID ) ) {
			return new \WP_Error( 'md3_mcp_trash_failed', __( 'The content could not be moved to the trash.', 'md3-wp-mcp' ), [ 'status' => 500 ] );
		}
		return Content_Formatter::summary( get_post( $post->ID ) );
	}
}
