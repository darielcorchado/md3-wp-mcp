<?php
namespace MD3\WPMCP\Abilities;

use MD3\WPMCP\Content_Sanitizer;
use MD3\WPMCP\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Shared create/update field writer. Validates everything first, then writes
 * only the allowlisted fields and meta keys.
 */
final class Content_Writer {

	const FIELDS = [ 'title', 'content', 'excerpt', 'slug', 'terms', 'featured_media', 'seo', 'parent', 'menu_order', 'template' ];

	const SEO_META = [
		'title'         => 'rank_math_title',
		'description'   => 'rank_math_description',
		'focus_keyword' => 'rank_math_focus_keyword',
	];

	const STATUSES = [ 'draft', 'pending' ];

	/**
	 * Creates ($existing null) or updates a post.
	 *
	 * On create `type` and `status` are read from $input; `author` is never
	 * accepted (the caller sets post_author). On update only the given fields
	 * change.
	 *
	 * @param array<string,mixed> $input    Writer fields.
	 * @param \WP_Post|null       $existing Post to update, null to create.
	 * @return int|\WP_Error Post ID.
	 */
	public static function save( array $input, ?\WP_Post $existing ): int|\WP_Error {
		$allowed = array_merge( self::FIELDS, [ 'status' ], $existing ? [] : [ 'type' ] );
		foreach ( array_keys( $input ) as $key ) {
			if ( ! in_array( $key, $allowed, true ) ) {
				return self::error( 'md3_mcp_unknown_field', sprintf( /* translators: %s: field name */ __( 'Unknown field: %s', 'md3-wp-mcp' ), $key ) );
			}
		}

		$type = $existing ? $existing->post_type : (string) ( $input['type'] ?? 'post' );
		if ( ! Settings::is_allowed_post_type( $type ) || ! post_type_exists( $type ) ) {
			return self::error( 'md3_mcp_type_not_allowed', __( 'This content type is not enabled for MCP.', 'md3-wp-mcp' ), 403 );
		}

		$status = $input['status'] ?? ( $existing ? null : 'draft' );
		if ( null !== $status && ! in_array( $status, self::STATUSES, true ) ) {
			return self::error( 'md3_mcp_bad_status', __( 'Status must be draft or pending. Publishing is not available.', 'md3-wp-mcp' ) );
		}

		$checked = self::validate( $input, $type );
		if ( is_wp_error( $checked ) ) {
			return $checked;
		}

		$post = [ 'post_type' => $type ];
		if ( null !== $status ) {
			$post['post_status'] = $status;
		}
		if ( isset( $input['title'] ) ) {
			$post['post_title'] = Content_Sanitizer::plain( (string) $input['title'] );
		}
		if ( isset( $input['content'] ) ) {
			$post['post_content'] = Content_Sanitizer::html( (string) $input['content'] );
		}
		if ( isset( $input['excerpt'] ) ) {
			$post['post_excerpt'] = Content_Sanitizer::html( (string) $input['excerpt'] );
		}
		if ( isset( $input['slug'] ) ) {
			$post['post_name'] = Content_Sanitizer::slug( (string) $input['slug'] );
		}
		if ( isset( $input['parent'] ) ) {
			$post['post_parent'] = (int) $input['parent'];
		}
		if ( isset( $input['menu_order'] ) ) {
			$post['menu_order'] = (int) $input['menu_order'];
		}
		if ( isset( $input['template'] ) ) {
			$post['page_template'] = (string) $input['template'];
		}

		if ( $existing ) {
			$post['ID'] = $existing->ID;
			$id         = wp_update_post( wp_slash( $post ), true );
		} else {
			$post['post_author'] = Settings::default_author_id();
			$id                  = wp_insert_post( wp_slash( $post ), true );
		}
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$id = (int) $id;

		foreach ( (array) ( $input['terms'] ?? [] ) as $taxonomy => $term_ids ) {
			$set = wp_set_object_terms( $id, array_map( 'intval', (array) $term_ids ), (string) $taxonomy );
			if ( is_wp_error( $set ) ) {
				return $set;
			}
		}

		if ( isset( $input['featured_media'] ) ) {
			if ( (int) $input['featured_media'] > 0 ) {
				set_post_thumbnail( $id, (int) $input['featured_media'] );
			} else {
				delete_post_thumbnail( $id );
			}
		}

		foreach ( (array) ( $input['seo'] ?? [] ) as $key => $value ) {
			update_post_meta( $id, self::SEO_META[ $key ], wp_slash( Content_Sanitizer::plain( (string) $value ) ) );
		}

		// Never email subscribers about MCP-written content (Brevo opt-out).
		update_post_meta( $id, 'sib_push_meta_box_present', 1 );
		update_post_meta( $id, 'sib_push_send_notification', '0' );

		return $id;
	}

