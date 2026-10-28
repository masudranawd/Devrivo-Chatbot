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
	'version'     => 1,
	'title'       => __( 'Business', 'devrivo-chatbot' ),
	'description' => __( 'For company and service websites: services, contact, careers and support.', 'devrivo-chatbot' ),
	'settings'    => array(
		'behaviour' => array(
			'welcome' => __( 'Hi there! I can tell you about our services, help you get in touch or point you to the right page.', 'devrivo-chatbot' ),
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
		'ai'        => array(
			'custom_instructions' => __( 'You are a friendly assistant for this business. Keep answers short, warm and in plain English. Answer only from the site content you are given. If you are not sure, say so and offer the contact form instead of guessing. Never make up prices, dates or promises.', 'devrivo-chatbot' ),
		),
	),
);
