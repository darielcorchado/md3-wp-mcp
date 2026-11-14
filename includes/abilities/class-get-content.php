<?php
namespace MD3\WPMCP\Abilities;

defined( 'ABSPATH' ) || exit;

final class Get_Content extends Ability {

	public function name(): string {
		return 'get-content';
	}

	public function label(): string {
		return __( 'Get content', 'md3-wp-mcp' );
	}

	public function description(): string {
		return __( 'Returns one post or page in full: raw block content, terms, featured image, SEO fields and translations.', 'md3-wp-mcp' );
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'properties'           => [
				'id' => [ 'type' => 'integer', 'minimum' => 1 ],
			],
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
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return new \WP_Error( 'md3_mcp_forbidden', __( 'You are not allowed to access this content.', 'md3-wp-mcp' ), [ 'status' => 403 ] );
		}
		return Content_Formatter::full( $post );
	}
}
