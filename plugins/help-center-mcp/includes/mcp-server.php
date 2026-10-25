<?php
/**
 * MCP server: offers the help center abilities to AI assistants over the Model Context Protocol.
 *
 * The MCP Adapter plugin does the protocol work (JSON-RPC, transports, version negotiation).
 * This file only decides what the server offers and who may connect.
 *
 *   HTTP:  POST /wp-json/help-center/mcp  (authenticate with an application password)
 *   STDIO: wp mcp-adapter serve --server=help-center --user=<login>
 *
 * @package HelpCenterMcp
 */

defined( 'ABSPATH' ) || exit;

add_action( 'mcp_adapter_init', 'help_center_register_mcp_server' );

/**
 * Registers the "help-center" MCP server.
 *
 * @param \WP\MCP\Core\McpAdapter $adapter The MCP Adapter instance.
 */
function help_center_register_mcp_server( $adapter ): void {
	$adapter->create_server(
		'help-center',  // Server ID, used by WP-CLI.
		'help-center',  // REST namespace...
		'mcp',          // ...and route: /wp-json/help-center/mcp.
		'Help Center',
		'Search, read and draft help center articles.',
		'1.0.0',
		array( \WP\MCP\Transport\HttpTransport::class ),
		\WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler::class,
		\WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler::class,
		// Tools: actions the model decides to call.
		array(
			'help-center/search-articles',
			'help-center/get-article',
			'help-center/list-topics',
			'help-center/create-draft',
		),
		// Resources: read-only context the client application can load.
		array(
			'help-center/style-guide',
		),
		// Prompts: task templates the user picks (slash commands in most clients).
		array(
			'help-center/answer-customer-question',
		),
		// Gatekeeper for the whole server. Each ability still runs its own permission check.
		static fn(): bool => current_user_can( 'edit_posts' )
	);
}
