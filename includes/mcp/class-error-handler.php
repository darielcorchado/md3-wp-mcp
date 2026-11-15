<?php
namespace MD3\WPMCP\Mcp;

use WP\MCP\Infrastructure\ErrorHandling\Contracts\McpErrorHandlerInterface;
use WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler;
use WP\MCP\Infrastructure\Observability\FailureReason;

defined( 'ABSPATH' ) || exit;

/**
 * The adapter's error log, without the expected refusals: a request with no
 * or a bad token, and a tool call refused for a missing scope or capability.
 * Those are answered to the client (and tool refusals are in the audit log);
 * everything else still goes to the PHP error log.
 */
final class Error_Handler implements McpErrorHandlerInterface {

	/**
	 * @param string             $message Log message.
	 * @param array<mixed,mixed> $context Context data.
	 * @param string             $type    Log type.
	 */
	public function log( string $message, array $context = array(), string $type = 'error' ): void {
		if ( str_starts_with( $message, 'Permission callback returned WP_Error' ) ) {
			return;
		}
		if ( FailureReason::PERMISSION_CHECK_FAILED === ( $context['failure_reason'] ?? null ) ) {
			return;
		}

		( new ErrorLogMcpErrorHandler() )->log( $message, $context, $type );
	}
}
