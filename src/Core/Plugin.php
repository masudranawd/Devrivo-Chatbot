<?php
/**
 * Main plugin class.
 *
 * @package DevRivo\Chatbot
 */

declare( strict_types=1 );

namespace DevRivo\Chatbot\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Boots the plugin once requirements are met.
 *
 * Services (settings, REST, assets, modules) are registered here as each
 * feature ships.
 */
final class Plugin {

	/**
	 * Shared instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Whether boot() has already run.
	 *
	 * @var bool
	 */
	private bool $booted = false;

	/**
	 * Entry point, hooked to `plugins_loaded`.
	 */
	public static function bootstrap(): void {
		$requirements = new Requirements();

		if ( ! $requirements->met() ) {
			add_action( 'admin_notices', array( $requirements, 'render_notice' ) );
			return;
		}

		self::instance()->boot();
	}

	/**
	 * Returns the shared instance.
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Use instance().
	 */
	private function __construct() {}

	/**
	 * Boots core services. Safe to call more than once.
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		// Covers updates without reactivation and network-activated sites.
		Activator::maybe_install();

		/**
		 * Fires after DevRivo Chatbot has loaded its core services.
		 *
		 * @since 1.0.0
		 *
		 * @param Plugin $plugin The plugin instance.
		 */
		do_action( 'devrivo_chatbot_loaded', $this );
	}

	/**
	 * Plugin version.
	 */
	public function version(): string {
		return DEVRIVO_CHATBOT_VERSION;
	}
}
