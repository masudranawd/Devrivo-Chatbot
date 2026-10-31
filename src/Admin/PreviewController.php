<?php
/**
 * Widget preview config REST controller (admin).
 *
 * @package DevRivo\Chatbot
 */

declare( strict_types=1 );

namespace DevRivo\Chatbot\Admin;

use DevRivo\Chatbot\Core\Capabilities;
use DevRivo\Chatbot\Core\Frontend\ConfigController;
use DevRivo\Chatbot\Core\Frontend\WidgetConfig;
use DevRivo\Chatbot\Core\Rest;
use DevRivo\Chatbot\Core\Settings\Settings;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * POST devrivo-chatbot/v1/preview-config (devrivo_chatbot_manage, no-store).
 *
 * Body: unsaved settings sections, as sent to POST /settings. They are merged
 * over the saved settings and validated exactly like a save, but nothing is
 * stored. Returns the public widget config those settings would produce (the
 * same allowlist as GET /config), or the field errors.
 */
final class PreviewController extends WP_REST_Controller {

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings repository.
	 */
	public function __construct( private Settings $settings ) {
		$this->namespace = Rest::NAMESPACE;
		$this->rest_base = 'preview-config';
	}

	/**
	 * Registers the route.
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'create_item_permissions_check' ),
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
	public function create_item_permissions_check( $request ) {
		return current_user_can( Capabilities::MANAGE );
	}

	/**
	 * Builds the preview config.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function create_item( $request ) {
		// Null when the body is not JSON.
		$values = $this->settings->preview( (array) $request->get_json_params() );

		if ( is_wp_error( $values ) ) {
			return self::no_store( rest_convert_error_to_response( $values ) );
		}

		$config = ConfigController::shape( ( new WidgetConfig( Settings::snapshot( $values ) ) )->build( determine_locale() ) );

		return self::no_store( new WP_REST_Response( $config ) );
	}

	/**
	 * Marks a response as never cached.
	 *
	 * @param WP_REST_Response $response Response.
	 */
	private static function no_store( WP_REST_Response $response ): WP_REST_Response {
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}
}
