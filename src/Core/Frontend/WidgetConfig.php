<?php
/**
 * Public widget config.
 *
 * @package DevRivo\Chatbot
 */

declare( strict_types=1 );

namespace DevRivo\Chatbot\Core\Frontend;

use DevRivo\Chatbot\AI\Models;
use DevRivo\Chatbot\Core\Rest;
use DevRivo\Chatbot\Core\Settings\Schema;
use DevRivo\Chatbot\Core\Settings\Settings;
use DevRivo\Chatbot\Engine\ActionTypes;
use DevRivo\Chatbot\Engine\Forms;
use DevRivo\Chatbot\Knowledge\Index;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the config the widget fetches from the public endpoint.
 *
 * Built from an allowlist only: AI, knowledge, privacy and integration
 * settings never leave the server here (the consent text is public by nature,
 * and the credit link is a single opt-in boolean). Actions are sent only when the widget can run
 * them (supported type, required values filled in, target flow present), with
 * only the config keys of their type. Flows are public content; choices and
 * buttons that point to actions that were left out are dropped.
 */
final class WidgetConfig {

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings repository.
	 */
	public function __construct( private Settings $settings ) {}

	/**
	 * The public config. Translated strings use the current locale.
	 *
	 * @param string $locale Locale the config is built for (passed to filters).
	 * @return array<string, mixed>
	 */
	public function build( string $locale ): array {
		$all     = $this->settings->all();
		$context = array( 'locale' => $locale );

		$display    = $all['display'];
		$appearance = $all['appearance'];
		$behaviour  = $all['behaviour'];

		$actions = $this->actions( $locale );

		$action_ids   = array_column( $actions, 'id' );
		$offer_schema = (array) ( Schema::section( 'offers' )['items'] ?? array() );
		$offers       = array();
		foreach ( $all['offers'] as $offer ) {
			// Stored list items only hold the keys that were sent.
			$offer = (array) Schema::with_defaults( $offer, $offer_schema );
			if ( '' !== $offer['action'] && ! in_array( $offer['action'], $action_ids, true ) ) {
				continue; // The action it points to was removed.
			}
			$offers[] = array(
				'id'        => $offer['id'],
				'title'     => $offer['title'],
				'text'      => $offer['text'],
				'cta_label' => $offer['cta_label'],
				'action'    => $offer['action'],
				'featured'  => $offer['featured'],
			);
		}

		/**
		 * Filters the greeting shown at the top of the widget's Home view.
		 *
		 * An empty string makes the widget greet by time of day.
		 *
		 * @since 1.0.0
		 *
		 * @param string               $greeting Greeting from the settings, or ''.
		 * @param array<string, mixed> $context  { @type string $locale Locale. }
		 */
		$greeting = (string) apply_filters( 'devrivo_chatbot_greeting', $behaviour['greeting'], $context );

		$fallback = (string) $behaviour['fallback_action'];

		$config = array(
			'version'         => Settings::config_version(),
			// AI answers: turned on, with a limit and a model from the provider's list.
			'ai_available'    => self::ai_available( (array) $all['ai'] ),
			// Shown under the composer in AI mode.
			'disclaimer'      => '' !== trim( (string) ( $all['ai']['disclaimer'] ?? '' ) ) ? trim( (string) $all['ai']['disclaimer'] ) : __( 'AI assistant. General information only.', 'devrivo-chatbot' ),
			'chat_url'        => rest_url( Rest::NAMESPACE . '/chat' ),
			// Guided answers: on when the index has pages or FAQs.
			'search_enabled'  => Index::has_content(),
			'search_url'      => rest_url( Rest::NAMESPACE . '/search' ),
			// Quick action offered when a search finds nothing.
			'fallback_action' => in_array( $fallback, $action_ids, true ) ? $fallback : '',
			'display'         => array(
				'position'       => $display['position'],
				'primary_color'  => $display['primary_color'],
				'launcher_label' => Display::launcher_label( (string) $display['launcher_label'] ),
			),
			'appearance'      => array(
				'color_scheme' => $appearance['color_scheme'],
				'radius'       => $appearance['radius'],
				'brand_name'   => $appearance['brand_name'],
				'font'         => $appearance['font'],
				'avatar_url'   => $appearance['avatar_id'] > 0 ? (string) wp_get_attachment_image_url( $appearance['avatar_id'], 'thumbnail' ) : '',
			),
			'behaviour'       => array(
				'greeting'        => $greeting,
				'welcome'         => $behaviour['welcome'],
				'auto_open'       => $behaviour['auto_open'],
				'auto_open_delay' => $behaviour['auto_open_delay'],
				'hours'           => array(
					'timezone'    => '' !== $behaviour['hours']['timezone'] ? $behaviour['hours']['timezone'] : wp_timezone_string(),
					'schedule'    => $behaviour['hours']['schedule'],
					'show_status' => $behaviour['hours']['show_status'],
				),
				'utm'             => $behaviour['utm'],
			),
			'actions'         => $actions,
			'flows'           => self::flows( $all['flows'], $action_ids ),
			'forms'           => self::forms( $all['forms'] ),
			'consent'         => Consent::public( $this->settings ),
			'session_url'     => rest_url( Rest::NAMESPACE . '/session' ),
			// Replace {form} with the form ID.
			'submit_url'      => rest_url( Rest::NAMESPACE . '/forms/{form}/submit' ),
			'offers'          => $offers,
			// "Powered by DevRivo" link in the panel footer (opt-in, off by default).
			'credit_link'     => (bool) ( $all['privacy']['credit_link'] ?? false ),
			'i18n'            => self::strings(),
		);

		/**
		 * Filters the full public config sent to the widget.
		 *
		 * The response is public and cached by browsers and proxies: never add
		 * personal or private data.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, mixed> $config  Widget config.
		 * @param array<string, mixed> $context { @type string $locale Locale. }
		 */
		return (array) apply_filters( 'devrivo_chatbot_widget_config', $config, $context );
	}

