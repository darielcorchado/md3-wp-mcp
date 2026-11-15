<?php
/**
 * Content tab.
 *
 * @var array{types: array<string,\WP_Post_Type>, enabled: string[], authors: \WP_User[], author: int, author_broken: bool} $view
 */

defined( 'ABSPATH' ) || exit;
?>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
	<input type="hidden" name="action" value="md3_mcp_save_content">
	<?php wp_nonce_field( 'md3_mcp_save_content' ); ?>

	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><?php esc_html_e( 'Post types', 'md3-wp-mcp' ); ?></th>
			<td>
				<fieldset>
					<legend class="screen-reader-text"><?php esc_html_e( 'Post types AI clients may work with', 'md3-wp-mcp' ); ?></legend>
					<?php foreach ( $view['types'] as $slug => $type ) : ?>
						<label style="display:block">
							<input type="checkbox" name="post_types[]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, $view['enabled'], true ) ); ?>>
							<?php echo esc_html( $type->labels->name ); ?> <code><?php echo esc_html( $slug ); ?></code>
						</label>
					<?php endforeach; ?>
				</fieldset>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="md3-default-author"><?php esc_html_e( 'Author for new content', 'md3-wp-mcp' ); ?></label></th>
			<td>
				<select name="default_author" id="md3-default-author">
					<option value="0" <?php selected( 0, $view['author'] ); ?>><?php esc_html_e( 'The MCP user', 'md3-wp-mcp' ); ?></option>
					<?php foreach ( $view['authors'] as $user ) : ?>
						<option value="<?php echo esc_attr( (string) $user->ID ); ?>" <?php selected( $user->ID, $view['author'] ); ?>>
							<?php echo esc_html( $user->display_name . ' (' . $user->user_login . ')' ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<?php if ( $view['author_broken'] ) : ?>
					<p class="description" style="color:#b32d2e"><?php esc_html_e( 'The saved author no longer exists or cannot edit posts, so new content falls back to the MCP user.', 'md3-wp-mcp' ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
	</table>
	<?php submit_button(); ?>
</form>
