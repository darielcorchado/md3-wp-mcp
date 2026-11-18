<?php
namespace MD3\WPMCP\Abilities;

use MD3\WPMCP\Auth\Request_Context;
use MD3\WPMCP\Auth\Scopes;
use MD3\WPMCP\Rate_Limiter;
use MD3\WPMCP\Uploads\Upload_Links;
use MD3\WPMCP\Uploads\Upload_Page;

defined( 'ABSPATH' ) || exit;

/**
 * Makes a one-time upload page for an image the user has in the chat, which
 * the AI can't send itself.
 */
final class Create_Upload_Link extends Ability {

	public function name(): string {
		return 'create-upload-link';
	}

	public function label(): string {
		return __( 'Create upload link', 'md3-wp-mcp' );
	}

	public function description(): string {
		return __( 'Creates a one-time link where the user uploads an image (JPEG, PNG, WebP or GIF, up to 10 MB) from their browser to the media library. Use it when the user attached or wants to add an image you cannot send with upload-media. Show the link to the user; it works once and expires in 10 minutes. When they say it is done, call get-upload with upload_id to get the media ID.', 'md3-wp-mcp' );
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

	/**
	 * Attaching to live content needs the publish scope, as in upload-media.
	 */
	protected function object_permission( array $input ): bool|\WP_Error {
		return $this->require_publish_if_live( (int) ( $input['attach_to'] ?? 0 ) );
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'properties'           => [
				'alt'       => [ 'type' => 'string', 'description' => 'Alt text for the image.' ],
				'title'     => [ 'type' => 'string' ],
				'caption'   => [ 'type' => 'string' ],
				'attach_to' => [ 'type' => 'integer', 'minimum' => 1, 'description' => 'ID of a post or page to attach the image to. Attaching to live content needs the publish scope.' ],
			],
			'additionalProperties' => false,
		];
	}

	public function output_schema(): array {
		return [ 'type' => 'object' ];
	}

	public function execute( array $input ): array|\WP_Error {
		$identity = Request_Context::get();
		if ( ! $identity ) {
			return new \WP_Error( 'md3_mcp_forbidden', __( 'Upload links can only be created over an MCP connection.', 'md3-wp-mcp' ), [ 'status' => 403 ] );
		}
		if ( ! current_user_can( 'upload_files' ) ) {
			return new \WP_Error( 'md3_mcp_forbidden', __( 'You are not allowed to upload files.', 'md3-wp-mcp' ), [ 'status' => 403 ] );
		}

		$parent = 0;
		if ( ! empty( $input['attach_to'] ) ) {
			$parent = Upload_Media::resolve_parent( (int) $input['attach_to'] );
			if ( is_wp_error( $parent ) ) {
				return $parent;
			}
		}

		if ( ! Rate_Limiter::hit( 'upload_link', $identity->client_id . '|' . $identity->user_id, Upload_Links::CREATE_LIMIT, Upload_Links::CREATE_WINDOW ) ) {
			return new \WP_Error( 'md3_mcp_rate_limited', __( 'Too many upload links. Try again in a few minutes.', 'md3-wp-mcp' ), [ 'status' => 429 ] );
		}

		// Upload_Media::insert() sanitizes these when the image is added.
		$details = array_map( 'strval', array_intersect_key( $input, array_flip( [ 'alt', 'title', 'caption' ] ) ) );

		$link = Upload_Links::create( $identity, $parent, $details, Request_Context::has_scope( Scopes::PUBLISH ) );
		if ( is_wp_error( $link ) ) {
			return $link;
		}

		return [
			'upload_id'    => $link['id'],
			'url'          => Upload_Page::url( $link['token'] ),
			'status'       => Upload_Links::WAITING,
			'expires_at'   => gmdate( 'c', $link['expires_at'] ),
			'expires_in'   => Upload_Links::TTL,
			'instructions' => 'Give the user this link to open in their browser and upload the image. It works once and expires in 10 minutes. When they say it is done, call get-upload with this upload_id to get the media ID and URL.',
		];
	}
}
