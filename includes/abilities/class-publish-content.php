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
			$date = self::parse_date( (string) $input['date'] );
			if ( ! $date ) {
				return new \WP_Error( 'md3_mcp_bad_date', __( 'date must be ISO 8601 or "Y-m-d H:i".', 'md3-wp-mcp' ), [ 'status' => 400 ] );
			}
		} elseif ( 'future' !== $post->post_status && ! self::has_future_date( $post, $now ) ) {
			// Publish now without touching a past date the post already has.
			// A future one (a draft that was scheduled) would make WordPress
			// schedule it again, so that case publishes with the current time.
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

	/**
	 * Whether the stored date is ahead of now, as wp_insert_post() would see
	 * it when publishing (a floating draft's GMT date comes from its local one).
	 */
	private static function has_future_date( \WP_Post $post, \DateTimeImmutable $now ): bool {
		$gmt = '0000-00-00 00:00:00' === $post->post_date_gmt ? get_gmt_from_date( $post->post_date ) : $post->post_date_gmt;
		$ts  = strtotime( $gmt . ' UTC' );
		return false !== $ts && $ts - $now->getTimestamp() >= MINUTE_IN_SECONDS;
	}

	/**
	 * Strict date parser: ISO 8601 ("T" or space, optional seconds, optional
	 * Z or offset) or "Y-m-d H:i". No relative words, no calendar overflow.
	 * Without an offset the site time zone applies.
	 */
	private static function parse_date( string $value ): ?\DateTimeImmutable {
		$pattern = '/^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})(?::(\d{2}))?(Z|[+-]\d{2}(?::?\d{2})?)?$/';
		if ( ! preg_match( $pattern, trim( $value ), $m ) ) {
			return null;
		}
		$year = (int) $m[1];
		$sec  = isset( $m[6] ) && '' !== $m[6] ? (int) $m[6] : 0;
		if ( $year < 1970 || ! checkdate( (int) $m[2], (int) $m[3], $year ) || (int) $m[4] > 23 || (int) $m[5] > 59 || $sec > 59 ) {
			return null;
		}

		$zone = wp_timezone();
		if ( isset( $m[7] ) && '' !== $m[7] ) {
			if ( 'Z' === $m[7] ) {
				$offset = '+00:00';
			} else {
				$digits = preg_replace( '/\D/', '', $m[7] );
				$hours  = (int) substr( $digits, 0, 2 );
				$mins   = (int) substr( $digits, 2, 2 );
				if ( $hours > 14 || $mins > 59 ) {
					return null;
				}
				$offset = $m[7][0] . sprintf( '%02d:%02d', $hours, $mins );
			}
			$zone = new \DateTimeZone( $offset );
		}

		$date = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', sprintf( '%04d-%02d-%02d %02d:%02d:%02d', $year, $m[2], $m[3], $m[4], $m[5], $sec ), $zone );
		return $date ?: null;
	}
}
