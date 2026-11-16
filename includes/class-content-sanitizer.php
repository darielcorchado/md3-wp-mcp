<?php
namespace MD3\WPMCP;

defined( 'ABSPATH' ) || exit;

/**
 * Sanitizers for content coming from MCP clients.
 */
final class Content_Sanitizer {

	/**
	 * Always applies wp_kses_post, regardless of the user's unfiltered_html.
	 */
	public static function html( string $html ): string {
		return wp_kses_post( $html );
	}

	public static function plain( string $text ): string {
		return trim( wp_strip_all_tags( $text ) );
	}

	public static function slug( string $s ): string {
		return sanitize_title( $s );
	}
}
