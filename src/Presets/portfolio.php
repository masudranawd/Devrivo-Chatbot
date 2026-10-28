<?php
/**
 * Built-in preset: Portfolio / Blog.
 *
 * Loaded by PresetRepository; returns the preset definition. Site-specific
 * values (URLs, phone numbers, emails) are left empty for the admin to fill in.
 *
 * @package DevRivo\Chatbot
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

return array(
	'id'          => 'portfolio',
	'version'     => 1,
	'title'       => __( 'Portfolio / Blog', 'devrivo-chatbot' ),
	'description' => __( 'For freelancers, creators and writers: work, articles, hiring and newsletter.', 'devrivo-chatbot' ),
	'settings'    => array(
		'behaviour' => array(
			'welcome' => __( 'Hi! Have a look around. I can show you my work, find an article or help you get in touch.', 'devrivo-chatbot' ),
		),
		'actions'   => array(
			array(
				'id'     => 'work',
				'label'  => __( 'Explore my work', 'devrivo-chatbot' ),
				'type'   => 'link',
				'urgent' => false,
				'config' => array( 'url' => '' ),
			),
			array(
				'id'     => 'articles',
				'label'  => __( 'Find an article', 'devrivo-chatbot' ),
				'type'   => 'page_search',
				'urgent' => false,
				'config' => array( 'post_types' => array( 'post' ) ),
			),
			array(
				'id'     => 'hire',
				'label'  => __( 'Hire me', 'devrivo-chatbot' ),
				'type'   => 'form',
				'urgent' => false,
				'config' => array( 'form' => 'hire' ),
			),
			array(
				'id'     => 'newsletter',
				'label'  => __( 'Join the newsletter', 'devrivo-chatbot' ),
				'type'   => 'link',
				'urgent' => false,
				'config' => array( 'url' => '' ),
			),
			array(
				'id'     => 'about',
				'label'  => __( 'About me', 'devrivo-chatbot' ),
				'type'   => 'link',
				'urgent' => false,
				'config' => array( 'url' => '' ),
			),
		),
		'ai'        => array(
			'custom_instructions' => __( 'You are a friendly assistant for a personal portfolio and blog. Keep answers short, warm and in plain English, and speak about the site owner in the first person ("my work", "I write about"). Answer only from the site content you are given and link to the most relevant article or project. If you are not sure, say so and offer the hire form instead of guessing.', 'devrivo-chatbot' ),
		),
	),
);
