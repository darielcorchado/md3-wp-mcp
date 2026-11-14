<?php
namespace MD3\WPMCP\Abilities;

use MD3\WPMCP\Auth\Scopes;
use MD3\WPMCP\Settings;

defined( 'ABSPATH' ) || exit;

final class Create_Content extends Ability {

	public function name(): string {
		return 'create-content';
	}

	public function label(): string {
		return __( 'Create content', 'md3-wp-mcp' );
	}

	public function description(): string {
		return __( 'Creates a draft or pending post or page. Publishing is not available.', 'md3-wp-mcp' );
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
				'type'   => [ 'type' => 'string', 'default' => 'post', 'description' => 'Post type.' ],
				'status' => [ 'type' => 'string', 'enum' => Content_Writer::STATUSES, 'default' => 'draft' ],
			] + Content_Writer::schema_properties(),
			'additionalProperties' => false,
		];
	}

	public function output_schema(): array {
		return [ 'type' => 'object' ];
	}

	public function execute( array $input ): array|\WP_Error {
		$input += [ 'type' => 'post', 'status' => 'draft' ];

		if ( ! Settings::is_allowed_post_type( (string) $input['type'] ) || ! post_type_exists( (string) $input['type'] ) ) {
			return new \WP_Error( 'md3_mcp_type_not_allowed', __( 'This content type is not enabled for MCP.', 'md3-wp-mcp' ), [ 'status' => 403 ] );
		}
		$caps = get_post_type_object( (string) $input['type'] )->cap;
		if ( ! current_user_can( $caps->edit_posts ) ) {
			return new \WP_Error( 'md3_mcp_forbidden', __( 'You are not allowed to create this content type.', 'md3-wp-mcp' ), [ 'status' => 403 ] );
		}

		$id = Content_Writer::save( $input, null );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		return Content_Formatter::full( get_post( $id ) );
	}
}
