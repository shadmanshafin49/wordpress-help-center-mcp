<?php
/**
 * Plugin Name:       Help Center MCP
 * Description:       A help center (articles and topics) that AI assistants can search, read and draft through the Model Context Protocol.
 * Version:           1.0.0
 * Requires at least: 6.9
 * Requires PHP:      8.1
 * Requires Plugins:  mcp-adapter
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       help-center-mcp
 *
 * @package HelpCenterMcp
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/includes/content-model.php';
require_once __DIR__ . '/includes/abilities.php';
require_once __DIR__ . '/includes/mcp-server.php';
require_once __DIR__ . '/includes/mcp-client.php';
require_once __DIR__ . '/includes/showcase.php';
require_once __DIR__ . '/includes/assistant.php';

// The post type adds new URLs (/help/...), so WordPress must rebuild its rewrite rules.
register_activation_hook(
	__FILE__,
	static function (): void {
		help_center_register_content_model();
		flush_rewrite_rules();
	}
);
register_deactivation_hook( __FILE__, 'flush_rewrite_rules' );
