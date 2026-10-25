<?php
/**
 * Abilities: the operations this plugin offers to AI assistants and other clients.
 *
 * An ability (WordPress Abilities API, WordPress 6.9+) is a named unit of work with a
 * description, JSON Schemas for its input and output, a permission check and a callback.
 * WordPress validates the input and checks permission before the callback runs, and
 * validates the output afterwards, so the callbacks below can trust their input.
 *
 * Abilities are private by default; mcp-server.php chooses which ones MCP clients see.
 *
 * @package HelpCenterMcp
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_categories_init', 'help_center_register_ability_category' );
add_action( 'wp_abilities_api_init', 'help_center_register_abilities' );

/**
 * Registers the category that groups this plugin's abilities.
 */
function help_center_register_ability_category(): void {
	wp_register_ability_category(
		'help-center',
		array(
			'label'       => __( 'Help Center', 'help-center-mcp' ),
			'description' => __( 'Search, read and draft help center articles.', 'help-center-mcp' ),
		)
	);
}

/**
 * Registers the abilities. Descriptions are written for the AI model: it decides
 * when and how to call a tool from its name, description and input schema alone.
 */
function help_center_register_abilities(): void {
	$article_properties = array(
		'id'      => array( 'type' => 'integer' ),
		'title'   => array( 'type' => 'string' ),
		'summary' => array( 'type' => 'string' ),
		'topics'  => array(
			'type'  => 'array',
			'items' => array( 'type' => 'string' ),
		),
		'url'     => array( 'type' => 'string' ),
	);

	// Tool: find articles.
	wp_register_ability(
		'help-center/search-articles',
		array(
			'label'               => __( 'Search help articles', 'help-center-mcp' ),
			'description'         => __( 'Searches published help center articles and returns the best matches with a short summary. Use it before answering any customer question. Pass one to three keywords (for example "refund" or "reset password"), not a whole sentence: an article only matches if it contains every keyword. Then read the most relevant article in full with help-center-get-article.', 'help-center-mcp' ),
			'category'            => 'help-center',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'query' => array(
						'type'        => 'string',
						'description' => 'One to three keywords, e.g. "refund" or "reset password".',
						'minLength'   => 2,
						'maxLength'   => 100,
					),
					'topic' => array(
						'type'        => 'string',
						'description' => 'Optional topic slug to narrow the search, e.g. "billing". help-center-list-topics returns the valid slugs.',
					),
					'limit' => array(
						'type'        => 'integer',
						'description' => 'Maximum number of results. Defaults to 5.',
						'minimum'     => 1,
						'maximum'     => 10,
					),
				),
				'required'             => array( 'query' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'articles' => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => $article_properties,
						),
					),
					'hint'     => array(
						'type'        => 'string',
						'description' => 'What to try next when nothing matched.',
					),
				),
			),
			'execute_callback'    => 'help_center_search_articles',
			'permission_callback' => '__return_true', // Published articles are public on the website too.
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// Tool: read one article.
	wp_register_ability(
		'help-center/get-article',
		array(
			'label'               => __( 'Get help article', 'help-center-mcp' ),
			'description'         => __( 'Returns the full text of one help article by ID (IDs come from help-center-search-articles). Link the article URL when you use it in an answer.', 'help-center-mcp' ),
			'category'            => 'help-center',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id' => array(
						'type'        => 'integer',
						'description' => 'Article ID.',
						'minimum'     => 1,
					),
				),
				'required'             => array( 'id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array_merge(
					$article_properties,
					array(
						'status'       => array( 'type' => 'string' ),
						'content'      => array( 'type' => 'string' ),
						'last_updated' => array( 'type' => 'string' ),
					)
				),
			),
			'execute_callback'    => 'help_center_get_article',
			'permission_callback' => 'help_center_can_read_article',
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// Tool: list topics. Takes no input, so it has no input schema.
	wp_register_ability(
		'help-center/list-topics',
		array(
			'label'               => __( 'List help topics', 'help-center-mcp' ),
			'description'         => __( 'Lists the help center topics with their slugs and how many published articles each one has. Use it to see what the help center covers, or to find a valid topic slug for help-center-search-articles or help-center-create-draft.', 'help-center-mcp' ),
			'category'            => 'help-center',
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'topics' => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'slug'          => array( 'type' => 'string' ),
								'name'          => array( 'type' => 'string' ),
								'article_count' => array( 'type' => 'integer' ),
								'url'           => array( 'type' => 'string' ),
							),
						),
					),
				),
			),
			'execute_callback'    => 'help_center_list_topics',
			'permission_callback' => '__return_true', // Topics are public on the website too.
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// Tool: write a draft. The only ability that changes anything, and it can't publish.
	wp_register_ability(
		'help-center/create-draft',
		array(
			'label'               => __( 'Create help article draft', 'help-center-mcp' ),
			'description'         => __( 'Saves a new help article as a draft for a human editor to review. It is never published automatically. Use it only when the help center does not already cover a question, and read the help-center://style-guide resource first. Write plain text: a blank line between paragraphs, "## " to start a heading, "- " to start a list item. Mark any fact you are not sure about as [CHECK: ...] so the editor verifies it.', 'help-center-mcp' ),
			'category'            => 'help-center',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'title'   => array(
						'type'        => 'string',
						'description' => 'The question as a customer would ask it, e.g. "How do I cancel my order?".',
						'minLength'   => 5,
						'maxLength'   => 120,
					),
					'summary' => array(
						'type'        => 'string',
						'description' => 'One-sentence answer, shown in search results.',
						'minLength'   => 10,
						'maxLength'   => 300,
					),
					'content' => array(
						'type'        => 'string',
						'description' => 'The article body as plain text, using the formatting rules above.',
						'minLength'   => 50,
						'maxLength'   => 10000,
					),
					'topic'   => array(
						'type'        => 'string',
						'description' => 'Topic slug, e.g. "orders". help-center-list-topics returns the valid slugs.',
					),
				),
				'required'             => array( 'title', 'summary', 'content' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'id'       => array( 'type' => 'integer' ),
					'status'   => array( 'type' => 'string' ),
					'edit_url' => array( 'type' => 'string' ),
					'message'  => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => 'help_center_create_draft',
			'permission_callback' => static fn(): bool => current_user_can( 'edit_posts' ),
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
			),
		)
	);

	// Resource: reference material the client loads as context.
	wp_register_ability(
		'help-center/style-guide',
		array(
			'label'               => __( 'Help center style guide', 'help-center-mcp' ),
			'description'         => __( 'How help articles are written: audience, tone, structure and length. Read it before drafting an article.', 'help-center-mcp' ),
			'category'            => 'help-center',
			'execute_callback'    => 'help_center_style_guide',
			'permission_callback' => static fn(): bool => current_user_can( 'edit_posts' ),
			'meta'                => array(
				'mcp' => array(
					'type'        => 'resource',
					'uri'         => 'help-center://style-guide',
					'mimeType'    => 'text/markdown',
					'annotations' => array(
						'audience' => array( 'assistant' ),
						'priority' => 0.8,
					),
				),
			),
		)
	);

	// Prompt: a reusable task template the user starts.
	wp_register_ability(
		'help-center/answer-customer-question',
		array(
			'label'               => __( 'Answer a customer question', 'help-center-mcp' ),
			'description'         => __( 'Answer a customer question using only the help center, link the articles used, and offer a draft article when the answer is missing.', 'help-center-mcp' ),
			'category'            => 'help-center',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'question' => array(
						'type'        => 'string',
						'description' => 'The customer question, in their own words.',
					),
				),
				'required'   => array( 'question' ),
			),
			'execute_callback'    => 'help_center_answer_question_prompt',
			'permission_callback' => static fn(): bool => current_user_can( 'read' ),
			'meta'                => array(
				'mcp' => array( 'type' => 'prompt' ),
			),
		)
	);
}

