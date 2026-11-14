<?php
namespace MD3\WPMCP\Abilities;

defined( 'ABSPATH' ) || exit;

final class List_Terms extends Ability {

	public function name(): string {
		return 'list-terms';
	}

	public function label(): string {
		return __( 'List terms', 'md3-wp-mcp' );
	}

	public function description(): string {
		return __( 'Lists the terms (categories, tags, ...) of a taxonomy used by the enabled content types.', 'md3-wp-mcp' );
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'properties'           => [
				'taxonomy' => [ 'type' => 'string' ],
				'search'   => [ 'type' => 'string' ],
				'language' => [ 'type' => 'string', 'description' => 'Language code (needs Polylang).' ],
			],
			'required'             => [ 'taxonomy' ],
			'additionalProperties' => false,
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'terms' => [ 'type' => 'array', 'items' => [ 'type' => 'object' ] ],
			],
		];
	}

	public function execute( array $input ): array|\WP_Error {
		$taxonomy = (string) $input['taxonomy'];
		if ( ! in_array( $taxonomy, Content_Formatter::allowed_taxonomies(), true ) ) {
			return new \WP_Error( 'md3_mcp_invalid_taxonomy', __( 'Unknown taxonomy for the enabled content types.', 'md3-wp-mcp' ), [ 'status' => 400 ] );
		}

		$args = [
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
			'number'     => 0,
		];
		if ( ! empty( $input['search'] ) ) {
			$args['search'] = (string) $input['search'];
		}
		if ( ! empty( $input['language'] ) && function_exists( 'pll_get_post_language' ) ) {
			$args['lang'] = (string) $input['language'];
		}

		$terms = get_terms( $args );
		if ( is_wp_error( $terms ) ) {
			return $terms;
		}

		return [
			'terms' => array_map(
				static fn( \WP_Term $t ): array => [
					'id'     => (int) $t->term_id,
					'name'   => $t->name,
					'slug'   => $t->slug,
					'parent' => (int) $t->parent,
					'count'  => (int) $t->count,
				],
				$terms
			),
		];
	}
}
