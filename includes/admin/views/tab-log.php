<?php
/**
 * Log tab.
 *
 * @var array{filters: array<string,string>, rows: object[], total: int, page: int, pages: int} $view
 */

use MD3\WPMCP\Admin\Settings_Page;

defined( 'ABSPATH' ) || exit;

$md3_filters = $view['filters'] + [
	'tool'      => '',
	'result'    => '',
	'client_id' => '',
];
?>
<form method="get" style="margin:12px 0">
	<input type="hidden" name="page" value="<?php echo esc_attr( Settings_Page::SLUG ); ?>">
	<input type="hidden" name="tab" value="log">
	<label class="screen-reader-text" for="md3-log-tool"><?php esc_html_e( 'Tool', 'md3-wp-mcp' ); ?></label>
	<input type="text" id="md3-log-tool" name="tool" placeholder="<?php esc_attr_e( 'Tool', 'md3-wp-mcp' ); ?>" value="<?php echo esc_attr( $md3_filters['tool'] ); ?>">
	<label class="screen-reader-text" for="md3-log-result"><?php esc_html_e( 'Result', 'md3-wp-mcp' ); ?></label>
	<select id="md3-log-result" name="result">
		<option value=""><?php esc_html_e( 'Any result', 'md3-wp-mcp' ); ?></option>
		<?php foreach ( [ 'ok', 'denied', 'error', 'expired' ] as $md3_result ) : ?>
			<option value="<?php echo esc_attr( $md3_result ); ?>" <?php selected( $md3_filters['result'], $md3_result ); ?>><?php echo esc_html( $md3_result ); ?></option>
		<?php endforeach; ?>
	</select>
	<label class="screen-reader-text" for="md3-log-client"><?php esc_html_e( 'Client ID', 'md3-wp-mcp' ); ?></label>
	<input type="text" id="md3-log-client" name="client_id" placeholder="<?php esc_attr_e( 'Client ID', 'md3-wp-mcp' ); ?>" value="<?php echo esc_attr( $md3_filters['client_id'] ); ?>">
	<?php submit_button( __( 'Filter', 'md3-wp-mcp' ), 'secondary', '', false ); ?>
</form>

<?php if ( ! $view['rows'] ) : ?>
	<p><?php esc_html_e( 'No log entries.', 'md3-wp-mcp' ); ?></p>
<?php else : ?>
	<table class="widefat striped">
		<thead><tr>
			<th><?php esc_html_e( 'Time', 'md3-wp-mcp' ); ?></th>
			<th><?php esc_html_e( 'User', 'md3-wp-mcp' ); ?></th>
			<th><?php esc_html_e( 'Client', 'md3-wp-mcp' ); ?></th>
			<th><?php esc_html_e( 'Tool', 'md3-wp-mcp' ); ?></th>
			<th><?php esc_html_e( 'Object', 'md3-wp-mcp' ); ?></th>
			<th><?php esc_html_e( 'Result', 'md3-wp-mcp' ); ?></th>
			<th><?php esc_html_e( 'Message', 'md3-wp-mcp' ); ?></th>
		</tr></thead>
		<tbody>
		<?php foreach ( $view['rows'] as $md3_row ) : ?>
			<tr>
				<td><?php echo esc_html( Settings_Page::format_time( $md3_row->created_at ) ); ?></td>
				<td><?php echo esc_html( (string) $md3_row->user_id ); ?></td>
				<td><?php echo esc_html( $md3_row->client_id ); ?></td>
				<td><?php echo esc_html( $md3_row->tool ); ?></td>
				<td><?php echo esc_html( trim( $md3_row->object_type . ' ' . ( null === $md3_row->object_id ? '' : '#' . $md3_row->object_id ) ) ); ?></td>
				<td><?php echo esc_html( $md3_row->result ); ?></td>
				<td><?php echo esc_html( $md3_row->message ); ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<?php
	$md3_links = paginate_links(
		[
			'base'      => add_query_arg( 'paged', '%#%', Settings_Page::tab_url( 'log' ) ),
			'format'    => '',
			'current'   => $view['page'],
			'total'     => $view['pages'],
			'add_args'  => array_filter( $view['filters'], static fn ( $value ) => '' !== $value ),
			'prev_text' => '&laquo;',
			'next_text' => '&raquo;',
		]
	);
	if ( $md3_links ) {
		echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post( $md3_links ) . '</div></div>';
	}
	?>
<?php endif; ?>
