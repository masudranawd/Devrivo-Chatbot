<?php
/**
 * Public widget config.
 *
 * @package DevRivo\Chatbot
 */

declare( strict_types=1 );

namespace DevRivo\Chatbot\Core\Frontend;

use DevRivo\Chatbot\Core\Settings\Schema;
use DevRivo\Chatbot\Core\Settings\Settings;
use DevRivo\Chatbot\Engine\ActionTypes;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the config the widget fetches from the public endpoint.
 *
 * Built from an allowlist only: AI, privacy, integration and form settings
 * never leave the server here. Actions are sent only when the widget can run
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

		$types   = ActionTypes::all();
		$actions = array();
		foreach ( $all['actions'] as $action ) {
			$public = self::action( $action, $types, $all['flows'] );
			if ( null !== $public ) {
				$actions[] = $public;
			}
		}

		/**
		 * Filters the quick actions sent to the widget (menu, popover, flows).
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
		$actions = array_values( (array) apply_filters( 'devrivo_chatbot_quick_actions', $actions, $context ) );

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

		$config = array(
			'version'      => Settings::config_version(),
			'chat_enabled' => false,
			'display'      => array(
				'position'       => $display['position'],
				'primary_color'  => $display['primary_color'],
				'launcher_label' => Display::launcher_label( (string) $display['launcher_label'] ),
			),
			'appearance'   => array(
				'color_scheme' => $appearance['color_scheme'],
				'radius'       => $appearance['radius'],
				'brand_name'   => $appearance['brand_name'],
				'font'         => $appearance['font'],
				'avatar_url'   => $appearance['avatar_id'] > 0 ? (string) wp_get_attachment_image_url( $appearance['avatar_id'], 'thumbnail' ) : '',
			),
			'behaviour'    => array(
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
			'actions'      => $actions,
			'flows'        => self::flows( $all['flows'], $action_ids ),
			'offers'       => $offers,
			'i18n'         => self::strings(),
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
	 * One action reduced to its public keys, or null when the widget can't run it
	 * (unsupported type, empty required value, or a flow that doesn't exist).
	 *
	 * @param array<string, mixed>                $action Stored action.
	 * @param array<string, array<string, mixed>> $types  Action types.
	 * @param array<string, mixed>                $flows  Stored flows.
	 * @return array<string, mixed>|null
	 */
	private static function action( array $action, array $types, array $flows ): ?array {
		// Stored list items only hold the keys that were sent.
		$action = (array) Schema::with_defaults( $action, (array) ( Schema::section( 'actions' )['items'] ?? array() ) );

		if ( ! ActionTypes::is_ready( $action, $types ) ) {
			return null;
		}
		if ( 'flow' === $action['type'] && ! isset( $flows[ (string) ( $action['config']['flow'] ?? '' ) ] ) ) {
			return null;
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
		);
	}
}
