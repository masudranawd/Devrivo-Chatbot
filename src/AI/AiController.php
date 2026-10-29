<?php
/**
 * AI status REST controller (admin).
 *
 * @package DevRivo\Chatbot
 */

declare( strict_types=1 );

namespace DevRivo\Chatbot\AI;

use DevRivo\Chatbot\Core\Capabilities;
use DevRivo\Chatbot\Core\Rest;
use DevRivo\Chatbot\Core\Settings\Settings;
use WordPress\AiClient\AiClient;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * GET devrivo-chatbot/v1/ai/status (devrivo_chatbot_manage, no-store).
 *
 * For the admin app: whether core AI is available, which providers are
 * installed and connected, the selected provider's models, whether the saved
 * model is valid, and this month's usage. Never includes credentials.
 */
final class AiController extends WP_REST_Controller {

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings repository.
	 */
	public function __construct( private Settings $settings ) {
		$this->namespace = Rest::NAMESPACE;
		$this->rest_base = 'ai';
	}

	/**
	 * Registers the route.
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/status',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
				),
			)
		);
	}

	/**
	 * Permission.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public function get_item_permissions_check( $request ) {
		return current_user_can( Capabilities::MANAGE );
	}

	/**
	 * Status.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_item( $request ) {
		$ai       = (array) $this->settings->get( 'ai' );
		$provider = (string) ( $ai['provider'] ?? '' );
		$model    = (string) ( $ai['model'] ?? '' );
		$models   = '' === $provider ? array() : Models::list( $provider );

		$response = new WP_REST_Response(
			array(
				'core_available' => function_exists( 'wp_ai_client_prompt' ) && function_exists( 'wp_supports_ai' ) && wp_supports_ai(),
				'providers'      => self::providers(),
				'provider'       => $provider,
				'models'         => is_wp_error( $models ) ? array() : $models,
				'models_error'   => is_wp_error( $models ) ? $models->get_error_message() : '',
				'model'          => $model,
				'model_valid'    => ! is_wp_error( $models ) && '' !== $model && in_array( $model, array_column( $models, 'id' ), true ),
				'enabled'        => ! empty( $ai['enabled'] ),
				'limit'          => (int) ( $ai['monthly_token_limit'] ?? 0 ),
				'usage'          => Budget::usage(),
			)
		);
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * Installed providers and whether each is connected.
	 *
	 * @return list<array{id: string, configured: bool}>
	 */
	private static function providers(): array {
		if ( ! class_exists( AiClient::class ) ) {
			return array();
		}

		$providers = array();
		try {
			$registry = AiClient::defaultRegistry();
			foreach ( $registry->getRegisteredProviderIds() as $id ) {
				$providers[] = array(
					'id'         => (string) $id,
					'configured' => $registry->isProviderConfigured( (string) $id ),
				);
			}
		} catch ( \Throwable $e ) {
			return array();
		}

		return $providers;
	}
}
