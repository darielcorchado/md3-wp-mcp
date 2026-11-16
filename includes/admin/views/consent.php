<?php
/**
 * Consent page of the authorize endpoint: a standalone page, no theme.
 *
 * @var array{client_name: string, redirect_label: string, scopes: array<array{id: string, label: string, checked: bool, disabled: bool}>, token: string, nonce_action: string, action_url: string, user_login: string, site_name: string} $view
 */

defined( 'ABSPATH' ) || exit;
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php echo esc_attr( get_option( 'blog_charset' ) ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="referrer" content="no-referrer">
<title><?php esc_html_e( 'Connect an AI client', 'md3-wp-mcp' ); ?> &lsaquo; <?php echo esc_html( $view['site_name'] ); ?></title>
<style>
	:root { color-scheme: light dark; --bg: #f0f0f1; --card: #fff; --text: #1d2327; --muted: #50575e; --border: #dcdcde; --accent: #2271b1; }
	@media (prefers-color-scheme: dark) { :root { --bg: #1d2327; --card: #2c3338; --text: #f0f0f1; --muted: #c3c4c7; --border: #50575e; --accent: #72aee6; } }
	* { box-sizing: border-box; }
	body { margin: 0; padding: 48px 16px; background: var(--bg); color: var(--text); font: 15px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
	main { max-width: 440px; margin: 0 auto; background: var(--card); border: 1px solid var(--border); border-radius: 8px; padding: 28px; }
	h1 { font-size: 20px; margin: 0 0 8px; }
	p { margin: 0 0 16px; color: var(--muted); }
	.client { color: var(--text); font-weight: 600; overflow-wrap: anywhere; }
	fieldset { border: 1px solid var(--border); border-radius: 6px; margin: 0 0 20px; padding: 12px 16px; }
	legend { padding: 0 4px; font-weight: 600; }
	label { display: flex; gap: 10px; align-items: flex-start; padding: 6px 0; }
	input[type="checkbox"] { margin-top: 4px; }
	.actions { display: flex; gap: 12px; }
	button { flex: 1; font: inherit; padding: 10px 16px; border-radius: 6px; border: 1px solid var(--accent); cursor: pointer; }
	.approve { background: var(--accent); color: #fff; }
	.deny { background: transparent; color: var(--accent); }
	.account { font-size: 13px; margin: 20px 0 0; }
</style>
</head>
<body>
<main>
	<h1><?php esc_html_e( 'Connect an AI client', 'md3-wp-mcp' ); ?></h1>
	<p>
		<?php
		printf(
			/* translators: 1: client name, 2: site name. */
			esc_html__( '%1$s wants to work with the content of %2$s.', 'md3-wp-mcp' ),
			'<span class="client">' . esc_html( $view['client_name'] ) . '</span>',
			'<span class="client">' . esc_html( $view['site_name'] ) . '</span>'
		);
		?>
	</p>
	<p>
		<?php
		printf(
			/* translators: %s: host (or app scheme) that receives the authorization. */
			esc_html__( 'If you approve, you will be sent back to %s.', 'md3-wp-mcp' ),
			'<span class="client">' . esc_html( $view['redirect_label'] ) . '</span>'
		);
		?>
		<?php esc_html_e( 'Only approve if you started this connection yourself.', 'md3-wp-mcp' ); ?>
	</p>

	<form method="post" action="<?php echo esc_url( $view['action_url'] ); ?>">
		<fieldset>
			<legend><?php esc_html_e( 'Allow it to', 'md3-wp-mcp' ); ?></legend>
			<?php foreach ( $view['scopes'] as $scope ) : ?>
				<label>
					<input type="checkbox" name="scopes[]" value="<?php echo esc_attr( $scope['id'] ); ?>"<?php checked( $scope['checked'] ); ?><?php disabled( $scope['disabled'] ); ?>>
					<span><?php echo esc_html( $scope['label'] ); ?></span>
				</label>
			<?php endforeach; ?>
		</fieldset>

		<input type="hidden" name="md3_mcp_token" value="<?php echo esc_attr( $view['token'] ); ?>">
		<?php wp_nonce_field( $view['nonce_action'], '_wpnonce', false ); ?>

		<div class="actions">
			<button type="submit" name="decision" value="approve" class="approve"><?php esc_html_e( 'Approve', 'md3-wp-mcp' ); ?></button>
			<button type="submit" name="decision" value="deny" class="deny"><?php esc_html_e( 'Deny', 'md3-wp-mcp' ); ?></button>
		</div>
	</form>

	<p class="account">
		<?php
		printf(
			/* translators: %s: login name of the signed-in user. */
			esc_html__( 'Signed in as %s. The connection will act as this user.', 'md3-wp-mcp' ),
			'<strong>' . esc_html( $view['user_login'] ) . '</strong>'
		);
		?>
	</p>
</main>
</body>
</html>
