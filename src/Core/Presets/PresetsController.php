<?php
/**
 * Presets REST controller.
 *
 * @package DevRivo\Chatbot
 */

declare( strict_types=1 );

namespace DevRivo\Chatbot\Core\Presets;

use DevRivo\Chatbot\Core\Capabilities;
use DevRivo\Chatbot\Core\Rest;
use DevRivo\Chatbot\Core\Settings\Schema;
use DevRivo\Chatbot\Core\Settings\Settings;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Admin-only (devrivo_chatbot_manage) preset routes under devrivo-chatbot/v1:
 *
 * - GET  /presets            List presets.
 * - GET  /presets/{id}       One preset with its settings.
 * - POST /presets/{id}/apply Apply a preset (mode: replace|merge).
 * - POST /presets/undo       Restore the settings saved before the last apply (or import).
 * - GET  /presets/backup     Whether there is something to undo: { exists, preset, created }.
 */
final class PresetsController extends WP_REST_Controller {

	/**
	 * Constructor.
	 *
	 * @param Settings         $settings Settings repository.
	 * @param PresetRepository $presets  Preset repository.
	 * @param PresetApplier    $applier  Preset applier.
	 */
	public function __construct( private Settings $settings, private PresetRepository $presets, private PresetApplier $applier ) {
		$this->namespace = Rest::NAMESPACE;
		$this->rest_base = 'presets';
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
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);

		// Before /presets/{id}, which would match "backup" too.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/backup',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'backup' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/undo',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'undo' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[a-z0-9_-]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[a-z0-9_-]+)/apply',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'apply' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
					'args'                => array(
						'mode' => array(
							'description' => __( 'How to apply the preset: replace sections, or only fill sections that still have their defaults.', 'devrivo-chatbot' ),
							'type'        => 'string',
							'enum'        => PresetApplier::MODES,
							'default'     => 'replace',
						),
					),
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
	 * Apply / undo permission.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public function update_item_permissions_check( $request ) {
		return current_user_can( Capabilities::MANAGE );
	}

	/**
	 * Lists presets.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_items( $request ) {
		$general = (array) $this->settings->get( 'general' );
		$active  = $general['preset'] ?? '';
		$items   = array();

		foreach ( $this->presets->all() as $id => $preset ) {
			$items[] = array(
				'id'          => $id,
				'title'       => $preset['title'],
				'description' => $preset['description'],
				'version'     => $preset['version'],
				'active'      => $active === $id,
			);
		}

		return $this->respond( $items );
	}

	/**
	 * Returns one preset with its settings.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ) {
		$preset = $this->presets->get( (string) $request['id'] );

		if ( null === $preset ) {
			return new WP_Error(
				'devrivo_chatbot_preset_not_found',
				__( 'Preset not found.', 'devrivo-chatbot' ),
				array( 'status' => 404 )
			);
		}

		$settings = array();
		foreach ( $preset['settings'] as $section => $value ) {
			$settings[ $section ] = Schema::to_json_shape( $value, (array) Schema::section( $section ) );
		}
		$preset['settings'] = array() === $settings ? new \stdClass() : $settings;

		return $this->respond( $preset );
	}

	/**
	 * Applies a preset.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function apply( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = $this->applier->apply( (string) $request['id'], (string) $request['mode'] );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->respond(
			array(
				'settings' => $this->settings_shape( $result['settings'] ),
				'applied'  => $result['applied'],
				'skipped'  => $result['skipped'],
			)
		);
	}

	/**
	 * Restores the settings saved before the last apply.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function undo( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = $this->applier->undo();

		return is_wp_error( $result ) ? $result : $this->respond( array( 'settings' => $this->settings_shape( $result ) ) );
	}

	/**
	 * Whether there is a backup to undo.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function backup( WP_REST_Request $request ): WP_REST_Response {
		return $this->respond( PresetApplier::backup_status() );
	}

	/**
	 * Schema of a preset list item.
	 *
	 * @return array<string, mixed>
	 */
	public function get_item_schema() {
		if ( ! $this->schema ) {
			$this->schema = array(
				'$schema'    => 'http://json-schema.org/draft-04/schema#',
				'title'      => 'devrivo-chatbot-preset',
				'type'       => 'object',
				'properties' => array(
					'id'          => array( 'type' => 'string' ),
					'title'       => array( 'type' => 'string' ),
					'description' => array( 'type' => 'string' ),
					'version'     => array( 'type' => 'integer' ),
					'active'      => array( 'type' => 'boolean' ),
					'settings'    => array( 'type' => 'object' ),
				),
			);
		}

		return $this->add_additional_fields_schema( $this->schema );
	}

	/**
	 * All settings with JSON-correct shapes ({} for empty objects).
	 *
	 * @param array<string, mixed> $values Settings.
	 * @return array<string, mixed>
	 */
	private function settings_shape( array $values ): array {
		$data = array();
		foreach ( Schema::sections() as $name => $schema ) {
			$data[ $name ] = Schema::to_json_shape( $values[ $name ] ?? null, $schema );
		}

		return $data;
	}

	/**
	 * Builds an uncached response.
	 *
	 * @param mixed $data Response data.
	 */
	private function respond( mixed $data ): WP_REST_Response {
		$response = new WP_REST_Response( $data );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}
}
