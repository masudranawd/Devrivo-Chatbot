<?php
/**
 * Setup wizard REST controller (admin).
 *
 * @package DevRivo\Chatbot
 */

declare( strict_types=1 );

namespace DevRivo\Chatbot\Admin;

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
 * Admin-only (devrivo_chatbot_manage, no-store) routes under devrivo-chatbot/v1:
 *
 * - GET  /setup/scan            Site scan with suggestions (nothing is saved).
 * - POST /setup/step            { step }: the step to resume at.
 * - POST /setup/complete        { enable_widget }: marks setup done, optionally turns the widget on.
 * - POST /setup/dismiss-notice  Hides the Plugins screen notice.
 *
 * The POST routes return all settings, like GET /settings.
 */
final class SetupController extends WP_REST_Controller {

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings repository.
	 * @param SiteScan $scan     Site scan.
	 */
	public function __construct( private Settings $settings, private SiteScan $scan ) {
		$this->namespace = Rest::NAMESPACE;
		$this->rest_base = 'setup';
	}

	/**
	 * Registers the routes.
	 */
	public function register_routes(): void {
		$routes = array(
			'scan'           => array( WP_REST_Server::READABLE, 'scan', array() ),
			'step'           => array(
				WP_REST_Server::CREATABLE,
				'step',
				array(
					'step' => array(
						'description' => __( 'Setup step to resume at.', 'devrivo-chatbot' ),
						'type'        => 'string',
						'enum'        => Schema::SETUP_STEPS,
						'required'    => true,
					),
				),
			),
			'complete'       => array(
				WP_REST_Server::CREATABLE,
				'complete',
				array(
					'enable_widget' => array(
						'description' => __( 'Also turn the chat widget on.', 'devrivo-chatbot' ),
						'type'        => 'boolean',
						'default'     => false,
					),
				),
			),
			'dismiss-notice' => array( WP_REST_Server::CREATABLE, 'dismiss_notice', array() ),
		);

		foreach ( $routes as $path => $route ) {
			register_rest_route(
				$this->namespace,
				'/' . $this->rest_base . '/' . $path,
				array(
					array(
						'methods'             => $route[0],
						'callback'            => array( $this, $route[1] ),
						'permission_callback' => array( $this, 'permissions_check' ),
						'args'                => $route[2],
					),
				)
			);
		}
	}

	/**
	 * Permission (all routes).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public function permissions_check( $request ) {
		return current_user_can( Capabilities::MANAGE );
	}

	/**
	 * Site scan.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function scan( WP_REST_Request $request ): WP_REST_Response {
		return self::respond( $this->scan->run() );
	}

	/**
	 * Saves the step to resume at.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function step( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->save( array( 'step' => (string) $request['step'] ) );
	}

	/**
	 * Marks setup as completed.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function complete( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->save(
			array(
				'completed'    => true,
				'completed_at' => time(),
			),
			$request['enable_widget'] ? array( Schema::DISPLAY => array( 'enabled' => true ) ) : array()
		);
	}

	/**
	 * Hides the setup notice on the Plugins screen.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function dismiss_notice( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->save( array( 'notice_dismissed' => true ) );
	}

	/**
	 * Merges keys into general.setup (plus other sections) and saves.
	 *
	 * @param array<string, mixed> $setup Setup keys.
	 * @param array<string, mixed> $other Other sections to save at the same time.
	 * @return WP_REST_Response|WP_Error
	 */
	private function save( array $setup, array $other = array() ): WP_REST_Response|WP_Error {
		$general = (array) $this->settings->get( 'general' );
		$result  = $this->settings->update(
			array_merge(
				array( 'general' => array( 'setup' => array_merge( (array) ( $general['setup'] ?? array() ), $setup ) ) ),
				$other
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$data = array();
		foreach ( Schema::sections() as $name => $schema ) {
			$data[ $name ] = Schema::to_json_shape( $result[ $name ] ?? null, $schema );
		}

		return self::respond( $data );
	}

	/**
	 * Builds an uncached response.
	 *
	 * @param array<string, mixed> $data Data.
	 */
	private static function respond( array $data ): WP_REST_Response {
		$response = new WP_REST_Response( $data );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}
}
