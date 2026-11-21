<?php
namespace MD3\WPMCP\Admin;

use MD3\WPMCP\OAuth\Discovery;

defined( 'ABSPATH' ) || exit;

/**
 * The discovery documents as files for an administrator to upload, for hosts
 * whose web server never passes `/.well-known/` to WordPress (MD3-32).
 *
 * The plugin never writes a file: the ZIP is built in memory and sent.
 */
final class Discovery_Files {

	const ACTION   = 'md3_mcp_download_discovery';
	const ZIP_NAME = 'md3-wp-mcp-discovery-files.zip';

	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION, [ self::class, 'handle' ] );
	}

	/**
	 * Download link for `zip`, `resource` or `server`, with its nonce.
	 * Not escaped: print it through esc_url().
	 */
	public static function url( string $which ): string {
		return add_query_arg(
			[
				'action'   => self::ACTION,
				'file'     => $which,
				'_wpnonce' => wp_create_nonce( self::ACTION ),
			],
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * `admin_post_md3_mcp_download_discovery`: sends one download and exits.
	 */
	public static function handle(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'md3-wp-mcp' ), '', [ 'response' => 403 ] );
		}
		check_admin_referer( self::ACTION );

		$which    = isset( $_GET['file'] ) ? sanitize_key( wp_unslash( $_GET['file'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked above.
		$download = self::download( $which );
		if ( null === $download ) {
			wp_die( esc_html__( 'Unknown file.', 'md3-wp-mcp' ), '', [ 'response' => 400 ] );
		}

		nocache_headers();
		header( 'Content-Type: ' . $download['type'] );
		header( 'Content-Disposition: attachment; filename="' . $download['name'] . '"' );
		header( 'Cache-Control: no-store' );
		header( 'X-Content-Type-Options: nosniff' );
		echo $download['body']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a file download.
		exit;
	}

	/**
	 * One download: the ZIP, or one file as octet-stream so browsers don't add `.json`.
	 *
	 * @return array{name: string, type: string, body: string}|null Null for an unknown name.
	 */
	public static function download( string $which ): ?array {
		$files = Discovery::static_files();
		$paths = array_keys( $files );

		if ( 'zip' === $which ) {
			return [
				'name' => self::ZIP_NAME,
				'type' => 'application/zip',
				'body' => self::zip( $files ),
			];
		}

		$index = [
			'resource' => 0,
			'server'   => 1,
		][ $which ] ?? null;
		if ( null === $index ) {
			return null;
		}

		return [
			'name' => basename( $paths[ $index ] ),
			'type' => 'application/octet-stream',
			'body' => $files[ $paths[ $index ] ],
		];
	}

	/**
	 * A stored (uncompressed) ZIP built in memory: an entry for each folder,
	 * then each file.
	 *
	 * @param array<string,string> $files Relative path => content.
	 */
	public static function zip( array $files ): string {
		$entries = [];
		foreach ( $files as $path => $content ) {
			$folders = explode( '/', $path );
			array_pop( $folders );
			$prefix = '';
			foreach ( $folders as $folder ) {
				$prefix .= $folder . '/';
				$entries[ $prefix ] ??= '';
			}
			$entries[ $path ] = $content;
		}

		$now  = getdate();
		$time = ( $now['hours'] << 11 ) | ( $now['minutes'] << 5 ) | intdiv( $now['seconds'], 2 );
		$date = ( ( $now['year'] - 1980 ) << 9 ) | ( $now['mon'] << 5 ) | $now['mday'];

		$data    = '';
		$central = '';
		foreach ( $entries as $name => $content ) {
			$name   = (string) $name;
			$is_dir = str_ends_with( $name, '/' );
			$crc    = crc32( $content );
			$size   = strlen( $content );
			$offset = strlen( $data );
			$common = pack( 'vvvvvVVVv', 20, 0, 0, $time, $date, $crc, $size, $size, strlen( $name ) );

			$data    .= pack( 'V', 0x04034b50 ) . $common . pack( 'v', 0 ) . $name . $content;
			$central .= pack( 'Vv', 0x02014b50, 20 ) . $common . pack( 'vvvvVV', 0, 0, 0, 0, $is_dir ? 0x10 : 0, $offset ) . $name;
		}

		$count = count( $entries );
		return $data . $central . pack( 'VvvvvVVv', 0x06054b50, 0, 0, $count, $count, strlen( $central ), strlen( $data ), 0 );
	}
}
