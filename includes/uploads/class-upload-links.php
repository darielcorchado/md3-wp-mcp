<?php
namespace MD3\WPMCP\Uploads;

use MD3\WPMCP\Audit_Log;
use MD3\WPMCP\Auth\Identity;
use MD3\WPMCP\OAuth\Tokens;
use MD3\WPMCP\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * One-time upload links (`create-upload-link`), stored in the `uploads` table.
 *
 * A link has a public ID, which the AI uses with `get-upload`, and a secret
 * token, which is only in the URL. Only the token's SHA-256 hash is stored.
 * A link belongs to the connection (client and MCP user) that made it, works
 * once, and expires after TTL seconds.
 */
final class Upload_Links {

	const TTL = 600;

	/** Links one connection may create per CREATE_WINDOW seconds. */
	const CREATE_LIMIT  = 10;
	const CREATE_WINDOW = 600;

	/** How long an expired or used link stays, so get-upload can still report it. */
	const KEEP_AFTER_EXPIRY = DAY_IN_SECONDS;

	/** An upload that started before expiry may still be running this long after it. */
	const UPLOAD_GRACE = HOUR_IN_SECONDS;

	const WAITING   = 'waiting';
	const UPLOADING = 'uploading';
	const UPLOADED  = 'uploaded';
	const EXPIRED   = 'expired';

	const TOKEN_PATTERN = '/^[A-Za-z0-9]{43}$/';

	/**
	 * Creates a link for a connection.
	 *
	 * @param array{alt?:string,title?:string,caption?:string} $details Applied to the image.
	 * @return array{id:string,token:string,expires_at:int}|\WP_Error
	 */
	public static function create( Identity $identity, int $attach_to, array $details, bool $can_publish ): array|\WP_Error {
		global $wpdb;

		// 43 characters from 62 is about 256 bits, from a CSPRNG.
		$token   = wp_generate_password( 43, false );
		$id      = wp_generate_password( 20, false );
		$now     = time();
		$expires = $now + self::TTL;

		$stored = $wpdb->insert(
			Schema::table( 'uploads' ),
			[
				'id'          => $id,
				'token_hash'  => self::hash( $token ),
				'client_id'   => $identity->client_id,
				'user_id'     => $identity->user_id,
				'can_publish' => $can_publish ? 1 : 0,
				'attach_to'   => $attach_to,
				'details'     => (string) wp_json_encode( $details ),
				'status'      => self::WAITING,
				'created_at'  => Tokens::utc( $now ),
				'expires_at'  => Tokens::utc( $expires ),
			],
			[ '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s' ]
		);
		if ( 1 !== $stored ) {
			return new \WP_Error( 'md3_mcp_upload_link_failed', __( 'The upload link could not be created. Try again.', 'md3-wp-mcp' ), [ 'status' => 500 ] );
		}

		return [
			'id'         => $id,
			'token'      => $token,
			'expires_at' => $expires,
		];
	}

	/**
	 * A link of the given connection, by its public ID.
	 */
	public static function find_for_connection( string $id, string $client_id, int $user_id ): ?object {
		global $wpdb;
		$table = Schema::table( 'uploads' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %s AND client_id = %s AND user_id = %d", $id, $client_id, $user_id ) );
		return $row ?: null;
	}

	/**
	 * A link by its secret token.
	 */
	public static function find_by_token( string $token ): ?object {
		global $wpdb;
		if ( ! preg_match( self::TOKEN_PATTERN, $token ) ) {
			return null;
		}
		$table = Schema::table( 'uploads' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE token_hash = %s", self::hash( $token ) ) );
		return $row ?: null;
	}

	/**
	 * What the AI is told: `waiting`, `uploaded` or `expired`. An upload in
	 * progress still counts as waiting.
	 */
	public static function state( object $row ): string {
		if ( self::UPLOADED === $row->status ) {
			return self::UPLOADED;
		}
		if ( self::EXPIRED === $row->status || strtotime( $row->expires_at . ' UTC' ) <= time() ) {
			return self::EXPIRED;
		}
		return self::WAITING;
	}

	/**
	 * @return array{alt?:string,title?:string,caption?:string}
	 */
	public static function details( object $row ): array {
		$details = json_decode( (string) $row->details, true );
		return is_array( $details ) ? array_intersect_key( $details, array_flip( [ 'alt', 'title', 'caption' ] ) ) : [];
	}

	/**
	 * Takes a waiting, unexpired link for one upload. Atomic, so two requests
	 * with the same link can't both upload.
	 */
	public static function claim( string $id ): bool {
		global $wpdb;
		$table = Schema::table( 'uploads' );
		return 1 === (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = %s WHERE id = %s AND status = %s AND expires_at > %s",
				self::UPLOADING,
				$id,
				self::WAITING,
				Tokens::utc( time() )
			)
		);
	}

	/**
	 * Gives a claimed link back when the upload failed, so it can be retried.
	 */
	public static function release( string $id ): void {
		global $wpdb;
		$wpdb->update( Schema::table( 'uploads' ), [ 'status' => self::WAITING ], [ 'id' => $id, 'status' => self::UPLOADING ] );
	}

	/**
	 * Marks a claimed link used, with the attachment it created.
	 */
	public static function complete( string $id, int $attachment_id ): void {
		global $wpdb;
		$wpdb->update(
			Schema::table( 'uploads' ),
			[
				'status'        => self::UPLOADED,
				'attachment_id' => $attachment_id,
				'used_at'       => Tokens::utc( time() ),
			],
			[ 'id' => $id ],
			[ '%s', '%d', '%s' ],
			[ '%s' ]
		);
	}

	/**
	 * Marks unused links past their expiry as expired, recording each once
	 * in the audit log, and deletes links that expired more than a day ago.
	 * An upload in progress gets UPLOAD_GRACE before it counts as expired.
	 *
	 * @return int Links deleted.
	 */
	public static function prune(): int {
		global $wpdb;
		$table = Schema::table( 'uploads' );
		$now   = Tokens::utc( time() );

		$grace   = Tokens::utc( time() - self::UPLOAD_GRACE );
		$expired = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, client_id, user_id, status FROM {$table} WHERE ( status = %s AND expires_at <= %s ) OR ( status = %s AND expires_at <= %s )",
				self::WAITING,
				$now,
				self::UPLOADING,
				$grace
			)
		);
		foreach ( $expired as $row ) {
			$changed = $wpdb->query(
				$wpdb->prepare( "UPDATE {$table} SET status = %s WHERE id = %s AND status = %s", self::EXPIRED, $row->id, $row->status )
			);
			if ( 1 === (int) $changed ) {
				Audit_Log::record_for( (int) $row->user_id, (string) $row->client_id, 'upload-link', 'expired', '', null, 'expired' );
			}
		}

		return (int) $wpdb->query(
			$wpdb->prepare( "DELETE FROM {$table} WHERE expires_at < %s", Tokens::utc( time() - self::KEEP_AFTER_EXPIRY ) )
		);
	}

	private static function hash( string $token ): string {
		return hash( 'sha256', $token );
	}
}
