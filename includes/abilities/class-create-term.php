<?php
namespace MD3\WPMCP\Abilities;

use MD3\WPMCP\Auth\Scopes;
use MD3\WPMCP\Content_Sanitizer;

defined( 'ABSPATH' ) || exit;

final class Create_Term extends Ability {

	public function name(): string {
		return 'create-term';
	}

	public function label(): string {
		return __( 'Create term', 'md3-wp-mcp' );
	}

	public function description(): string {
		return __( 'Creates a category, tag or other term in a taxonomy used by the enabled content types.', 'md3-wp-mcp' );
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
				'taxonomy' => [ 'type' => 'string' ],
				'name'     => [ 'type' => 'string', 'minLength' => 1 ],
				'slug'     => [ 'type' => 'string' ],
				'parent'   => [ 'type' => 'integer', 'minimum' => 1 ],
				'language' => [ 'type' => 'string', 'description' => 'Language code (needs Polylang).' ],
			],
			'required'             => [ 'taxonomy', 'name' ],
			'additionalProperties' => false,
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'id'   => [ 'type' => 'integer' ],
				'name' => [ 'type' => 'string' ],
				'slug' => [ 'type' => 'string' ],
			],
		];
	}

	public function execute( array $input ): array|\WP_Error {
		$taxonomy = (string) $input['taxonomy'];
		if ( ! in_array( $taxonomy, Content_Formatter::allowed_taxonomies(), true ) ) {
			return new \WP_Error( 'md3_mcp_invalid_taxonomy', __( 'Unknown taxonomy for the enabled content types.', 'md3-wp-mcp' ), [ 'status' => 400 ] );
		}
		$caps = get_taxonomy( $taxonomy )->cap;
		if ( ! current_user_can( $caps->edit_terms ) && ! current_user_can( $caps->manage_terms ) ) {
			return new \WP_Error( 'md3_mcp_forbidden', __( 'You are not allowed to create terms in this taxonomy.', 'md3-wp-mcp' ), [ 'status' => 403 ] );
		}

		$name = Content_Sanitizer::plain( (string) $input['name'] );
		if ( '' === $name ) {
			return new \WP_Error( 'md3_mcp_bad_name', __( 'The term name is empty.', 'md3-wp-mcp' ), [ 'status' => 400 ] );
		}

		$args = [];
		if ( ! empty( $input['slug'] ) ) {
			$args['slug'] = Content_Sanitizer::slug( (string) $input['slug'] );
		}
		if ( ! empty( $input['parent'] ) ) {
			if ( ! is_taxonomy_hierarchical( $taxonomy ) || ! term_exists( (int) $input['parent'], $taxonomy ) ) {
				return new \WP_Error( 'md3_mcp_bad_term', __( 'parent must be an existing term of a hierarchical taxonomy.', 'md3-wp-mcp' ), [ 'status' => 400 ] );
			}
			$args['parent'] = (int) $input['parent'];
		}

		$result = wp_insert_term( wp_slash( $name ), $taxonomy, wp_slash( $args ) );
		if ( is_wp_error( $result ) ) {
			$result->add_data( [ 'status' => 400 ] );
			return $result;
		}

		$term_id = (int) $result['term_id'];
		if ( ! empty( $input['language'] ) && function_exists( 'pll_set_term_language' ) ) {
			pll_set_term_language( $term_id, (string) $input['language'] );
		}

		$term = get_term( $term_id, $taxonomy );
		return [
			'id'   => $term_id,
			'name' => $term->name,
			'slug' => $term->slug,
		];
	}
}