/**
 * Searches published articles.
 *
 * @param array $input Validated input: query, optional topic and limit.
 * @return array|WP_Error
 */
function help_center_search_articles( array $input ) {
	$args = array(
		'post_type'      => 'help_article',
		'post_status'    => 'publish',
		's'              => $input['query'],
		'orderby'        => 'relevance',
		'posts_per_page' => $input['limit'] ?? 5,
	);

	if ( ! empty( $input['topic'] ) ) {
		// An unknown topic is an error, not an empty result: "no matches" would suggest the help center lacks the answer.
		$topic = help_center_find_topic( $input['topic'] );

		if ( is_wp_error( $topic ) ) {
			return $topic;
		}

		$args['tax_query'] = array(
			array(
				'taxonomy' => 'help_topic',
				'field'    => 'term_id',
				'terms'    => $topic->term_id,
			),
		);
	}

	$articles = array_map( 'help_center_article_summary', get_posts( $args ) );

	if ( ! $articles ) {
		return array(
			'articles' => array(),
			'hint'     => 'No published article matches. Try one broader keyword or a synonym. If the help center does not cover the question, say so instead of guessing.',
		);
	}

	return array( 'articles' => $articles );
}

/**
 * Anyone may read published articles; other statuses need edit rights for that post.
 *
 * @param array $input Validated input: id.
 * @return bool
 */
function help_center_can_read_article( array $input ): bool {
	$post = get_post( $input['id'] );

	if ( ! $post || 'help_article' !== $post->post_type ) {
		return true; // Let the callback answer "not found" instead of a misleading "forbidden".
	}

	return 'publish' === $post->post_status || current_user_can( 'edit_post', $post->ID );
}

/**
 * Returns one article, with its content as plain text.
 *
 * @param array $input Validated input: id.
 * @return array|WP_Error
 */
