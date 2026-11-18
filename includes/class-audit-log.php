<?php
namespace MD3\WPMCP;

use MD3\WPMCP\Auth\Request_Context;

defined( 'ABSPATH' ) || exit;

/**
 * Audit trail of tool calls.
 */
final class Audit_Log {

	public static function record( string $tool, string $result, string $object_type = '', ?int $object_id = null, string $message = '' ): void {
		$identity = Request_Context::get();
		self::record_for(
			$identity ? $identity->user_id : get_current_user_id(),
			$identity ? $identity->client_id : '',
			$tool,
			$result,
			$object_type,
			$object_id,
			$message
		);
	}

	/**
	 * Records an event of a given connection, outside an MCP request (the
	 * upload page, the daily cleanup).
	 */
	public static function record_for( int $user_id, string $client_id, string $tool, string $result, string $object_type = '', ?int $object_id = null, string $message = '' ): void {
		global $wpdb;

		$wpdb->insert(
			Schema::table( 'log' ),
			[
				'created_at'  => gmdate( 'Y-m-d H:i:s' ),
				'user_id'     => $user_id,
				'client_id'   => substr( $client_id, 0, 191 ),
				'tool'        => substr( $tool, 0, 64 ),
				'object_type' => substr( $object_type, 0, 32 ),
				'object_id'   => $object_id,
				'result'      => substr( $result, 0, 8 ),
				'message'     => substr( $message, 0, 255 ),
				'ip_hash'     => Plugin::client_ip_hash(),
			]
		);
	}

	/**
	 * @param array{tool?:string,result?:string,user_id?:int,client_id?:string} $filters
	 * @return array{rows: array, total: int}
	 */
	public static function query( array $filters, int $page = 1, int $per_page = 50 ): array {
		global $wpdb;

		$table = Schema::table( 'log' );
		$where = [];
		$args  = [];

		foreach ( [ 'tool', 'result', 'client_id' ] as $field ) {
			if ( isset( $filters[ $field ] ) && '' !== $filters[ $field ] ) {
				$where[] = "{$field} = %s";
				$args[]  = (string) $filters[ $field ];
			}
		}
		if ( ! empty( $filters['user_id'] ) ) {
			$where[] = 'user_id = %d';
			$args[]  = (int) $filters['user_id'];
		}

		$clause   = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';
		$per_page = max( 1, $per_page );
		$offset   = ( max( 1, $page ) - 1 ) * $per_page;

		$count_sql = "SELECT COUNT(*) FROM {$table} {$clause}";
		$rows_sql  = "SELECT * FROM {$table} {$clause} ORDER BY id DESC LIMIT %d OFFSET %d";

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		$total = (int) ( $args ? $wpdb->get_var( $wpdb->prepare( $count_sql, $args ) ) : $wpdb->get_var( $count_sql ) );
		$rows  = $wpdb->get_results( $wpdb->prepare( $rows_sql, array_merge( $args, [ $per_page, $offset ] ) ) );
		// phpcs:enable

		return [
			'rows'  => $rows ?: [],
			'total' => $total,
		];
	}

	/**
	 * Deletes rows older than the given number of days.
	 *
	 * @return int Rows deleted.
	 */
	public static function prune( int $days = 90 ): int {
		global $wpdb;

		$table  = Schema::table( 'log' );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $cutoff ) );
	}
}
