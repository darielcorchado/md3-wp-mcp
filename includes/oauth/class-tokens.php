<?php
namespace MD3\WPMCP\OAuth;

use MD3\WPMCP\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Connections (a client and a user with live tokens), revocation and pruning.
 */
final class Tokens {

	/**
	 * Revokes every code and token of one connection.
	 *
	 * @return int Access and refresh tokens newly revoked.
	 */
	public static function revoke_connection( string $client_id, int $user_id ): int {
		global $wpdb;
		$where = $wpdb->prepare( 'client_id = %s AND user_id = %d AND revoked = 0', $client_id, $user_id );
		return self::revoke_where( $where );
	}

	/**
	 * Revokes every code and token of every connection.
	 *
	 * @return int Access and refresh tokens newly revoked.
	 */
	public static function revoke_all(): int {
		return self::revoke_where( 'revoked = 0' );
	}

	/**
	 * Revokes one access token of a client, and the refresh token issued with
	 * it (RFC 7009). Not a rotation: `rotated_at` stays empty, so a later
	 * replay of the refresh token isn't taken for theft.
	 *
	 * @param string $access_hash SHA-256 of the access token's `jti`.
	 * @return int Tokens newly revoked.
	 */
	public static function revoke_by_access_hash( string $access_hash, string $client_id ): int {
		global $wpdb;
		$count  = (int) $wpdb->query(
			$wpdb->prepare( 'UPDATE ' . Schema::table( 'access_tokens' ) . ' SET revoked = 1 WHERE id_hash = %s AND client_id = %s AND revoked = 0', $access_hash, $client_id )
		);
		$count += (int) $wpdb->query(
			$wpdb->prepare( 'UPDATE ' . Schema::table( 'refresh_tokens' ) . ' SET revoked = 1 WHERE access_token_hash = %s AND client_id = %s AND revoked = 0', $access_hash, $client_id )
		);
		return $count;
	}

	/**
	 * Revokes one refresh token of a client, and the access token it was
	 * issued with (RFC 7009). Not a rotation (see revoke_by_access_hash()).
	 *
	 * @param string $refresh_hash SHA-256 of the refresh token's ID.
	 * @return int Tokens newly revoked.
	 */
	public static function revoke_by_refresh_hash( string $refresh_hash, string $client_id ): int {
		global $wpdb;
		$refresh = Schema::table( 'refresh_tokens' );

		$access_hash = $wpdb->get_var( $wpdb->prepare( "SELECT access_token_hash FROM {$refresh} WHERE id_hash = %s AND client_id = %s", $refresh_hash, $client_id ) );
		if ( null === $access_hash ) {
			return 0;
		}

		$count  = (int) $wpdb->query( $wpdb->prepare( "UPDATE {$refresh} SET revoked = 1 WHERE id_hash = %s AND revoked = 0", $refresh_hash ) );
		$count += (int) $wpdb->query(
			$wpdb->prepare( 'UPDATE ' . Schema::table( 'access_tokens' ) . ' SET revoked = 1 WHERE id_hash = %s AND client_id = %s AND revoked = 0', $access_hash, $client_id )
		);
		return $count;
	}

