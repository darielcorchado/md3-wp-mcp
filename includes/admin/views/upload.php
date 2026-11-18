<?php
/**
 * The one-time upload page: a standalone page, no theme, no scripts.
 *
 * @var array{done: bool, error: string, nonce_action: string, expires_at: int, site_name: string, max_mb: int} $view
 */

defined( 'ABSPATH' ) || exit;
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php echo esc_attr( get_option( 'blog_charset' ) ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="referrer" content="no-referrer">
<title><?php esc_html_e( 'Upload an image', 'md3-wp-mcp' ); ?> &lsaquo; <?php echo esc_html( $view['site_name'] ); ?></title>
<style>
	:root { color-scheme: light dark; --bg: #f0f0f1; --card: #fff; --text: #1d2327; --muted: #50575e; --border: #dcdcde; --accent: #2271b1; --error: #d63638; }
	@media (prefers-color-scheme: dark) { :root { --bg: #1d2327; --card: #2c3338; --text: #f0f0f1; --muted: #c3c4c7; --border: #50575e; --accent: #72aee6; --error: #f86368; } }
	* { box-sizing: border-box; }
	body { margin: 0; padding: 48px 16px; background: var(--bg); color: var(--text); font: 15px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
	main { max-width: 440px; margin: 0 auto; background: var(--card); border: 1px solid var(--border); border-radius: 8px; padding: 28px; }
	h1 { font-size: 20px; margin: 0 0 8px; }
	p { margin: 0 0 16px; color: var(--muted); }
	.error { color: var(--error); font-weight: 600; }
	label.drop { display: block; border: 2px dashed var(--border); border-radius: 8px; padding: 24px 16px; margin: 0 0 20px; text-align: center; cursor: pointer; }
	label.drop span { display: block; margin: 0 0 12px; color: var(--text); }
	input[type="file"] { max-width: 100%; font: inherit; color: var(--muted); }
	button { width: 100%; font: inherit; padding: 10px 16px; border-radius: 6px; border: 1px solid var(--accent); background: var(--accent); color: #fff; cursor: pointer; }
	.small { font-size: 13px; margin: 20px 0 0; }
</style>
</head>
<body>
<main>
<?php if ( $view['done'] ) : ?>
	<h1><?php esc_html_e( 'Image uploaded', 'md3-wp-mcp' ); ?></h1>
	<p><?php esc_html_e( 'You can close this tab and go back to the conversation. Tell the AI the image is uploaded.', 'md3-wp-mcp' ); ?></p>
<?php else : ?>
	<h1><?php esc_html_e( 'Upload an image', 'md3-wp-mcp' ); ?></h1>
	<p>
		<?php
		printf(
			/* translators: 1: site name, 2: maximum file size in MB. */
			esc_html__( 'The image is added to the media library of %1$s for your AI conversation. JPEG, PNG, WebP or GIF, up to %2$d MB.', 'md3-wp-mcp' ),
			esc_html( $view['site_name'] ),
			(int) $view['max_mb']
		);
		?>
	</p>
	<?php if ( '' !== $view['error'] ) : ?>
		<p class="error" role="alert"><?php echo esc_html( $view['error'] ); ?></p>
	<?php endif; ?>

	<form method="post" enctype="multipart/form-data">
		<label class="drop">
			<span><?php esc_html_e( 'Drop an image here, or choose a file.', 'md3-wp-mcp' ); ?></span>
			<input type="file" name="file" accept="image/jpeg,image/png,image/webp,image/gif" required>
		</label>
		<?php wp_nonce_field( $view['nonce_action'], '_wpnonce', false ); ?>
		<button type="submit"><?php esc_html_e( 'Upload', 'md3-wp-mcp' ); ?></button>
	</form>

	<p class="small">
		<?php
		printf(
			/* translators: %s: time the link expires. */
			esc_html__( 'This link works once and expires at %s.', 'md3-wp-mcp' ),
			esc_html( wp_date( (string) get_option( 'time_format' ), (int) $view['expires_at'] ) )
		);
		?>
	</p>
<?php endif; ?>
</main>
</body>
</html>