	/**
	 * Whether AI can answer: turned on, a monthly limit above 0 and a model
	 * that is in the provider's list. Uses the cached model list only, so the
	 * public config never calls the provider (the model was checked on save).
	 *
	 * @param array<string, mixed> $ai AI settings.
	 */
	public static function ai_available( array $ai ): bool {
		return ! empty( $ai['enabled'] )
			&& (int) ( $ai['monthly_token_limit'] ?? 0 ) > 0
			&& Models::is_valid( (string) ( $ai['provider'] ?? '' ), (string) ( $ai['model'] ?? '' ), false );
	}

	/**
	 * Quick actions the widget can run, with only their public keys.
	 *
	 * @param string $locale Locale (passed to filters).
	 * @return array<int, array<string, mixed>>
	 */
	public function actions( string $locale ): array {
		$all     = $this->settings->all();
		$types   = ActionTypes::all();
		$actions = array();
		foreach ( $all['actions'] as $action ) {
			$public = self::action( $action, $types, $all['flows'], $all['forms'] );
			if ( null !== $public ) {
				$actions[] = $public;
			}
		}

		/**
		 * Filters the quick actions sent to the widget (menu, popover, flows)
		 * and offered to the AI's suggest_action tool.
		 *
		 * Each action has id, label, type, urgent, show_in_menu and config
		 * (only the keys of its type). Actions with show_in_menu false are only
		 * used inside flows.
		 *
		 * @since 1.0.0
		 *
		 * @param array<int, array<string, mixed>> $actions Quick actions.
		 * @param array<string, mixed>             $context { @type string $locale Locale. }
		 */
		return array_values( (array) apply_filters( 'devrivo_chatbot_quick_actions', $actions, array( 'locale' => $locale ) ) );
	}

