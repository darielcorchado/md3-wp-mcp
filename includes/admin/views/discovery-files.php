<?php
/**
 * Discovery files box at the end of the Security tab (MD3-32): the two
 * discovery documents as files to upload, for web servers that never pass
 * `/.well-known/` to WordPress.
 *
 * @var array{discovery_files: array{show: bool, reason: string, zip: string, files: array<array{path: string, url: string, download: string}>, show_url: string}} $view
 */

defined( 'ABSPATH' ) || exit;

$md3_files = $view['discovery_files'];
$md3_code  = [ 'code' => [] ];

if ( ! $md3_files['show'] && '' === $md3_files['show_url'] ) {
	return;
}

if ( ! $md3_files['show'] ) : ?>
	<p>
		<a href="<?php echo esc_url( $md3_files['show_url'] ); ?>"><?php esc_html_e( 'Show the discovery files anyway', 'md3-wp-mcp' ); ?></a>
		<span class="description"><?php esc_html_e( '(for hosts where these checks cannot run)', 'md3-wp-mcp' ); ?></span>
	</p>
	<?php
	return;
endif;

$md3_intro = [
	'missing' => __( 'Your web server does not pass /.well-known/ to WordPress, so AI clients cannot find the sign-in server. Upload these two files and the web server will serve them itself. The plugin never writes files on your server.', 'md3-wp-mcp' ),
	'stale'   => __( 'The discovery documents your site returns are out of date. If you uploaded the files, download them again and replace the old ones. If you did not, a page cache or CDN serves an old copy: purge it and click "Check again".', 'md3-wp-mcp' ),
	'asked'   => __( 'If your web server does not pass /.well-known/ to WordPress, upload these two files and the web server will serve them itself. The plugin never writes files on your server.', 'md3-wp-mcp' ),
][ $md3_files['reason'] ] ?? '';
?>
<div id="md3-discovery-files" class="card" style="max-width:900px">
	<h2><?php esc_html_e( 'Discovery files', 'md3-wp-mcp' ); ?></h2>
	<p><?php echo esc_html( $md3_intro ); ?></p>
	<p><a class="button button-primary" href="<?php echo esc_url( $md3_files['zip'] ); ?>"><?php esc_html_e( 'Download the ZIP', 'md3-wp-mcp' ); ?></a></p>
	<p><?php esc_html_e( 'Or each file on its own:', 'md3-wp-mcp' ); ?></p>
	<ul>
		<?php foreach ( $md3_files['files'] as $md3_file ) : ?>
			<li><a href="<?php echo esc_url( $md3_file['download'] ); ?>"><code><?php echo esc_html( $md3_file['path'] ); ?></code></a></li>
		<?php endforeach; ?>
	</ul>

	<h3><?php esc_html_e( 'How to install them', 'md3-wp-mcp' ); ?></h3>
	<ol>
		<li>
			<?php
			echo wp_kses(
				sprintf(
					/* translators: 1: htdocs, 2: public_html, 3: web (folder names, in code tags). */
					__( 'Extract the ZIP in the folder that your domain\'s root address serves: the web root, such as %1$s or %2$s, or %3$s on Bedrock. That is not always the WordPress folder. Or upload the two files by FTP to the same paths.', 'md3-wp-mcp' ),
					'<code>htdocs</code>',
					'<code>public_html</code>',
					'<code>web</code>'
				),
				$md3_code
			);
			?>
		</li>
		<li>
			<?php
			echo wp_kses(
				sprintf(
					/* translators: %s: .well-known (folder name, in code tags). */
					__( '%s is a hidden folder and the files have no extension. That is expected; do not rename them.', 'md3-wp-mcp' ),
					'<code>.well-known</code>'
				),
				$md3_code
			);
			?>
		</li>
		<li>
			<?php esc_html_e( 'Each file must open at this exact address:', 'md3-wp-mcp' ); ?>
			<ul>
				<?php foreach ( $md3_files['files'] as $md3_file ) : ?>
					<li><a href="<?php echo esc_url( $md3_file['url'] ); ?>" target="_blank" rel="noopener noreferrer"><code><?php echo esc_html( $md3_file['url'] ); ?></code></a></li>
				<?php endforeach; ?>
			</ul>
		</li>
		<li><?php esc_html_e( 'Click "Check again".', 'md3-wp-mcp' ); ?></li>
		<li>
			<?php
			echo wp_kses(
				sprintf(
					/* translators: %s: .well-known (folder name, in code tags). */
					__( 'Download them again after changing the site address or Automatic registration, and after updating the plugin. When you uninstall the plugin, delete these two files, not the whole %s folder (Let\'s Encrypt uses it).', 'md3-wp-mcp' ),
					'<code>.well-known</code>'
				),
				$md3_code
			);
			?>
		</li>
	</ol>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="md3_mcp_run_diagnostics">
		<?php wp_nonce_field( 'md3_mcp_run_diagnostics', '_wpnonce', true, true ); ?>
		<?php submit_button( __( 'Check again', 'md3-wp-mcp' ), 'secondary', 'submit', false ); ?>
	</form>
</div>
