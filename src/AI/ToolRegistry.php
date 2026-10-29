<?php
/**
 * Tools the AI can call.
 *
 * @package DevRivo\Chatbot
 */

declare( strict_types=1 );

namespace DevRivo\Chatbot\AI;

use DateTimeImmutable;
use DateTimeZone;
use DevRivo\Chatbot\Core\Settings\Schema;
use DevRivo\Chatbot\Knowledge\Knowledge;

defined( 'ABSPATH' ) || exit;

/**
 * Built-in tools: search_site, get_opening_hours and suggest_action. Tools
 * only read public data. Sources found by search_site and actions offered by
 * suggest_action are collected for the response.
 *
 * One instance per chat request.
 */
final class ToolRegistry {

	/**
	 * Most actions the AI may suggest in one reply.
	 */
	public const MAX_ACTIONS = 3;

	/**
	 * Tools after filtering, keyed by name.
	 *
	 * @var array<string, array{name: string, description: string, parameters: array<string, mixed>, callback: callable}>|null
	 */
	private ?array $tools = null;

	/**
	 * Pages found by search_site.
	 *
	 * @var list<array{title: string, url: string}>
	 */
	private array $sources = array();

	/**
	 * Action IDs offered by suggest_action.
	 *
	 * @var list<string>
	 */
	private array $actions = array();

	/**
	 * Constructor.
	 *
	 * @param Knowledge                        $knowledge      Knowledge index.
	 * @param array<string, mixed>             $hours          Opening hours settings (behaviour.hours).
	 * @param array<int, array<string, mixed>> $public_actions Public quick actions (WidgetConfig::actions()).
	 * @param array<string, mixed>             $context        Request context (passed to the filter).
	 */
	public function __construct( private Knowledge $knowledge, private array $hours, private array $public_actions, private array $context = array() ) {}

	/**
	 * Declarations for the model: name, description, parameters.
	 *
	 * @return list<array{name: string, description: string, parameters: array<string, mixed>}>
	 */
	public function declarations(): array {
		return array_values(
			array_map(
				static fn( array $tool ): array => array(
					'name'        => $tool['name'],
					'description' => $tool['description'],
					'parameters'  => $tool['parameters'],
				),
				$this->tools()
			)
		);
	}

	/**
	 * Runs a tool. Unknown tools and failures return an error result to the model.
	 *
	 * @param string               $name Tool name.
	 * @param array<string, mixed> $args Arguments from the model.
	 * @return array<string, mixed>
	 */
	public function call( string $name, array $args ): array {
		$tool = $this->tools()[ $name ] ?? null;
		if ( null === $tool ) {
			return array( 'error' => 'unknown_tool' );
		}

		try {
			$result = call_user_func( $tool['callback'], $args );
		} catch ( \Throwable $e ) {
			return array( 'error' => 'tool_failed' );
		}

		return is_array( $result ) ? $result : array( 'result' => $result );
	}

	/**
	 * Pages found by search_site in this request.
	 *
	 * @return list<array{title: string, url: string}>
	 */
	public function sources(): array {
		return $this->sources;
	}

	/**
	 * Action IDs offered by suggest_action in this request.
	 *
	 * @return list<string>
	 */
	public function actions(): array {
		return $this->actions;
	}

	/**
	 * Opening hours with "open now" worked out in the business timezone.
	 *
	 * @param array<string, mixed> $hours    Opening hours settings.
	 * @param int|null             $now      Unix time (tests).
	 * @return array{timezone: string, schedule: array<string, list<array{open: string, close: string}>>, open_now: bool|null}
	 */
	public static function opening_hours( array $hours, ?int $now = null ): array {
		$timezone = (string) ( $hours['timezone'] ?? '' );
		try {
			$zone = '' !== $timezone ? new DateTimeZone( $timezone ) : wp_timezone();
		} catch ( \Exception $e ) {
			$zone = wp_timezone();
		}

		$schedule = array();
		$any      = false;
		foreach ( Schema::DAYS as $day ) {
			$schedule[ $day ] = array();
			foreach ( (array) ( $hours['schedule'][ $day ] ?? array() ) as $range ) {
				if ( is_array( $range ) && isset( $range['open'], $range['close'] ) ) {
					$schedule[ $day ][] = array(
						'open'  => (string) $range['open'],
						'close' => (string) $range['close'],
					);
					$any                = true;
				}
			}
		}

		$open_now = null;
		if ( $any ) {
			$time     = ( new DateTimeImmutable( '@' . ( $now ?? time() ) ) )->setTimezone( $zone );
			$day      = strtolower( $time->format( 'D' ) );
			$clock    = $time->format( 'H:i' );
			$open_now = false;
			foreach ( $schedule as $key => $ranges ) {
				foreach ( $key === $day ? $ranges : array() as $range ) {
					if ( $clock >= $range['open'] && $clock < $range['close'] ) {
						$open_now = true;
					}
				}
			}
		}

		return array(
			'timezone' => $zone->getName(),
			'schedule' => $schedule,
			'open_now' => $open_now,
		);
	}

