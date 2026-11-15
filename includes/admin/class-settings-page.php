<?php
namespace MD3\WPMCP\Admin;

use MD3\WPMCP\OAuth\Keys;
use MD3\WPMCP\OAuth\Tokens;
use MD3\WPMCP\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Settings > MD3 WP MCP: the five tabs and their admin-post handlers (design §6).
 */
final class Settings_Page {

	const SLUG        = 'md3-wp-mcp';
	const LOG_PER_PAGE = 50;

	/** Action name => handler method. Each action has its own nonce, named after the action. */
	const ACTIONS = [
		'md3_mcp_save_content'    => 'save_content',
		'md3_mcp_save_security'   => 'save_security',
		'md3_mcp_revoke'          => 'revoke',
		'md3_mcp_revoke_all'      => 'revoke_all',
		'md3_mcp_rotate_keys'     => 'rotate_keys',
		'md3_mcp_run_diagnostics' => 'run_diagnostics',
	];

	public static function register(): void {
		add_action( 'admin_menu', [ self::class, 'add_menu' ] );
		foreach ( array_keys( self::ACTIONS ) as $action ) {
			add_action( 'admin_post_' . $action, static fn () => self::dispatch( $action ) );
		}
	}

	public static function add_menu(): void {
		add_options_page(
			'MD3 WP MCP',
			'MD3 WP MCP',
			'manage_options',
			self::SLUG,
			static fn () => self::render_page( isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'content' ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab choice.
		);
	}

	/**
	 * @return array<string,string> Tab slug => label.
	 */
	public static function tabs(): array {
		return [
			'content'     => __( 'Content', 'md3-wp-mcp' ),
			'security'    => __( 'Security', 'md3-wp-mcp' ),
			'connections' => __( 'Connections', 'md3-wp-mcp' ),
			'log'         => __( 'Log', 'md3-wp-mcp' ),
			'connect'     => __( 'Connect', 'md3-wp-mcp' ),
		];
	}

	/**
	 * Checks capability and nonce, runs the action, and redirects (and exits).
	 */
	public static function dispatch( string $action ): void {
		$method = self::ACTIONS[ $action ] ?? null;
		if ( null === $method ) {
			wp_die( esc_html__( 'Unknown action.', 'md3-wp-mcp' ), '', [ 'response' => 400 ] );
		}

		if ( ! self::may_run( $action ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'md3-wp-mcp' ), '', [ 'response' => 403 ] );
		}
		check_admin_referer( $action );

		[ $tab, $notice ] = self::$method();
		self::redirect( $tab, $notice );
	}

	/**
	 * Revoking one connection is also allowed for the MCP user, for their own connections.
	 */
	private static function may_run( string $action ): bool {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		if ( 'md3_mcp_revoke' !== $action ) {
			return false;
		}
		$mcp = Settings::mcp_user_id();
		return $mcp > 0 && get_current_user_id() === $mcp && self::request_int( 'user_id' ) === $mcp;
	}

	/** @return array{0:string,1:string} Tab and notice. */
	private static function save_content(): array {
		$types = isset( $_POST['post_types'] ) && is_array( $_POST['post_types'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['post_types'] ) ) : []; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in dispatch().
		Settings::update_post_types( $types );
		Settings::update_default_author( self::request_int( 'default_author' ) );
		return [ 'content', 'saved' ];
	}

