<?php
/**
 * Public form submit REST controller.
 *
 * @package DevRivo\Chatbot
 */

declare( strict_types=1 );

namespace DevRivo\Chatbot\Core\Frontend;

use DevRivo\Chatbot\Core\Rest;
use DevRivo\Chatbot\Core\Security\Gate;
use DevRivo\Chatbot\Core\Settings\Settings;
use DevRivo\Chatbot\Data\Leads;
use DevRivo\Chatbot\Engine\Forms;
use DevRivo\Chatbot\Integrations\Email;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * POST devrivo-chatbot/v1/forms/{id}/submit
 *
 * Body (JSON): { token, website (honeypot, must be empty), consent: true,
 * fields: { field_id: value }, page_url }.
 *
 * Steps: security gate → form exists → honeypot → validation and consent →
 * save the lead (no IP address) → email the team → hooks.
 */
final class FormsController extends WP_REST_Controller {

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings repository.
	 * @param Leads    $leads    Leads repository.
	 * @param Email    $email    Notifications.
	 */
	public function __construct( private Settings $settings, private Leads $leads, private Email $email ) {
		$this->namespace = Rest::NAMESPACE;
		$this->rest_base = 'forms';
	}

	/**
	 * Registers the route.
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[a-z0-9_-]+)/submit',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'submit' ),
					// Public: visitors aren't logged in. Protected by Gate (size, origin, signed token, rate limit) and the honeypot.
					'permission_callback' => '__return_true',
				),
			)
		);
	}

	/**
	 * Handles a submission.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function submit( WP_REST_Request $request ): WP_REST_Response {
		$form_id = (string) $request['id'];

		$gate = Gate::check(
			$request,
			array(
				'endpoint' => 'form',
				'form_id'  => $form_id,
			)
		);
		if ( is_wp_error( $gate ) ) {
			return self::error( $gate );
		}

		$form = $this->settings->get( 'forms' )[ $form_id ] ?? null;
		if ( ! is_array( $form ) ) {
			return self::error( new WP_Error( 'devrivo_chatbot_form_not_found', __( 'This form is no longer available.', 'devrivo-chatbot' ), array( 'status' => 404 ) ) );
		}

		// Bots fill the hidden "website" field: answer as if it worked, keep nothing.
		$honeypot = $request->get_param( 'website' );
		if ( is_string( $honeypot ) && '' !== trim( $honeypot ) ) {
			return $this->success( $form );
		}

		$fields = Forms::fields( $form_id, $form );
		$result = Forms::validate( $fields, $request->get_param( 'fields' ) );
		$errors = $result['errors'];
		if ( true !== $request->get_param( 'consent' ) ) {
			$errors['consent'] = __( 'Please tick the box to agree before sending.', 'devrivo-chatbot' );
		}
		if ( array() !== $errors ) {
			return self::error(
				new WP_Error(
					'rest_invalid_param',
					__( 'Please check the highlighted fields.', 'devrivo-chatbot' ),
					array(
						'status' => 400,
						'params' => $errors,
					)
				)
			);
		}

		$values = $result['values'];
		$data   = array();
		$email  = '';
		foreach ( $fields as $field ) {
			$value  = $values[ $field['id'] ] ?? '';
			$data[] = array(
				'id'    => (string) $field['id'],
				'label' => (string) $field['label'],
				'value' => $value,
			);
			if ( '' === $email && 'email' === $field['type'] && '' !== $value ) {
				$email = $value;
			}
		}

		/**
		 * Filters a lead before it is saved.
		 *
		 * @since 1.0.0
		 *
		 * Keys: form_id, email, name, data (list of { id, label, value }), page_url, consent_text.
		 *
		 * @param array<string, mixed> $lead    Lead.
		 * @param string               $form_id Form ID.
		 */
		$lead = (array) apply_filters(
			'devrivo_chatbot_lead_data',
			array(
				'form_id'      => $form_id,
				'email'        => $email,
				'name'         => $values['name'] ?? '',
				'data'         => $data,
				'page_url'     => Gate::page_url( $request->get_param( 'page_url' ) ),
				'consent_text' => Consent::text( $this->settings ),
			),
			$form_id
		);

		$id    = $this->leads->create(
			array(
				'form_id'      => $form_id,
				'email'        => sanitize_email( (string) ( $lead['email'] ?? '' ) ),
				'name'         => sanitize_text_field( (string) ( $lead['name'] ?? '' ) ),
				'data'         => is_array( $lead['data'] ?? null ) ? $lead['data'] : $data,
				'page_url'     => esc_url_raw( (string) ( $lead['page_url'] ?? '' ) ),
				'consent_text' => sanitize_textarea_field( (string) ( $lead['consent_text'] ?? '' ) ),
			)
		);
		$saved = null === $id ? null : $this->leads->find( $id );
		if ( null === $saved ) {
			return self::error( new WP_Error( 'devrivo_chatbot_save_failed', __( 'We could not save your details. Please try again later.', 'devrivo-chatbot' ), array( 'status' => 500 ) ) );
		}

		$this->email->notify( $saved, (string) $form['title'] );

		/**
		 * Fires after a widget form was submitted and saved.
		 *
		 * @since 1.0.0
		 *
		 * @param string                $form_id Form ID.
		 * @param array<string, string> $data    Clean values keyed by field ID.
		 */
		do_action( 'devrivo_chatbot_form_submitted', $form_id, $values );

		/**
		 * Fires after a lead was saved (the visitor gave consent).
		 *
		 * @since 1.0.0
		 *
		 * @param int                  $lead_id   Lead ID.
		 * @param array<string, mixed> $lead_data The saved lead.
		 */
		do_action( 'devrivo_chatbot_lead_created', $id, $saved );

		return $this->success( $form );
	}

	/**
	 * Success response (also used for the honeypot).
	 *
	 * @param array<string, mixed> $form Form.
	 */
	private function success( array $form ): WP_REST_Response {
		$message = trim( (string) ( $form['success_message'] ?? '' ) );

		$response = new WP_REST_Response(
			array(
				'success' => true,
				'message' => '' !== $message ? $message : __( 'Thank you. We have received your details and will be in touch soon.', 'devrivo-chatbot' ),
			)
		);
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * Error response, with Retry-After for rate limits.
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
