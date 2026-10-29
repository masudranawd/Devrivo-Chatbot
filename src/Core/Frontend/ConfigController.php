<?php
/**
 * Public widget config REST controller.
 *
 * @package DevRivo\Chatbot
 */

declare( strict_types=1 );

namespace DevRivo\Chatbot\Core\Frontend;

use DevRivo\Chatbot\Core\Rest;
use DevRivo\Chatbot\Core\Settings\Schema;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * GET devrivo-chatbot/v1/config
 *
 * Public and read-only. The URL carries the config version and locale, so the
 * response is cached for a day and revalidated with an ETag.
 */
final class ConfigController extends WP_REST_Controller {

	/**
	 * Route path, relative to the REST root.
	 */
	public const ROUTE = Rest::NAMESPACE . '/config';

	/**
	 * Locale pattern (e.g. en_US, de_DE_formal, pt_BR).
	 */
	private const LOCALE_PATTERN = '^[a-z]{2,3}(_[A-Za-z0-9]{2,8})*$';

	/**
	 * Constructor.
	 *
	 * @param WidgetConfig $config Config builder.
	 */
	public function __construct( private WidgetConfig $config ) {
		$this->namespace = Rest::NAMESPACE;
		$this->rest_base = 'config';
	}

	/**
	 * Registers the route.
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					// Public and read-only: the response contains no personal or private data.
					'permission_callback' => '__return_true',
					'args'                => array(
						'v'      => array(
							'description' => __( 'Config version, for cache busting.', 'devrivo-chatbot' ),
							'type'        => 'integer',
							'minimum'     => 0,
						),
						'locale' => array(
							'description' => __( 'Locale of the page the widget runs on.', 'devrivo-chatbot' ),
							'type'        => 'string',
							'pattern'     => self::LOCALE_PATTERN,
						),
					),
				),
			)
		);
	}

	/**
	 * Returns the widget config, or 304 when the client's copy is current.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_item( $request ) {
		$locale   = $this->locale( (string) $request->get_param( 'locale' ) );
		$switched = determine_locale() !== $locale && switch_to_locale( $locale );

		$data = self::shape( $this->config->build( $locale ) );

		if ( $switched ) {
			restore_previous_locale();
		}

		$etag = '"' . md5( (string) wp_json_encode( $data ) ) . '"';

		$response = self::matches( (string) $request->get_header( 'if_none_match' ), $etag )
			? new WP_REST_Response( null, 304 )
			: new WP_REST_Response( $data );

		$response->header( 'Cache-Control', 'public, max-age=86400' );
		$response->header( 'ETag', $etag );

		return $response;
	}

	/**
	 * The requested locale if this site has it installed, else the current one.
	 *
	 * @param string $requested Requested locale.
	 */
	private function locale( string $requested ): string {
		if ( '' !== $requested && ( 'en_US' === $requested || in_array( $requested, get_available_languages(), true ) ) ) {
			return $requested;
		}

		return determine_locale();
	}

	/**
	 * JSON shapes: action configs, flows and nodes are always objects.
	 *
	 * @param array<string, mixed> $data Config.
	 * @return array<string, mixed>
	 */
	private static function shape( array $data ): array {
		if ( isset( $data['actions'] ) && is_array( $data['actions'] ) ) {
			foreach ( $data['actions'] as $index => $action ) {
				if ( is_array( $action ) ) {
					$data['actions'][ $index ]['config'] = (object) ( $action['config'] ?? array() );
				}
			}
		}

		if ( isset( $data['flows'] ) && is_array( $data['flows'] ) ) {
			$data['flows'] = Schema::to_json_shape( $data['flows'], (array) Schema::section( 'flows' ) );
		}
		if ( isset( $data['forms'] ) && array() === $data['forms'] ) {
			$data['forms'] = new \stdClass();
		}

		return $data;
	}

	/**
	 * Whether an If-None-Match header matches the ETag.
	 *
	 * @param string $header If-None-Match value.
	 * @param string $etag   Current ETag.
	 */
	private static function matches( string $header, string $etag ): bool {
		foreach ( explode( ',', $header ) as $candidate ) {
			// Ignore weak prefixes and suffixes servers add when compressing (e.g. "abc-gzip").
			$candidate = trim( preg_replace( '/^W\//', '', trim( $candidate ) ) ?? '', '"' );
			$candidate = preg_replace( '/-(gzip|br|zstd)$/', '', $candidate ) ?? '';
			if ( '*' === $candidate || trim( $etag, '"' ) === $candidate ) {
				return true;
			}
		}

		return false;
	}
}
