<?php
/**
 * Admin page and app assets.
 *
 * @package DevRivo\Chatbot
 */

declare( strict_types=1 );

namespace DevRivo\Chatbot\Admin;

use DevRivo\Chatbot\Core\Capabilities;
use DevRivo\Chatbot\Core\Rest;
use DevRivo\Chatbot\Engine\ActionTypes;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the "DevRivo Chatbot" menu page and loads the React admin app on that
 * screen only (from its load-{hook} action). Adds the plugin list links.
 */
final class AdminPage {

	/**
	 * Menu slug.
	 */
	public const SLUG = 'devrivo-chatbot';

	/**
	 * Script and style handle.
	 */
	public const HANDLE = 'devrivo-chatbot-admin';

	/**
	 * Element the app renders into.
	 */
	public const ROOT_ID = 'devrivo-chatbot-admin';

	/**
	 * Source code repository.
	 */
	public const REPOSITORY = 'https://github.com/masudranawd/Devrivo-Chatbot';

	/**
	 * Screen hook suffix, set when the menu is added.
	 *
	 * @var string
	 */
	private string $hook = '';

	/**
	 * Build folder.
	 *
	 * @var string
	 */
	private string $build_dir;

	/**
	 * Constructor.
	 *
	 * @param string|null $build_dir Build folder (tests); defaults to the plugin's build/.
	 */
	public function __construct( ?string $build_dir = null ) {
		$this->build_dir = trailingslashit( $build_dir ?? DEVRIVO_CHATBOT_PATH . 'build' );
	}

	/**
	 * Hooks the menu and plugin list links.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_filter( 'plugin_action_links_' . DEVRIVO_CHATBOT_BASENAME, array( $this, 'action_links' ) );
		add_filter( 'plugin_row_meta', array( $this, 'row_meta' ), 10, 2 );
	}

	/**
	 * Adds the top-level menu page.
	 */
	public function add_menu(): void {
		$hook = add_menu_page(
			__( 'DevRivo Chatbot', 'devrivo-chatbot' ),
			__( 'DevRivo Chatbot', 'devrivo-chatbot' ),
			Capabilities::MANAGE,
			self::SLUG,
			array( $this, 'render' ),
			'dashicons-format-chat'
		);

		if ( '' !== $hook ) {
			$this->hook = $hook;
			add_action( 'load-' . $hook, array( $this, 'load' ) );
		}
	}

