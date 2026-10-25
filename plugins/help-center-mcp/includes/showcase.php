<?php
/**
 * The website's "AI & MCP" page: shows visitors what AI assistants can do with the help center.
 *
 * [help_center_ai_showcase] renders the page body. The tool list is read live from the
 * MCP server, the recorded session replays a real Claude session against it, and the
 * console lets visitors send their own MCP requests (read-only tools only) through
 * mcp-client.php. With an Anthropic API key set, assistant.php adds a live assistant.
 *
 * @package HelpCenterMcp
 */

defined( 'ABSPATH' ) || exit;

add_shortcode( 'help_center_ai_showcase', 'help_center_ai_showcase_shortcode' );
add_action( 'rest_api_init', 'help_center_register_console_route' );

/**
 * Registers POST /wp-json/help-center/v1/console, the backend of the page's live console.
 */
function help_center_register_console_route(): void {
	register_rest_route(
		'help-center/v1',
		'/console',
		array(
			'methods'             => 'POST',
			'callback'            => 'help_center_console_request',
			'permission_callback' => '__return_true', // Public like the page itself: it only runs read-only tools.
			'args'                => array(
				'method' => array(
					'type'     => 'string',
					'required' => true,
					'enum'     => array( 'tools/list', 'tools/call', 'resources/list', 'resources/read', 'prompts/list', 'prompts/get' ),
				),
				'params' => array(
					'type'    => 'object',
					'default' => array(),
				),
			),
		)
	);
}

/**
 * Sends one console request to the MCP server and returns both sides of the exchange.
 *
 * @param WP_REST_Request $request Console request: method and params.
 * @return array|WP_Error
 */
function help_center_console_request( WP_REST_Request $request ) {
	if ( ! help_center_rate_limit( 'console', 30, 5 * MINUTE_IN_SECONDS ) ) {
		return new WP_Error( 'help_center_rate_limited', 'Too many requests from your network. Try again in a few minutes.', array( 'status' => 429 ) );
	}

	$method = $request['method'];
	$params = $request['params'];

	if ( 'tools/call' === $method && ! isset( help_center_read_only_tools()[ $params['name'] ?? '' ] ) ) {
		return new WP_Error( 'help_center_read_only', 'This public console only runs read-only tools. Drafting articles needs an account of its own, like the AI Assistant user.', array( 'status' => 403 ) );
	}

	if ( isset( $params['arguments'] ) ) {
		$params['arguments'] = (object) $params['arguments']; // {} decodes to an empty PHP array; send it as an object again.
	}

	return help_center_mcp_request( $method, $params );
}

/**
 * A fixed-window rate limit for the page's public endpoints.
 *
 * @param string $bucket      Name of the limit, e.g. "console".
 * @param int    $limit       Requests allowed per window.
 * @param int    $window      Window length in seconds, counted from the first request.
 * @param bool   $per_visitor Count each IP address separately, or all visitors together.
 * @return bool Whether this request may go ahead.
 */
function help_center_rate_limit( string $bucket, int $limit, int $window, bool $per_visitor = true ): bool {
	$visitor = $per_visitor ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) ) : 'everyone';
	$key     = 'help_center_rate_' . md5( $bucket . '|' . $visitor );
	$entry   = get_transient( $key );

	if ( ! is_array( $entry ) ) {
		$entry = array(
			'count'   => 0,
			'expires' => time() + $window,
		);
	}

	if ( $entry['count'] >= $limit ) {
		return false;
	}

	++$entry['count'];
	set_transient( $key, $entry, max( 1, $entry['expires'] - time() ) );

	return true;
}

/**
 * [help_center_ai_showcase]: the body of the AI & MCP page.
 *
 * @return string HTML.
 */
