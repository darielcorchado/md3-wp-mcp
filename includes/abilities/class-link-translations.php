<?php
namespace MD3\WPMCP\Abilities;

use MD3\WPMCP\Auth\Scopes;

defined( 'ABSPATH' ) || exit;

/**
 * Sets a post's Polylang language and links it to its translations.
 * Registered only when Polylang is active.
 */
final class Link_Translations extends Ability {

	public function name(): string {
		return 'link-translations';
	}

	public function label(): string {
		return __( 'Link translations', 'md3-wp-mcp' );
	}

	public function description(): string {
		return __( 'Sets the language of a post or page and links it with its translations in other languages (Polylang).', 'md3-wp-mcp' );
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
				'id'           => [ 'type' => 'integer', 'minimum' => 1 ],
				'language'     => [ 'type' => 'string', 'description' => 'Language slug of this item, such as "en" or "es".' ],
				'translations' => [
					'type'                 => 'object',
					'description'          => 'Language slug => ID of the translated item of the same content type.',
					'additionalProperties' => [ 'type' => 'integer', 'minimum' => 1 ],
				],
			],
			'required'             => [ 'id', 'language' ],
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
			return new \WP_Error( 'md3_mcp_forbidden', __( 'You are not allowed to edit this content.', 'md3-wp-mcp' ), [ 'status' => 403 ] );
		}

		$languages = array_map( 'strval', (array) pll_languages_list() );
		$language  = (string) ( $input['language'] ?? '' );
		if ( ! in_array( $language, $languages, true ) ) {
			return Content_Writer::unknown_language( $language, $languages );
		}

		$requested = (array) ( $input['translations'] ?? [] );
		if ( isset( $requested[ $language ] ) && (int) $requested[ $language ] !== $post->ID ) {
			return new \WP_Error( 'md3_mcp_bad_translation', __( 'The translation in the item\'s own language must be the item itself.', 'md3-wp-mcp' ), [ 'status' => 400 ] );
		}

		$links = [];
		$items = [ $language => $post ];
		foreach ( $requested as $lang => $translation_id ) {
			$lang = (string) $lang;
			if ( ! in_array( $lang, $languages, true ) ) {
				return Content_Writer::unknown_language( $lang, $languages );
			}
			$other = $this->load_post( (int) $translation_id );
			if ( is_wp_error( $other ) ) {
				return $other;
			}
			if ( ! current_user_can( 'edit_post', $other->ID ) ) {
				return new \WP_Error( 'md3_mcp_forbidden', __( 'You are not allowed to edit a translation you listed.', 'md3-wp-mcp' ), [ 'status' => 403 ] );
			}
			if ( $other->post_type !== $post->post_type ) {
				return new \WP_Error( 'md3_mcp_bad_translation', __( 'Translations must be of the same content type.', 'md3-wp-mcp' ), [ 'status' => 400 ] );
			}
			if ( $lang !== $language ) {
				$links[ $lang ] = $other->ID;
				$items[ $lang ] = $other;
			}
		}

		// An item cannot be its own translation in another language, nor appear twice.
		if ( in_array( $post->ID, $links, true ) || count( $links ) !== count( array_unique( $links ) ) ) {
			return new \WP_Error( 'md3_mcp_bad_translation', __( 'Each translation must be a different item.', 'md3-wp-mcp' ), [ 'status' => 400 ] );
		}

		$placing  = [ $language => $post->ID ] + $links;
		$conflict = $this->conflict( $post->ID, $placing );
		if ( is_wp_error( $conflict ) ) {
			return $conflict;
		}

		// Changing the language of live content changes what visitors see.
		foreach ( $items as $lang => $item ) {
			if ( pll_get_post_language( $item->ID ) !== $lang && self::is_live( $item ) ) {
				$scope = $this->require_scope( Scopes::PUBLISH );
				if ( true !== $scope ) {
					return $scope;
				}
			}
		}

		foreach ( $placing as $lang => $id ) {
			pll_set_post_language( $id, $lang );
		}

		// The group keeps its other members; the placed items take their slots.
		$merged = array_filter(
			array_map( 'intval', (array) pll_get_post_translations( $post->ID ) ),
			static fn ( int $id, $lang ): bool => ! in_array( $id, $placing, true ) || ( $placing[ $lang ] ?? 0 ) === $id,
			ARRAY_FILTER_USE_BOTH
		);
		foreach ( $placing as $lang => $id ) {
			$merged[ $lang ] = $id;
		}
		pll_save_post_translations( $merged );

		return [
			'id'           => $post->ID,
			'language'     => $language,
			'translations' => array_map( 'intval', (array) pll_get_post_translations( $post->ID ) ),
		];
	}

	/**
	 * Linking only adds to a group: it never displaces an item from a slot of
	 * the post's group, nor pulls an item out of another group. Unlinking
	 * stays a wp-admin task.
	 *
	 * @param array<string,int> $placing Language => item ID, the post included.
	 */
	private function conflict( int $post_id, array $placing ): ?\WP_Error {
		$group = array_map( 'intval', (array) pll_get_post_translations( $post_id ) );
		foreach ( $placing as $lang => $id ) {
			if ( isset( $group[ $lang ] ) && $group[ $lang ] !== $id ) {
				return self::translation_conflict();
			}
		}

		foreach ( $placing as $id ) {
			if ( $id === $post_id ) {
				continue;
			}
			$other_group = array_map( 'intval', (array) pll_get_post_translations( $id ) );
			if ( ! in_array( $post_id, $other_group, true ) && array_diff( $other_group, [ $id ] ) ) {
				return self::translation_conflict();
			}
		}

		return null;
	}

	private static function translation_conflict(): \WP_Error {
		return new \WP_Error(
			'md3_mcp_translation_conflict',
			__( 'This would replace or move an existing translation. Unlink it in wp-admin first.', 'md3-wp-mcp' ),
			[ 'status' => 409 ]
		);
	}
}