function help_center_get_article( array $input ) {
	$post = get_post( $input['id'] );

	if ( ! $post || 'help_article' !== $post->post_type ) {
		return new WP_Error( 'help_center_not_found', 'No help article has this ID. Use help-center-search-articles to find one.' );
	}

	return array_merge(
		help_center_article_summary( $post ),
		array(
			'status'       => $post->post_status,
			'content'      => help_center_blocks_to_text( $post->post_content ),
			'last_updated' => get_post_modified_time( 'c', true, $post ),
		)
	);
}

/**
 * Lists all topics, including new ones without published articles: drafts can still be filed under them.
 *
 * @return array
 */
function help_center_list_topics(): array {
	$terms = get_terms(
		array(
			'taxonomy'   => 'help_topic',
			'hide_empty' => false,
		)
	);

	return array(
		'topics' => array_map(
			static fn( WP_Term $term ): array => array(
				'slug'          => $term->slug,
				'name'          => html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' ), // Stored as "Billing &amp; Payments".
				'article_count' => $term->count, // Counts published articles only.
				'url'           => get_term_link( $term ),
			),
			$terms
		),
	);
}

/**
 * Finds a topic by slug. The error lists the valid slugs, so the model can correct itself.
 *
 * @param string $slug Topic slug.
 * @return WP_Term|WP_Error
 */
function help_center_find_topic( string $slug ) {
	$topic = get_term_by( 'slug', $slug, 'help_topic' );

	if ( $topic ) {
		return $topic;
	}

	$valid_topics = get_terms(
		array(
			'taxonomy'   => 'help_topic',
			'hide_empty' => false,
			'fields'     => 'slugs',
		)
	);

	return new WP_Error(
		'help_center_unknown_topic',
		sprintf( 'Unknown topic "%s". Use one of: %s.', $slug, implode( ', ', $valid_topics ) )
	);
}

/**
 * Saves an article draft. The status is always "draft": publishing stays a human decision.
 *
 * @param array $input Validated input: title, summary, content, optional topic.
 * @return array|WP_Error
 */
function help_center_create_draft( array $input ) {
	$topic = null;

	if ( ! empty( $input['topic'] ) ) {
		$topic = help_center_find_topic( $input['topic'] );

		if ( is_wp_error( $topic ) ) {
			return $topic;
		}
	}

	$post_id = wp_insert_post(
		array(
			'post_type'    => 'help_article',
			'post_status'  => 'draft',
			'post_title'   => sanitize_text_field( $input['title'] ),
			'post_excerpt' => sanitize_text_field( $input['summary'] ),
			'post_content' => help_center_text_to_blocks( $input['content'] ),
			'post_author'  => get_current_user_id(),
		),
		true
	);

	if ( is_wp_error( $post_id ) ) {
		return $post_id;
	}

	if ( $topic ) {
		wp_set_object_terms( $post_id, $topic->term_id, 'help_topic' );
	}

	return array(
		'id'       => $post_id,
		'status'   => 'draft',
		'edit_url' => admin_url( 'post.php?post=' . $post_id . '&action=edit' ),
		'message'  => 'Draft saved. An editor reviews it, including any [CHECK: ...] notes, before it is published.',
	);
}

/**
 * The style guide, served as an MCP resource.
 *
 * @return array[] Resource contents.
 */
function help_center_style_guide(): array {
	$site = get_bloginfo( 'name' );

	$text = <<<MARKDOWN
		# {$site} help center style guide

		- **Audience:** customers, not engineers. Use plain language and address the reader as "you".
		- **Title:** the question as a customer would ask it, e.g. "How do I reset my password?".
		- **Structure:** answer in the first sentence, then the steps as a list, then edge cases.
		- **Length:** under 250 words, one question per article.
		- **Facts:** never invent policies, prices, dates or deadlines. Mark anything unconfirmed as [CHECK: ...] so the editor verifies it.
		- **Ending:** stop after the last fact. Don't add a contact line: every article page already ends with a "Contact support" box.
		MARKDOWN;

	return array(
		array(
			'uri'      => 'help-center://style-guide',
			'mimeType' => 'text/markdown',
			'text'     => $text,
		),
	);
}

/**
 * Builds the "answer a customer question" prompt.
 *
 * @param array $input Validated input: question.
 * @return array{messages: array[]}
 */
function help_center_answer_question_prompt( array $input ): array {
	$text = sprintf(
		"A customer asked: \"%s\"\n\n" .
		"Answer as a friendly support agent for %s:\n" .
		"1. Search the help center (try two or three different keywords if the first search finds nothing) and read the most relevant articles in full.\n" .
		"2. Answer only with facts from those articles, in plain language, and link each article you used.\n" .
		"3. If the help center does not cover the question, say so honestly and do not guess. Then offer to draft a new article that follows the help-center://style-guide resource.",
		$input['question'],
		get_bloginfo( 'name' )
	);

	return array(
		'messages' => array(
			array(
				'role'    => 'user',
				'content' => array(
					'type' => 'text',
					'text' => $text,
				),
			),
		),
	);
}
