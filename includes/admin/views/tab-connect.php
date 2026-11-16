<?php
/**
 * Connect tab: setup steps per client (design §9).
 *
 * @var array{url: string} $view
 */

defined( 'ABSPATH' ) || exit;

$md3_url     = $view['url'];
$md3_clients = [
	[ __( 'Claude (web, desktop, mobile)', 'md3-wp-mcp' ), __( 'Settings > Connectors > Add custom connector > paste the URL > Connect > sign in to WordPress and approve.', 'md3-wp-mcp' ) ],
	[ __( 'Claude Code', 'md3-wp-mcp' ), 'claude mcp add --transport http md3 ' . $md3_url . ' ' . __( '(then /mcp to sign in)', 'md3-wp-mcp' ) ],
	[ __( 'ChatGPT', 'md3-wp-mcp' ), __( 'Settings > Apps & Connectors > developer mode > create a connector with the URL, OAuth.', 'md3-wp-mcp' ) ],
	[ __( 'Gemini CLI', 'md3-wp-mcp' ), '"md3": { "httpUrl": "' . $md3_url . '" } ' . __( 'under mcpServers in settings.json, then /mcp auth md3.', 'md3-wp-mcp' ) ],
	[ __( 'Muse', 'md3-wp-mcp' ), __( 'Muse Code CLI: add a remote HTTP server with the URL and sign in with OAuth. The consumer Muse app still needs checking.', 'md3-wp-mcp' ) ],
];
?>
<h2><?php esc_html_e( 'MCP URL', 'md3-wp-mcp' ); ?></h2>
<p>
	<input type="text" readonly class="large-text code" id="md3-mcp-url" style="max-width:640px" value="<?php echo esc_attr( $md3_url ); ?>">
	<button type="button" class="button" id="md3-copy-url"><?php esc_html_e( 'Copy', 'md3-wp-mcp' ); ?></button>
	<span id="md3-copy-done" role="status" aria-live="polite"></span>
</p>
<table class="widefat striped" style="max-width:900px">
	<thead><tr>
		<th><?php esc_html_e( 'Client', 'md3-wp-mcp' ); ?></th>
		<th><?php esc_html_e( 'How', 'md3-wp-mcp' ); ?></th>
	</tr></thead>
	<tbody>
	<?php foreach ( $md3_clients as $md3_client ) : ?>
		<tr>
			<td><?php echo esc_html( $md3_client[0] ); ?></td>
			<td><?php echo esc_html( $md3_client[1] ); ?></td>
		</tr>
	<?php endforeach; ?>
	</tbody>
</table>
<p class="description"><?php esc_html_e( 'These screens change often. Check each client when you connect it.', 'md3-wp-mcp' ); ?></p>
<script>
document.getElementById('md3-copy-url').addEventListener('click', function () {
	var field = document.getElementById('md3-mcp-url');
	var done = function () { document.getElementById('md3-copy-done').textContent = <?php echo wp_json_encode( __( 'Copied.', 'md3-wp-mcp' ) ); ?>; };
	if (navigator.clipboard) { navigator.clipboard.writeText(field.value).then(done); return; }
	field.select(); document.execCommand('copy'); done();
});
</script>