	/** @return array{0:string,1:string} */
	private static function save_security(): array {
		Settings::update_security(
			[
				'mcp_user_id'    => self::request_int( 'mcp_user_id' ),
				'dcr'            => ! empty( $_POST['dcr'] ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
				'mcp_rate_limit' => self::request_int( 'mcp_rate_limit' ),
			]
		);
		return [ 'security', 'saved' ];
	}

	/** @return array{0:string,1:string} */
	private static function revoke(): array {
		$client_id = isset( $_REQUEST['client_id'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['client_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		Tokens::revoke_connection( $client_id, self::request_int( 'user_id' ) );
		$from_profile = isset( $_REQUEST['return'] ) && 'profile' === $_REQUEST['return']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return [ $from_profile ? 'profile' : 'connections', 'revoked' ];
	}

	/** @return array{0:string,1:string} */
	private static function revoke_all(): array {
		Tokens::revoke_all();
		return [ 'security', 'revoked_all' ];
	}

	/** @return array{0:string,1:string} */
	private static function rotate_keys(): array {
		Keys::rotate();
		return [ 'security', 'rotated' ];
	}

	/** @return array{0:string,1:string} */
	private static function run_diagnostics(): array {
		set_transient( self::diagnostics_key(), Diagnostics::run(), 10 * MINUTE_IN_SECONDS );
		return [ 'security', 'diagnostics' ];
	}

	private static function diagnostics_key(): string {
		return 'md3_mcp_diag_results_' . get_current_user_id();
	}

	private static function request_int( string $key ): int {
		return isset( $_REQUEST[ $key ] ) ? absint( wp_unslash( $_REQUEST[ $key ] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
	}

	private static function redirect( string $tab, string $notice ): void {
		$base = 'profile' === $tab
			? admin_url( 'profile.php' )
			: add_query_arg(
				[
					'page' => self::SLUG,
					'tab'  => $tab,
				],
				admin_url( 'options-general.php' )
			);
		wp_safe_redirect( add_query_arg( 'md3_notice', $notice, $base ) );
		exit;
	}

	/**
	 * Notices a redirect can ask for, so the query string never prints free text.
	 *
	 * @return array<string,string>
	 */
	public static function notice_messages(): array {
		return [
			'saved'       => __( 'Settings saved.', 'md3-wp-mcp' ),
			'revoked'     => __( 'Connection revoked.', 'md3-wp-mcp' ),
			'revoked_all' => __( 'All connections revoked.', 'md3-wp-mcp' ),
			'rotated'     => __( 'Keys rotated. Every client has to connect again.', 'md3-wp-mcp' ),
			'diagnostics' => __( 'Checks finished.', 'md3-wp-mcp' ),
		];
	}

	/**
	 * A UTC DATETIME from the tables, in the site's time zone and format.
	 */
	public static function format_time( ?string $utc ): string {
		if ( null === $utc || '' === $utc || str_starts_with( $utc, '0000' ) ) {
			return __( 'Never', 'md3-wp-mcp' );
		}
		return get_date_from_gmt( $utc, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );
	}

	public static function render_page( string $tab ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'md3-wp-mcp' ), '', [ 'response' => 403 ] );
		}

		$tabs = self::tabs();
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'content';
		}

		$notice   = isset( $_GET['md3_notice'] ) ? sanitize_key( wp_unslash( $_GET['md3_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$messages = self::notice_messages();

		echo '<div class="wrap"><h1>MD3 WP MCP</h1>';

		if ( isset( $messages[ $notice ] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $messages[ $notice ] ) . '</p></div>';
		}
		if ( 0 === Settings::mcp_user_id() ) {
			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				wp_kses(
					sprintf(
						/* translators: %s: link to the Security tab. */
						__( 'The MCP user is not set, so no AI client can connect. Choose one on the %s tab.', 'md3-wp-mcp' ),
						'<a href="' . esc_url( self::tab_url( 'security' ) ) . '">' . esc_html__( 'Security', 'md3-wp-mcp' ) . '</a>'
					),
					[ 'a' => [ 'href' => [] ] ]
				)
			);
		}

		echo '<nav class="nav-tab-wrapper">';
		foreach ( $tabs as $slug => $label ) {
			printf(
				'<a href="%1$s" class="nav-tab%2$s">%3$s</a>',
				esc_url( self::tab_url( $slug ) ),
				$slug === $tab ? ' nav-tab-active' : '',
				esc_html( $label )
			);
		}
		echo '</nav>';

		$view = self::view_data( $tab );
		include MD3_WP_MCP_DIR . 'includes/admin/views/tab-' . $tab . '.php';

		echo '</div>';
	}

	public static function tab_url( string $tab ): string {
		return add_query_arg(
			[
				'page' => self::SLUG,
				'tab'  => $tab,
			],
			admin_url( 'options-general.php' )
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function view_data( string $tab ): array {
		switch ( $tab ) {
			case 'content':
				$types = get_post_types( [ 'public' => true ], 'objects' );
				unset( $types['attachment'] );
				$author = (int) get_option( Settings::OPT_DEFAULT_AUTHOR, 0 );
				return [
					'types'         => $types,
					'enabled'       => Settings::post_types(),
					'authors'       => get_users( [ 'capability' => 'edit_posts' ] ),
					'author'        => $author,
					'author_broken' => $author > 0 && Settings::default_author_id() !== $author,
				];

			case 'security':
				$editors = array_filter(
					get_users( [ 'role' => 'editor' ] ),
					static fn ( $user ) => ! user_can( $user, 'manage_options' )
				);
				$results = get_transient( self::diagnostics_key() );
				return [
					'security'    => Settings::security(),
					'editors'     => $editors,
					'diagnostics' => is_array( $results ) ? $results : null,
				];

			case 'connections':
				return [ 'connections' => Tokens::connections() ];

			case 'log':
				return self::log_data();

			default:
				return [ 'url' => \MD3\WPMCP\OAuth\OAuth::resource_url() ];
		}
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function log_data(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
		$filters = [];
		foreach ( [ 'tool', 'result', 'client_id' ] as $field ) {
			if ( isset( $_GET[ $field ] ) ) {
				$filters[ $field ] = sanitize_text_field( wp_unslash( $_GET[ $field ] ) );
			}
		}
		$page = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;
		// phpcs:enable

		$result = \MD3\WPMCP\Audit_Log::query( $filters, $page, self::LOG_PER_PAGE );

		return [
			'filters' => $filters,
			'rows'    => $result['rows'],
			'total'   => $result['total'],
			'page'    => $page,
			'pages'   => max( 1, (int) ceil( $result['total'] / self::LOG_PER_PAGE ) ),
		];
	}
}
