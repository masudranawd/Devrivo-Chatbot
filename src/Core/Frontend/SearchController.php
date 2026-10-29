<?php
/**
 * Public knowledge search REST controller.
 *
 * @package DevRivo\Chatbot
 */

declare( strict_types=1 );

namespace DevRivo\Chatbot\Core\Frontend;

use DevRivo\Chatbot\Core\Rest;
use DevRivo\Chatbot\Core\Security\Gate;
use DevRivo\Chatbot\Core\Security\RateLimiter;
use DevRivo\Chatbot\Knowledge\Knowledge;
use DevRivo\Chatbot\Knowledge\Search;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * GET devrivo-chatbot/v1/search?q=…[&types=post,page]
 *
 * Read-only search over public site content. Rate limited per client IP;
 * successful responses may be cached by browsers and proxies for five
 * minutes. Queries are never stored or logged by the plugin.
 */
final class SearchController extends WP_REST_Controller {

	/**
	 * Constructor.
	 *
	 * @param Knowledge $knowledge Knowledge.
	 */
	public function __construct( private Knowledge $knowledge ) {
		$this->namespace = Rest::NAMESPACE;
		$this->rest_base = 'search';
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
					'callback'            => array( $this, 'get_items' ),
					// Public and read-only: it searches content that is already public on the site.
					'permission_callback' => '__return_true',
					'args'                => array(
						'q'     => array(
							'description'       => __( 'Question or keywords.', 'devrivo-chatbot' ),
							'type'              => 'string',
							'required'          => true,
							'validate_callback' => array( self::class, 'validate_query' ),
						),
						'types' => array(
							'description' => __( 'Comma-separated post types to search (no FAQs). Only indexed types are used.', 'devrivo-chatbot' ),
							'type'        => 'string',
							'pattern'     => '^[a-z0-9_,-]{0,420}$',
						),
					),
				),
			)
		);
	}

	/**
	 * Query length check (after trimming).
	 *
	 * @param mixed $value Value.
	 * @return true|WP_Error
	 */
	public static function validate_query( mixed $value ): bool|WP_Error {
		$length = is_string( $value ) ? mb_strlen( trim( $value ) ) : 0;

		if ( $length < Search::MIN_LENGTH || $length > Search::MAX_LENGTH ) {
			return new WP_Error(
				'rest_invalid_param',
				/* translators: 1: Minimum length, 2: Maximum length. */
				sprintf( __( 'The question must be between %1$d and %2$d characters long.', 'devrivo-chatbot' ), Search::MIN_LENGTH, Search::MAX_LENGTH ),
				array( 'status' => 400 )
			);
		}

		return true;
	}

	/**
	 * Searches.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_items( $request ) {
		$limit = Gate::limits( array( 'endpoint' => 'search' ) )['search'];
		$retry = RateLimiter::hit( 'search:ip', RateLimiter::key( RateLimiter::client_ip( $request ) ), $limit['max'], $limit['window'] );

		if ( $retry > 0 ) {
			$response = rest_convert_error_to_response(
				new WP_Error(
					'devrivo_chatbot_rate_limited',
					__( 'Too many questions in a short time. Please wait a moment and try again.', 'devrivo-chatbot' ),
					array(
						'status'      => 429,
						'retry_after' => $retry,
					)
				)
			);
			$response->header( 'Retry-After', (string) $retry );
			$response->header( 'Cache-Control', 'no-store' );
			return $response;
		}

		$results = $this->knowledge->search()->run( trim( (string) $request['q'] ), $this->types( (string) $request['types'] ) );

		$response = new WP_REST_Response( array( 'results' => $results ) );
		$response->header( 'Cache-Control', 'public, max-age=300' );

		return $response;
	}

	/**
	 * Requested post types that are indexed. A request for types none of
	 * which are indexed searches nothing.
	 *
	 * @param string $types Comma-separated types, or ''.
	 * @return string[]
	 */
	private function types( string $types ): array {
		$requested = array_filter( array_map( 'trim', explode( ',', $types ) ) );
		if ( array() === $requested ) {
			return array();
		}

		$types = array_values( array_intersect( $this->knowledge->posts()->post_types(), $requested ) );

		// No valid type: a type that can't match anything.
		return array() === $types ? array( '-' ) : $types;
	}
}
