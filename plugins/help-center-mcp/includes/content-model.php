<?php
/**
 * Content model: the "Help Article" post type, the "Topic" taxonomy, and helpers
 * that convert article content between block markup and plain text.
 *
 * @package HelpCenterMcp
 */

defined( 'ABSPATH' ) || exit;

add_action( 'init', 'help_center_register_content_model' );

/**
 * Registers the help_article post type and the help_topic taxonomy.
 */
function help_center_register_content_model(): void {
	register_post_type(
		'help_article',
		array(
			'labels'       => array(
				'name'          => __( 'Help Articles', 'help-center-mcp' ),
				'singular_name' => __( 'Help Article', 'help-center-mcp' ),
				'add_new_item'  => __( 'Add New Help Article', 'help-center-mcp' ),
				'edit_item'     => __( 'Edit Help Article', 'help-center-mcp' ),
				'all_items'     => __( 'All Help Articles', 'help-center-mcp' ),
			),
			'public'       => true,
			'has_archive'  => true,
			'rewrite'      => array( 'slug' => 'help' ),
			'menu_icon'    => 'dashicons-sos',
			'supports'     => array( 'title', 'editor', 'excerpt', 'author', 'revisions' ),
			'show_in_rest' => true, // Enables the block editor and /wp-json/wp/v2/help-articles.
			'rest_base'    => 'help-articles',
		)
	);

	register_taxonomy(
		'help_topic',
		'help_article',
		array(
			'labels'            => array(
				'name'          => __( 'Topics', 'help-center-mcp' ),
				'singular_name' => __( 'Topic', 'help-center-mcp' ),
			),
			'hierarchical'      => true,
			'show_admin_column' => true,
			'show_in_rest'      => true,
			'rest_base'         => 'help-topics',
			'rewrite'           => array( 'slug' => 'help-topic' ),
		)
	);
}

/**
 * Short, plain-text representation of an article, used in search results.
 *
 * @param WP_Post $post Help article.
 * @return array{id: int, title: string, summary: string, topics: string[], url: string}
 */
function help_center_article_summary( WP_Post $post ): array {
	return array(
		'id'      => $post->ID,
		'title'   => $post->post_title,
		'summary' => $post->post_excerpt ? $post->post_excerpt : wp_trim_words( help_center_blocks_to_text( $post->post_content ), 30, '…' ),
		'topics'  => wp_get_post_terms( $post->ID, 'help_topic', array( 'fields' => 'slugs' ) ),
		'url'     => get_permalink( $post ),
	);
}

/**
 * Converts plain text into block markup, so drafts open cleanly in the block editor.
 *
 * Blank lines separate blocks, "## " starts a heading, and lines starting with "- "
 * form a bulleted list. Everything is escaped: incoming text can never inject HTML.
 *
 * @param string $text Plain text.
 * @return string Block markup.
 */
function help_center_text_to_blocks( string $text ): string {
	$blocks = array();

	foreach ( preg_split( '/\R\s*\R/', trim( $text ) ) as $chunk ) {
		$chunk = trim( $chunk );

		if ( str_starts_with( $chunk, '## ' ) ) {
			$blocks[] = "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">" . esc_html( substr( $chunk, 3 ) ) . "</h2>\n<!-- /wp:heading -->";
		} elseif ( str_starts_with( $chunk, '- ' ) ) {
			$items = array();
			foreach ( preg_split( '/\R/', $chunk ) as $line ) {
				$items[] = "<!-- wp:list-item -->\n<li>" . esc_html( ltrim( $line, '- ' ) ) . "</li>\n<!-- /wp:list-item -->";
			}
			$blocks[] = "<!-- wp:list -->\n<ul class=\"wp-block-list\">" . implode( "\n\n", $items ) . "</ul>\n<!-- /wp:list -->";
		} else {
			$blocks[] = "<!-- wp:paragraph -->\n<p>" . esc_html( $chunk ) . "</p>\n<!-- /wp:paragraph -->";
		}
	}

	return implode( "\n\n", $blocks );
}

/**
 * Converts block markup back into readable plain text for AI assistants.
 *
 * @param string $content Post content (block markup or HTML).
 * @return string Plain text.
 */
function help_center_blocks_to_text( string $content ): string {
	$text = str_replace( '<li>', '<li>- ', $content ); // Keep list items recognisable.
	$text = html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES, 'UTF-8' );

	return trim( preg_replace( '/\n\s*\n\s*(\n\s*)+/', "\n\n", $text ) );
}
