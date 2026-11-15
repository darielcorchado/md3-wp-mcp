<?php
namespace MD3\WPMCP\Admin;

use MD3\WPMCP\OAuth\Tokens;
use MD3\WPMCP\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The MCP user's own connections, on their profile screen.
 */
final class Profile_Connections {

	public static function register(): void {
		add_action( 'show_user_profile', [ self::class, 'render' ] );
	}

	/**
	 * @param \WP_User $user The profile being shown.
	 */
	public static function render( $user ): void {
		if ( ! $user instanceof \WP_User || 0 === Settings::mcp_user_id() || Settings::mcp_user_id() !== (int) $user->ID ) {
			return;
		}

		$connections = Tokens::connections( (int) $user->ID );

		echo '<h2>' . esc_html__( 'AI connections', 'md3-wp-mcp' ) . '</h2>';
		if ( ! $connections ) {
			echo '<p>' . esc_html__( 'No AI client is connected to this account.', 'md3-wp-mcp' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped" style="max-width:800px"><thead><tr>';
		foreach ( [ __( 'Client', 'md3-wp-mcp' ), __( 'Access', 'md3-wp-mcp' ), __( 'Last used', 'md3-wp-mcp' ), '' ] as $heading ) {
			echo '<th>' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $connections as $connection ) {
			// A link, not a form: the profile page is itself one form and forms cannot nest.
			$url = wp_nonce_url(
				add_query_arg(
					[
						'action'    => 'md3_mcp_revoke',
						'client_id' => $connection['client_id'],
						'user_id'   => $connection['user_id'],
						'return'    => 'profile',
					],
					admin_url( 'admin-post.php' )
				),
				'md3_mcp_revoke'
			);
			echo '<tr><td>' . esc_html( $connection['client_name'] ) . '</td>';
			echo '<td>' . esc_html( implode( ', ', $connection['scopes'] ) ) . '</td>';
			echo '<td>' . esc_html( Settings_Page::format_time( $connection['last_used_at'] ) ) . '</td>';
			echo '<td><a class="button" href="' . esc_url( $url ) . '">' . esc_html__( 'Revoke', 'md3-wp-mcp' ) . '</a></td></tr>';
		}
		echo '</tbody></table>';
	}
}
