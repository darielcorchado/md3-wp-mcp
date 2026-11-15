<?php
namespace MD3\WPMCP\Abilities;

use MD3\WPMCP\Auth\Scopes;

defined( 'ABSPATH' ) || exit;

final class Update_Content extends Ability {

	public function name(): string {
		return 'update-content';
	}

	public function label(): string {
		return __( 'Update content', 'md3-wp-mcp' );
	}

	public function description(): string {
		return __( 'Updates the given fields of a post or page. Changing live content needs the publish scope.', 'md3-wp-mcp' );
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
				'id'     => [ 'type' => 'integer', 'minimum' => 1 ],
				'status' => [ 'type' => 'string', 'enum' => Content_Writer::STATUSES ],
			] + Content_Writer::schema_properties(),
			'required'             => [ 'id' ],
			'additionalProperties' => false,
		];
	}

	public function output_schema(): array {
		return [ 'type' => 'object' ];
	}

	/**
	 * Editing live (published, scheduled or private) content needs the publish scope.
	 */
	protected function object_permission( array $input ): bool|\WP_Error {
		$post = isset( $input['id'] ) ? get_post( (int) $input['id'] ) : null;
		if ( $post && in_array( $post->post_status, [ 'publish', 'future', 'private' ], true ) ) {
			return $this->require_scope( Scopes::PUBLISH );
		}
		return true;
	}

	public function execute( array $input ): array|\WP_Error {
		$post = $this->load_post( (int) $input['id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		// Restoring needs restore-content (and its scope), not a status change.
		if ( 'trash' === $post->post_status ) {
			return new \WP_Error( 'md3_mcp_trashed', __( 'Restore this content before editing it.', 'md3-wp-mcp' ), [ 'status' => 409 ] );
		}
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return new \WP_Error( 'md3_mcp_forbidden', __( 'You are not allowed to edit this content.', 'md3-wp-mcp' ), [ 'status' => 403 ] );
		}

		unset( $input['id'] );
		$id = Content_Writer::save( $input, $post );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		return Content_Formatter::full( get_post( $id ) );
	}
}
