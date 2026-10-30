<?php
/**
 * In-process MCP client: lets the website talk to its own MCP server.
 *
 * The AI & MCP page (showcase.php) sends real JSON-RPC messages through the same REST
 * route, permission checks and MCP Adapter code that external clients reach over HTTP,
 * only without the network round trip.
 * Requests use the stateless 2026-07-28 revision, so there is no handshake or session.
 *
 * @package HelpCenterMcp
 */

defined( 'ABSPATH' ) || exit;

const HELP_CENTER_MCP_REVISION = '2026-07-28';

/**
 * Sends one MCP request to the help-center server, signed in as the website-demo account.
 *
 * @param string $method JSON-RPC method, e.g. "tools/call".
 * @param array  $params Method parameters. The protocol metadata (_meta) is added here.
 * @return array{request: array{headers: array<string, string>, body: array}, status: int, response: mixed, duration_ms: int}
 *               Both sides of the exchange, as they would travel over HTTP.
 */
function help_center_mcp_request( string $method, array $params = array() ): array {
	static $next_id = 1;

	$params['_meta'] = array(
		'io.modelcontextprotocol/protocolVersion'    => HELP_CENTER_MCP_REVISION,
		'io.modelcontextprotocol/clientCapabilities' => new stdClass(),
		'io.modelcontextprotocol/clientInfo'         => array(
			'name'    => 'help-center-website',
			'version' => '1.0.0',
		),
	);

	$body = array(
		'jsonrpc' => '2.0',
		'id'      => $next_id++,
		'method'  => $method,
		'params'  => $params,
	);

	$headers = array(
		'Content-Type'         => 'application/json',
		'Accept'               => 'application/json, text/event-stream',
		'MCP-Protocol-Version' => HELP_CENTER_MCP_REVISION,
		'Mcp-Method'           => $method,
	);

	// Requests aimed at one tool, prompt or resource repeat its name in a header.
	$name = 'resources/read' === $method ? ( $params['uri'] ?? null ) : ( $params['name'] ?? null );
	if ( in_array( $method, array( 'tools/call', 'prompts/get', 'resources/read' ), true ) && is_string( $name ) ) {
		$headers['Mcp-Name'] = $name;
	}

	$request = new WP_REST_Request( 'POST', '/help-center/mcp' );
	$request->set_headers( $headers );
	$request->set_body( wp_json_encode( $body ) );

	$previous_user = get_current_user_id();
	wp_set_current_user( help_center_demo_user_id() );

	try {
		$started  = microtime( true );
		$response = rest_do_request( $request );
		$duration = (int) round( ( microtime( true ) - $started ) * 1000 );
	} finally {
		wp_set_current_user( $previous_user );
	}

	return array(
		'request'     => array(
			'headers' => $headers,
			'body'    => $body,
		),
		'status'      => $response->get_status(),
		'response'    => rest_get_server()->response_to_data( $response, false ),
		'duration_ms' => $duration,
	);
}

/**
 * The result of an MCP exchange as plain arrays.
 *
 * @param array $exchange Return value of help_center_mcp_request().
 * @return array|WP_Error The JSON-RPC result, or an error when the server returned none.
 */
function help_center_mcp_result( array $exchange ) {
	$response = json_decode( wp_json_encode( $exchange['response'] ), true );

	if ( ! isset( $response['result'] ) ) {
		return new WP_Error( 'help_center_mcp_error', $response['error']['message'] ?? $response['message'] ?? 'The MCP server returned no result.' );
	}

	return $response['result'];
}

/**
 * The tools the server marks as read-only, read from a live tools/list.
 *
 * The website only ever runs these: anything that writes needs a signed-in account.
 *
 * @return array[] MCP tool definitions, keyed by tool name.
 */
function help_center_read_only_tools(): array {
	$result = help_center_mcp_result( help_center_mcp_request( 'tools/list' ) );

	if ( is_wp_error( $result ) ) {
		return array();
	}

	$read_only = array_filter(
		$result['tools'],
		static fn( array $tool ): bool => true === ( $tool['annotations']['readOnlyHint'] ?? false )
	);

	return array_column( $read_only, null, 'name' );
}

/**
 * The account the website signs in as, created on first use.
 *
 * It is a Contributor because the MCP server only admits users who can edit posts.
 * The website only runs read-only tools with it, and since the account owns no posts,
 * WordPress never shows it anyone's drafts: it sees exactly what a visitor sees.
 *
 * @return int User ID, or 0 if the account can't be created.
 */
function help_center_demo_user_id(): int {
	$user = get_user_by( 'login', 'website-demo' );

	if ( $user ) {
		return $user->ID;
	}

	$user_id = wp_insert_user(
		array(
			'user_login'   => 'website-demo',
			'user_email'   => 'website-demo@example.com',
			'user_pass'    => wp_generate_password( 32 ),
			'display_name' => 'Website demo',
			'role'         => 'contributor',
		)
	);

	return is_wp_error( $user_id ) ? 0 : $user_id;
}