	/**
	 * Checks every field before anything is written.
	 *
	 * @param array<string,mixed> $input Writer fields.
	 */
	private static function validate( array $input, string $type ): true|\WP_Error {
		if ( isset( $input['terms'] ) ) {
			if ( ! is_array( $input['terms'] ) ) {
				return self::error( 'md3_mcp_bad_term', __( 'terms must map a taxonomy to a list of term IDs.', 'md3-wp-mcp' ) );
			}
			$usable = array_intersect( get_object_taxonomies( $type ), Content_Formatter::allowed_taxonomies() );
			foreach ( $input['terms'] as $taxonomy => $term_ids ) {
				if ( ! in_array( $taxonomy, $usable, true ) ) {
					return self::error( 'md3_mcp_invalid_taxonomy', sprintf( /* translators: %s: taxonomy */ __( 'Taxonomy "%s" is not available for this content type.', 'md3-wp-mcp' ), $taxonomy ) );
				}
				foreach ( (array) $term_ids as $term_id ) {
					if ( ! is_int( $term_id ) || $term_id < 1 || ! term_exists( $term_id, $taxonomy ) ) {
						return self::error( 'md3_mcp_bad_term', sprintf( /* translators: 1: term ID 2: taxonomy */ __( 'Term %1$s does not exist in %2$s.', 'md3-wp-mcp' ), is_scalar( $term_id ) ? (string) $term_id : '?', $taxonomy ) );
					}
				}
			}
		}

		if ( ! empty( $input['featured_media'] ) ) {
			if ( ! wp_attachment_is_image( (int) $input['featured_media'] ) ) {
				return self::error( 'md3_mcp_bad_media', __( 'featured_media must be the ID of an image in the media library.', 'md3-wp-mcp' ) );
			}
		}

		if ( isset( $input['seo'] ) ) {
			if ( ! is_array( $input['seo'] ) ) {
				return self::error( 'md3_mcp_bad_seo', __( 'seo must be an object.', 'md3-wp-mcp' ) );
			}
			foreach ( array_keys( $input['seo'] ) as $key ) {
				if ( ! isset( self::SEO_META[ $key ] ) ) {
					return self::error( 'md3_mcp_unknown_field', sprintf( /* translators: %s: field name */ __( 'Unknown field: %s', 'md3-wp-mcp' ), 'seo.' . $key ) );
				}
			}
		}

		$hierarchical_only = array_intersect( [ 'parent', 'menu_order', 'template' ], array_keys( $input ) );
		if ( $hierarchical_only && 'page' !== $type && ! is_post_type_hierarchical( $type ) ) {
			return self::error( 'md3_mcp_unsupported_field', sprintf( /* translators: %s: field names */ __( 'This content type does not support: %s', 'md3-wp-mcp' ), implode( ', ', $hierarchical_only ) ) );
		}

		if ( ! empty( $input['parent'] ) ) {
			$parent = get_post( (int) $input['parent'] );
			if ( ! $parent || $parent->post_type !== $type ) {
				return self::error( 'md3_mcp_bad_parent', __( 'parent must be an existing item of the same content type.', 'md3-wp-mcp' ) );
			}
		}

		if ( isset( $input['template'] ) && 'default' !== $input['template'] ) {
			// get_page_templates() maps template name => file; the file is what gets stored.
			$templates = array_values( wp_get_theme()->get_page_templates( null, $type ) );
			if ( ! in_array( $input['template'], $templates, true ) ) {
				return self::error( 'md3_mcp_bad_template', __( 'Unknown page template for the active theme.', 'md3-wp-mcp' ) );
			}
		}

		return true;
	}

	private static function error( string $code, string $message, int $status = 400 ): \WP_Error {
		return new \WP_Error( $code, $message, [ 'status' => $status ] );
	}

	/**
	 * Input properties shared by create-content and update-content.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function schema_properties(): array {
		return [
			'title'          => [ 'type' => 'string' ],
			'content'        => [ 'type' => 'string', 'description' => 'Block markup (HTML with block comments).' ],
			'excerpt'        => [ 'type' => 'string' ],
			'slug'           => [ 'type' => 'string' ],
			'terms'          => [
				'type'                 => 'object',
				'description'          => 'Taxonomy => term ID list. Replaces the terms of that taxonomy.',
				'additionalProperties' => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ] ],
			],
			'featured_media' => [ 'type' => 'integer', 'minimum' => 0, 'description' => 'Media library ID; 0 removes the featured image.' ],
			'seo'            => [
				'type'                 => 'object',
				'properties'           => [
					'title'         => [ 'type' => 'string' ],
					'description'   => [ 'type' => 'string' ],
					'focus_keyword' => [ 'type' => 'string' ],
				],
				'additionalProperties' => false,
			],
			'parent'         => [ 'type' => 'integer', 'minimum' => 0, 'description' => 'Pages and hierarchical types only.' ],
			'menu_order'     => [ 'type' => 'integer', 'description' => 'Pages and hierarchical types only.' ],
			'template'       => [ 'type' => 'string', 'description' => 'Page template file or "default". Pages and hierarchical types only.' ],
		];
	}
}
