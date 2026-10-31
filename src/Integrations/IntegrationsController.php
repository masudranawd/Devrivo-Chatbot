<?php
/**
 * Integrations REST controller (admin).
 *
 * @package DevRivo\Chatbot
 */

declare( strict_types=1 );

namespace DevRivo\Chatbot\Integrations;

use DevRivo\Chatbot\Core\Capabilities;
use DevRivo\Chatbot\Core\Rest;
use DevRivo\Chatbot\Core\Security\RateLimiter;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * POST devrivo-chatbot/v1/integrations/test-email (devrivo_chatbot_manage, no-store).
 *
 * Sends a test notification to the saved recipients (else the site admin
 * email), at most 3 times per 10 minutes per user. Returns { sent, recipients }.
 */
final class IntegrationsController extends WP_REST_Controller {

	/**
	 * Test emails allowed per user and window.
	 */
	public const TEST_EMAIL_MAX = 3;

	/**
	 * Rate limit window, in seconds.
	 */
	public const TEST_EMAIL_WINDOW = 600;

	/**
	 * Constructor.
	 *
	 * @param Email $email Notifications.
	 */
	public function __construct( private Email $email ) {
		$this->namespace = Rest::NAMESPACE;
		$this->rest_base = 'integrations';
	}

	/**
	 * Registers the route.
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/test-email',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'test_email' ),
					'permission_callback' => array( $this, 'permissions_check' ),
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
	public function permissions_check( $request ) {
		return current_user_can( Capabilities::MANAGE );
	}

	/**
	 * Sends the test email.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function test_email( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$wait = RateLimiter::hit( 'test_email', RateLimiter::key( 'user:' . get_current_user_id() ), self::TEST_EMAIL_MAX, self::TEST_EMAIL_WINDOW );
		if ( $wait > 0 ) {
			return new WP_Error(
				'devrivo_chatbot_rate_limited',
				__( 'You have sent several test emails in a short time. Please wait a few minutes and try again.', 'devrivo-chatbot' ),
				array(
					'status'      => 429,
					'retry_after' => $wait,
				)
			);
		}

		$result = $this->email->test();

		if ( ! $result['sent'] ) {
			return new WP_Error(
				'devrivo_chatbot_email_failed',
				__( 'WordPress could not send the email. Check your site\'s email setup, for example with an SMTP plugin.', 'devrivo-chatbot' ),
				array(
					'status'     => 500,
					'recipients' => $result['recipients'],
				)
			);
		}

		$response = new WP_REST_Response( $result );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}
}
