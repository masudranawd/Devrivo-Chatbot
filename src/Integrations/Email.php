<?php
/**
 * Lead notification emails.
 *
 * @package DevRivo\Chatbot
 */

declare( strict_types=1 );

namespace DevRivo\Chatbot\Integrations;

use DevRivo\Chatbot\Core\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Emails the site team about a new lead, as plain text through wp_mail().
 *
 * Every header value has CR/LF removed so visitor input can't add headers.
 * A failed send never loses the lead or shows an error to the visitor.
 */
final class Email {

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings repository.
	 */
	public function __construct( private Settings $settings ) {}

	/**
	 * Sends the notification.
	 *
	 * @param array<string, mixed> $lead       Saved lead (id, email, data, page_url).
	 * @param string               $form_title Form title.
	 * @return bool Whether wp_mail() accepted the message.
	 */
	public function notify( array $lead, string $form_title ): bool {
		$recipients = $this->recipients( $lead );
		if ( array() === $recipients ) {
			return false;
		}

		$site    = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$subject = self::header_value(
			sprintf(
				/* translators: 1: Site name, 2: Form title. */
				__( '[%1$s] New enquiry: %2$s', 'devrivo-chatbot' ),
				$site,
				$form_title
			)
		);

		$lines = array( $form_title, '' );
		foreach ( (array) ( $lead['data'] ?? array() ) as $field ) {
			$lines[] = sprintf( '%s: %s', (string) ( $field['label'] ?? '' ), (string) ( $field['value'] ?? '' ) );
		}
		$lines[] = '';
		/* translators: %s: Page URL. */
		$lines[] = sprintf( __( 'Page: %s', 'devrivo-chatbot' ), (string) ( $lead['page_url'] ?? '' ) );
		/* translators: %s: Date and time. */
		$lines[] = sprintf( __( 'Time: %s', 'devrivo-chatbot' ), wp_date( (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' ) ) );

		$headers = array( 'Content-Type: text/plain; charset=UTF-8' );
		$email   = (string) ( $lead['email'] ?? '' );
		if ( '' !== $email && is_email( $email ) ) {
			$headers[] = 'Reply-To: ' . self::header_value( $email );
		}

		try {
			return (bool) wp_mail( $recipients, $subject, implode( "\n", $lines ), $headers );
		} catch ( \Throwable $e ) {
			return false; // A broken mailer must not lose the lead.
		}
	}

	/**
	 * Notification addresses: Integrations → Notification emails, else the site admin email.
	 *
	 * @param array<string, mixed> $lead Lead.
	 * @return string[]
	 */
	public function recipients( array $lead ): array {
		$emails = (array) ( $this->settings->get( 'integrations' )['notification_emails'] ?? array() );
		if ( array() === $emails ) {
			$emails = array( (string) get_option( 'admin_email' ) );
		}

		/**
		 * Filters who is emailed about a new lead.
		 *
		 * @since 1.0.0
		 *
		 * @param string[]             $emails Email addresses.
		 * @param array<string, mixed> $lead   The saved lead.
		 */
		$emails = (array) apply_filters( 'devrivo_chatbot_notification_recipients', $emails, $lead );

		return array_values( array_unique( array_filter( array_map( static fn( $e ): string => self::header_value( (string) $e ), $emails ), 'is_email' ) ) );
	}

	/**
	 * A header-safe value: no CR, LF or other control characters.
	 *
	 * @param string $value Value.
	 */
	private static function header_value( string $value ): string {
		return trim( (string) preg_replace( '/[\x00-\x1F\x7F]+/', ' ', $value ) );
	}
}
