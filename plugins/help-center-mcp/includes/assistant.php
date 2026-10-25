<?php
/**
 * Live assistant for the AI & MCP page: Claude answers a visitor's question through MCP.
 *
 * This is the other side of the protocol, a small MCP host. It reads the tools and the
 * answer prompt from this site's MCP server, gives Claude the read-only tools, and sends
 * every tool call Claude makes to the server as a tools/call request (mcp-client.php).
 * The official Anthropic PHP SDK talks to the Claude API.
 *
 * It stays off until the SDK is installed (composer install in the plugin folder) and an
 * API key is set: ANTHROPIC_API_KEY in the environment, or HELP_CENTER_ANTHROPIC_API_KEY
 * in wp-config.php. Every question costs API credits, so the endpoint is rate limited
 * per visitor and per day.
 *
 * @package HelpCenterMcp
 */

defined( 'ABSPATH' ) || exit;

use Anthropic\Beta\Messages\BetaToolUseBlock;
use Anthropic\Client;
use Anthropic\Core\Exceptions\AnthropicException;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\PermissionDeniedException;
use Anthropic\Core\Exceptions\RateLimitException;

add_action( 'rest_api_init', 'help_center_register_assistant_route' );

/**
 * The Anthropic API key, from wp-config.php or the environment.
 *
 * @return string Empty when none is set.
 */
function help_center_assistant_api_key(): string {
	if ( defined( 'HELP_CENTER_ANTHROPIC_API_KEY' ) ) {
		return (string) HELP_CENTER_ANTHROPIC_API_KEY;
	}

	return (string) getenv( 'ANTHROPIC_API_KEY' );
}

/**
 * Whether the live assistant can run: the SDK is installed and an API key is set.
 *
 * @return bool
 */
function help_center_assistant_enabled(): bool {
	return '' !== help_center_assistant_api_key() && is_readable( dirname( __DIR__ ) . '/vendor/autoload.php' );
}

/**
 * Registers POST /wp-json/help-center/v1/assistant.
 */
function help_center_register_assistant_route(): void {
	register_rest_route(
		'help-center/v1',
		'/assistant',
		array(
			'methods'             => 'POST',
			'callback'            => 'help_center_assistant_request',
			'permission_callback' => '__return_true', // Public like the page, but rate limited: each question costs API credits.
			'args'                => array(
				'question' => array(
					'type'      => 'string',
					'required'  => true,
					'minLength' => 3,
					'maxLength' => 300,
				),
			),
		)
	);
}

/**
 * Answers one visitor question, within the rate limits.
 *
 * @param WP_REST_Request $request Assistant request: question.
 * @return array|WP_Error
 */
function help_center_assistant_request( WP_REST_Request $request ) {
	if ( ! help_center_assistant_enabled() ) {
		return new WP_Error( 'help_center_assistant_off', 'The live assistant is not set up on this site.', array( 'status' => 503 ) );
	}

	if ( ! help_center_rate_limit( 'assistant', 5, 10 * MINUTE_IN_SECONDS ) || ! help_center_rate_limit( 'assistant-daily', 100, DAY_IN_SECONDS, false ) ) {
		return new WP_Error( 'help_center_rate_limited', 'The live assistant has answered a lot of questions recently. Try again later, or replay the recorded session.', array( 'status' => 429 ) );
	}

	set_time_limit( 120 ); // A few model requests in a row can outlast PHP's default 30 seconds.

	return help_center_assistant_answer( $request['question'] );
}

/**
 * Answers a customer question with Claude, using this site's MCP server for the tools and the task.
 *
 * @param string $question The visitor's question.
 * @return array{answer: string, model: string, steps: array[]}|WP_Error The answer, the model
 *         that wrote it, and every MCP exchange along the way.
 */
