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
	'version'     => 2,
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
				'config' => array( 'flow' => 'enquire' ),
			),
			array(
				'id'     => 'book',
				'label'  => __( 'Book a consultation', 'devrivo-chatbot' ),
				'type'   => 'link',
				'urgent' => false,
				'config' => array(
					'url'     => '',
					'new_tab' => true,
				),
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
			// Helpers used only inside flows.
			array(
				'id'           => 'call_practice',
				'label'        => __( 'Call the practice', 'devrivo-chatbot' ),
				'type'         => 'call',
				'urgent'       => false,
				'config'       => array( 'phone' => '' ),
				'show_in_menu' => false,
			),
			array(
				'id'           => 'whatsapp_practice',
				'label'        => __( 'Message us on WhatsApp', 'devrivo-chatbot' ),
				'type'         => 'whatsapp',
				'urgent'       => false,
				'config'       => array(
					'phone'   => '',
					'message' => __( 'Hi, I need some urgent help, please.', 'devrivo-chatbot' ),
				),
				'show_in_menu' => false,
			),
		),
		'flows'     => array(
			'enquire' => array(
				'title' => __( 'Enquire about a treatment', 'devrivo-chatbot' ),
				'start' => 'ask',
				'nodes' => array(
					'ask'     => array(
						'type'    => 'choices',
						'text'    => __( 'Happy to help. What is your enquiry about?', 'devrivo-chatbot' ),
						'choices' => array(
							array(
								/* translators: Quick reply, keep under 60 characters. */
								'label' => __( 'A new treatment', 'devrivo-chatbot' ),
								'next'  => 'new',
							),
							array(
								/* translators: Quick reply, keep under 60 characters. */
								'label' => __( 'A check-up or hygiene visit', 'devrivo-chatbot' ),
								'next'  => 'checkup',
							),
							array(
								/* translators: Quick reply, keep under 60 characters. */
								'label' => __( 'Something else', 'devrivo-chatbot' ),
								'next'  => 'other',
							),
						),
					),
					'new'     => array(
						'type'    => 'action',
						'text'    => __( 'The best first step is a consultation, where you can talk through your options with no obligation. Book a time that suits you, or give us a call.', 'devrivo-chatbot' ),
						'actions' => array( 'book', 'call_practice' ),
					),
					'checkup' => array(
						'type'    => 'action',
						'text'    => __( 'We would be glad to see you. Book a time online, or call us and the team will find a slot.', 'devrivo-chatbot' ),
						'actions' => array( 'book', 'call_practice' ),
					),
					'other'   => array(
						'type'    => 'action',
						'text'    => __( 'No problem. The team can help with most questions over the phone, or you can book a consultation to talk it through.', 'devrivo-chatbot' ),
						'actions' => array( 'book', 'call_practice' ),
					),
				),
			),
			'urgent'  => array(
				'title' => __( 'I need urgent help', 'devrivo-chatbot' ),
				'start' => 'triage',
				'nodes' => array(
					'triage'    => array(
						'type'    => 'choices',
						'text'    => __( 'I am sorry you are dealing with this. Which best describes what is happening?', 'devrivo-chatbot' ),
						'choices' => array(
							array(
								/* translators: Quick reply, keep under 60 characters. */
								'label'  => __( 'Trouble breathing or swallowing, or heavy bleeding', 'devrivo-chatbot' ),
								'next'   => 'emergency',
								'urgent' => true,
							),
							array(
								/* translators: Quick reply, keep under 60 characters. */
								'label' => __( 'Severe toothache or facial swelling', 'devrivo-chatbot' ),
								'next'  => 'when',
							),
							array(
								/* translators: Quick reply, keep under 60 characters. */
								'label' => __( 'A broken or knocked-out tooth', 'devrivo-chatbot' ),
								'next'  => 'when',
							),
							array(
								/* translators: Quick reply, keep under 60 characters. */
								'label' => __( 'A lost filling, crown or other problem', 'devrivo-chatbot' ),
								'next'  => 'when',
							),
						),
					),
					'emergency' => array(
						'type' => 'end',
						'text' => __( 'Please contact your local emergency services immediately. Swelling that affects breathing or swallowing, or bleeding that will not stop, needs urgent medical attention.', 'devrivo-chatbot' ),
					),
					'when'      => array(
						'type'      => 'condition',
						'condition' => 'open_now',
						'then'      => 'open',
						'else'      => 'closed',
					),
					'open'      => array(
						'type'    => 'action',
						'text'    => __( 'Please call the practice now so the team can find you the earliest possible appointment.', 'devrivo-chatbot' ),
						'actions' => array( 'call_practice' ),
					),
					'closed'    => array(
						'type'    => 'action',
						'text'    => __( 'The practice may be closed right now. For urgent advice, please contact your local out-of-hours dental service. You can also message us on WhatsApp and the team will reply as soon as they can.', 'devrivo-chatbot' ),
						'actions' => array( 'whatsapp_practice' ),
					),
				),
			),
		),
		'ai'        => array(
			'custom_instructions' => __( 'You are a friendly assistant for a healthcare practice. Keep answers short, warm and in plain English. Give general information only, taken from the site content you are given. Never diagnose, and never give medical or clinical advice, even if asked directly. If someone describes an emergency, tell them to contact their local emergency services immediately. For any question about symptoms, treatment choices or their own health, suggest booking a consultation.', 'devrivo-chatbot' ),
		),
	),
);