function help_center_ai_showcase_shortcode(): string {
	help_center_enqueue_showcase_assets();

	$endpoint  = rest_url( 'help-center/mcp' );
	$github    = get_option( 'help_center_github_url', '' );
	$tools     = help_center_mcp_result( help_center_mcp_request( 'tools/list' ) );
	$resources = help_center_mcp_result( help_center_mcp_request( 'resources/list' ) );
	$prompts   = help_center_mcp_result( help_center_mcp_request( 'prompts/list' ) );

	ob_start();
	?>
	<div class="alignwide hc-showcase">
		<section class="hc-hero">
			<p class="hc-eyebrow"><?php esc_html_e( 'For AI assistants', 'help-center-mcp' ); ?></p>
			<h2><?php esc_html_e( 'This help center is also an MCP server', 'help-center-mcp' ); ?></h2>
			<p class="hc-lead"><?php esc_html_e( 'Customers read these articles on the website. AI assistants such as Claude use the same articles through the Model Context Protocol (MCP): they search and read them to answer support questions, and draft new ones when something is missing. WordPress decides what they are allowed to do.', 'help-center-mcp' ); ?></p>
			<div class="hc-actions">
				<a class="hc-button" href="#hc-session"><?php esc_html_e( 'Watch a session', 'help-center-mcp' ); ?></a>
				<a class="hc-button hc-button--ghost" href="#hc-console"><?php esc_html_e( 'Try the live console', 'help-center-mcp' ); ?></a>
				<?php if ( $github ) : ?>
					<a class="hc-button hc-button--ghost" href="<?php echo esc_url( $github ); ?>"><?php esc_html_e( 'Read the code on GitHub', 'help-center-mcp' ); ?></a>
				<?php endif; ?>
			</div>
		</section>

		<section class="hc-section" id="hc-how">
			<h2><?php esc_html_e( 'How it works', 'help-center-mcp' ); ?></h2>
			<ol class="hc-flow">
				<li><strong><?php esc_html_e( 'AI assistant', 'help-center-mcp' ); ?></strong><span><?php esc_html_e( 'Claude, or any other MCP client', 'help-center-mcp' ); ?></span></li>
				<li><strong><?php esc_html_e( 'MCP Adapter', 'help-center-mcp' ); ?></strong><span><?php esc_html_e( 'JSON-RPC over HTTP, signed in with an application password', 'help-center-mcp' ); ?></span></li>
				<li><strong><?php esc_html_e( 'Abilities API', 'help-center-mcp' ); ?></strong><span><?php esc_html_e( 'Validates the input against its JSON Schema and checks permissions', 'help-center-mcp' ); ?></span></li>
				<li><strong><?php esc_html_e( 'Help center plugin', 'help-center-mcp' ); ?></strong><span><?php esc_html_e( 'Searches, reads and drafts the articles you see on this site', 'help-center-mcp' ); ?></span></li>
			</ol>
			<p class="hc-muted"><?php esc_html_e( 'There is no AI model inside WordPress. The site publishes what an assistant may do and enforces the rules; the assistant decides what to call and when.', 'help-center-mcp' ); ?></p>
		</section>

		<section class="hc-section" id="hc-capabilities">
			<h2><?php esc_html_e( 'What assistants can use', 'help-center-mcp' ); ?></h2>
			<p class="hc-muted">
				<?php
				printf(
					/* translators: 1: tools/list, 2: resources/list, 3: prompts/list. */
					esc_html__( 'Read live from the server just now, with %1$s, %2$s and %3$s. The descriptions are written for the model: they are all it knows about each tool.', 'help-center-mcp' ),
					'<code>tools/list</code>',
					'<code>resources/list</code>',
					'<code>prompts/list</code>'
				);
				?>
			</p>
			<div class="hc-cards">
				<?php foreach ( is_wp_error( $tools ) ? array() : $tools['tools'] as $tool ) : ?>
					<?php $read_only = true === ( $tool['annotations']['readOnlyHint'] ?? false ); ?>
					<article class="hc-card">
						<p class="hc-card__kind">
							<?php esc_html_e( 'Tool', 'help-center-mcp' ); ?>
							<span class="hc-badge <?php echo $read_only ? '' : 'hc-badge--writes'; ?>"><?php echo $read_only ? esc_html__( 'Read-only', 'help-center-mcp' ) : esc_html__( 'Writes drafts', 'help-center-mcp' ); ?></span>
						</p>
						<h3><code><?php echo esc_html( $tool['name'] ); ?></code></h3>
						<p><?php echo esc_html( $tool['description'] ); ?></p>
						<?php echo help_center_showcase_inputs( $tool['inputSchema'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the helper. ?>
					</article>
				<?php endforeach; ?>
				<?php foreach ( is_wp_error( $resources ) ? array() : $resources['resources'] as $resource ) : ?>
					<article class="hc-card">
						<p class="hc-card__kind"><?php esc_html_e( 'Resource', 'help-center-mcp' ); ?><span class="hc-badge hc-badge--neutral"><?php echo esc_html( $resource['mimeType'] ?? '' ); ?></span></p>
						<h3><code><?php echo esc_html( $resource['uri'] ); ?></code></h3>
						<p><?php echo esc_html( $resource['description'] ?? '' ); ?></p>
					</article>
				<?php endforeach; ?>
				<?php foreach ( is_wp_error( $prompts ) ? array() : $prompts['prompts'] as $prompt ) : ?>
					<article class="hc-card">
						<p class="hc-card__kind"><?php esc_html_e( 'Prompt', 'help-center-mcp' ); ?><span class="hc-badge hc-badge--neutral"><?php esc_html_e( 'Picked by the user', 'help-center-mcp' ); ?></span></p>
						<h3><code><?php echo esc_html( $prompt['name'] ); ?></code></h3>
						<p><?php echo esc_html( $prompt['description'] ?? '' ); ?></p>
						<?php if ( ! empty( $prompt['arguments'] ) ) : ?>
							<p class="hc-inputs"><?php esc_html_e( 'Arguments:', 'help-center-mcp' ); ?> <?php echo esc_html( implode( ', ', wp_list_pluck( $prompt['arguments'], 'name' ) ) ); ?></p>
						<?php endif; ?>
					</article>
				<?php endforeach; ?>
			</div>
		</section>

		<section class="hc-section" id="hc-session">
			<h2><?php esc_html_e( 'Watch the assistant work', 'help-center-mcp' ); ?></h2>
			<?php if ( help_center_assistant_enabled() ) : ?>
				<p class="hc-muted"><?php esc_html_e( 'Ask a customer question and watch Claude answer it live through this site\'s MCP server, or replay a recorded session.', 'help-center-mcp' ); ?></p>
				<div class="hc-assistant" data-hc-assistant></div>
			<?php else : ?>
				<p class="hc-muted"><?php esc_html_e( 'A recorded session: Claude answering two customer questions through this site\'s MCP server. Open any step to see the exact MCP messages.', 'help-center-mcp' ); ?></p>
			<?php endif; ?>
			<div class="hc-replay" data-hc-replay></div>
			<noscript><p><?php esc_html_e( 'The session replay needs JavaScript.', 'help-center-mcp' ); ?></p></noscript>
		</section>

		<section class="hc-section" id="hc-console">
			<h2><?php esc_html_e( 'Live MCP console', 'help-center-mcp' ); ?></h2>
			<p class="hc-muted"><?php esc_html_e( 'Send real MCP requests to this site and see the raw messages, exactly as an AI assistant would. The console runs read-only tools as a demo account, so it can only see what visitors see.', 'help-center-mcp' ); ?></p>
			<div class="hc-console" data-hc-console></div>
			<noscript><p><?php esc_html_e( 'The console needs JavaScript.', 'help-center-mcp' ); ?></p></noscript>
		</section>

		<section class="hc-section" id="hc-connect">
			<h2><?php esc_html_e( 'Connect your own AI', 'help-center-mcp' ); ?></h2>
			<p><?php esc_html_e( 'Any MCP client can connect over HTTP:', 'help-center-mcp' ); ?></p>
			<pre class="hc-code"><code><?php echo esc_html( $endpoint ); ?></code></pre>
			<p><?php esc_html_e( 'In Claude Code:', 'help-center-mcp' ); ?></p>
			<pre class="hc-code"><code><?php echo esc_html( sprintf( 'claude mcp add --transport http help-center %s --header "Authorization: Basic <your key>"', $endpoint ) ); ?></code></pre>
			<p class="hc-muted"><?php esc_html_e( 'Keys are WordPress application passwords for a Contributor account: it can draft articles but never publish, edit or delete them, and each key can be revoked at any time. Ask the site owner for one.', 'help-center-mcp' ); ?></p>
		</section>

		<section class="hc-section" id="hc-safety">
			<h2><?php esc_html_e( 'Why it is safe', 'help-center-mcp' ); ?></h2>
			<ul class="hc-checks">
				<li><strong><?php esc_html_e( 'Least privilege.', 'help-center-mcp' ); ?></strong> <?php esc_html_e( 'The assistant signs in as its own Contributor account, so WordPress lets it write drafts and nothing more.', 'help-center-mcp' ); ?></li>
				<li><strong><?php esc_html_e( 'Two permission checks.', 'help-center-mcp' ); ?></strong> <?php esc_html_e( 'One to connect to the server, and one inside every tool, per article.', 'help-center-mcp' ); ?></li>
				<li><strong><?php esc_html_e( 'A human publishes.', 'help-center-mcp' ); ?></strong> <?php esc_html_e( 'New articles are always saved as drafts, with unconfirmed facts marked [CHECK: ...] for the editor.', 'help-center-mcp' ); ?></li>
				<li><strong><?php esc_html_e( 'Validated input and output.', 'help-center-mcp' ); ?></strong> <?php esc_html_e( 'Every call is checked against its JSON Schema before any plugin code runs.', 'help-center-mcp' ); ?></li>
				<li><strong><?php esc_html_e( 'No markup injection.', 'help-center-mcp' ); ?></strong> <?php esc_html_e( 'Text from the assistant is escaped, so a prompt-injected draft cannot plant scripts.', 'help-center-mcp' ); ?></li>
				<li><strong><?php esc_html_e( 'Errors the model can act on.', 'help-center-mcp' ); ?></strong> <?php esc_html_e( 'A failed call says what to do instead, so the assistant can correct itself.', 'help-center-mcp' ); ?></li>
			</ul>
		</section>
	</div>
	<?php
	return (string) ob_get_clean();
}

/**
 * Lists a tool's input fields, e.g. "query (string, required), topic (string)".
 *
 * @param array $schema The tool's input JSON Schema.
 * @return string HTML.
 */
function help_center_showcase_inputs( array $schema ): string {
	if ( empty( $schema['properties'] ) ) {
		return '<p class="hc-inputs">' . esc_html__( 'Input: none', 'help-center-mcp' ) . '</p>';
	}

	$fields = array();
	foreach ( $schema['properties'] as $name => $property ) {
		$details  = array_filter( array( $property['type'] ?? '', in_array( $name, $schema['required'] ?? array(), true ) ? __( 'required', 'help-center-mcp' ) : '' ) );
		$fields[] = '<code>' . esc_html( $name ) . '</code> (' . esc_html( implode( ', ', $details ) ) . ')';
	}

	return '<p class="hc-inputs">' . esc_html__( 'Input:', 'help-center-mcp' ) . ' ' . implode( ', ', $fields ) . '</p>';
}

/**
 * Loads the page's script and styles, with the data the script needs.
 */
function help_center_enqueue_showcase_assets(): void {
	$assets = dirname( __DIR__ ) . '/assets/';

	wp_enqueue_style( 'help-center-showcase', plugins_url( 'assets/showcase.css', __DIR__ ), array(), (string) filemtime( $assets . 'showcase.css' ) );
	wp_enqueue_script( 'help-center-showcase', plugins_url( 'assets/showcase.js', __DIR__ ), array(), (string) filemtime( $assets . 'showcase.js' ), true );

	$article = get_page_by_path( 'charged-twice', OBJECT, 'help_article' );

	$data = array(
		'consoleUrl'   => rest_url( 'help-center/v1/console' ),
		'assistantUrl' => help_center_assistant_enabled() ? rest_url( 'help-center/v1/assistant' ) : '',
		'presets'      => array(
			array(
				'label'  => __( 'List the tools', 'help-center-mcp' ),
				'note'   => __( 'What an assistant sees when it connects: each tool\'s name, description, JSON Schemas and annotations.', 'help-center-mcp' ),
				'method' => 'tools/list',
				'params' => new stdClass(),
			),
			array(
				'label'  => __( 'Search for "refund"', 'help-center-mcp' ),
				'note'   => __( 'A keyword search over published articles. The result comes back twice: as text for the model, and as structuredContent that matches the output schema.', 'help-center-mcp' ),
				'method' => 'tools/call',
				'params' => array(
					'name'      => 'help-center-search-articles',
					'arguments' => array( 'query' => 'refund' ),
				),
			),
			array(
				'label'  => __( 'Read an article', 'help-center-mcp' ),
				'note'   => __( 'The full text of one article, by the ID that search returned.', 'help-center-mcp' ),
				'method' => 'tools/call',
				'params' => array(
					'name'      => 'help-center-get-article',
					'arguments' => array( 'id' => $article ? $article->ID : 1 ),
				),
			),
			array(
				'label'  => __( 'List the topics', 'help-center-mcp' ),
				'note'   => __( 'A tool without input: its JSON Schema is an empty object.', 'help-center-mcp' ),
				'method' => 'tools/call',
				'params' => array(
					'name'      => 'help-center-list-topics',
					'arguments' => new stdClass(),
				),
			),
			array(
				'label'  => __( 'Use a topic that doesn\'t exist', 'help-center-mcp' ),
				'note'   => __( 'The error is a tool result with isError: true that lists the valid topics, so the model can correct itself.', 'help-center-mcp' ),
				'method' => 'tools/call',
				'params' => array(
					'name'      => 'help-center-search-articles',
					'arguments' => array(
						'query' => 'refund',
						'topic' => 'payments',
					),
				),
			),
			array(
				'label'  => __( 'Send invalid input', 'help-center-mcp' ),
				'note'   => __( 'WordPress checks the input against the tool\'s JSON Schema before any plugin code runs. A query needs at least 2 characters.', 'help-center-mcp' ),
				'method' => 'tools/call',
				'params' => array(
					'name'      => 'help-center-search-articles',
					'arguments' => array( 'query' => 'x' ),
				),
			),
			array(
				'label'  => __( 'Read the style guide resource', 'help-center-mcp' ),
				'note'   => __( 'A resource is context the client loads: the rules an assistant follows when it drafts an article.', 'help-center-mcp' ),
				'method' => 'resources/read',
				'params' => array( 'uri' => 'help-center://style-guide' ),
			),
			array(
				'label'  => __( 'Fill in the answer prompt', 'help-center-mcp' ),
				'note'   => __( 'A prompt is a task template the user picks, such as a slash command. The server fills in the question.', 'help-center-mcp' ),
				'method' => 'prompts/get',
				'params' => array(
					'name'      => 'help-center-answer-customer-question',
					'arguments' => array( 'question' => 'Where is my parcel?' ),
				),
			),
			array(
				'label'  => __( 'Try to write a draft', 'help-center-mcp' ),
				'note'   => __( 'Refused: this public console only runs read-only tools. An assistant with its own key can draft, but never publish.', 'help-center-mcp' ),
				'method' => 'tools/call',
				'params' => array(
					'name'      => 'help-center-create-draft',
					'arguments' => array(
						'title'   => 'How do I cancel my order?',
						'summary' => 'You can cancel an order until it ships.',
						'content' => 'You can cancel an order as long as it has not shipped yet. [CHECK: confirm the policy.]',
					),
				),
			),
		),
	);

	wp_add_inline_script(
		'help-center-showcase',
		'window.helpCenterShowcase = ' . wp_json_encode( $data ) . ";\nwindow.helpCenterShowcase.session = " . help_center_recorded_session_json() . ';',
		'before'
	);
}

/**
 * The recorded session as JSON, with its links pointing at this site instead of where it was recorded.
 *
 * It stays JSON text rather than PHP arrays, so empty objects ({}) in the MCP messages stay objects.
 *
 * @return string JSON.
 */
function help_center_recorded_session_json(): string {
	$json    = (string) file_get_contents( dirname( __DIR__ ) . '/assets/recorded-session.json' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.
	$session = json_decode( $json, true );

	if ( ! is_array( $session ) ) {
		return 'null';
	}

	// The address appears as plain text, and JSON-escaped inside tool results, which are JSON strings themselves.
	$origin = $session['origin'];
	$site   = untrailingslashit( home_url() );
	$json   = str_replace(
		array( $origin, str_replace( '/', '\\\\/', $origin ) ),
		array( $site, str_replace( '/', '\\\\/', $site ) ),
		$json
	);

	return str_replace( '</', '<\/', $json ); // Keep "</script>" in any string from ending the inline script.
}
