<?php
namespace MD3\WPMCP\Abilities;

use MD3\WPMCP\Settings;

defined( 'ABSPATH' ) || exit;

final class List_Content extends Ability {

	const MAX_PER_PAGE = 50;

	public function name(): string {
		return 'list-content';
	}

	public function label(): string {
		return __( 'List content', 'md3-wp-mcp' );
	}

	public function description(): string {
		return __( 'Lists posts, pages and other enabled content, including drafts, with filters and pagination.', 'md3-wp-mcp' );
	}

	public function input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'type'     => [ 'type' => 'string', 'default' => 'post', 'description' => 'Post type.' ],
				'status'   => [
					'type'        => 'string',
					'enum'        => [ 'publish', 'draft', 'pending', 'future', 'private', 'trash', 'any' ],
					'default'     => 'any',
					'description' => 'Status filter. "any" covers every status except trash.',
				],
				'search'   => [ 'type' => 'string' ],
				'language' => [ 'type' => 'string', 'description' => 'Language code (needs Polylang).' ],
				'taxonomy' => [ 'type' => 'string', 'description' => 'Taxonomy to filter by, together with term_id.' ],
				'term_id'  => [ 'type' => 'integer', 'minimum' => 1 ],
				'author'   => [ 'type' => 'integer', 'minimum' => 1 ],
				'after'    => [ 'type' => 'string', 'pattern' => '^\d{4}-\d{2}-\d{2}$', 'description' => 'Y-m-d' ],
				'before'   => [ 'type' => 'string', 'pattern' => '^\d{4}-\d{2}-\d{2}$', 'description' => 'Y-m-d' ],
				'page'     => [ 'type' => 'integer', 'minimum' => 1, 'default' => 1 ],
				'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PER_PAGE, 'default' => 20 ],
			],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'items' => [ 'type' => 'array', 'items' => [ 'type' => 'object' ] ],
				'total' => [ 'type' => 'integer' ],
				'pages' => [ 'type' => 'integer' ],
			],
		];
	}

	public function execute( array $input ): array|\WP_Error {
		$type = (string) ( $input['type'] ?? 'post' );
		if ( ! Settings::is_allowed_post_type( $type ) ) {
			return new \WP_Error( 'md3_mcp_type_not_allowed', __( 'This content type is not enabled for MCP.', 'md3-wp-mcp' ), [ 'status' => 403 ] );
		}

		$status   = (string) ( $input['status'] ?? 'any' );
		$per_page = max( 1, min( self::MAX_PER_PAGE, (int) ( $input['per_page'] ?? 20 ) ) );

		$args = [
			'post_type'           => $type,
			'post_status'         => 'any' === $status ? [ 'publish', 'draft', 'pending', 'future', 'private' ] : $status,
			'posts_per_page'      => $per_page,
			'paged'               => max( 1, (int) ( $input['page'] ?? 1 ) ),
			'orderby'             => 'date',
			'order'               => 'DESC',
			'ignore_sticky_posts' => true,
			'no_found_rows'       => false,
			'suppress_filters'    => false,
		];

		if ( ! empty( $input['search'] ) ) {
			$args['s'] = (string) $input['search'];
		}
		if ( ! empty( $input['author'] ) ) {
			$args['author'] = (int) $input['author'];
		}
		if ( ! empty( $input['language'] ) && function_exists( 'pll_get_post_language' ) ) {
			$args['lang'] = (string) $input['language'];
		}

		if ( ! empty( $input['taxonomy'] ) ) {
			$taxonomy = (string) $input['taxonomy'];
			if ( ! in_array( $taxonomy, Content_Formatter::allowed_taxonomies(), true ) || empty( $input['term_id'] ) ) {
				return new \WP_Error( 'md3_mcp_invalid_taxonomy', __( 'Pass a taxonomy of an enabled content type together with term_id.', 'md3-wp-mcp' ), [ 'status' => 400 ] );
			}
			$args['tax_query'] = [
				[
					'taxonomy'         => $taxonomy,
					'field'            => 'term_id',
					'terms'            => [ (int) $input['term_id'] ],
					'include_children' => false,
				],
			];
		}

		$date_query = [];
		if ( ! empty( $input['after'] ) ) {
			$date_query['after'] = (string) $input['after'];
		}
		if ( ! empty( $input['before'] ) ) {
			$date_query['before'] = (string) $input['before'];
		}
		if ( $date_query ) {
			$date_query['inclusive'] = true;
			$args['date_query']      = [ $date_query ];
		}

		$query = new \WP_Query( $args );

		return [
			'items' => array_map( [ Content_Formatter::class, 'summary' ], $query->posts ),
			'total' => (int) $query->found_posts,
			'pages' => (int) $query->max_num_pages,
		];
	}
}
