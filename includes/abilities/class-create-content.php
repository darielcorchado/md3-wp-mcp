<?php
namespace MD3\WPMCP\Abilities;

use MD3\WPMCP\Auth\Scopes;

defined( 'ABSPATH' ) || exit;

final class Create_Content extends Ability {

	public function name(): string {
		return 'create-content';
	}

	public function label(): string {
		return __( 'Create content', 'md3-wp-mcp' );
	}

	public function description(): string {
		return __( 'Creates a draft or pending post or page. Publishing is not available.', 'md3-wp-mcp' );
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
				'type'   => [ 'type' => 'string', 'default' => 'post', 'description' => 'Post type.' ],
				'status'   => [ 'type' => 'string', 'enum' => Content_Writer::STATUSES, 'default' => 'draft' ],
				'language' => [ 'type' => 'string', 'description' => 'Polylang language slug (needs Polylang). To change it later or link translations, use link-translations.' ],
			] + Content_Writer::schema_properties(),
			'additionalProperties' => false,
		];
	}

	public function output_schema(): array {
		return [ 'type' => 'object' ];
	}

	public function execute( array $input ): array|\WP_Error {
		$input += [ 'type' => 'post', 'status' => 'draft' ];

		// The writer checks the type and the create capability.
		$id = Content_Writer::save( $input, null );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		return Content_Formatter::full( get_post( $id ) );
	}
}
