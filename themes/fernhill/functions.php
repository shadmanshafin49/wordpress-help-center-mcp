<?php
/**
 * Fernhill Help Center theme: styles, help center shortcodes and search behaviour.
 *
 * The help_article post type and help_topic taxonomy come from the Help Center MCP
 * plugin; this theme only decides how they look.
 *
 * @package Fernhill
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_enqueue_scripts', 'fernhill_enqueue_styles' );
add_action( 'pre_get_posts', 'fernhill_adjust_main_query' );
add_shortcode( 'fernhill_topics', 'fernhill_topics_shortcode' );
add_shortcode( 'fernhill_breadcrumbs', 'fernhill_breadcrumbs_shortcode' );
add_shortcode( 'fernhill_related', 'fernhill_related_shortcode' );
add_shortcode( 'fernhill_updated', 'fernhill_updated_shortcode' );

/**
 * Loads the theme stylesheet.
 */
function fernhill_enqueue_styles(): void {
	wp_enqueue_style( 'fernhill', get_stylesheet_uri(), array(), wp_get_theme()->get( 'Version' ) );
}

/**
 * Site search only looks at help articles, and article lists are sorted A–Z.
 *
 * @param WP_Query $query The query WordPress is about to run.
 */
function fernhill_adjust_main_query( WP_Query $query ): void {
	if ( is_admin() || ! $query->is_main_query() ) {
		return;
	}

	if ( $query->is_search() ) {
		$query->set( 'post_type', 'help_article' );
	}

	if ( $query->is_post_type_archive( 'help_article' ) || $query->is_tax( 'help_topic' ) ) {
		$query->set( 'orderby', 'title' );
		$query->set( 'order', 'ASC' );
		$query->set( 'posts_per_page', 50 );
	}
}

/**
 * [fernhill_topics]: one card per topic, with its description and article count.
 *
 * @return string HTML.
 */
function fernhill_topics_shortcode(): string {
	$topics = get_terms(
		array(
			'taxonomy'   => 'help_topic',
			'hide_empty' => false,
		)
	);

	if ( is_wp_error( $topics ) || ! $topics ) {
		return '';
	}

	$cards = '';
	foreach ( $topics as $topic ) {
		$cards .= sprintf(
			'<a class="fh-topic" href="%1$s"><span class="fh-topic__name">%2$s</span><span class="fh-topic__description">%3$s</span><span class="fh-topic__count">%4$s</span></a>',
			esc_url( get_term_link( $topic ) ),
			esc_html( $topic->name ),
			esc_html( $topic->description ),
			/* translators: %d: number of published articles in the topic. */
			esc_html( sprintf( _n( '%d article', '%d articles', $topic->count, 'fernhill' ), $topic->count ) )
		);
	}

	return '<div class="fh-topics">' . $cards . '</div>';
}

/**
 * [fernhill_breadcrumbs]: Help Center › Topic (on articles) or › All articles (on lists).
 *
 * @return string HTML.
 */
function fernhill_breadcrumbs_shortcode(): string {
	$crumbs = array(
		sprintf( '<a href="%s">%s</a>', esc_url( home_url( '/' ) ), esc_html__( 'Help Center', 'fernhill' ) ),
	);

	if ( is_singular( 'help_article' ) ) {
		$topics = get_the_terms( get_queried_object_id(), 'help_topic' );

		if ( $topics && ! is_wp_error( $topics ) ) {
			$crumbs[] = sprintf( '<a href="%s">%s</a>', esc_url( get_term_link( $topics[0] ) ), esc_html( $topics[0]->name ) );
		}
	} else {
		$crumbs[] = sprintf( '<a href="%s">%s</a>', esc_url( get_post_type_archive_link( 'help_article' ) ), esc_html__( 'All articles', 'fernhill' ) );
	}

	return '<nav class="fh-breadcrumbs" aria-label="' . esc_attr__( 'Breadcrumb', 'fernhill' ) . '">' . implode( '<span aria-hidden="true">›</span>', $crumbs ) . '</nav>';
}

/**
 * [fernhill_updated]: when the current article was last updated.
 *
 * The core Date block only shows a modified date once a post has been edited after
 * publishing, so freshly published articles would show nothing.
 *
 * @return string HTML.
 */
function fernhill_updated_shortcode(): string {
	return '<p class="fh-meta">' . esc_html(
		/* translators: %s: date the article was last updated. */
		sprintf( __( 'Updated %s', 'fernhill' ), get_the_modified_date( '', get_queried_object_id() ) )
	) . '</p>';
}

/**
 * [fernhill_related]: other published articles from the same topic.
 *
 * @return string HTML.
 */
function fernhill_related_shortcode(): string {
	$post_id = get_queried_object_id();
	$topics  = get_the_terms( $post_id, 'help_topic' );

	if ( ! $topics || is_wp_error( $topics ) ) {
		return '';
	}

	$related = get_posts(
		array(
			'post_type'      => 'help_article',
			'post_status'    => 'publish',
			'posts_per_page' => 5,
			'post__not_in'   => array( $post_id ),
			'tax_query'      => array(
				array(
					'taxonomy' => 'help_topic',
					'terms'    => wp_list_pluck( $topics, 'term_id' ),
				),
			),
		)
	);

	if ( ! $related ) {
		return '';
	}

	$items = '';
	foreach ( $related as $article ) {
		$items .= sprintf( '<li><a href="%s">%s</a></li>', esc_url( get_permalink( $article ) ), esc_html( get_the_title( $article ) ) );
	}

	return '<section class="fh-related"><h2>' . esc_html__( 'Related articles', 'fernhill' ) . '</h2><ul>' . $items . '</ul></section>';
}
