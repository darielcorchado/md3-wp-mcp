<?php
namespace MD3\WPMCP\Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * The single list of MCP abilities.
 */
final class Registry {

	/**
	 * @return Ability[]
	 */
	public static function abilities(): array {
		return [
			new List_Post_Types(),
			new List_Content(),
			new Get_Content(),
			new List_Terms(),
			new Create_Content(),
			new Update_Content(),
			new Create_Term(),
			new Publish_Content(),
			new Unpublish_Content(),
			new Trash_Content(),
			new Restore_Content(),
		];
	}

	/**
	 * @return string[] Full ability names, with the `md3-wp-mcp/` prefix.
	 */
	public static function names(): array {
		return array_map(
			static fn( Ability $ability ): string => Ability::PREFIX . $ability->name(),
			self::abilities()
		);
	}

	/**
	 * Hooks the category and the abilities into the Abilities API.
	 */
	public static function register(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		add_action(
			'wp_abilities_api_categories_init',
			static function (): void {
				wp_register_ability_category(
					Ability::CATEGORY,
					[
						'label'       => __( 'MD3 WP MCP', 'md3-wp-mcp' ),
						'description' => __( 'Read and edit site content.', 'md3-wp-mcp' ),
					]
				);
			}
		);

		add_action(
			'wp_abilities_api_init',
			static function (): void {
				foreach ( self::abilities() as $ability ) {
					$ability->register();
				}
			}
		);
	}
}