	/**
	 * Our screen is loading: enqueue the app (only here).
	 */
	public function load(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Screen hook suffix ('' before the menu was added).
	 */
	public function hook(): string {
		return $this->hook;
	}

	/**
	 * Enqueues the app, its styles, translations and boot data.
	 */
	public function enqueue(): void {
		$asset = $this->asset( 'admin' );

		wp_enqueue_script( self::HANDLE, DEVRIVO_CHATBOT_URL . 'build/admin.js', $asset['dependencies'], $asset['version'], true );
		wp_set_script_translations( self::HANDLE, 'devrivo-chatbot' );
		wp_add_inline_script(
			self::HANDLE,
			'window.devrivoChatbotAdmin = ' . wp_json_encode( $this->boot(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . ';',
			'before'
		);

		wp_enqueue_style( 'wp-components' );
		if ( is_readable( $this->build_dir . 'admin.css' ) ) {
			wp_enqueue_style( self::HANDLE, DEVRIVO_CHATBOT_URL . 'build/admin.css', array( 'wp-components' ), $asset['version'] );
			if ( is_readable( $this->build_dir . 'admin-rtl.css' ) ) {
				wp_style_add_data( self::HANDLE, 'rtl', 'replace' );
			}
		}

		wp_enqueue_media();
	}

	/**
	 * Renders the app root.
	 */
	public function render(): void {
		printf(
			'<div class="wrap devrivo-chatbot-wrap"><div id="%1$s"></div><noscript><div class="notice notice-error"><p>%2$s</p></div></noscript></div>',
			esc_attr( self::ROOT_ID ),
			esc_html__( 'DevRivo Chatbot settings need JavaScript. Please turn on JavaScript in your browser and reload this page.', 'devrivo-chatbot' )
		);
	}

	/**
	 * Data the app needs before its first request.
	 *
	 * @return array<string, mixed>
	 */
	public function boot(): array {
		$widget = $this->asset( 'widget' );

		return array(
			'version'            => DEVRIVO_CHATBOT_VERSION,
			'siteName'           => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
			'homeUrl'            => home_url( '/' ),
			'connectorsUrl'      => admin_url( 'options-connectors.php' ),
			'privacyExportUrl'   => admin_url( 'export-personal-data.php' ),
			'privacyEraseUrl'    => admin_url( 'erase-personal-data.php' ),
			'privacySettingsUrl' => admin_url( 'options-privacy.php' ),
			'privacyPolicyUrl'   => (string) get_privacy_policy_url(),
			'postTypes'          => self::post_types(),
			'timezones'          => timezone_identifiers_list(),
			'siteTimezone'       => wp_timezone_string(),
			'actionTypes'        => self::action_types(),
			'previewWidgetUrl'   => add_query_arg( 'ver', $widget['version'], DEVRIVO_CHATBOT_URL . 'build/widget.js' ),
			'leadsExportUrl'     => LeadsExport::url(),
			// Setup wizard: AI provider plugins, and the public chat for "Test a question".
			'aiPluginsUrl'       => admin_url( 'plugin-install.php?s=' . rawurlencode( 'AI Provider' ) . '&tab=search&type=term' ),
			'chatUrl'            => rest_url( Rest::NAMESPACE . '/chat' ),
			'sessionUrl'         => rest_url( Rest::NAMESPACE . '/session' ),
		);
	}

	/**
	 * "Settings" link in the plugin list.
	 *
	 * @param array<int|string, string> $links Links.
	 * @return array<int|string, string>
	 */
	public function action_links( array $links ): array {
		if ( current_user_can( Capabilities::MANAGE ) ) {
			array_unshift(
				$links,
				sprintf( '<a href="%1$s">%2$s</a>', esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ), esc_html__( 'Settings', 'devrivo-chatbot' ) )
			);
		}

		return $links;
	}

	/**
	 * Source code link under the plugin description.
	 *
	 * @param array<int|string, string> $links Links.
	 * @param string                    $file  Plugin file.
	 * @return array<int|string, string>
	 */
	public function row_meta( array $links, string $file ): array {
		if ( DEVRIVO_CHATBOT_BASENAME === $file ) {
			$links[] = sprintf( '<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>', esc_url( self::REPOSITORY ), esc_html__( 'Source code on GitHub', 'devrivo-chatbot' ) );
		}

		return $links;
	}

	/**
	 * Post types offered in the admin (Display rules, later Knowledge):
	 * publicly viewable, not attachments, and not hidden from search
	 * (builder templates such as elementor_library).
	 *
	 * @return list<array{slug: string, label: string}>
	 */
	public static function post_types(): array {
		$types = array();
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
			if ( 'attachment' !== $type->name && ! $type->exclude_from_search && is_post_type_viewable( $type ) ) {
				$types[ $type->name ] = wp_strip_all_tags( (string) $type->labels->name );
			}
		}

		/**
		 * Filters the post types offered in the admin app (Display rules and
		 * Knowledge), as slug => label.
		 *
		 * The default excludes attachments and post types hidden from search.
		 * Types that don't exist are dropped after filtering.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, string> $types Post type labels keyed by slug.
		 */
		$types = (array) apply_filters( 'devrivo_chatbot_admin_post_types', $types );

		$list = array();
		foreach ( $types as $slug => $label ) {
			$slug = (string) $slug;
			if ( post_type_exists( $slug ) ) {
				$list[] = array(
					'slug'  => $slug,
					'label' => wp_strip_all_tags( (string) $label ),
				);
			}
		}

		return $list;
	}

	/**
	 * Action types for the quick actions editor: id, supported, required keys
	 * and config fields (key, type, max length, pattern).
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function action_types(): array {
		$types = array();
		foreach ( ActionTypes::all() as $id => $type ) {
			$fields = array();
			foreach ( (array) ( $type['schema']['properties'] ?? array() ) as $key => $field ) {
				$fields[] = array(
					'key'       => (string) $key,
					'type'      => isset( $field['anyOf'] ) ? 'string' : (string) ( $field['type'] ?? 'string' ),
					'format'    => isset( $field['anyOf'] ) ? 'email' : (string) ( $field['format'] ?? '' ),
					'maxLength' => (int) ( $field['maxLength'] ?? 0 ),
					'pattern'   => (string) ( $field['pattern'] ?? '' ),
				);
			}
			$types[] = array(
				'id'        => (string) $id,
				'supported' => $type['supported'],
				'required'  => $type['required'],
				'fields'    => $fields,
			);
		}

		return $types;
	}

	/**
	 * Reads a build/*.asset.php file.
	 *
	 * @param string $name Entry name.
	 * @return array{dependencies: string[], version: string}
	 */
	private function asset( string $name ): array {
		$file  = $this->build_dir . $name . '.asset.php';
		$asset = is_readable( $file ) ? include $file : array();

		return array(
			'dependencies' => is_array( $asset['dependencies'] ?? null ) ? array_values( $asset['dependencies'] ) : array(),
			'version'      => is_string( $asset['version'] ?? null ) ? $asset['version'] : DEVRIVO_CHATBOT_VERSION,
		);
	}
}