function help_center_assistant_answer( string $question ) {
	require_once dirname( __DIR__ ) . '/vendor/autoload.php';

	// The tools come from the server's tools/list: Claude sees exactly what any MCP client sees.
	$allowed = help_center_read_only_tools();
	$tools   = array();
	foreach ( $allowed as $tool ) {
		$tools[] = array(
			'name'        => $tool['name'],
			'description' => $tool['description'],
			'inputSchema' => array_filter(
				array(
					'type'       => 'object',
					'properties' => $tool['inputSchema']['properties'] ?? array(),
					'required'   => $tool['inputSchema']['required'] ?? array(),
				)
			),
		);
	}

	// The task is the server's own MCP prompt, filled in with the question.
	$prompt = help_center_mcp_request(
		'prompts/get',
		array(
			'name'      => 'help-center-answer-customer-question',
			'arguments' => (object) array( 'question' => $question ),
		)
	);
	$task   = help_center_mcp_result( $prompt );

	if ( is_wp_error( $task ) ) {
		return $task;
	}

	$steps    = array( $prompt );
	$messages = array(
		array(
			'role'    => 'user',
			'content' => $task['messages'][0]['content']['text'],
		),
	);
	$client   = new Client( apiKey: help_center_assistant_api_key() );
	$system   = sprintf(
		'You answer customer questions on the public demo of the %s help center. You can search and read articles, but you cannot save drafts here: when the help center does not cover a question, say so, and say that an editor will be asked to write the article. Keep answers under 120 words and link every article you use.',
		get_bloginfo( 'name' )
	);

	try {
		for ( $turn = 1; $turn <= 6; $turn++ ) {
			$response = $client->beta->messages->create(
				model: defined( 'HELP_CENTER_ASSISTANT_MODEL' ) ? HELP_CENTER_ASSISTANT_MODEL : 'claude-opus-5-5',
				maxTokens: 8000,
				system: $system,
				tools: $tools,
				messages: $messages,
				outputConfig: array( 'effort' => 'low' ),
				fallbacks: 'default', // If the model declines for policy reasons, the API retries on its recommended fallback model.
				betas: array( 'server-side-fallback-2026-07-01' ),
			);

			if ( 'tool_use' !== $response->stopReason ) {
				break;
			}

			$results = array();
			foreach ( $response->content as $block ) {
				if ( ! $block instanceof BetaToolUseBlock ) {
					continue;
				}

				if ( ! isset( $allowed[ $block->name ] ) ) {
					$results[] = array(
						'type'      => 'tool_result',
						'toolUseID' => $block->id,
						'content'   => 'This tool is not available here.',
						'isError'   => true,
					);
					continue;
				}

				$exchange  = help_center_mcp_request(
					'tools/call',
					array(
						'name'      => $block->name,
						'arguments' => (object) $block->input,
					)
				);
				$result    = help_center_mcp_result( $exchange );
				$steps[]   = $exchange;
				$results[] = array(
					'type'      => 'tool_result',
					'toolUseID' => $block->id,
					'content'   => is_wp_error( $result ) ? $result->get_error_message() : ( $result['content'][0]['text'] ?? '' ),
					'isError'   => is_wp_error( $result ) || ! empty( $result['isError'] ),
				);
			}

			$messages[] = array(
				'role'    => 'assistant',
				'content' => $response->content,
			);
			$messages[] = array(
				'role'    => 'user',
				'content' => $results,
			);
		}
	} catch ( AuthenticationException | PermissionDeniedException $e ) {
		return new WP_Error( 'help_center_assistant_key', 'The Claude API rejected this site\'s API key.', array( 'status' => 503 ) );
	} catch ( RateLimitException $e ) {
		return new WP_Error( 'help_center_assistant_busy', 'The Claude API is busy right now. Try again in a minute.', array( 'status' => 503 ) );
	} catch ( APIConnectionException $e ) {
		return new WP_Error( 'help_center_assistant_offline', 'The site could not reach the Claude API.', array( 'status' => 503 ) );
	} catch ( AnthropicException $e ) {
		return new WP_Error( 'help_center_assistant_failed', 'The Claude API request failed: ' . $e->getMessage(), array( 'status' => 502 ) );
	}

	if ( 'refusal' === $response->stopReason ) {
		return new WP_Error( 'help_center_assistant_refused', 'The assistant declined this question. Try another one.', array( 'status' => 422 ) );
	}

	$answer = '';
	foreach ( $response->content as $block ) {
		if ( 'text' === $block->type ) {
			$answer .= $block->text;
		}
	}

	return array(
		'answer' => '' !== $answer ? $answer : 'The assistant ran out of steps before it could answer. Try a simpler question.',
		'model'  => $response->model,
		'steps'  => $steps,
	);
}
