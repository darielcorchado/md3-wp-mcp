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
			return self::bad_language( $language, $languages );
		}

		$links = [];
		foreach ( (array) ( $input['translations'] ?? [] ) as $lang => $translation_id ) {
			$lang = (string) $lang;
			if ( ! in_array( $lang, $languages, true ) ) {
				return self::bad_language( $lang, $languages );
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
			$links[ $lang ] = $other->ID;
		}

		// An item cannot be its own translation in another language, nor appear twice.
		unset( $links[ $language ] );
		if ( in_array( $post->ID, $links, true ) || count( $links ) !== count( array_unique( $links ) ) ) {
			return new \WP_Error( 'md3_mcp_bad_translation', __( 'Each translation must be a different item.', 'md3-wp-mcp' ), [ 'status' => 400 ] );
		}

		pll_set_post_language( $post->ID, $language );
		foreach ( $links as $lang => $translation_id ) {
			pll_set_post_language( $translation_id, $lang );
		}

		$merged              = array_map( 'intval', (array) pll_get_post_translations( $post->ID ) );
		$merged              = array_merge( $merged, $links );
		$merged[ $language ] = $post->ID;
		pll_save_post_translations( $merged );

		return [
			'id'           => $post->ID,
			'language'     => $language,
			'translations' => array_map( 'intval', (array) pll_get_post_translations( $post->ID ) ),
		];
	}

	/**
	 * @param string[] $languages Valid slugs.
	 */
	private static function bad_language( string $language, array $languages ): \WP_Error {
		return new \WP_Error(
			'md3_mcp_bad_language',
			sprintf( /* translators: 1: language slug 2: valid slugs */ __( 'Unknown language "%1$s". Available: %2$s', 'md3-wp-mcp' ), $language, implode( ', ', $languages ) ),
			[ 'status' => 400 ]
		);
	}
}
