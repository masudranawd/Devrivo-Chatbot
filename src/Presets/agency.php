<?php
/**
 * Built-in preset: Agency.
 *
 * Loaded by PresetRepository; returns the preset definition. Site-specific
 * values (URLs, phone numbers, emails) are left empty for the admin to fill in.
 *
 * @package DevRivo\Chatbot
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

return array(
	'id'          => 'agency',
	'version'     => 2,
	'title'       => __( 'Agency', 'devrivo-chatbot' ),
	'description' => __( 'For creative and digital agencies: services, estimates, case studies and discovery calls.', 'devrivo-chatbot' ),
	'settings'    => array(
		'behaviour' => array(
			'welcome' => __( 'Hi there! Planning a project? I can walk you through our services, show our work or help you get an estimate.', 'devrivo-chatbot' ),
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
				'id'     => 'estimate',
				'label'  => __( 'Get an estimate', 'devrivo-chatbot' ),
				'type'   => 'form',
				'urgent' => false,
				'config' => array( 'form' => 'estimate' ),
			),
			array(
				'id'     => 'case-studies',
				'label'  => __( 'See case studies', 'devrivo-chatbot' ),
				'type'   => 'link',
				'urgent' => false,
				'config' => array( 'url' => '' ),
			),
			array(
				'id'     => 'discovery-call',
				'label'  => __( 'Book a discovery call', 'devrivo-chatbot' ),
				'type'   => 'link',
				'urgent' => false,
				'config' => array( 'url' => '' ),
			),
			array(
				'id'     => 'brief',
				'label'  => __( 'Send a project brief', 'devrivo-chatbot' ),
				'type'   => 'form',
				'urgent' => false,
				'config' => array( 'form' => 'brief' ),
			),
		),
		'forms'     => array(
			'estimate' => array(
				'title'           => __( 'Get an estimate', 'devrivo-chatbot' ),
				'submit_label'    => __( 'Request an estimate', 'devrivo-chatbot' ),
				'success_message' => __( 'Thanks. We will review your project and send you an estimate.', 'devrivo-chatbot' ),
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
						'id'    => 'company',
						'type'  => 'text',
						'label' => __( 'Company (optional)', 'devrivo-chatbot' ),
					),
					array(
						'id'       => 'service',
						'type'     => 'select',
						'label'    => __( 'What do you need?', 'devrivo-chatbot' ),
						'required' => true,
						'options'  => array(
							__( 'A new website', 'devrivo-chatbot' ),
							__( 'A website redesign', 'devrivo-chatbot' ),
							__( 'Branding', 'devrivo-chatbot' ),
							__( 'Digital marketing', 'devrivo-chatbot' ),
							__( 'Something else', 'devrivo-chatbot' ),
						),
					),
					array(
						'id'         => 'details',
						'type'       => 'textarea',
						'label'      => __( 'Tell us about your project', 'devrivo-chatbot' ),
						'required'   => true,
						'max_length' => 1500,
					),
				),
			),
			'brief'    => array(
				'title'           => __( 'Send a project brief', 'devrivo-chatbot' ),
				'submit_label'    => __( 'Send brief', 'devrivo-chatbot' ),
				'success_message' => __( 'Thanks for your brief. We will be in touch to talk it through.', 'devrivo-chatbot' ),
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
						'id'    => 'company',
						'type'  => 'text',
						'label' => __( 'Company (optional)', 'devrivo-chatbot' ),
					),
					array(
						'id'         => 'brief',
						'type'       => 'textarea',
						'label'      => __( 'Your brief', 'devrivo-chatbot' ),
						'required'   => true,
						'max_length' => 2000,
					),
					array(
						'id'    => 'deadline',
						'type'  => 'date',
						'label' => __( 'Ideal launch date (optional)', 'devrivo-chatbot' ),
					),
				),
			),
		),
		'ai'        => array(
			'custom_instructions' => __( 'You are a friendly assistant for a creative and digital agency. Keep answers short, warm and in plain English. Answer only from the site content you are given. Do not quote prices or timelines unless the site states them; instead, offer the estimate form or a discovery call.', 'devrivo-chatbot' ),
		),
	),
);
