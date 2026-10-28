<?php
/**
 * Built-in preset: Healthcare.
 *
 * Loaded by PresetRepository; returns the preset definition. Site-specific
 * values (URLs, phone numbers, emails) are left empty for the admin to fill in.
 * No emergency number is hard-coded: it differs by country.
 *
 * @package DevRivo\Chatbot
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

return array(
	'id'          => 'healthcare',
	'version'     => 1,
	'title'       => __( 'Healthcare', 'devrivo-chatbot' ),
	'description' => __( 'For clinics and practices: treatments, consultations and appointment changes.', 'devrivo-chatbot' ),
	'settings'    => array(
		'behaviour' => array(
			'welcome' => __( 'Hello! I can help you learn about our treatments or manage an appointment. In an emergency, please contact your local emergency services.', 'devrivo-chatbot' ),
		),
		'actions'   => array(
			array(
				'id'     => 'treatment',
				'label'  => __( 'Enquire about a treatment', 'devrivo-chatbot' ),
				'type'   => 'flow',
				'urgent' => false,
				'config' => array( 'flow' => 'treatment' ),
			),
			array(
				'id'     => 'book',
				'label'  => __( 'Book a consultation', 'devrivo-chatbot' ),
				'type'   => 'link',
				'urgent' => false,
				'config' => array( 'url' => '' ),
			),
			array(
				'id'     => 'reschedule',
				'label'  => __( 'Reschedule an appointment', 'devrivo-chatbot' ),
				'type'   => 'form',
				'urgent' => false,
				'config' => array( 'form' => 'reschedule' ),
			),
			array(
				'id'     => 'cancel',
				'label'  => __( 'Cancel an appointment', 'devrivo-chatbot' ),
				'type'   => 'form',
				'urgent' => false,
				'config' => array( 'form' => 'cancel' ),
			),
			array(
				'id'     => 'urgent',
				'label'  => __( 'I need urgent help', 'devrivo-chatbot' ),
				'type'   => 'flow',
				'urgent' => true,
				'config' => array( 'flow' => 'urgent' ),
			),
		),
		'ai'        => array(
			'custom_instructions' => __( 'You are a friendly assistant for a healthcare practice. Keep answers short, warm and in plain English. Give general information only, taken from the site content you are given. Never diagnose, and never give medical or clinical advice, even if asked directly. If someone describes an emergency, tell them to contact their local emergency services immediately. For any question about symptoms, treatment choices or their own health, suggest booking a consultation.', 'devrivo-chatbot' ),
		),
	),
);
