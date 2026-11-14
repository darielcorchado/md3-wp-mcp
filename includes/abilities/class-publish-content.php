<?php
namespace MD3\WPMCP\Abilities;

use MD3\WPMCP\Auth\Scopes;

defined( 'ABSPATH' ) || exit;

final class Publish_Content extends Ability {

	public function name(): string {
		return 'publish-content';
	}

	public function label(): string {
		return __( 'Publish content', 'md3-wp-mcp' );
	}

	public function description(): string {
		return __( 'Publishes a post or page now, or schedules it with a date. A date without a UTC offset is read in the site time zone; a past date publishes immediately with that date.', 'md3-wp-mcp' );
	}

	public function scope(): string {
		return Scopes::PUBLISH;
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
				'id'   => [ 'type' => 'integer', 'minimum' => 1 ],
				'date' => [ 'type' => 'string', 'description' => 'ISO 8601 or "Y-m-d H:i". Without an offset the site time zone applies.' ],
			],
			'required'             => [ 'id' ],
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
		$type = get_post_type_object( $post->post_type );
		if ( ! $type || ! current_user_can( 'edit_post', $post->ID ) || ! current_user_can( $type->cap->publish_posts ) ) {
			return new \WP_Error( 'md3_mcp_forbidden', __( 'You are not allowed to publish this content.', 'md3-wp-mcp' ), [ 'status' => 403 ] );
		}
		if ( 'trash' === $post->post_status ) {
			return new \WP_Error( 'md3_mcp_trashed', __( 'Restore this content before publishing it.', 'md3-wp-mcp' ), [ 'status' => 409 ] );
		}

		$now  = new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
		$date = $now;
		if ( isset( $input['date'] ) && '' !== $input['date'] ) {
			$date = date_create_immutable( (string) $input['date'], wp_timezone() );
			if ( ! $date ) {
				return new \WP_Error( 'md3_mcp_bad_date', __( 'date must be ISO 8601 or "Y-m-d H:i".', 'md3-wp-mcp' ), [ 'status' => 400 ] );
			}
		} elseif ( 'future' !== $post->post_status ) {
			// Publish now without touching a date the post already has.
			$date = null;
		}

		$update = [ 'ID' => $post->ID, 'post_status' => 'publish' ];
		if ( $date ) {
			$update['post_status']   = $date > $now ? 'future' : 'publish';
			$update['post_date']     = $date->setTimezone( wp_timezone() )->format( 'Y-m-d H:i:s' );
			$update['post_date_gmt'] = $date->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
			$update['edit_date']     = true;
		}

		$result = wp_update_post( wp_slash( $update ), true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		Content_Writer::disable_subscriber_notification( $post->ID );

		return Content_Formatter::summary( get_post( $post->ID ) );
	}
}
