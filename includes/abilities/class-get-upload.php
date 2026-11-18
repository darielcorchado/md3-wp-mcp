<?php
namespace MD3\WPMCP\Abilities;

use MD3\WPMCP\Auth\Request_Context;
use MD3\WPMCP\Uploads\Upload_Links;

defined( 'ABSPATH' ) || exit;

/**
 * Status of an upload link made by the same connection.
 */
final class Get_Upload extends Ability {

	public function name(): string {
		return 'get-upload';
	}

	public function label(): string {
		return __( 'Get upload', 'md3-wp-mcp' );
	}

	public function description(): string {
		return __( 'Returns the status of an upload link from create-upload-link: waiting, uploaded or expired. Once uploaded, it returns the image (media ID, URL, size and alt text), ready for set-featured-image or the content.', 'md3-wp-mcp' );
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'properties'           => [
				'upload_id' => [ 'type' => 'string', 'description' => 'upload_id returned by create-upload-link.' ],
			],
			'required'             => [ 'upload_id' ],
			'additionalProperties' => false,
		];
	}

	public function output_schema(): array {
		return [ 'type' => 'object' ];
	}

	public function execute( array $input ): array|\WP_Error {
		$identity = Request_Context::get();
		$row      = $identity ? Upload_Links::find_for_connection( (string) $input['upload_id'], $identity->client_id, $identity->user_id ) : null;
		if ( ! $row ) {
			return new \WP_Error( 'md3_mcp_not_found', __( 'Upload link not found. Links are kept for a day after they expire.', 'md3-wp-mcp' ), [ 'status' => 404 ] );
		}

		$result = [
			'upload_id'  => (string) $row->id,
			'status'     => Upload_Links::state( $row ),
			'expires_at' => gmdate( 'c', (int) strtotime( $row->expires_at . ' UTC' ) ),
		];

		$attachment_id = (int) $row->attachment_id;
		if ( Upload_Links::UPLOADED === $result['status'] && $attachment_id > 0 && get_post( $attachment_id ) ) {
			$result['media'] = Content_Formatter::media( $attachment_id );
		}
		return $result;
	}
}
