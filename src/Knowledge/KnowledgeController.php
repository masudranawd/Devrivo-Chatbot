<?php
/**
 * Knowledge index REST controller (admin).
 *
 * @package DevRivo\Chatbot
 */

declare( strict_types=1 );

namespace DevRivo\Chatbot\Knowledge;

use DevRivo\Chatbot\Core\Capabilities;
use DevRivo\Chatbot\Core\Rest;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Admin-only (devrivo_chatbot_manage) routes under devrivo-chatbot/v1:
 *
 * - GET  /knowledge/status   driver, indexed posts, chunks, FAQs and the rebuild state.
 * - POST /knowledge/rebuild  starts (or restarts) a full rebuild; returns the status.
 */
final class KnowledgeController extends WP_REST_Controller {

	/**
	 * Constructor.
	 *
	 * @param Knowledge $knowledge Knowledge.
	 */
	public function __construct( private Knowledge $knowledge ) {
		$this->namespace = Rest::NAMESPACE;
		$this->rest_base = 'knowledge';
	}

	/**
	 * Registers the routes.
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

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/rebuild',
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
	 * Status permission.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public function get_item_permissions_check( $request ) {
		return current_user_can( Capabilities::MANAGE );
	}

	/**
	 * Rebuild permission.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public function create_item_permissions_check( $request ) {
		return current_user_can( Capabilities::MANAGE );
	}

	/**
	 * Index status.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_item( $request ) {
		return $this->status();
	}

	/**
	 * Starts a rebuild.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function create_item( $request ) {
		Rebuild::start();

		return $this->status();
	}

	/**
	 * Status response (never cached).
	 */
	private function status(): WP_REST_Response {
		$state = Rebuild::state();
		$date  = static fn( int $time ): ?string => $time > 0 ? gmdate( 'Y-m-d\TH:i:s\Z', $time ) : null;

		$response = new WP_REST_Response(
			array_merge(
				array( 'driver' => Index::driver() ),
				$this->knowledge->index()->counts(),
				array(
					'rebuild' => array(
						'status'      => $state['status'],
						'total'       => $state['total'],
						'processed'   => $state['processed'],
						'last_id'     => $state['last_id'],
						'started_at'  => $date( $state['started_at'] ),
						'finished_at' => $date( $state['finished_at'] ),
					),
				)
			)
		);
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}
}
