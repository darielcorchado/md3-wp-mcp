<?php
namespace MD3\WPMCP\Abilities;

use MD3\WPMCP\Settings;

defined( 'ABSPATH' ) || exit;

final class List_Post_Types extends Ability {

	public function name(): string {
		return 'list-post-types';
	}

	public function label(): string {
		return __( 'List post types', 'md3-wp-mcp' );
	}

	public function description(): string {
		return __( 'Lists the content types this site lets you work with, with the taxonomies and features each supports.', 'md3-wp-mcp' );
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'properties'           => new \stdClass(),
			'additionalProperties' => false,
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'post_types' => [
					'type'  => 'array',
					'items' => [
						'type'       => 'object',
						'properties' => [
							'name'         => [ 'type' => 'string' ],
							'label'        => [ 'type' => 'string' ],
							'hierarchical' => [ 'type' => 'boolean' ],
							'supports'     => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
							'taxonomies'   => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
						],
					],
				],
			],
		];
	}

	public function execute( array $input ): array|\WP_Error {
		$types = [];
		foreach ( Settings::post_types() as $name ) {
			$object = get_post_type_object( $name );
			if ( ! $object ) {
				continue;
			}
			$types[] = [
				'name'         => $name,
				'label'        => $object->labels->name,
				'hierarchical' => (bool) $object->hierarchical,
				'supports'     => array_keys( array_filter( get_all_post_type_supports( $name ) ) ),
				'taxonomies'   => array_values( array_intersect( get_object_taxonomies( $name ), Content_Formatter::allowed_taxonomies() ) ),
			];
		}
		return [ 'post_types' => $types ];
	}
}
