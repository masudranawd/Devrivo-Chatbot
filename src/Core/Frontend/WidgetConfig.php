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

defined( 'ABSPATH' ) || exit;

/**
 * Builds the config the widget fetches from the public endpoint.
 *
 * Built from an allowlist only: AI, privacy, integration, flow and form
 * settings never leave the server here, and each action type exposes only the
 * config keys the widget needs.
 */
final class WidgetConfig {

	/**
	 * Action config keys the widget may see, per action type. Other types expose none.
	 */
	private const ACTION_CONFIG_KEYS = array(
		'link'      => array( 'url' ),
		'call'      => array( 'phone' ),
		'whatsapp'  => array( 'phone', 'message' ),
		'email'     => array( 'email', 'subject' ),
		'form'      => array( 'form' ),
		'flow'      => array( 'flow' ),
		'ai_prompt' => array( 'prompt' ),
	);

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

		$actions = array_map( array( self::class, 'action' ), $all['actions'] );

		/**
		 * Filters the quick actions shown in the widget's popover and Home view.
		 *
		 * Each action has id, label, type, urgent and config (only the keys the
		 * widget needs for its type).
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
			),
			'actions'      => $actions,
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
	 * One action, reduced to the public keys.
	 *
	 * @param array<string, mixed> $action Stored action.
	 * @return array<string, mixed>
	 */
	private static function action( array $action ): array {
		$config = array();
		foreach ( self::ACTION_CONFIG_KEYS[ $action['type'] ] ?? array() as $key ) {
			$value          = $action['config'][ $key ] ?? '';
			$config[ $key ] = is_scalar( $value ) ? (string) $value : '';
		}

		return array(
			'id'     => $action['id'],
			'label'  => $action['label'],
			'type'   => $action['type'],
			'urgent' => (bool) ( $action['urgent'] ?? false ),
			'config' => $config,
		);
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
		);
	}
}
