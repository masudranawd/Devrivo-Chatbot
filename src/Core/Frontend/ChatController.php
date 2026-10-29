<?php
/**
 * Public chat REST controller.
 *
 * @package DevRivo\Chatbot
 */

declare( strict_types=1 );

namespace DevRivo\Chatbot\Core\Frontend;

use DevRivo\Chatbot\AI\ChatEngine;
use DevRivo\Chatbot\Core\Rest;
use DevRivo\Chatbot\Core\Security\Gate;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * POST devrivo-chatbot/v1/chat with a JSON body { message, history?, page_url?, token }.
 *
 * Answers with AI when it is available, otherwise (and after any AI error)
 * with the Guided answer. Nothing is stored: the conversation lives only in
 * the visitor's browser memory and is sent back with each message.
 */
final class ChatController extends WP_REST_Controller {

	/**
	 * Most characters in a message.
	 */
	public const MAX_MESSAGE = 500;

	/**
	 * Most earlier turns sent with a message.
	 */
	public const MAX_HISTORY = 8;

	/**
	 * Most characters per earlier turn.
	 */
	public const MAX_TURN = 1000;

	/**
	 * Constructor.
	 *
	 * @param ChatEngine $engine Chat engine.
	 */
	public function __construct( private ChatEngine $engine ) {
		$this->namespace = Rest::NAMESPACE;
		$this->rest_base = 'chat';
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
					// Public: visitors aren't logged in. Protected by Gate (size, origin, signed token, rate limits).
					'permission_callback' => '__return_true',
					'args'                => array(
						'message'  => array(
							'type'              => 'string',
							'required'          => true,
							'validate_callback' => array( self::class, 'validate_message' ),
						),
						'history'  => array(
							'type'     => 'array',
							'default'  => array(),
							'maxItems' => self::MAX_HISTORY,
							'items'    => array(
								'type'                 => 'object',
								'properties'           => array(
									'role' => array(
										'type'     => 'string',
										'enum'     => array( 'user', 'assistant' ),
										'required' => true,
									),
									'text' => array(
										'type'      => 'string',
										'maxLength' => self::MAX_TURN,
										'required'  => true,
									),
								),
								'additionalProperties' => false,
							),
						),
						'page_url' => array(
							'type'      => 'string',
							'maxLength' => 2000,
							'default'   => '',
						),
						'token'    => array(
							'type'      => 'string',
							'maxLength' => 512,
						),
					),
				),
			)
		);
	}

	/**
	 * Message length check (after trimming).
	 *
	 * @param mixed $value Value.
	 * @return true|WP_Error
	 */
	public static function validate_message( mixed $value ): bool|WP_Error {
		$length = is_string( $value ) ? mb_strlen( trim( $value ) ) : 0;

		if ( $length < 1 || $length > self::MAX_MESSAGE ) {
			return new WP_Error(
				'rest_invalid_param',
				/* translators: %d: Maximum length. */
				sprintf( __( 'The message must be between 1 and %d characters long.', 'devrivo-chatbot' ), self::MAX_MESSAGE ),
				array( 'status' => 400 )
			);
		}

		return true;
	}

	/**
	 * Answers a message.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function create_item( $request ) {
		$gate = Gate::check(
			$request,
			array(
				'endpoint' => 'chat',
				'min_age'  => 1,
			)
		);
		if ( is_wp_error( $gate ) ) {
			return self::error( $gate );
		}

		$message  = sanitize_textarea_field( trim( (string) $request['message'] ) );
		$history  = self::history( (array) $request['history'] );
		$page_url = Gate::page_url( $request['page_url'] );
		$context  = array(
			'page_url' => $page_url,
			'history'  => count( $history ),
		);

		/**
		 * Fires when a visitor's chat message passed the security checks.
		 *
		 * The message is never stored by the plugin; don't store it either
		 * without telling visitors.
		 *
		 * @since 1.0.0
		 *
		 * @param string               $message Message.
		 * @param array<string, mixed> $context { @type string $page_url Current page. @type int $history Earlier turns. }
		 */
		do_action( 'devrivo_chatbot_message_received', $message, $context );

		$answer = $this->engine->respond( $message, $history, $page_url );

		/**
		 * Fires after an answer was built, before it is sent.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, mixed> $answer  { @type string $mode ai|guided, @type string $text, @type array $sources, @type string[] $actions }
		 * @param array<string, mixed> $context { @type string $page_url Current page. @type int $history Earlier turns. }
		 */
		do_action( 'devrivo_chatbot_response_sent', $answer, $context );

		$response = new WP_REST_Response( $answer );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * Earlier turns, cleaned.
	 *
	 * @param array<int, mixed> $history History from the request (schema-checked).
	 * @return list<array{role: string, text: string}>
	 */
	private static function history( array $history ): array {
		$clean = array();
		foreach ( array_slice( $history, -self::MAX_HISTORY ) as $turn ) {
			$text = is_array( $turn ) ? sanitize_textarea_field( (string) ( $turn['text'] ?? '' ) ) : '';
			if ( '' !== trim( $text ) ) {
				$clean[] = array(
					'role' => 'assistant' === ( $turn['role'] ?? '' ) ? 'assistant' : 'user',
					'text' => mb_substr( $text, 0, self::MAX_TURN ),
				);
			}
		}

		return $clean;
	}

	/**
	 * Error response (never cached; Retry-After for rate limits).
	 *
	 * @param WP_Error $error Error.
	 */
	private static function error( WP_Error $error ): WP_REST_Response {
		$response = rest_convert_error_to_response( $error );
		$data     = (array) $error->get_error_data();
		if ( isset( $data['retry_after'] ) ) {
			$response->header( 'Retry-After', (string) (int) $data['retry_after'] );
		}
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}
}
