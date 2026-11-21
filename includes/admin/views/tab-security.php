<?php
/**
 * Security tab.
 *
 * @var array{security: array{mcp_user_id: int, dcr: bool, mcp_rate_limit: int}, editors: \WP_User[], diagnostics: ?array<array{check: string, ok: bool, detail: string, fix?: string}>, discovery_files: array<string,mixed>} $view
 */

defined( 'ABSPATH' ) || exit;

$md3_security = $view['security'];
?>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
	<input type="hidden" name="action" value="md3_mcp_save_security">
	<?php wp_nonce_field( 'md3_mcp_save_security' ); ?>

	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><label for="md3-mcp-user"><?php esc_html_e( 'MCP user', 'md3-wp-mcp' ); ?></label></th>
			<td>
				<select name="mcp_user_id" id="md3-mcp-user">
					<option value="0"><?php esc_html_e( '— Not set —', 'md3-wp-mcp' ); ?></option>
					<?php foreach ( $view['editors'] as $user ) : ?>
						<option value="<?php echo esc_attr( (string) $user->ID ); ?>" <?php selected( $user->ID, $md3_security['mcp_user_id'] ); ?>>
							<?php echo esc_html( $user->display_name . ' (' . $user->user_login . ')' ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<p class="description"><?php esc_html_e( 'The only account AI clients can act as. Editors only; administrators are never allowed.', 'md3-wp-mcp' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Automatic registration', 'md3-wp-mcp' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="dcr" value="1" <?php checked( $md3_security['dcr'] ); ?>>
					<?php esc_html_e( 'Let AI clients register themselves (dynamic client registration)', 'md3-wp-mcp' ); ?>
				</label>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="md3-rate-limit"><?php esc_html_e( 'MCP rate limit', 'md3-wp-mcp' ); ?></label></th>
			<td>
				<input type="number" name="mcp_rate_limit" id="md3-rate-limit" min="1" max="1000" value="<?php echo esc_attr( (string) $md3_security['mcp_rate_limit'] ); ?>" class="small-text">
				<?php esc_html_e( 'requests per minute per access token (1 to 1000)', 'md3-wp-mcp' ); ?>
			</td>
		</tr>
	</table>
	<?php submit_button(); ?>
</form>

<h2><?php esc_html_e( 'Keys and connections', 'md3-wp-mcp' ); ?></h2>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:12px">
	<input type="hidden" name="action" value="md3_mcp_rotate_keys">
	<?php wp_nonce_field( 'md3_mcp_rotate_keys' ); ?>
	<?php submit_button( __( 'Rotate keys', 'md3-wp-mcp' ), 'secondary', 'submit', false, [ 'onclick' => 'return confirm(' . wp_json_encode( __( 'Rotating the keys signs every AI client out. Continue?', 'md3-wp-mcp' ) ) . ');' ] ); ?>
</form>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block">
	<input type="hidden" name="action" value="md3_mcp_revoke_all">
	<?php wp_nonce_field( 'md3_mcp_revoke_all' ); ?>
	<?php submit_button( __( 'Revoke all connections', 'md3-wp-mcp' ), 'secondary', 'submit', false, [ 'onclick' => 'return confirm(' . wp_json_encode( __( 'Revoke every connection?', 'md3-wp-mcp' ) ) . ');' ] ); ?>
</form>

<h2><?php esc_html_e( 'Diagnostics', 'md3-wp-mcp' ); ?></h2>
<p><?php esc_html_e( 'Checks that the web server and any page cache let the sign-in flow through. The site requests itself.', 'md3-wp-mcp' ); ?></p>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
	<input type="hidden" name="action" value="md3_mcp_run_diagnostics">
	<?php wp_nonce_field( 'md3_mcp_run_diagnostics' ); ?>
	<?php submit_button( __( 'Run checks', 'md3-wp-mcp' ), 'secondary', 'submit', false ); ?>
</form>
<?php if ( null !== $view['diagnostics'] ) : ?>
	<table class="widefat striped" style="max-width:900px;margin-top:12px">
		<thead><tr>
			<th><?php esc_html_e( 'Check', 'md3-wp-mcp' ); ?></th>
			<th><?php esc_html_e( 'Result', 'md3-wp-mcp' ); ?></th>
			<th><?php esc_html_e( 'Detail', 'md3-wp-mcp' ); ?></th>
		</tr></thead>
		<tbody>
		<?php foreach ( $view['diagnostics'] as $md3_row ) : ?>
			<tr>
				<td><?php echo esc_html( $md3_row['check'] ); ?></td>
				<td><?php echo $md3_row['ok'] ? esc_html__( 'OK', 'md3-wp-mcp' ) : '<strong>' . esc_html__( 'Failed', 'md3-wp-mcp' ) . '</strong>'; ?></td>
				<td><?php echo esc_html( $md3_row['detail'] ); ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
<?php endif; ?>
<?php require __DIR__ . '/discovery-files.php'; ?>
