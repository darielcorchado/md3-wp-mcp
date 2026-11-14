<?php
namespace MD3\WPMCP\Mcp;

use MD3\WPMCP\Abilities\Registry;
use MD3\WPMCP\Auth\Request_Context;
use MD3\WPMCP\Rate_Limiter;
use MD3\WPMCP\Settings;
use WP\MCP\Core\McpAdapter;
use WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler;
use WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler;
use WP\MCP\Transport\HttpTransport;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the plugin's MCP server and guards its transport.
 */
final class Server {

	const ID        = 'md3-wp-mcp';
	const NAMESPACE = 'md3-wp-mcp';
	const ROUTE     = 'mcp';

	/**
	 * The refusal of the current request, kept for the REST response.
	 *
	 * The adapter turns a WP_Error from the permission callback into a plain
	 * `false`, which WordPress answers with a generic 401/403. This keeps our
	 * own code and status so clients see why they were refused.
	 */
	private static ?\WP_Error $refusal = null;

	/**
	 * Hooks the server into the adapter and the REST refusal handling.
	 */
	public static function hook(): void {
		add_action( 'mcp_adapter_init', [ self::class, 'register' ] );
		add_filter( 'rest_request_after_callbacks', [ self::class, 'restore_refusal' ] );
	}

	/**
	 * Creates the server on the adapter's `mcp_adapter_init` action.
	 */
	public static function register( McpAdapter $adapter ): void {
		$adapter->create_server(
			self::ID,
			self::NAMESPACE,
			self::ROUTE,
			'MD3 WP MCP',
			'Read and edit site content: posts, pages, terms and media.',
			MD3_WP_MCP_VERSION,
			[ HttpTransport::class ],
			ErrorLogMcpErrorHandler::class,
			NullMcpObservabilityHandler::class,
			Registry::names(),
			[],
			[],
			[ self::class, 'transport_permission' ]
		);
	}

	/**
	 * Allows a request only for an OAuth identity acting as the MCP user.
	 *
	 * @return bool|\WP_Error
	 */
	public static function transport_permission( \WP_REST_Request $request ) {
		self::$refusal = null;

		$identity = Request_Context::get();
		if ( null === $identity ) {
			return self::refuse( 'md3_mcp_unauthorized', __( 'Authentication is required.', 'md3-wp-mcp' ), 401 );
		}

		$mcp_user_id = Settings::mcp_user_id();
		if ( 0 === $mcp_user_id || get_current_user_id() !== $mcp_user_id ) {
			return self::refuse( 'md3_mcp_not_mcp_user', __( 'This token is not for the MCP user.', 'md3-wp-mcp' ), 403 );
		}

		$limit = Settings::security()['mcp_rate_limit'];
		if ( ! Rate_Limiter::hit( 'mcp', $identity->token_hash, $limit ) ) {
			return self::refuse( 'md3_mcp_rate_limited', __( 'Too many requests. Try again shortly.', 'md3-wp-mcp' ), 429 );
		}

		return true;
	}

	/**
	 * Swaps the adapter's generic refusal for ours.
	 *
	 * @param mixed $result Response or error the route produced.
	 * @return mixed
	 */
	public static function restore_refusal( $result ) {
		$refusal       = self::$refusal;
		self::$refusal = null;

		if ( null !== $refusal && is_wp_error( $result ) ) {
			return $refusal;
		}

		return $result;
	}

	private static function refuse( string $code, string $message, int $status ): \WP_Error {
		self::$refusal = new \WP_Error( $code, $message, [ 'status' => $status ] );

		return self::$refusal;
	}
}
