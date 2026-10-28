<?php
/**
 * Runtime requirement checks.
 *
 * @package DevRivo\Chatbot
 */

declare( strict_types=1 );

namespace DevRivo\Chatbot\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Checks the WordPress version. The PHP version is checked earlier, in the
 * bootstrap file.
 *
 * Errors are stored as codes and translated only when the notice renders,
 * because this runs on `plugins_loaded`, before `init`, when loading
 * translations triggers a "loaded too early" notice.
 */
final class Requirements {

	/**
	 * Failed requirement codes.
	 *
	 * @var string[]
	 */
	private array $errors = array();

	/**
	 * Whether the checks have run.
	 *
	 * @var bool
	 */
	private bool $checked = false;

	/**
	 * Whether all requirements are met.
	 */
	public function met(): bool {
		$this->check();

		return array() === $this->errors;
	}

	/**
	 * Failed requirement codes.
	 *
	 * @return string[]
	 */
	public function errors(): array {
		$this->check();

		return $this->errors;
	}

	/**
	 * Whether the WordPress core AI Client is present.
	 *
	 * This is not a requirement: without it the plugin runs in Guided mode.
	 */
	public static function ai_client_available(): bool {
		return function_exists( 'wp_ai_client_prompt' );
	}

	/**
	 * Prints an admin notice for each failed requirement.
	 */
	public function render_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		foreach ( $this->errors() as $code ) {
			printf(
				'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
				esc_html( $this->message( $code ) )
			);
		}
	}

	/**
	 * Runs the checks once.
	 */
	private function check(): void {
		if ( $this->checked ) {
			return;
		}
		$this->checked = true;

		if ( version_compare( self::base_wp_version(), DEVRIVO_CHATBOT_MIN_WP, '<' ) ) {
			$this->errors[] = 'wp_version';
		}
	}

	/**
	 * WordPress version without a pre-release suffix, so 7.0-RC1 counts as 7.0.
	 */
	private static function base_wp_version(): string {
		return (string) preg_replace( '/-.+$/', '', (string) get_bloginfo( 'version' ) );
	}

	/**
	 * Translated message for an error code.
	 *
	 * @param string $code Error code.
	 */
	private function message( string $code ): string {
		return match ( $code ) {
			'wp_version' => sprintf(
				/* translators: 1: Required WordPress version. 2: Current WordPress version. */
				__( 'DevRivo Chatbot requires WordPress %1$s or newer. This site runs WordPress %2$s, so the plugin stays inactive until WordPress is updated.', 'devrivo-chatbot' ),
				DEVRIVO_CHATBOT_MIN_WP,
				get_bloginfo( 'version' )
			),
			default      => __( 'DevRivo Chatbot cannot run on this site because a requirement is not met.', 'devrivo-chatbot' ),
		};
	}
}
