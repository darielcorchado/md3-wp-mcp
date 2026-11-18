<?php
namespace MD3\WPMCP\Abilities;

use MD3\WPMCP\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Turns posts into the arrays the tools return.
 */
final class Content_Formatter {

	/**
	 * @return array{id:int,type:string,title:string,status:string,slug:string,date:string,modified:string,link:string,language:?string}
	 */
	public static function summary( \WP_Post $p ): array {
		return [
			'id'       => (int) $p->ID,
			'type'     => $p->post_type,
			'title'    => $p->post_title,
			'status'   => $p->post_status,
			'slug'     => $p->post_name,
			'date'     => $p->post_date_gmt && '0000-00-00 00:00:00' !== $p->post_date_gmt ? mysql_to_rfc3339( $p->post_date_gmt ) : '',
			'modified' => mysql_to_rfc3339( $p->post_modified_gmt ),
			'link'     => (string) get_permalink( $p ),
			'language' => self::language( $p->ID ),
		];
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function full( \WP_Post $p ): array {
		return self::summary( $p ) + [
			'content'        => $p->post_content,
			'excerpt'        => $p->post_excerpt,
			'parent'         => (int) $p->post_parent,
			'menu_order'     => (int) $p->menu_order,
			'template'       => (string) get_page_template_slug( $p ),
			'terms'          => self::terms( $p ),
			'featured_image' => self::featured_image( $p ),
			'seo'            => [
				'title'         => (string) get_post_meta( $p->ID, 'rank_math_title', true ),
				'description'   => (string) get_post_meta( $p->ID, 'rank_math_description', true ),
				'focus_keyword' => (string) get_post_meta( $p->ID, 'rank_math_focus_keyword', true ),
			],
			'translations'   => self::translations( $p->ID ),
			'edit_link'      => (string) get_edit_post_link( $p->ID, 'raw' ),
			'preview_link'   => (string) get_preview_post_link( $p ),
		];
	}

	/**
	 * Public taxonomies of the allowed post types.
	 *
	 * @return string[]
	 */
	public static function allowed_taxonomies(): array {
		$taxonomies = [];
		foreach ( Settings::post_types() as $type ) {
			foreach ( get_object_taxonomies( $type, 'objects' ) as $taxonomy ) {
				if ( $taxonomy->public ) {
					$taxonomies[] = $taxonomy->name;
				}
			}
		}
		return array_values( array_unique( $taxonomies ) );
	}

	private static function language( int $post_id ): ?string {
		if ( ! function_exists( 'pll_get_post_language' ) ) {
			return null;
		}
		$language = pll_get_post_language( $post_id );
		return $language ? (string) $language : null;
	}

	/**
	 * @return array<string,int>
	 */
	private static function translations( int $post_id ): array {
		if ( ! function_exists( 'pll_get_post_translations' ) ) {
			return [];
		}
		return array_map( 'intval', (array) pll_get_post_translations( $post_id ) );
	}

	/**
	 * @return array<string,array<int,array{id:int,name:string,slug:string}>>
	 */
	private static function terms( \WP_Post $p ): array {
		$out = [];
		foreach ( array_intersect( get_object_taxonomies( $p->post_type ), self::allowed_taxonomies() ) as $taxonomy ) {
			$terms = get_the_terms( $p, $taxonomy );
			if ( ! $terms || is_wp_error( $terms ) ) {
				continue;
			}
			$out[ $taxonomy ] = array_map(
				static fn( \WP_Term $t ): array => [
					'id'   => (int) $t->term_id,
					'name' => $t->name,
					'slug' => $t->slug,
				],
				array_values( $terms )
			);
		}
		return $out;
	}

	/**
	 * An image in the media library, as upload-media, get-upload and
	 * list-media return it.
	 *
	 * @return array{id:int,title:string,filename:string,url:string,mime:string,width:int,height:int,alt:string,date:string}
	 */
	public static function media( int $id ): array {
		$meta = wp_get_attachment_metadata( $id );
		$post = get_post( $id );
		return [
			'id'       => $id,
			'title'    => $post ? $post->post_title : '',
			'filename' => wp_basename( (string) get_post_meta( $id, '_wp_attached_file', true ) ),
			'url'      => (string) wp_get_attachment_url( $id ),
			'mime'     => (string) get_post_mime_type( $id ),
			'width'    => (int) ( $meta['width'] ?? 0 ),
			'height'   => (int) ( $meta['height'] ?? 0 ),
			'alt'      => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
			'date'     => $post ? mysql_to_rfc3339( $post->post_date_gmt ) : '',
		];
	}

	/**
	 * @return array{id:int,url:string,alt:string}|null
	 */
	private static function featured_image( \WP_Post $p ): ?array {
		$id = (int) get_post_thumbnail_id( $p );
		if ( ! $id ) {
			return null;
		}
		$url = wp_get_attachment_url( $id );
		if ( ! $url ) {
			return null;
		}
		return [
			'id'  => $id,
			'url' => $url,
			'alt' => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
		];
	}
}