	/**
	 * One action reduced to its public keys, or null when the widget can't run it
	 * (unsupported type, empty required value, or a flow or form that doesn't exist).
	 *
	 * @param array<string, mixed>                $action Stored action.
	 * @param array<string, array<string, mixed>> $types  Action types.
	 * @param array<string, mixed>                $flows  Stored flows.
	 * @param array<string, mixed>                $forms  Stored forms.
	 * @return array<string, mixed>|null
	 */
	private static function action( array $action, array $types, array $flows, array $forms ): ?array {
		// Stored list items only hold the keys that were sent.
		$action = (array) Schema::with_defaults( $action, (array) ( Schema::section( 'actions' )['items'] ?? array() ) );

		if ( ! ActionTypes::is_ready( $action, $types ) ) {
			return null;
		}
		if ( 'flow' === $action['type'] && ! isset( $flows[ (string) ( $action['config']['flow'] ?? '' ) ] ) ) {
			return null;
		}
		if ( 'form' === $action['type'] && ! isset( $forms[ (string) ( $action['config']['form'] ?? '' ) ] ) ) {
			return null;
		}
		if ( 'page_search' === $action['type'] && ! Index::has_content() ) {
			return null; // Nothing to search yet.
		}

		return array(
			'id'           => $action['id'],
			'label'        => $action['label'],
			'type'         => $action['type'],
			'urgent'       => (bool) $action['urgent'],
			'show_in_menu' => (bool) $action['show_in_menu'],
			// Only the keys in the type's schema.
			'config'       => (array) Schema::with_defaults( $action['config'], $types[ $action['type'] ]['schema'] ),
		);
	}

	/**
	 * Public flows. Choices and buttons pointing to actions that were left out
	 * are dropped (a choice that also has a next step keeps it).
	 *
	 * @param array<string, mixed> $flows      Stored flows.
	 * @param array<int, mixed>    $action_ids IDs of the actions sent to the widget.
	 * @return array<string, mixed>
	 */
	private static function flows( array $flows, array $action_ids ): array {
		$public = array();

		foreach ( $flows as $flow_id => $flow ) {
			$nodes = array();

			foreach ( (array) $flow['nodes'] as $node_id => $node ) {
				$choices = array();
				foreach ( (array) $node['choices'] as $choice ) {
					$choice = array(
						'label'  => (string) $choice['label'],
						'next'   => (string) ( $choice['next'] ?? '' ),
						'action' => (string) ( $choice['action'] ?? '' ),
						'urgent' => (bool) ( $choice['urgent'] ?? false ),
					);
					if ( '' !== $choice['action'] && ! in_array( $choice['action'], $action_ids, true ) ) {
						if ( '' === $choice['next'] ) {
							continue;
						}
						$choice['action'] = '';
					}
					$choices[] = $choice;
				}

				$node['choices']   = $choices;
				$node['actions']   = array_values( array_intersect( (array) $node['actions'], $action_ids ) );
				$nodes[ $node_id ] = $node;
			}

			$public[ $flow_id ] = array(
				'title' => $flow['title'],
				'start' => $flow['start'],
				'nodes' => $nodes,
			);
		}

		return $public;
	}

	/**
	 * Public forms: title, button label, success message and fields.
	 *
	 * @param array<string, mixed> $forms Stored forms.
	 * @return array<string, mixed>
	 */
	private static function forms( array $forms ): array {
		$public = array();

		foreach ( $forms as $form_id => $form ) {
			if ( ! is_array( $form ) ) {
				continue;
			}
			$public[ $form_id ] = array(
				'title'           => (string) $form['title'],
				'submit_label'    => (string) ( $form['submit_label'] ?? '' ),
				'success_message' => (string) ( $form['success_message'] ?? '' ),
				'fields'          => array_map(
					static fn( array $field ): array => array(
						'id'          => (string) $field['id'],
						'type'        => (string) $field['type'],
						'label'       => (string) $field['label'],
						'required'    => (bool) ( $field['required'] ?? false ),
						'placeholder' => (string) ( $field['placeholder'] ?? '' ),
						'max_length'  => (int) ( $field['max_length'] ?? 200 ),
						'options'     => array_values( array_map( 'strval', (array) ( $field['options'] ?? array() ) ) ),
					),
					Forms::fields( (string) $form_id, $form )
				),
			);
		}

		return $public;
	}

