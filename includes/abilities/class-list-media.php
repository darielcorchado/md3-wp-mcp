<?php
namespace MD3\WPMCP\Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * Finds images in the media library, so the AI can reuse them.
 */
final class List_Media extends Ability {

	const MAX_PER_PAGE = 50;

	public function name(): string {
		return 'list-media';
	}

	public function label(): string {
		return __( 'List media', 'md3-wp-mcp' );
	}

	public function description(): string {
		return __( 'Lists images in the media library, newest first, searching the title, caption, description and file name. Use it to reuse an existing image (for example with set-featured-image) instead of uploading it again.', 'md3-wp-mcp' );
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'properties'           => [
				'search'   => [ 'type' => 'string' ],
				'page'     => [ 'type' => 'integer', 'minimum' => 1, 'default' => 1 ],
				'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PER_PAGE, 'default' => 20 ],
			],
			'additionalProperties' => false,
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'media' => [ 'type' => 'array', 'items' => [ 'type' => 'object' ] ],
				'total' => [ 'type' => 'integer' ],
				'pages' => [ 'type' => 'integer' ],
				'page'  => [ 'type' => 'integer' ],
			],
		];
	}

	public function execute( array $input ): array|\WP_Error {
		// Editors see the whole media library in wp-admin with this capability.
		if ( ! current_user_can( 'upload_files' ) ) {
			return new \WP_Error( 'md3_mcp_forbidden', __( 'You are not allowed to see the media library.', 'md3-wp-mcp' ), [ 'status' => 403 ] );
		}

		$page     = max( 1, (int) ( $input['page'] ?? 1 ) );
		$per_page = min( self::MAX_PER_PAGE, max( 1, (int) ( $input['per_page'] ?? 20 ) ) );

		$args = [
			'post_type'              => 'attachment',
			'post_status'            => 'inherit',
			'post_mime_type'         => 'image',
			'posts_per_page'         => $per_page,
			'paged'                  => $page,
			'orderby'                => 'date',
			'order'                  => 'DESC',
			'no_found_rows'          => false,
			'update_post_term_cache' => false,
		];
		$search = isset( $input['search'] ) ? trim( (string) $input['search'] ) : '';
		if ( '' !== $search ) {
			$args['s'] = $search;
		}

		// Search the file name too, as the media library does. WP_Query
		// removes this filter once it has read it, so it applies to this query only.
		add_filter( 'wp_allow_query_attachment_by_filename', '__return_true' );
		$query = new \WP_Query( $args );

		return [
			'media' => array_map( static fn( \WP_Post $p ): array => Content_Formatter::media( (int) $p->ID ), $query->posts ),
			'total' => (int) $query->found_posts,
			'pages' => (int) $query->max_num_pages,
			'page'  => $page,
		];
	}
}
