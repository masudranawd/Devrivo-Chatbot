<?php
/**
 * Public form submit tests: validation, leads, emails, hooks.
 *
 * @package DevRivo\Chatbot
 */

declare( strict_types=1 );

namespace DevRivo\Chatbot\Tests\Integration;

use DevRivo\Chatbot\Core\Plugin;
use DevRivo\Chatbot\Core\Security\SessionToken;
use DevRivo\Chatbot\Core\Settings\Settings;
use DevRivo\Chatbot\Data\Leads;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_UnitTestCase;

final class FormSubmitTest extends WP_UnitTestCase {

	private const ROUTE = '/devrivo-chatbot/v1/forms/%s/submit';

	private Leads $leads;

	public function set_up(): void {
		parent::set_up();

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );

		delete_option( Settings::OPTION );
		Plugin::instance()->settings()->flush();
		reset_phpmailer_instance();
		wp_set_current_user( 0 );

		$this->leads = new Leads();
		Plugin::instance()->settings()->update(
			array(
				'forms'   => array( 'contact' => self::form() ),
				'privacy' => array( 'consent_text' => 'I <strong>agree</strong>.' ),
			)
		);
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		reset_phpmailer_instance();

		parent::tear_down();
	}

	public function test_valid_submission_saves_a_lead_and_emails_the_team(): void {
		Plugin::instance()->settings()->update( array( 'integrations' => array( 'notification_emails' => array( 'team@example.com', 'boss@example.com' ) ) ) );

		$response = $this->submit();

		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		$this->assertSame( 'no-store', $response->get_headers()['Cache-Control'] );
		$this->assertSame(
			array(
				'success' => true,
				'message' => 'Thanks!',
			),
			$response->get_data()
		);

		$lead = $this->only_lead();
		$this->assertSame( 'contact', $lead['form_id'] );
		$this->assertSame( 'new', $lead['status'] );
		$this->assertSame( 'ann@example.com', $lead['email'] );
		$this->assertSame( 'Ann', $lead['name'] );
		$this->assertSame( home_url( '/contact/' ), $lead['page_url'], 'Query string and fragment removed.' );
		$this->assertSame( 'I agree.', $lead['consent_text'] );
		$this->assertNotSame( '', $lead['consent_at'] );
		$this->assertSame(
			array(
				array(
					'id'    => 'name',
					'label' => 'Name',
					'value' => 'Ann',
				),
				array(
					'id'    => 'email',
					'label' => 'Email',
					'value' => 'ann@example.com',
				),
				array(
					'id'    => 'phone',
					'label' => 'Phone',
					'value' => '+44 (0)20 7946-0000',
				),
				array(
					'id'    => 'when',
					'label' => 'Date',
					'value' => '2026-02-28',
				),
				array(
					'id'    => 'time',
					'label' => 'Time',
					'value' => 'Morning',
				),
				array(
					'id'    => 'message',
					'label' => 'Message',
					'value' => "Line one\nLine two",
				),
			),
			$lead['data']
		);

		$mail = tests_retrieve_phpmailer_instance()->get_sent( 0 );
		$this->assertSame( array( 'team@example.com', 'boss@example.com' ), array_column( $mail->to, 0 ) );
		$this->assertStringContainsString( 'New enquiry: Contact us', $mail->subject );
		$this->assertStringContainsString( 'Reply-To: ann@example.com', $mail->header );
		$this->assertStringContainsString( 'Name: Ann', $mail->body );
		$this->assertStringContainsString( 'Message: Line one', $mail->body );
		$this->assertStringContainsString( home_url( '/contact/' ), $mail->body );
	}

	public function test_no_ip_address_is_stored(): void {
		$_SERVER['REMOTE_ADDR'] = '198.51.100.77';
		$this->submit();
		$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

		global $wpdb;
		$columns = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', Leads::table() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row     = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i', Leads::table() ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$this->assertEmpty( array_intersect( array( 'ip', 'ip_address', 'user_agent' ), $columns ) );
		$this->assertStringNotContainsString( '198.51.100.77', (string) wp_json_encode( $row ) );
	}

	public function test_email_falls_back_to_admin_email(): void {
		$admin = static fn(): string => 'site-admin@example.com';
		add_filter( 'pre_option_admin_email', $admin );

		$this->submit();
		remove_filter( 'pre_option_admin_email', $admin );

		$this->assertSame( array( 'site-admin@example.com' ), array_column( tests_retrieve_phpmailer_instance()->get_sent( 0 )->to, 0 ) );
	}

	public function test_no_header_injection(): void {
		update_option( 'blogname', "Site\r\nBcc: evil@example.com" );
		$filter = static fn(): array => array( "team@example.com\r\nBcc: evil@example.com", 'ok@example.com' );
		add_filter( 'devrivo_chatbot_notification_recipients', $filter );

		$this->submit();
		remove_filter( 'devrivo_chatbot_notification_recipients', $filter );

		$mail = tests_retrieve_phpmailer_instance()->get_sent( 0 );
		$this->assertSame( array( 'ok@example.com' ), array_column( $mail->to, 0 ) );
		$this->assertStringNotContainsString( "\n", $mail->subject );
		$this->assertStringNotContainsString( 'Bcc: evil', $mail->header );
	}

	public function test_no_reply_to_without_a_visitor_email(): void {
		Plugin::instance()->settings()->update(
			array(
				'forms' => array(
					'contact' => array(
						'title'  => 'Call me',
						'fields' => array(
							array(
								'id'       => 'phone',
								'type'     => 'tel',
								'label'    => 'Phone',
								'required' => true,
							),
						),
					),
				),
			)
		);

		$this->submit( array( 'fields' => array( 'phone' => '0123 456' ) ) );

		$this->assertStringNotContainsString( 'Reply-To', tests_retrieve_phpmailer_instance()->get_sent( 0 )->header );
	}

	public function test_a_mail_failure_keeps_the_lead_and_still_succeeds(): void {
		$fail = static fn(): bool => false;
		add_filter( 'pre_wp_mail', $fail );

		$response = $this->submit();
		remove_filter( 'pre_wp_mail', $fail );

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 1, $this->leads->paginate()['items'] );
	}

	public function test_honeypot_looks_like_success_but_keeps_nothing(): void {
		$response = $this->submit( array( 'website' => 'https://spam.example' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['success'] );
		$this->assertSame( 0, $this->leads->paginate()['total'] );
		$this->assertFalse( tests_retrieve_phpmailer_instance()->get_sent( 0 ) );
	}

	public function test_unknown_form_returns_404(): void {
		$this->assertSame( 404, $this->submit( array(), 'nope' )->get_status() );
	}

	public function test_gate_runs_first(): void {
		$this->assertSame( 403, $this->submit( array( 'token' => 'bad' ) )->get_status() );
		$this->assertSame( 403, $this->submit( array(), 'contact', array( 'Origin' => 'https://evil.example' ) )->get_status() );
		$this->assertSame( 0, $this->leads->paginate()['total'] );
	}

	public function test_rate_limit_returns_429_with_retry_after(): void {
		$token = SessionToken::issue( time() - 10 )['token'];
		for ( $i = 0; $i < 5; $i++ ) {
			$this->submit( array( 'token' => $token ) );
		}

		$response = $this->submit( array( 'token' => $token ) );

		$this->assertSame( 429, $response->get_status() );
		$this->assertGreaterThan( 0, (int) $response->get_headers()['Retry-After'] );
		$this->assertSame( 5, $this->leads->paginate()['total'] );
	}

	/**
	 * Invalid values per field type.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public static function invalid_values(): array {
		return array(
			'required missing' => array( array( 'name' => '' ), 'name' ),
			'required spaces'  => array( array( 'name' => '   ' ), 'name' ),
			'bad email'        => array( array( 'email' => 'ann@' ), 'email' ),
			'bad phone'        => array( array( 'phone' => 'call me' ), 'phone' ),
			'bad date'         => array( array( 'when' => '2026-02-30' ), 'when' ),
			'date format'      => array( array( 'when' => '28/02/2026' ), 'when' ),
			'bad option'       => array( array( 'time' => 'Midnight' ), 'time' ),
			'too long'         => array( array( 'name' => str_repeat( 'a', 41 ) ), 'name' ),
			'array value'      => array( array( 'name' => array( 'Ann' ) ), 'name' ),
		);
	}

	/**
	 * @dataProvider invalid_values
	 *
	 * @param array<string, mixed> $fields Field overrides.
	 * @param string               $field  Field expected in the error.
	 */
	public function test_invalid_values_return_400_per_field( array $fields, string $field ): void {
		$response = $this->submit( array( 'fields' => array_merge( self::values(), $fields ) ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertArrayHasKey( $field, $response->get_data()['data']['params'] );
		$this->assertSame( 0, $this->leads->paginate()['total'] );
	}

	public function test_consent_must_be_true(): void {
		foreach ( array( false, 'true', 1, null ) as $consent ) {
			$response = $this->submit( array( 'consent' => $consent ) );
			$this->assertSame( 400, $response->get_status() );
			$this->assertArrayHasKey( 'consent', $response->get_data()['data']['params'] );
		}
	}

	public function test_optional_fields_and_unknown_keys(): void {
		$values = array_merge(
			self::values(),
			array(
				'phone'    => '',
				'injected' => 'ignored',
			)
		);

		$this->assertSame( 200, $this->submit( array( 'fields' => $values ) )->get_status() );
		$this->assertNotContains( 'injected', array_column( $this->only_lead()['data'], 'id' ) );
	}

	public function test_values_are_sanitised(): void {
		$values = array_merge(
			self::values(),
			array(
				'name'    => '<b>Ann</b>',
				'message' => "<script>x</script>Hi\nthere",
			)
		);

		$this->submit( array( 'fields' => $values ) );
		$data = array_column( $this->only_lead()['data'], 'value', 'id' );

		$this->assertSame( 'Ann', $data['name'] );
		$this->assertSame( "Hi\nthere", $data['message'] );
	}

	public function test_hooks_and_filters(): void {
		$submitted = array();
		$created   = array();
		$on_submit = static function ( ...$args ) use ( &$submitted ): void {
			$submitted[] = $args;
		};
		$on_create = static function ( ...$args ) use ( &$created ): void {
			$created[] = $args;
		};
		$lead_data = static function ( array $lead, string $form_id ): array {
			$lead['name'] = 'Filtered ' . $form_id;
			return $lead;
		};
		$fields    = static function ( array $fields ): array {
			$fields[] = array(
				'id'       => 'company',
				'type'     => 'text',
				'label'    => 'Company',
				'required' => true,
			);
			return $fields;
		};
		add_action( 'devrivo_chatbot_form_submitted', $on_submit, 10, 2 );
		add_action( 'devrivo_chatbot_lead_created', $on_create, 10, 2 );
		add_filter( 'devrivo_chatbot_lead_data', $lead_data, 10, 2 );
		add_filter( 'devrivo_chatbot_form_fields', $fields );

		$missing = $this->submit();
		$ok      = $this->submit( array( 'fields' => array_merge( self::values(), array( 'company' => 'Acme' ) ) ) );

		remove_action( 'devrivo_chatbot_form_submitted', $on_submit, 10 );
		remove_action( 'devrivo_chatbot_lead_created', $on_create, 10 );
		remove_filter( 'devrivo_chatbot_lead_data', $lead_data, 10 );
		remove_filter( 'devrivo_chatbot_form_fields', $fields );

		$this->assertSame( 400, $missing->get_status(), 'The fields filter also applies to validation.' );
		$this->assertArrayHasKey( 'company', $missing->get_data()['data']['params'] );
		$this->assertSame( 200, $ok->get_status() );

		$lead = $this->only_lead();
		$this->assertSame( 'Filtered contact', $lead['name'] );
		$this->assertCount( 1, $submitted );
		$this->assertSame( 'contact', $submitted[0][0] );
		$this->assertSame( 'Acme', $submitted[0][1]['company'] );
		$this->assertCount( 1, $created );
		$this->assertSame( $lead['id'], $created[0][0] );
		$this->assertSame( $lead, $created[0][1] );
	}

	/**
	 * Valid field values.
	 *
	 * @return array<string, string>
	 */
	private static function values(): array {
		return array(
			'name'    => 'Ann',
			'email'   => 'ann@example.com',
			'phone'   => '+44 (0)20 7946-0000',
			'when'    => '2026-02-28',
			'time'    => 'Morning',
			'message' => "Line one\nLine two",
		);
	}

	/**
	 * Test form with every field type.
	 *
	 * @return array<string, mixed>
	 */
	private static function form(): array {
		$field = static fn( string $id, string $type, string $label, bool $required = false, array $extra = array() ): array => array_merge(
			array(
				'id'       => $id,
				'type'     => $type,
				'label'    => $label,
				'required' => $required,
			),
			$extra
		);

		return array(
			'title'           => 'Contact us',
			'success_message' => 'Thanks!',
			'fields'          => array(
				$field( 'name', 'text', 'Name', true, array( 'max_length' => 40 ) ),
				$field( 'email', 'email', 'Email', true ),
				$field( 'phone', 'tel', 'Phone' ),
				$field( 'when', 'date', 'Date' ),
				$field( 'time', 'select', 'Time', false, array( 'options' => array( 'Morning', 'Afternoon' ) ) ),
				$field( 'message', 'textarea', 'Message', true, array( 'max_length' => 1000 ) ),
			),
		);
	}

	/**
	 * The only saved lead.
	 *
	 * @return array<string, mixed>
	 */
	private function only_lead(): array {
		$items = $this->leads->paginate()['items'];
		$this->assertCount( 1, $items );

		return $items[0];
	}

	/**
	 * Submits the form.
	 *
	 * @param array<string, mixed>  $body    Body overrides.
	 * @param string                $form_id Form ID.
	 * @param array<string, string> $headers Header overrides.
	 */
	private function submit( array $body = array(), string $form_id = 'contact', array $headers = array() ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', sprintf( self::ROUTE, $form_id ) );
		$request->set_header( 'Content-Type', 'application/json' );
		foreach ( array_merge( array( 'Origin' => home_url() ), $headers ) as $name => $value ) {
			$request->set_header( $name, $value );
		}
		$request->set_body(
			(string) wp_json_encode(
				array_merge(
					array(
						'token'    => SessionToken::issue( time() - 10 )['token'],
						'website'  => '',
						'consent'  => true,
						'fields'   => self::values(),
						'page_url' => home_url( '/contact/?email=ann@example.com#form' ),
					),
					$body
				)
			)
		);

		return rest_get_server()->dispatch( $request );
	}
}
