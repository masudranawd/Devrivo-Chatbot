<?php
/**
 * Leads REST controller (admin).
 *
 * @package DevRivo\Chatbot
 */

declare( strict_types=1 );

namespace DevRivo\Chatbot\Data;

use DevRivo\Chatbot\Core\Capabilities;
use DevRivo\Chatbot\Core\Rest;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Admin-only (devrivo_chatbot_manage) lead routes under devrivo-chatbot/v1:
 *
 * - GET    /leads        page, per_page (max 100), status, form_id; X-WP-Total, X-WP-TotalPages.
 * - GET    /leads/{id}
 * - PATCH  /leads/{id}   { status }
 * - DELETE /leads/{id}
 */
final class LeadsController extends WP_REST_Controller {

	/**
	 * Constructor.
	 *
	 * @param Leads $leads Leads repository.
	 */
	public function __construct( private Leads $leads ) {
		$this->namespace = Rest::NAMESPACE;
		$this->rest_base = 'leads';
	}

	/**
	 * Registers the routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
					'args'                => array(
						'page'     => array(
							'type'    => 'integer',
							'minimum' => 1,
							'default' => 1,
						),
						'per_page' => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 100,
							'default' => 20,
						),
						'status'   => array(
							'type' => 'string',
							'enum' => Leads::STATUSES,
						),
						'form_id'  => array(
							'type'    => 'string',
							'pattern' => '^[a-z0-9_-]{1,40}$',
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
					'args'                => array(
						'status' => array(
							'type'     => 'string',
							'enum'     => Leads::STATUSES,
							'required' => true,
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( $this, 'delete_item_permissions_check' ),
				),
			)
		);
	}

	/**
	 * List permission.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public function get_items_permissions_check( $request ) {
		return current_user_can( Capabilities::MANAGE );
	}

	/**
	 * Read permission.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public function get_item_permissions_check( $request ) {
		return current_user_can( Capabilities::MANAGE );
	}

	/**
	 * Update permission.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public function update_item_permissions_check( $request ) {
		return current_user_can( Capabilities::MANAGE );
	}

	/**
	 * Delete permission.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public function delete_item_permissions_check( $request ) {
		return current_user_can( Capabilities::MANAGE );
	}

	/**
	 * Lists leads.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_items( $request ) {
		$per_page = (int) $request['per_page'];
		$result   = $this->leads->paginate(
			array(
				'page'     => (int) $request['page'],
				'per_page' => $per_page,
				'status'   => (string) $request['status'],
				'form_id'  => (string) $request['form_id'],
			)
		);

		$response = $this->respond( array_map( array( self::class, 'shape' ), $result['items'] ) );
		$response->header( 'X-WP-Total', (string) $result['total'] );
		$response->header( 'X-WP-TotalPages', (string) (int) ceil( $result['total'] / max( 1, $per_page ) ) );

		return $response;
	}

	/**
	 * One lead.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ) {
		$lead = $this->leads->find( (int) $request['id'] );

		return null === $lead ? self::not_found() : $this->respond( self::shape( $lead ) );
	}

	/**
	 * Changes a lead's status.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( $request ) {
		$id = (int) $request['id'];
		if ( ! $this->leads->update_status( $id, (string) $request['status'] ) ) {
			return self::not_found();
		}

		return $this->respond( self::shape( (array) $this->leads->find( $id ) ) );
	}

	/**
	 * Deletes a lead.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_item( $request ) {
		$id = (int) $request['id'];

		return $this->leads->delete( $id )
			? $this->respond(
				array(
					'deleted' => true,
					'id'      => $id,
				)
			)
			: self::not_found();
	}

	/**
	 * Lead for the API, dates in RFC 3339 (UTC).
	 *
	 * @param array<string, mixed> $lead Lead.
	 * @return array<string, mixed>
	 */
	private static function shape( array $lead ): array {
		foreach ( array( 'consent_at', 'created_at', 'updated_at' ) as $key ) {
			$lead[ $key ] = mysql_to_rfc3339( (string) $lead[ $key ] ) . 'Z';
		}

		return $lead;
	}

	/**
	 * 404 error.
	 */
	private static function not_found(): WP_Error {
		return new WP_Error( 'devrivo_chatbot_lead_not_found', __( 'Lead not found.', 'devrivo-chatbot' ), array( 'status' => 404 ) );
	}

	/**
	 * Uncached response.
	 *
	 * @param mixed $data Data.
	 */
	private function respond( mixed $data ): WP_REST_Response {
		$response = new WP_REST_Response( $data );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}
}
