<?php
/**
 * Site content: help topics, help articles, and the Contact and AI & MCP pages.
 * Also removes WordPress's default sample content ("Hello world!" and "Sample Page").
 *
 * Run with WP-CLI (setup.sh does this): wp eval-file /project/setup/seed.php
 * Safe to re-run: existing topics, articles and pages are left alone.
 *
 * @package HelpCenterMcp
 */

$topics = array(
	'account'  => array( 'Account & Login', 'Signing in, passwords and your account settings.' ),
	'billing'  => array( 'Billing & Payments', 'Payment methods, charges, discount codes and gift cards.' ),
	'orders'   => array( 'Orders & Returns', 'Returns, refunds, damaged items and warranty.' ),
	'shipping' => array( 'Shipping & Delivery', 'Delivery options, costs and order tracking.' ),
);

foreach ( $topics as $slug => list( $name, $description ) ) {
	$term = get_term_by( 'slug', $slug, 'help_topic' );

	if ( ! $term ) {
		wp_insert_term(
			$name,
			'help_topic',
			array(
				'slug'        => $slug,
				'description' => $description,
			)
		);
	} elseif ( '' === $term->description ) {
		wp_update_term( $term->term_id, 'help_topic', array( 'description' => $description ) );
	}
}

$articles = array(
	array(
		'slug'    => 'reset-your-password',
		'topic'   => 'account',
		'title'   => 'How do I reset my password?',
		'summary' => 'Select "Forgot password?" on the sign-in page and follow the link we email you. The link works for 60 minutes.',
		'content' => <<<'TEXT'
			If you can't remember your password, you can choose a new one in about two minutes.

			- Go to the sign-in page and select "Forgot password?".
			- Enter the email address on your account.
			- Open the email from Fernhill and select "Reset password".
			- Choose a new password with at least 12 characters.

			The reset link works for 60 minutes. If it has expired, request a new one.

			No email? Check your spam folder and make sure you used the address you signed up with.
			TEXT,
	),
	array(
		'slug'    => 'change-your-email-address',
		'topic'   => 'account',
		'title'   => 'How do I change my email address?',
		'summary' => 'Go to Account > Profile, select "Change email" and confirm the new address from the link we send.',
		'content' => <<<'TEXT'
			You can change the email address on your account at any time.

			- Sign in and open Account > Profile.
			- Select "Change email" and enter the new address.
			- Confirm the change using the link we send to the new address.

			Until you confirm, receipts and delivery updates keep going to your old address.
			TEXT,
	),
	array(
		'slug'    => 'delete-your-account',
		'topic'   => 'account',
		'title'   => 'How do I delete my account?',
		'summary' => 'Go to Account > Privacy and select "Delete account". Your account is permanently deleted after 14 days.',
		'content' => <<<'TEXT'
			You can delete your Fernhill account at any time.

			- Sign in and open Account > Privacy.
			- Select "Delete account" and confirm with your password.

			Your account is deactivated straight away and permanently deleted after 14 days. To change your mind, sign in again within those 14 days.

			We keep order records for 7 years because tax law requires it, but they are no longer linked to an account you can sign in to.
			TEXT,
	),
	array(
		'slug'    => 'payment-methods',
		'topic'   => 'billing',
		'title'   => 'Which payment methods do you accept?',
		'summary' => 'Visa, Mastercard, American Express, PayPal and Fernhill gift cards.',
		'content' => <<<'TEXT'
			We accept Visa, Mastercard, American Express, PayPal and Fernhill gift cards.

			We don't accept cash on delivery, bank transfers or cryptocurrency.

			Your card is charged when your order ships, not when you place it.
			TEXT,
	),
	array(
		'slug'    => 'charged-twice',
		'topic'   => 'billing',
		'title'   => 'Why was I charged twice?',
		'summary' => 'One of the charges is usually a temporary authorization hold that your bank removes within 3-5 business days.',
		'content' => <<<'TEXT'
			If you see two charges for one order, one of them is almost always a temporary authorization hold. Your bank places the hold when you order and removes it automatically, usually within 3-5 business days.

			Only the charge made when your order ships is final.

			If both charges are still there after 5 business days, contact us with your order number and we will refund the duplicate.
			TEXT,
	),
	array(
		'slug'    => 'use-a-discount-code',
		'topic'   => 'billing',
		'title'   => 'How do I use a discount code?',
		'summary' => 'Enter the code in the "Discount code" box at checkout. You can use one code per order.',
		'content' => <<<'TEXT'
			Enter your code in the "Discount code" box on the checkout page and select "Apply". The new total appears before you pay.

			- You can use one code per order.
			- A code can't be added to an order that has already been placed.
			- Gift cards aren't discount codes: add them under "Payment" instead.

			If a code doesn't work, check its expiry date and any minimum order value in the email it came with.
			TEXT,
	),
	array(
		'slug'    => 'returns-and-refunds',
		'topic'   => 'orders',
		'title'   => 'How do returns and refunds work?',
		'summary' => 'Return unused items within 30 days of delivery. We refund your original payment method within 2 business days of receiving the return.',
		'content' => <<<'TEXT'
			You can return unused items in their original packaging within 30 days of delivery.

			- Open Account > Orders, choose the order and select "Return items".
			- Print the prepaid return label.
			- Drop the parcel off at any post office.

			We refund your original payment method within 2 business days of receiving the return. Your bank may take another 5-7 business days to show it.

			Sale items and gift cards can't be returned.
			TEXT,
	),
	array(
		'slug'    => 'damaged-or-wrong-item',
		'topic'   => 'orders',
		'title'   => 'What if my order arrives damaged or wrong?',
		'summary' => 'Tell us within 7 days of delivery, with a photo, and we will send a replacement or a full refund.',
		'content' => <<<'TEXT'
			We're sorry! Tell us within 7 days of delivery and we'll put it right.

			- Open Account > Orders, choose the order and select "Report a problem".
			- Add a photo of the item and, if it's damaged, of the packaging.
			- Choose a replacement or a full refund.

			You don't need to send damaged items back. Replacements ship within 1 business day.
			TEXT,
	),
	array(
		'slug'    => 'product-warranty',
		'topic'   => 'orders',
		'title'   => 'Do your products come with a warranty?',
		'summary' => 'Every Fernhill product has a 2-year warranty against manufacturing defects.',
		'content' => <<<'TEXT'
			Every Fernhill product has a 2-year warranty against manufacturing defects, starting from the delivery date.

			The warranty doesn't cover normal wear, accidental damage, or damage from not following the care instructions.

			To make a claim, open Account > Orders, choose the order and select "Report a problem". Add a photo and a short description, and we'll reply within one business day.
			TEXT,
	),
	array(
		'slug'    => 'track-your-order',
		'topic'   => 'shipping',
		'title'   => 'How do I track my order?',
		'summary' => 'Use the tracking link in your shipping email, or find it under Account > Orders.',
		'content' => <<<'TEXT'
			As soon as your order ships, we email you a tracking link.

			You can also find it at any time under Account > Orders. The first tracking update can take up to 24 hours to appear.

			If tracking hasn't changed for 5 business days, contact us and we will open an investigation with the carrier.
			TEXT,
	),
	array(
		'slug'    => 'shipping-times-and-costs',
		'topic'   => 'shipping',
		'title'   => 'How long does shipping take and what does it cost?',
		'summary' => 'Standard shipping takes 3-5 business days ($4.99, free over $50). Express takes 1-2 business days ($14.99).',
		'content' => <<<'TEXT'
			## Delivery options

			- Standard: 3-5 business days, $4.99, free on orders over $50.
			- Express: 1-2 business days, $14.99.

			Orders placed before 2 pm Eastern Time on a business day ship the same day.

			We currently deliver to addresses in the United States only.
			TEXT,
	),
);

