<?php
/**
 * Settings export / import REST controller (admin).
 *
 * @package DevRivo\Chatbot
 */

declare( strict_types=1 );

namespace DevRivo\Chatbot\Admin;

use DevRivo\Chatbot\Core\Capabilities;
use DevRivo\Chatbot\Core\Presets\PresetApplier;
use DevRivo\Chatbot\Core\Rest;
use DevRivo\Chatbot\Core\Settings\Schema;
use DevRivo\Chatbot\Core\Settings\Settings;
use DevRivo\Chatbot\Core\Settings\SettingsMigrator;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Admin-only (devrivo_chatbot_manage, no-store) routes under devrivo-chatbot/v1:
 *
 * - GET  /settings/export  { meta: { plugin, plugin_version, settings_version, exported_at, site }, settings }.
 * - POST /settings/import  { data, sections[], skip_site_specific }: the chosen
 *   sections of an exported file are saved in one all-or-nothing
 *   Settings::update(), after the same backup a preset makes (so Undo works).
 */
final class TransferController extends WP_REST_Controller {

	/**
	 * Marker in the export meta, so files from other plugins are refused.
	 */
	public const PLUGIN = 'devrivo-chatbot';

	/**
	 * Largest accepted request body, in bytes.
	 */
	public const MAX_BYTES = 1048576;

	/**
	 * Constructor.
	 *
	 * @param Settings      $settings Settings repository.
	 * @param PresetApplier $applier  Saves with a backup.
	 */
	public function __construct( private Settings $settings, private PresetApplier $applier ) {
		$this->namespace = Rest::NAMESPACE;
		$this->rest_base = 'settings';
	}

