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
	'version'     => 3,
	'title'       => __( 'Portfolio / Blog', 'devrivo-chatbot' ),
	'description' => __( 'For freelancers, creators and writers: work, articles, hiring and newsletter.', 'devrivo-chatbot' ),
	'settings'    => array(
		'behaviour' => array(
			'welcome'         => __( 'Hi! Have a look around. I can show you my work, find an article or help you get in touch.', 'devrivo-chatbot' ),
			'fallback_action' => 'hire',
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
		'forms'     => array(
			'hire' => array(
				'title'           => __( 'Work with me', 'devrivo-chatbot' ),
				'submit_label'    => __( 'Send', 'devrivo-chatbot' ),
				'success_message' => __( 'Thanks for reaching out. I will get back to you soon.', 'devrivo-chatbot' ),
				'fields'          => array(
					array(
						'id'       => 'name',
						'type'     => 'text',
						'label'    => __( 'Your name', 'devrivo-chatbot' ),
						'required' => true,
					),
					array(
						'id'       => 'email',
						'type'     => 'email',
						'label'    => __( 'Email', 'devrivo-chatbot' ),
						'required' => true,
					),
					array(
						'id'         => 'project',
						'type'       => 'textarea',
						'label'      => __( 'Tell me about your project', 'devrivo-chatbot' ),
						'required'   => true,
						'max_length' => 1500,
					),
					array(
						'id'    => 'timeline',
						'type'  => 'text',
						'label' => __( 'When would you like to start? (optional)', 'devrivo-chatbot' ),
					),
				),
			),
		),
		'knowledge' => array(
			'faqs' => array(
				array(
					'id'       => 'hire',
					'question' => __( 'How can I work with you?', 'devrivo-chatbot' ),
					'answer'   => __( 'Choose "Hire me" in the menu and tell me about your project. I will get back to you.', 'devrivo-chatbot' ),
				),
				array(
					'id'       => 'articles',
					'question' => __( 'Where can I find your articles?', 'devrivo-chatbot' ),
					'answer'   => __( 'Choose "Find an article" in the menu and type a topic. I will show you matching articles.', 'devrivo-chatbot' ),
				),
				array(
					'id'       => 'work',
					'question' => __( 'Where can I see your work?', 'devrivo-chatbot' ),
					'answer'   => __( 'My work is shown on this website. Ask me about a project or topic and I will point you to the right page.', 'devrivo-chatbot' ),
				),
			),
		),
		'ai'        => array(
			'custom_instructions' => __( 'You are a friendly assistant for a personal portfolio and blog. Keep answers short, warm and in plain English, and speak about the site owner in the first person ("my work", "I write about"). Answer only from the site content you are given and link to the most relevant article or project. If you are not sure, say so and offer the hire form instead of guessing.', 'devrivo-chatbot' ),
		),
	),
);
