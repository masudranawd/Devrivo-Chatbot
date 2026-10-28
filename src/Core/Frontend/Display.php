<?php
/**
 * Display rules.
 *
 * @package DevRivo\Chatbot
 */

declare( strict_types=1 );

namespace DevRivo\Chatbot\Core\Frontend;

use DevRivo\Chatbot\Core\Settings\Schema;
use DevRivo\Chatbot\Core\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Decides whether the widget loads on the current front-end request.
 *
 * Uses only the small autoloaded display option, so it adds no database query.
 * Devices are not checked here: page caches would serve one device's answer to
 * every visitor, so the loader checks the device in the browser instead.
 */
final class Display {

	/**
	 * Memoised result for this request.
	 *
	 * @var bool|null
	 */
	private ?bool $display = null;

	/**
	 * Whether the widget should load on this request.
	 */
	public function should_display(): bool {
		if ( null === $this->display ) {
			$this->display = $this->decide();
		}

		return $this->display;
	}

	/**
	 * The display section (defaults filled in).
	 *
	 * @return array<string, mixed>
	 */
	public static function options(): array {
		$schema = (array) Schema::section( Schema::DISPLAY );
		$stored = get_option( Settings::DISPLAY_OPTION, array() );
		$value  = Schema::with_defaults( is_array( $stored ) ? $stored : null, $schema );

		// Corrupt or hand-edited data falls back to defaults, like Settings does.
		if ( ! is_array( $value ) || is_wp_error( rest_validate_value_from_schema( $value, $schema, Schema::DISPLAY ) ) ) {
			$value = (array) Schema::default_for( $schema );
		}

		return $value;
	}

	/**
	 * Launcher label, or the translated default when it is empty.
	 *
	 * @param string $label Label from the settings.
	 */
	public static function launcher_label( string $label ): string {
		$label = trim( $label );

		return '' !== $label ? $label : __( 'How can we help?', 'devrivo-chatbot' );
	}

	/**
	 * Works out the answer.
	 */
	private function decide(): bool {
		$options = self::options();

		if ( empty( $options['enabled'] ) || ! $this->is_page_request() ) {
			return false;
		}

		$singular  = is_singular();
		$post_id   = $singular ? (int) get_queried_object_id() : 0;
		$post_type = $singular ? (string) get_post_type( $post_id ) : '';
		$rules     = $options['rules'];

		$matches = $singular && (
			in_array( $post_id, array_map( 'intval', $rules['post_ids'] ), true )
			|| in_array( $post_type, $rules['post_types'], true )
		);

		$display = match ( $rules['mode'] ) {
			'include' => $matches,
			'exclude' => ! $matches,
			default   => true,
		};

		/**
		 * Filters whether the widget loads on the current front-end request.
		 *
		 * Runs only when the widget is enabled and the request is a normal page
		 * view (not admin, AJAX, REST, feeds, embeds, robots.txt or trackbacks).
		 * Devices are checked later, in the browser.
		 *
		 * @since 1.0.0
		 *
		 * @param bool                 $display Whether to load the widget, after the display rules.
		 * @param array<string, mixed> $context {
		 *     Request context.
		 *
		 *     @type int    $post_id   Queried post ID on singular views, else 0.
		 *     @type string $post_type Queried post type on singular views, else ''.
		 * }
		 */
		return (bool) apply_filters(
			'devrivo_chatbot_should_display',
			$display,
			array(
				'post_id'   => $post_id,
				'post_type' => $post_type,
			)
		);
	}

	/**
	 * Whether this is a normal front-end page view.
	 */
	private function is_page_request(): bool {
		return ! (
			is_admin()
			|| wp_doing_ajax()
			|| wp_is_serving_rest_request()
			|| wp_is_json_request()
			|| is_feed()
			|| is_embed()
			|| is_robots()
			|| is_trackback()
		);
	}
}
