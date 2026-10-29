<?php
/**
 * Built-in preset: Business.
 *
 * Loaded by PresetRepository; returns the preset definition. Site-specific
 * values (URLs, phone numbers, emails) are left empty for the admin to fill in.
 *
 * @package DevRivo\Chatbot
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

return array(
	'id'          => 'business',
	'version'     => 3,
	'title'       => __( 'Business', 'devrivo-chatbot' ),
	'description' => __( 'For company and service websites: services, contact, careers and support.', 'devrivo-chatbot' ),
	'settings'    => array(
		'behaviour' => array(
			'welcome'         => __( 'Hi there! I can tell you about our services, help you get in touch or point you to the right page.', 'devrivo-chatbot' ),
			'fallback_action' => 'contact',
		),
		'actions'   => array(
			array(
				'id'     => 'services',
				'label'  => __( 'Our services', 'devrivo-chatbot' ),
				'type'   => 'link',
				'urgent' => false,
				'config' => array( 'url' => '' ),
			),
			array(
				'id'     => 'contact',
				'label'  => __( 'Contact us', 'devrivo-chatbot' ),
				'type'   => 'form',
				'urgent' => false,
				'config' => array( 'form' => 'contact' ),
			),
			array(
				'id'     => 'careers',
				'label'  => __( 'Careers', 'devrivo-chatbot' ),
				'type'   => 'link',
				'urgent' => false,
				'config' => array( 'url' => '' ),
			),
			array(
				'id'     => 'locations',
				'label'  => __( 'Office locations', 'devrivo-chatbot' ),
				'type'   => 'ai_prompt',
				'urgent' => false,
				'config' => array( 'prompt' => __( 'Where are your offices, and when are they open?', 'devrivo-chatbot' ) ),
			),
			array(
				'id'     => 'support',
				'label'  => __( 'Get support', 'devrivo-chatbot' ),
				'type'   => 'form',
				'urgent' => false,
				'config' => array( 'form' => 'support' ),
			),
		),
		'forms'     => array(
			'contact' => array(
				'title'           => __( 'Contact us', 'devrivo-chatbot' ),
				'submit_label'    => __( 'Send message', 'devrivo-chatbot' ),
				'success_message' => __( 'Thanks for getting in touch. We will reply as soon as we can.', 'devrivo-chatbot' ),
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
						'id'    => 'phone',
						'type'  => 'tel',
						'label' => __( 'Phone (optional)', 'devrivo-chatbot' ),
					),
					array(
						'id'         => 'message',
						'type'       => 'textarea',
						'label'      => __( 'How can we help?', 'devrivo-chatbot' ),
						'required'   => true,
						'max_length' => 1000,
					),
				),
			),
			'support' => array(
				'title'           => __( 'Get support', 'devrivo-chatbot' ),
				'submit_label'    => __( 'Send request', 'devrivo-chatbot' ),
				'success_message' => __( 'Thanks. Our team will look into this and get back to you.', 'devrivo-chatbot' ),
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
						'id'       => 'topic',
						'type'     => 'select',
						'label'    => __( 'What is it about?', 'devrivo-chatbot' ),
						'required' => true,
						'options'  => array(
							__( 'An order or booking', 'devrivo-chatbot' ),
							__( 'A technical problem', 'devrivo-chatbot' ),
							__( 'Billing', 'devrivo-chatbot' ),
							__( 'Something else', 'devrivo-chatbot' ),
						),
					),
					array(
						'id'         => 'message',
						'type'       => 'textarea',
						'label'      => __( 'Tell us what happened', 'devrivo-chatbot' ),
						'required'   => true,
						'max_length' => 1000,
					),
				),
			),
		),
		'knowledge' => array(
			'faqs' => array(
				array(
					'id'       => 'get-in-touch',
					'question' => __( 'How can I get in touch?', 'devrivo-chatbot' ),
					'answer'   => __( 'Choose "Contact us" in the menu and send us your details. Our team will get back to you.', 'devrivo-chatbot' ),
				),
				array(
					'id'       => 'support',
					'question' => __( 'I need help with something. What should I do?', 'devrivo-chatbot' ),
					'answer'   => __( 'Choose "Get support" in the menu and tell us what you need help with. Our team will get back to you.', 'devrivo-chatbot' ),
				),
				array(
					'id'       => 'services',
					'question' => __( 'What services do you offer?', 'devrivo-chatbot' ),
					'answer'   => __( 'Our services are described on this website. Ask me about a service and I will point you to the right page.', 'devrivo-chatbot' ),
				),
			),
		),
		'ai'        => array(
			'custom_instructions' => __( 'You are a friendly assistant for this business. Keep answers short, warm and in plain English. Answer only from the site content you are given. If you are not sure, say so and offer the contact form instead of guessing. Never make up prices, dates or promises.', 'devrivo-chatbot' ),
		),
	),
);