	/**
	 * Interface strings for the widget.
	 *
	 * @return array<string, string>
	 */
	private static function strings(): array {
		return array(
			'assistant'      => __( 'Assistant', 'devrivo-chatbot' ),
			'close'          => __( 'Close', 'devrivo-chatbot' ),
			'close_menu'     => __( 'Close menu', 'devrivo-chatbot' ),
			'back'           => __( 'Back', 'devrivo-chatbot' ),
			'home'           => __( 'Home', 'devrivo-chatbot' ),
			'offers'         => __( 'Offers', 'devrivo-chatbot' ),
			'menu'           => __( 'Quick actions', 'devrivo-chatbot' ),
			'ask'            => __( 'Ask a question', 'devrivo-chatbot' ),
			'ask_hint'       => __( 'Get an answer straight away', 'devrivo-chatbot' ),
			'send'           => __( 'Send', 'devrivo-chatbot' ),
			'message_label'  => __( 'Your message', 'devrivo-chatbot' ),
			'placeholder'    => __( 'Type your message…', 'devrivo-chatbot' ),
			'welcome'        => __( 'How can we help you today?', 'devrivo-chatbot' ),
			'offer_cta'      => __( 'Find out more', 'devrivo-chatbot' ),
			'good_morning'   => __( 'Good morning', 'devrivo-chatbot' ),
			'good_afternoon' => __( 'Good afternoon', 'devrivo-chatbot' ),
			'good_evening'   => __( 'Good evening', 'devrivo-chatbot' ),
			'back_to_menu'   => __( 'Back to menu', 'devrivo-chatbot' ),
			'open_now'       => __( 'We are open now', 'devrivo-chatbot' ),
			'closed_now'     => __( 'We are closed right now', 'devrivo-chatbot' ),
			'required'       => __( 'required', 'devrivo-chatbot' ),
			'submit'         => __( 'Send', 'devrivo-chatbot' ),
			'sending'        => __( 'Sending…', 'devrivo-chatbot' ),
			'sent'           => __( 'Sent', 'devrivo-chatbot' ),
			'form_success'   => __( 'Thank you. We have received your details and will be in touch soon.', 'devrivo-chatbot' ),
			'field_required' => __( 'This field is required.', 'devrivo-chatbot' ),
			'invalid_email'  => __( 'Enter an email address like name@example.com.', 'devrivo-chatbot' ),
			'invalid_phone'  => __( 'Enter a valid phone number.', 'devrivo-chatbot' ),
			'invalid_date'   => __( 'Enter a valid date.', 'devrivo-chatbot' ),
			'invalid_option' => __( 'Choose one of the options.', 'devrivo-chatbot' ),
			'too_long'       => __( 'This is too long.', 'devrivo-chatbot' ),
			'consent_needed' => __( 'Please tick the box to agree before sending.', 'devrivo-chatbot' ),
			'form_error'     => __( 'Something went wrong. Please try again.', 'devrivo-chatbot' ),
			'network_error'  => __( 'We could not send your details. Please check your connection and try again.', 'devrivo-chatbot' ),
			'rate_limited'   => __( 'Too many attempts. Please wait a few minutes and try again.', 'devrivo-chatbot' ),
			'new_tab'        => __( '(opens in a new tab)', 'devrivo-chatbot' ),
			'choose'         => __( 'Choose…', 'devrivo-chatbot' ),
			'ask_prompt'     => __( 'What would you like to know?', 'devrivo-chatbot' ),
			'search_found'   => __( 'Here is what I found on our website:', 'devrivo-chatbot' ),
			'no_results'     => __( 'I couldn’t find that on our website. Would you like the team to get back to you about it?', 'devrivo-chatbot' ),
			'read_more'      => __( 'Read more on our site', 'devrivo-chatbot' ),
			'search_limited' => __( 'You have asked a lot of questions in a short time. Please wait a moment and try again.', 'devrivo-chatbot' ),
			'search_error'   => __( 'Sorry, I couldn’t search just now. Please check your connection and try again.', 'devrivo-chatbot' ),
			'ai_tag'         => __( 'AI', 'devrivo-chatbot' ),
			'chat_limited'   => __( 'You have sent a lot of messages in a short time. Please wait a moment and try again.', 'devrivo-chatbot' ),
			'chat_error'     => __( 'Sorry, I couldn’t answer just now. Please check your connection and try again.', 'devrivo-chatbot' ),
			'powered_by'     => __( 'Powered by DevRivo', 'devrivo-chatbot' ),
		);
	}
}
