<?php
namespace MD3\WPMCP;

defined( 'ABSPATH' ) || exit;

/**
 * Fixed-window rate limiter backed by transients.
 */
final class Rate_Limiter {

	/**
	 * Counts a hit and reports whether it is within the limit.
	 */
	public static function hit( string $bucket, string $key, int $limit, int $window = 60 ): bool {
		$window = max( 1, $window );
		$start  = (int) ( floor( time() / $window ) * $window );
		$name   = 'md3_mcp_rl_' . $bucket . '_' . md5( $key ) . $start;

		$count = (int) get_transient( $name ) + 1;
		set_transient( $name, $count, $window );

		return $count <= $limit;
	}
}