$created = 0;

foreach ( $articles as $article ) {
	if ( get_page_by_path( $article['slug'], OBJECT, 'help_article' ) ) {
		continue;
	}

	$post_id = wp_insert_post(
		array(
			'post_type'    => 'help_article',
			'post_status'  => 'publish',
			'post_name'    => $article['slug'],
			'post_title'   => $article['title'],
			'post_excerpt' => $article['summary'],
			'post_content' => help_center_text_to_blocks( $article['content'] ),
			'post_author'  => 1,
		),
		true
	);

	if ( is_wp_error( $post_id ) ) {
		WP_CLI::warning( $article['slug'] . ': ' . $post_id->get_error_message() );
		continue;
	}

	wp_set_object_terms( $post_id, $article['topic'], 'help_topic' );
	++$created;
}

if ( ! get_page_by_path( 'contact' ) ) {
	wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_name'    => 'contact',
			'post_title'   => 'Contact support',
			'post_author'  => 1,
			'post_content' => <<<'HTML'
				<!-- wp:paragraph -->
				<p>We're a small team, and a real person reads every message. Most questions are already answered in our <a href="/help/">help articles</a>.</p>
				<!-- /wp:paragraph -->

				<!-- wp:heading -->
				<h2 class="wp-block-heading">Email us</h2>
				<!-- /wp:heading -->

				<!-- wp:paragraph -->
				<p><a href="mailto:support@fernhill.example">support@fernhill.example</a>. We reply within one business day.</p>
				<!-- /wp:paragraph -->

				<!-- wp:heading -->
				<h2 class="wp-block-heading">Opening hours</h2>
				<!-- /wp:heading -->

				<!-- wp:paragraph -->
				<p>Monday to Friday, 9 am to 6 pm Eastern Time.</p>
				<!-- /wp:paragraph -->

				<!-- wp:heading -->
				<h2 class="wp-block-heading">Before you write</h2>
				<!-- /wp:heading -->

				<!-- wp:paragraph -->
				<p>Have your order number ready. You'll find it in your order confirmation email and under Account &gt; Orders.</p>
				<!-- /wp:paragraph -->
				HTML,
		)
	);
}

// The page that shows the MCP server at work. Its content comes from the plugin's shortcode.
if ( ! get_page_by_path( 'ai' ) ) {
	wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_name'    => 'ai',
			'post_title'   => 'AI & MCP',
			'post_author'  => 1,
			'post_content' => '<!-- wp:shortcode -->[help_center_ai_showcase]<!-- /wp:shortcode -->',
		)
	);
}

// WordPress's own sample content doesn't belong on a live help center.
foreach ( array( 'hello-world' => 'post', 'sample-page' => 'page' ) as $slug => $type ) {
	$sample = get_page_by_path( $slug, OBJECT, $type );

	if ( $sample ) {
		wp_delete_post( $sample->ID, true );
	}
}

WP_CLI::success( sprintf( '%d help articles created.', $created ) );
