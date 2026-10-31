<?php
/**
 * Front-end assets.
 *
 * @package DevRivo\Chatbot
 */

declare( strict_types=1 );

namespace DevRivo\Chatbot\Core\Frontend;

use DevRivo\Chatbot\Core\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Enqueues the small widget loader where the widget is displayed, and nothing
 * anywhere else.
 *
 * The loader draws the launcher and loads build/widget.js on interaction or
 * when the browser is idle. Its boot data comes from the autoloaded display
 * option only; everything else is fetched from the public config endpoint.
 */
final class Assets {

	/**
	 * Loader script handle.
	 */
	public const HANDLE = 'devrivo-chatbot-loader';

	/**
	 * Folder with the built files and their .asset.php files.
	 *
	 * @var string
	 */
	private string $build_dir;

	/**
	 * Constructor.
	 *
	 * @param Display     $display   Display rules.
	 * @param string|null $build_dir Build folder (tests use a fixture); defaults to the plugin's build/.
	 */
	public function __construct( private Display $display, ?string $build_dir = null ) {
		$this->build_dir = trailingslashit( $build_dir ?? DEVRIVO_CHATBOT_PATH . 'build' );
	}

	/**
	 * Hooks enqueueing.
	 */
	public function register(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Enqueues the loader and its boot data. Runs on wp_enqueue_scripts.
	 */
	public function enqueue(): void {
		if ( ! $this->display->should_display() ) {
			return;
		}

		$loader = $this->asset( 'widget-loader' );
		if ( null === $loader ) {
			return; // Not built (development checkout).
		}

		wp_enqueue_script(
			self::HANDLE,
			DEVRIVO_CHATBOT_URL . 'build/widget-loader.js',
			$loader['dependencies'],
			$loader['version'],
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		wp_add_inline_script(
			self::HANDLE,
			'window.devrivoChatbotBoot = ' . wp_json_encode( $this->boot(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . ';',
			'before'
		);
	}

	/**
	 * Boot data for the loader.
	 *
	 * @return array<string, mixed>
	 */
	public function boot(): array {
		$options = Display::options();
		$widget  = $this->asset( 'widget' );

		return array(
			'configUrl'     => add_query_arg(
				array(
					'v'      => Settings::config_version(),
					'locale' => determine_locale(),
				),
				rest_url( ConfigController::ROUTE )
			),
			'widgetUrl'     => add_query_arg( 'ver', $widget['version'] ?? DEVRIVO_CHATBOT_VERSION, DEVRIVO_CHATBOT_URL . 'build/widget.js' ),
			// Bundled Figtree; only downloaded when the widget uses it.
			'fontUrl'       => self::font_url(),
			'position'      => $options['position'],
			'primaryColor'  => $options['primary_color'],
			'launcherLabel' => Display::launcher_label( (string) $options['launcher_label'] ),
			'devices'       => array_values( $options['rules']['devices'] ),
		);
	}

	/**
	 * Reads a build/*.asset.php file.
	 *
	 * @param string $name Entry name.
	 * @return array{dependencies: string[], version: string}|null
	 */
	private function asset( string $name ): ?array {
		$file = $this->build_dir . $name . '.asset.php';
		if ( ! is_readable( $file ) ) {
			return null;
		}

		$asset = include $file;

		return array(
			'dependencies' => is_array( $asset['dependencies'] ?? null ) ? $asset['dependencies'] : array(),
			'version'      => is_string( $asset['version'] ?? null ) ? $asset['version'] : DEVRIVO_CHATBOT_VERSION,
		);
	}

	/**
	 * URL of the bundled Figtree font (variable woff2, weights 300–900).
	 */
	public static function font_url(): string {
		return add_query_arg( 'ver', DEVRIVO_CHATBOT_VERSION, DEVRIVO_CHATBOT_URL . 'assets/fonts/figtree/figtree-variable.woff2' );
	}
}