	/**
	 * Registers the routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/export',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'export' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/import',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'import' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array(
						'data'               => array(
							'description' => __( 'The exported file: { meta, settings }.', 'devrivo-chatbot' ),
							'type'        => 'object',
							'required'    => true,
						),
						'sections'           => array(
							'description' => __( 'Sections to import. Empty for every section in the file.', 'devrivo-chatbot' ),
							'type'        => 'array',
							'items'       => array(
								'type' => 'string',
								'enum' => array_keys( Schema::sections() ),
							),
							'default'     => array(),
						),
						'skip_site_specific' => array(
							'description' => __( 'Keep this site\'s page IDs, avatar and AI connection instead of the file\'s.', 'devrivo-chatbot' ),
							'type'        => 'boolean',
							'default'     => true,
						),
					),
				),
			)
		);
	}

	/**
	 * Permission (both routes).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public function permissions_check( $request ) {
		return current_user_can( Capabilities::MANAGE );
	}

	/**
	 * Exports all settings.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function export( WP_REST_Request $request ): WP_REST_Response {
		return self::respond(
			array(
				'meta'     => array(
					'plugin'           => self::PLUGIN,
					'plugin_version'   => DEVRIVO_CHATBOT_VERSION,
					'settings_version' => Settings::VERSION,
					'exported_at'      => gmdate( 'Y-m-d\TH:i:s\Z' ),
					'site'             => home_url( '/' ),
				),
				'settings' => self::shape( $this->settings->all() ),
			)
		);
	}

	/**
	 * Imports settings.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function import( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		if ( strlen( $request->get_body() ) > self::MAX_BYTES ) {
			return new WP_Error(
				'devrivo_chatbot_import_too_large',
				__( 'The file is too large. Settings files are at most 1 MB.', 'devrivo-chatbot' ),
				array( 'status' => 413 )
			);
		}

		$settings = self::settings_from( $request['data'] );
		if ( is_wp_error( $settings ) ) {
			return $settings;
		}

		$sections  = array_values( array_unique( array_map( 'strval', (array) $request['sections'] ) ) );
		$available = array_keys( array_intersect_key( $settings, Schema::sections() ) );
		if ( array() === $sections ) {
			$sections = $available;
		}

		foreach ( $sections as $section ) {
			if ( ! in_array( $section, $available, true ) ) {
				return new WP_Error(
					'rest_invalid_param',
					/* translators: %s: Settings section name. */
					sprintf( __( 'The file has no "%s" section.', 'devrivo-chatbot' ), $section ),
					array( 'status' => 400 )
				);
			}
		}

		if ( array() === $sections ) {
			return new WP_Error(
				'rest_invalid_param',
				__( 'The file contains no settings to import.', 'devrivo-chatbot' ),
				array( 'status' => 400 )
			);
		}

		$changes = array_intersect_key( $settings, array_flip( $sections ) );
		if ( $request['skip_site_specific'] ) {
			$changes = self::without_site_specific( $changes, $this->settings->all() );
		}

		$result = $this->applier->save_with_backup( $changes );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return self::respond(
			array(
				'settings' => self::shape( $result ),
				'sections' => $sections,
			)
		);
	}

	/**
	 * Settings from an exported file, migrated to the current structure.
	 *
	 * @param mixed $data Decoded file.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function settings_from( mixed $data ): array|WP_Error {
		$meta    = is_array( $data ) && is_array( $data['meta'] ?? null ) ? $data['meta'] : array();
		$version = $meta['settings_version'] ?? null;

		if ( self::PLUGIN !== ( $meta['plugin'] ?? null ) ) {
			return self::invalid_file( __( 'This is not a DevRivo Chatbot settings file.', 'devrivo-chatbot' ) );
		}
		if ( ! is_int( $version ) || $version < 1 ) {
			return self::invalid_file( __( 'The settings file is damaged: its version is missing.', 'devrivo-chatbot' ) );
		}
		if ( $version > Settings::VERSION ) {
			return self::invalid_file( __( 'This file comes from a newer version of DevRivo Chatbot. Update the plugin, then import it again.', 'devrivo-chatbot' ) );
		}
		if ( ! is_array( $data['settings'] ?? null ) || array() === $data['settings'] ) {
			return self::invalid_file( __( 'The settings file is damaged: it contains no settings.', 'devrivo-chatbot' ) );
		}

		// Steps at or below the file's version are skipped.
		return SettingsMigrator::migrate( $data['settings'], $version );
	}

	/**
	 * Keeps this site's own values for settings that only make sense on the
	 * site they came from: page and post IDs, the avatar image and the AI
	 * connection (on/off, provider, model, monthly limit).
	 *
	 * @param array<string, mixed> $changes Sections to import.
	 * @param array<string, mixed> $current Current settings.
	 * @return array<string, mixed>
	 */
	private static function without_site_specific( array $changes, array $current ): array {
		if ( is_array( $changes['display'] ?? null ) && is_array( $changes['display']['rules'] ?? null ) ) {
			// Objects merge one level deep only, so the nested list is set explicitly.
			$changes['display']['rules']['post_ids'] = $current['display']['rules']['post_ids'] ?? array();
		}

		if ( is_array( $changes['knowledge'] ?? null ) ) {
			unset( $changes['knowledge']['exclude_ids'] );
		}

		if ( is_array( $changes['appearance'] ?? null ) ) {
			unset( $changes['appearance']['avatar_id'] );
		}

		if ( is_array( $changes['ai'] ?? null ) ) {
			unset( $changes['ai']['enabled'], $changes['ai']['provider'], $changes['ai']['model'], $changes['ai']['monthly_token_limit'] );
		}

		return $changes;
	}

	/**
	 * Error for a file that can't be imported.
	 *
	 * @param string $message Message.
	 */
	private static function invalid_file( string $message ): WP_Error {
		return new WP_Error( 'devrivo_chatbot_invalid_import', $message, array( 'status' => 400 ) );
	}

	/**
	 * All sections with JSON-correct shapes ({} for empty objects).
	 *
	 * @param array<string, mixed> $values Settings.
	 * @return array<string, mixed>
	 */
	private static function shape( array $values ): array {
		$data = array();
		foreach ( Schema::sections() as $name => $schema ) {
			$data[ $name ] = Schema::to_json_shape( $values[ $name ] ?? null, $schema );
		}

		return $data;
	}

	/**
	 * Builds an uncached response.
	 *
	 * @param array<string, mixed> $data Response data.
	 */
	private static function respond( array $data ): WP_REST_Response {
		$response = new WP_REST_Response( $data );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}
}
