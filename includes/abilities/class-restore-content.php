<?php
namespace MD3\WPMCP\Abilities;

use MD3\WPMCP\Auth\Scopes;

defined( 'ABSPATH' ) || exit;

final class Restore_Content extends Ability {

	public function name(): string {
		return 'restore-content';
	}

	public function label(): string {
		return __( 'Restore content', 'md3-wp-mcp' );
	}

	public function description(): string {
		return __( 'Restores trashed content. It comes back as a draft.', 'md3-wp-mcp' );
	}

	public function scope(): string {
		return Scopes::DELETE;
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
			return new \WP_Error( 'md3_mcp_forbidden', __( 'You are not allowed to restore this content.', 'md3-wp-mcp' ), [ 'status' => 403 ] );
		}
		if ( 'trash' !== $post->post_status ) {
			return new \WP_Error( 'md3_mcp_not_trashed', __( 'This content is not in the trash.', 'md3-wp-mcp' ), [ 'status' => 409 ] );
		}

		if ( ! wp_untrash_post( $post->ID ) ) {
			return new \WP_Error( 'md3_mcp_restore_failed', __( 'The content could not be restored.', 'md3-wp-mcp' ), [ 'status' => 500 ] );
		}
		return Content_Formatter::summary( get_post( $post->ID ) );
	}
}
