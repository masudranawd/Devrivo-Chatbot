<?php
/**
 * Session token REST controller.
 *
 * @package DevRivo\Chatbot
 */

declare( strict_types=1 );

namespace DevRivo\Chatbot\Core\Frontend;

use DevRivo\Chatbot\Core\Rest;
use DevRivo\Chatbot\Core\Security\SessionToken;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * GET devrivo-chatbot/v1/session: a fresh signed token for public submits.
 */
final class SessionController extends WP_REST_Controller {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->namespace = Rest::NAMESPACE;
		$this->rest_base = 'session';
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
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					// Public: a token proves nothing about the visitor; it only lets the gate check freshness.
					'permission_callback' => '__return_true',
				),
			)
		);
	}

	/**
	 * Issues a token.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_item( $request ) {
		$response = new WP_REST_Response( SessionToken::issue() );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}
}
