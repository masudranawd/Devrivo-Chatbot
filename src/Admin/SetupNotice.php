<?php
/**
 * Setup notice on the Plugins screen.
 *
 * @package DevRivo\Chatbot
 */

declare( strict_types=1 );

namespace DevRivo\Chatbot\Admin;

use DevRivo\Chatbot\Core\Capabilities;
use DevRivo\Chatbot\Core\Rest;
use DevRivo\Chatbot\Core\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * One dismissible "Set up your assistant" notice: only on the Plugins screen,
 * only for users who can manage the plugin, and only until setup is
 * completed or the notice is dismissed (saved through the REST API with the
 * standard REST nonce of wp-api-fetch).
 */
final class SetupNotice {

	/**
	 * Notice element ID.
	 */
	public const ID = 'devrivo-chatbot-setup-notice';

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings repository.
	 */
	public function __construct( private Settings $settings ) {}

	/**
	 * Hooks the notice and its script.
	 */
	public function register(): void {
		add_action( 'admin_notices', array( $this, 'render' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Whether the notice shows on the current screen.
	 */
	public function should_show(): bool {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			return false;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( null === $screen || 'plugins' !== $screen->id ) {
			return false;
		}

		$setup = (array) ( $this->settings->get( 'general' )['setup'] ?? array() );

		return empty( $setup['completed'] ) && empty( $setup['notice_dismissed'] );
	}

	/**
	 * Dismiss script (only with the notice).
	 */
	public function enqueue(): void {
		if ( ! $this->should_show() ) {
			return;
		}

		wp_enqueue_script( 'wp-api-fetch' );
		wp_add_inline_script(
			'wp-api-fetch',
			sprintf(
				'document.addEventListener("click",function(e){var b=e.target&&e.target.closest&&e.target.closest("#%1$s .notice-dismiss");if(b){wp.apiFetch({path:%2$s,method:"POST"}).catch(function(){});}});',
				self::ID,
				wp_json_encode( '/' . Rest::NAMESPACE . '/setup/dismiss-notice', JSON_UNESCAPED_SLASHES )
			)
		);
	}

	/**
	 * Prints the notice.
	 */
	public function render(): void {
		if ( ! $this->should_show() ) {
			return;
		}

		printf(
			'<div id="%1$s" class="notice notice-info is-dismissible"><p><strong>%2$s</strong> %3$s</p><p><a class="button button-primary" href="%4$s">%5$s</a></p></div>',
			esc_attr( self::ID ),
			esc_html__( 'DevRivo Chatbot is installed.', 'devrivo-chatbot' ),
			esc_html__( 'A short setup gets your assistant working in about five minutes.', 'devrivo-chatbot' ),
			esc_url( admin_url( 'admin.php?page=' . AdminPage::SLUG . '#/setup' ) ),
			esc_html__( 'Set up your assistant', 'devrivo-chatbot' )
		);
	}
}
