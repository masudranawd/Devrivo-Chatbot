<?php
/**
 * REST API registration.
 *
 * @package DevRivo\Chatbot
 */

declare( strict_types=1 );

namespace DevRivo\Chatbot\Core;

use DevRivo\Chatbot\Core\Settings\Settings;
use DevRivo\Chatbot\Core\Settings\SettingsController;

defined( 'ABSPATH' ) || exit;

/**
 * Registers every REST controller under devrivo-chatbot/v1.
 */
final class Rest {

	/**
	 * REST namespace.
	 */
	public const NAMESPACE = 'devrivo-chatbot/v1';

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings repository.
	 */
	public function __construct( private Settings $settings ) {}

	/**
	 * Hooks route registration.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Registers routes. Runs on rest_api_init.
	 */
	public function register_routes(): void {
		( new SettingsController( $this->settings ) )->register_routes();
	}
}
