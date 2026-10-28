<?php
/**
 * Settings REST controller.
 *
 * @package DevRivo\Chatbot
 */

declare( strict_types=1 );

namespace DevRivo\Chatbot\Core\Settings;

use DevRivo\Chatbot\Core\Capabilities;
use DevRivo\Chatbot\Core\Rest;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * GET / POST|PUT|PATCH / DELETE devrivo-chatbot/v1/settings
 *
 * Admin-only (devrivo_chatbot_manage). Cookie-authenticated requests need the
 * wp_rest nonce, which @wordpress/api-fetch sends automatically.
 */
final class SettingsController extends WP_REST_Controller {

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings repository.
	 */
	public function __construct( private Settings $settings ) {
		$this->namespace = Rest::NAMESPACE;
		$this->rest_base = 'settings';
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
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( $this, 'delete_item_permissions_check' ),
					'args'                => array(
						'section' => array(
							'description' => __( 'Section to reset. Omit to reset everything.', 'devrivo-chatbot' ),
							'type'        => 'string',
							'enum'        => array_keys( Schema::sections() ),
						),
					),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
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
	 * Write permission.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public function update_item_permissions_check( $request ) {
		return current_user_can( Capabilities::MANAGE );
	}

	/**
	 * Reset permission.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public function delete_item_permissions_check( $request ) {
		return current_user_can( Capabilities::MANAGE );
	}

	/**
	 * Returns all settings.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_item( $request ) {
		return $this->respond( $this->settings->all() );
	}

	/**
	 * Partially updates settings. Body: { "section": { ...values } }.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( $request ) {
		// Null when the body is not JSON.
		$changes = (array) $request->get_json_params();

		if ( array() === $changes ) {
			return new WP_Error(
				'rest_invalid_param',
				__( 'Send a JSON object with one or more settings sections.', 'devrivo-chatbot' ),
				array( 'status' => 400 )
			);
		}

		$result = $this->settings->update( $changes );

		return is_wp_error( $result ) ? $result : $this->respond( $result );
	}

	/**
	 * Resets one section (?section=) or all settings to defaults.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_item( $request ) {
		$section = $request->get_param( 'section' );
		$result  = $this->settings->reset( is_string( $section ) && '' !== $section ? $section : null );

		return is_wp_error( $result ) ? $result : $this->respond( $result );
	}

	/**
	 * Settings schema for OPTIONS requests and discovery.
	 *
	 * @return array<string, mixed>
	 */
	public function get_item_schema() {
		if ( ! $this->schema ) {
			$this->schema = array(
				'$schema'    => 'http://json-schema.org/draft-04/schema#',
				'title'      => 'devrivo-chatbot-settings',
				'type'       => 'object',
				'properties' => Schema::sections(),
			);
		}

		return $this->add_additional_fields_schema( $this->schema );
	}

	/**
	 * Builds a response with JSON-correct shapes ({} for empty objects).
	 *
	 * @param array<string, mixed> $values Settings.
	 */
	private function respond( array $values ): WP_REST_Response {
		$data = array();
		foreach ( Schema::sections() as $name => $schema ) {
			$data[ $name ] = Schema::to_json_shape( $values[ $name ] ?? null, $schema );
		}

		$response = new WP_REST_Response( $data );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}
}