	/**
	 * Tools after the filter, keyed by name.
	 *
	 * @return array<string, array{name: string, description: string, parameters: array<string, mixed>, callback: callable}>
	 */
	private function tools(): array {
		if ( null !== $this->tools ) {
			return $this->tools;
		}

		$action_ids = array_values( array_map( static fn( array $a ): string => (string) $a['id'], $this->public_actions ) );

		$tools = array(
			array(
				'name'        => 'search_site',
				'description' => 'Searches this website\'s published pages, posts and FAQs. Returns titles, URLs and short excerpts.',
				'parameters'  => array(
					'type'       => 'object',
					'properties' => array(
						'query' => array(
							'type'        => 'string',
							'description' => 'Search keywords.',
						),
					),
					'required'   => array( 'query' ),
				),
				'callback'    => array( $this, 'search_site' ),
			),
			array(
				'name'        => 'get_opening_hours',
				'description' => 'Returns the business opening hours for each day (24-hour times in the business timezone) and whether it is open right now (null if no hours are set).',
				// Same shape as in the spike (an object schema with a property).
				'parameters'  => array(
					'type'       => 'object',
					'properties' => array(
						'day' => array(
							'type'        => 'string',
							'description' => 'Day of the week in English, for example Saturday. Optional; all days are returned.',
						),
					),
				),
				'callback'    => fn(): array => self::opening_hours( $this->hours ),
			),
		);

		if ( array() !== $action_ids ) {
			$labels = array();
			foreach ( $this->public_actions as $action ) {
				$labels[] = $action['id'] . ' (' . $action['label'] . ')';
			}
			$tools[] = array(
				'name'        => 'suggest_action',
				'description' => 'Shows the visitor a button for one of the website\'s quick actions, for example a contact or booking form. Use it instead of asking for personal details. Available: ' . implode( ', ', $labels ) . '.',
				'parameters'  => array(
					'type'       => 'object',
					'properties' => array(
						'action_id' => array(
							'type' => 'string',
							'enum' => $action_ids,
						),
					),
					'required'   => array( 'action_id' ),
				),
				'callback'    => array( $this, 'suggest_action' ),
			);
		}

		/**
		 * Filters the tools the AI can call.
		 *
		 * Each tool: name (lowercase letters and underscores), description,
		 * parameters (JSON schema) and callback( array $args ): array. Tools run
		 * for anonymous visitors: only return public information.
		 *
		 * @since 1.0.0
		 *
		 * @param array<int, mixed>    $tools   Tools.
		 * @param array<string, mixed> $context Request context.
		 */
		$filtered = (array) apply_filters( 'devrivo_chatbot_ai_tools', $tools, $this->context );

		$this->tools = array();
		foreach ( $filtered as $tool ) {
			if (
				is_array( $tool )
				&& is_string( $tool['name'] ?? null ) && preg_match( '/^[a-z_]{1,40}$/', $tool['name'] )
				&& is_string( $tool['description'] ?? null )
				&& is_callable( $tool['callback'] ?? null )
			) {
				$this->tools[ $tool['name'] ] = array(
					'name'        => $tool['name'],
					'description' => $tool['description'],
					'parameters'  => is_array( $tool['parameters'] ?? null ) ? $tool['parameters'] : array( 'type' => 'object' ),
					'callback'    => $tool['callback'],
				);
			}
		}

		return $this->tools;
	}

	/**
	 * Tool: search_site.
	 *
	 * @param array<string, mixed> $args { query }.
	 * @return array<string, mixed>
	 */
	public function search_site( array $args ): array {
		$query = mb_substr( trim( (string) ( $args['query'] ?? '' ) ), 0, 100 );
		if ( mb_strlen( $query ) < 2 ) {
			return array( 'error' => 'query_too_short' );
		}

		$results = array();
		foreach ( $this->knowledge->search()->run( $query ) as $result ) {
			$results[] = array(
				'type'    => $result['type'],
				'title'   => $result['title'],
				'url'     => $result['url'],
				'excerpt' => $result['excerpt'],
			);
			if ( '' !== $result['url'] ) {
				$this->sources[] = array(
					'title' => $result['title'],
					'url'   => $result['url'],
				);
			}
		}

		return array( 'results' => $results );
	}

	/**
	 * Tool: suggest_action.
	 *
	 * @param array<string, mixed> $args { action_id }.
	 * @return array<string, mixed>
	 */
	public function suggest_action( array $args ): array {
		$id = (string) ( $args['action_id'] ?? '' );
		foreach ( $this->public_actions as $action ) {
			if ( $action['id'] === $id ) {
				if ( ! in_array( $id, $this->actions, true ) && count( $this->actions ) < self::MAX_ACTIONS ) {
					$this->actions[] = $id;
				}
				return array(
					'shown' => true,
					'label' => $action['label'],
				);
			}
		}

		return array( 'error' => 'unknown_action' );
	}
}