	/**
	 * Connections with a live access or refresh token, newest first.
	 *
	 * Scopes come from the connection's latest access token; `created_at` is
	 * its oldest stored access token.
	 *
	 * @return array<array{client_id: string, client_name: string, user_id: int, scopes: string[], created_at: string, last_used_at: ?string}>
	 */
	public static function connections( ?int $user_id = null ): array {
		global $wpdb;
		$access  = Schema::table( 'access_tokens' );
		$refresh = Schema::table( 'refresh_tokens' );
		$clients = Schema::table( 'clients' );
		$now     = self::utc( time() );
		$user    = null === $user_id ? '' : $wpdb->prepare( ' AND user_id = %d', $user_id );

		$pairs = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT client_id, user_id FROM {$refresh} WHERE revoked = 0 AND expires_at > %s{$user}
				UNION
				SELECT client_id, user_id FROM {$access} WHERE revoked = 0 AND expires_at > %s{$user}",
				$now,
				$now
			)
		);

		$connections = [];
		foreach ( $pairs as $pair ) {
			$stats  = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT MIN(created_at) AS created_at, MAX(last_used_at) AS last_used_at FROM {$access} WHERE client_id = %s AND user_id = %d",
					$pair->client_id,
					$pair->user_id
				)
			);
			$scopes = (string) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT scopes FROM {$access} WHERE client_id = %s AND user_id = %d ORDER BY created_at DESC LIMIT 1",
					$pair->client_id,
					$pair->user_id
				)
			);
			$name   = (string) $wpdb->get_var( $wpdb->prepare( "SELECT name FROM {$clients} WHERE client_id = %s", $pair->client_id ) );

			$connections[] = [
				'client_id'    => $pair->client_id,
				'client_name'  => '' !== $name ? $name : $pair->client_id,
				'user_id'      => (int) $pair->user_id,
				'scopes'       => '' === $scopes ? [] : explode( ' ', $scopes ),
				'created_at'   => (string) ( $stats->created_at ?? '' ),
				'last_used_at' => $stats->last_used_at ?? null,
			];
		}

		usort( $connections, static fn ( $a, $b ) => strcmp( $b['created_at'], $a['created_at'] ) );

		return $connections;
	}

	/**
	 * Deletes expired codes and tokens, and unused clients (prune_clients()).
	 *
	 * Rotated refresh tokens stay until they expire, for reuse detection. An
	 * expired access token stays while an unexpired refresh token points to
	 * it, so the connection keeps its scopes.
	 *
	 * @return int Rows deleted.
	 */
	public static function prune(): int {
		global $wpdb;
		$codes   = Schema::table( 'auth_codes' );
		$access  = Schema::table( 'access_tokens' );
		$refresh = Schema::table( 'refresh_tokens' );
		$clients = Schema::table( 'clients' );
		$now     = self::utc( time() );

		$deleted  = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$codes} WHERE expires_at < %s", $now ) );
		$deleted += (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$refresh} WHERE expires_at < %s", $now ) );
		$deleted += (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE a FROM {$access} a
				LEFT JOIN {$refresh} r ON r.access_token_hash = a.id_hash
				WHERE a.expires_at < %s AND r.id_hash IS NULL",
				$now
			)
		);
		$deleted += self::prune_clients();

		return $deleted;
	}

	/**
	 * Deletes clients that never finished sign-in within 24 h, and finished
	 * DCR or CIMD clients older than a day with no live code or token, so
	 * the DCR cap only counts clients in use.
	 *
	 * @return int Clients deleted.
	 */
	public static function prune_clients(): int {
		global $wpdb;
		$codes   = Schema::table( 'auth_codes' );
		$access  = Schema::table( 'access_tokens' );
		$refresh = Schema::table( 'refresh_tokens' );
		$clients = Schema::table( 'clients' );
		$now     = self::utc( time() );
		$day_ago = self::utc( time() - DAY_IN_SECONDS );

		$deleted  = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$clients} WHERE completed = 0 AND created_at < %s", $day_ago ) );
		$deleted += (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$clients} WHERE completed = 1 AND created_at < %s
				AND NOT EXISTS ( SELECT 1 FROM {$access} a WHERE a.client_id = {$clients}.client_id AND a.revoked = 0 AND a.expires_at > %s )
				AND NOT EXISTS ( SELECT 1 FROM {$refresh} r WHERE r.client_id = {$clients}.client_id AND r.revoked = 0 AND r.expires_at > %s )
				AND NOT EXISTS ( SELECT 1 FROM {$codes} c WHERE c.client_id = {$clients}.client_id AND c.revoked = 0 AND c.expires_at > %s )",
				$day_ago,
				$now,
				$now,
				$now
			)
		);

		return $deleted;
	}

	/**
	 * A timestamp as a UTC DATETIME string, the format of every table.
	 */
	public static function utc( int $timestamp ): string {
		return gmdate( 'Y-m-d H:i:s', $timestamp );
	}

	/**
	 * @param string $where Prepared condition on client_id, user_id and revoked.
	 */
	private static function revoke_where( string $where ): int {
		global $wpdb;
		$wpdb->query( 'UPDATE ' . Schema::table( 'auth_codes' ) . " SET revoked = 1 WHERE {$where}" );
		$count  = (int) $wpdb->query( 'UPDATE ' . Schema::table( 'access_tokens' ) . " SET revoked = 1 WHERE {$where}" );
		$count += (int) $wpdb->query( 'UPDATE ' . Schema::table( 'refresh_tokens' ) . " SET revoked = 1 WHERE {$where}" );
		return $count;
	}
}
