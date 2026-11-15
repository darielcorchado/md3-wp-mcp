<?php
/**
 * Connections tab.
 *
 * @var array{connections: array<array{client_id: string, client_name: string, user_id: int, scopes: string[], created_at: string, last_used_at: ?string}>} $view
 */

use MD3\WPMCP\Admin\Settings_Page;

defined( 'ABSPATH' ) || exit;
?>
<?php if ( ! $view['connections'] ) : ?>
	<p><?php esc_html_e( 'No AI client is connected.', 'md3-wp-mcp' ); ?></p>
<?php else : ?>
	<table class="widefat striped" style="margin-top:12px">
		<thead><tr>
			<th><?php esc_html_e( 'Client', 'md3-wp-mcp' ); ?></th>
			<th><?php esc_html_e( 'Access', 'md3-wp-mcp' ); ?></th>
			<th><?php esc_html_e( 'Connected', 'md3-wp-mcp' ); ?></th>
			<th><?php esc_html_e( 'Last used', 'md3-wp-mcp' ); ?></th>
			<th></th>
		</tr></thead>
		<tbody>
		<?php foreach ( $view['connections'] as $md3_connection ) : ?>
			<tr>
				<td><?php echo esc_html( $md3_connection['client_name'] ); ?></td>
				<td><?php echo esc_html( implode( ', ', $md3_connection['scopes'] ) ); ?></td>
				<td><?php echo esc_html( Settings_Page::format_time( $md3_connection['created_at'] ) ); ?></td>
				<td><?php echo esc_html( Settings_Page::format_time( $md3_connection['last_used_at'] ) ); ?></td>
				<td>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="md3_mcp_revoke">
						<input type="hidden" name="client_id" value="<?php echo esc_attr( $md3_connection['client_id'] ); ?>">
						<input type="hidden" name="user_id" value="<?php echo esc_attr( (string) $md3_connection['user_id'] ); ?>">
						<?php wp_nonce_field( 'md3_mcp_revoke' ); ?>
						<?php submit_button( __( 'Revoke', 'md3-wp-mcp' ), 'secondary small', 'submit', false ); ?>
					</form>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
<?php endif; ?>
