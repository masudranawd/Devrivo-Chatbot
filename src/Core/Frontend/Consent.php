<?php
/**
 * Consent text shown on forms.
 *
 * @package DevRivo\Chatbot
 */

declare( strict_types=1 );

namespace DevRivo\Chatbot\Core\Frontend;

use DevRivo\Chatbot\Core\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * One source for the consent checkbox: the widget shows it and each lead
 * stores a snapshot of the same text.
 */
final class Consent {

	/**
	 * Consent for the widget.
	 *
	 * @param Settings $settings Settings repository.
	 * @return array{text: string, policy_url: string, policy_label: string}
	 */
	public static function public( Settings $settings ): array {
		return array(
			'text'         => self::text( $settings ),
			'policy_url'   => (string) get_privacy_policy_url(),
			'policy_label' => __( 'Privacy policy', 'devrivo-chatbot' ),
		);
	}

	/**
	 * Plain consent text: Privacy → Consent text without HTML, or the default.
	 *
	 * @param Settings $settings Settings repository.
	 */
	public static function text( Settings $settings ): string {
		$text = trim( wp_strip_all_tags( (string) ( $settings->get( 'privacy' )['consent_text'] ?? '' ) ) );

		return '' !== $text
			? $text
			: __( 'I agree to my details being stored and used to reply to my enquiry.', 'devrivo-chatbot' );
	}
}
