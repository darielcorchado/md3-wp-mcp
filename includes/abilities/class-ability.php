<?php
namespace MD3\WPMCP\Abilities;

use MD3\WPMCP\Audit_Log;
use MD3\WPMCP\Auth\Request_Context;
use MD3\WPMCP\Auth\Scopes;
use MD3\WPMCP\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Base class for the MCP tools. Each subclass is one ability.
 */
abstract class Ability {

	const PREFIX   = 'md3-wp-mcp/';
	const CATEGORY = 'md3-wp-mcp';

	/**
	 * Ability name without the `md3-wp-mcp/` prefix.
	 */
	abstract public function name(): string;

	abstract public function label(): string;

	abstract public function description(): string;

	/**
	 * @param array<string,mixed> $input Validated input.
	 * @return array<string,mixed>|\WP_Error
	 */
	abstract public function execute( array $input ): array|\WP_Error;

	/**
	 * JSON schema for the input. Empty means the tool takes no input.
	 */
	public function input_schema(): array {
		return [];
	}

	public function output_schema(): array {
		return [];
	}

	/**
	 * OAuth scope the caller must hold.
	 */
	public function scope(): string {
		return Scopes::READ;
	}

	/**
	 * MCP tool annotations.
	 */
	public function annotations(): array {
		return [
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		];
	}

	/**
	 * Scope check, then the tool's own object-level check.
	 *
	 * @param array<string,mixed>|null $input Input, null for tools without a schema.
	 */
	public function check_permission( ?array $input ): bool|\WP_Error {
		if ( ! Request_Context::has_scope( $this->scope() ) ) {
			return new \WP_Error(
				'md3_mcp_insufficient_scope',
				__( 'The access token does not grant the scope this tool needs.', 'md3-wp-mcp' ),
				[ 'status' => 403 ]
			);
		}
		return $this->object_permission( $input ?? [] );
	}

	/**
	 * Tool-specific permission check, run after the scope check.
	 *
	 * @param array<string,mixed> $input Input.
	 */
	protected function object_permission( array $input ): bool|\WP_Error {
		return true;
	}

	/**
	 * Loads a post that this tool may work with. Attachments and revisions are
	 * never reachable; only the post types enabled in the settings are.
	 */
	protected function load_post( int $id ): \WP_Post|\WP_Error {
		$post = $id > 0 ? get_post( $id ) : null;

		if ( ! $post || in_array( $post->post_type, [ 'attachment', 'revision' ], true ) ) {
			return new \WP_Error( 'md3_mcp_not_found', __( 'Content not found.', 'md3-wp-mcp' ), [ 'status' => 404 ] );
		}
		if ( ! Settings::is_allowed_post_type( $post->post_type ) ) {
			return new \WP_Error( 'md3_mcp_type_not_allowed', __( 'This content type is not enabled for MCP.', 'md3-wp-mcp' ), [ 'status' => 403 ] );
		}
		return $post;
	}

	/**
	 * Registers the ability with the WordPress Abilities API. Call on
	 * `wp_abilities_api_init`.
	 */
	public function register(): void {
		$args = [
			'label'               => $this->label(),
			'description'         => $this->description(),
			'category'            => self::CATEGORY,
			'execute_callback'    => fn( $input = null ) => $this->run( is_array( $input ) ? $input : [] ),
			'permission_callback' => fn( $input = null ) => $this->guard( is_array( $input ) ? $input : null ),
			'meta'                => [
				'mcp'          => [
					'public' => false,
					'type'   => 'tool',
				],
				'annotations'  => $this->annotations(),
				'show_in_rest' => false,
			],
		];

		$input_schema = $this->input_schema();
		if ( $input_schema ) {
			$args['input_schema'] = $input_schema;
		}
		$output_schema = $this->output_schema();
		if ( $output_schema ) {
			$args['output_schema'] = $output_schema;
		}

		wp_register_ability( self::PREFIX . $this->name(), $args );
	}

	/**
	 * @param array<string,mixed>|null $input Input.
	 */
	private function guard( ?array $input ): bool|\WP_Error {
		$result = $this->check_permission( $input );
		if ( true !== $result ) {
			$message = is_wp_error( $result ) ? $result->get_error_code() : 'denied';
			Audit_Log::record( $this->name(), 'denied', '', null, $message );
		}
		return $result;
	}

	/**
	 * @param array<string,mixed> $input Validated input.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function run( array $input ): array|\WP_Error {
		$result = $this->execute( $input );

		$object_id = isset( $input['id'] ) ? (int) $input['id'] : null;
		if ( is_wp_error( $result ) ) {
			Audit_Log::record( $this->name(), 'error', (string) ( $input['type'] ?? '' ), $object_id, $result->get_error_code() );
		} else {
			if ( isset( $result['id'] ) ) {
				$object_id = (int) $result['id'];
			}
			Audit_Log::record( $this->name(), 'ok', (string) ( $result['type'] ?? $input['type'] ?? '' ), $object_id );
		}
		return $result;
	}
}
