<?php
/**
 * Chat engine: AI with automatic fallback to Guided answers.
 *
 * @package DevRivo\Chatbot
 */

declare( strict_types=1 );

namespace DevRivo\Chatbot\AI;

use DevRivo\Chatbot\Core\Frontend\WidgetConfig;
use DevRivo\Chatbot\Core\Settings\Settings;
use DevRivo\Chatbot\Knowledge\Knowledge;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Answers one chat message.
 *
 * AI runs only when the admin enabled it with a provider, a model from that
 * provider's list and a monthly token limit, tokens are left this month, the
 * model is still offered and the AI Client supports the prompt. Otherwise, and
 * after any AI error, the Guided answer (site search) is used. Provider error
 * messages never reach visitors; nothing is stored except the token counter.
 */
final class ChatEngine {

	/**
	 * Most tool rounds before a final call without tools.
	 */
	public const MAX_TOOL_ROUNDS = 3;

	/**
	 * Fallback reason codes.
	 */
	public const REASONS = array( 'provider_error', 'rate_limited', 'timeout', 'budget', 'model_unavailable', 'unsupported', 'disabled' );

	/**
	 * Constructor.
	 *
	 * @param Settings          $settings  Settings repository.
	 * @param Knowledge         $knowledge Knowledge index.
	 * @param AiClientInterface $client    AI client.
	 */
	public function __construct( private Settings $settings, private Knowledge $knowledge, private AiClientInterface $client ) {}

	/**
	 * Answers a message.
	 *
	 * @param string                                  $message  Visitor's message.
	 * @param list<array{role: string, text: string}> $history  Earlier turns (browser memory only).
	 * @param string                                  $page_url Current page on this site, or ''.
	 * @return array{mode: string, text: string, sources: list<array{title: string, url: string}>, actions: list<string>}
	 */
	public function respond( string $message, array $history, string $page_url ): array {
		$context = array(
			'page_url' => $page_url,
			'history'  => count( $history ),
		);

		$actions = ( new WidgetConfig( $this->settings ) )->actions( determine_locale() );
		$tools   = new ToolRegistry( $this->knowledge, (array) ( $this->settings->get( 'behaviour' )['hours'] ?? array() ), $actions, $context );

		$reason = $this->unavailable( $tools->declarations(), $context );
		if ( null === $reason ) {
			$answer = $this->ai( $message, $history, $page_url, $tools, array_column( $actions, 'id' ), $context );
			if ( ! is_wp_error( $answer ) ) {
				return $answer;
			}
			$reason = self::reason( $answer );
		}

		if ( '' !== $reason ) {
			/**
			 * Fires when AI mode was meant to answer but the Guided answer was used.
			 *
			 * @since 1.0.0
			 *
			 * @param string               $reason  provider_error, rate_limited, timeout, budget, model_unavailable, unsupported or disabled.
			 * @param array<string, mixed> $context { @type string $page_url Current page. @type int $history Earlier turns. }
			 */
			do_action( 'devrivo_chatbot_ai_fallback', $reason, $context );
		}

		return $this->guided( $message, array_column( $actions, 'id' ) );
	}

	/**
	 * Why AI can't answer now: null when it can, '' when AI isn't turned on
	 * (plain Guided mode, not a fallback), else a fallback reason.
	 *
	 * @param list<array<string, mixed>> $tools   Tool declarations.
	 * @param array<string, mixed>       $context Context.
	 */
	private function unavailable( array $tools, array $context ): ?string {
		$ai       = (array) $this->settings->get( 'ai' );
		$limit    = (int) ( $ai['monthly_token_limit'] ?? 0 );
		$provider = (string) ( $ai['provider'] ?? '' );
		$model    = (string) ( $ai['model'] ?? '' );

		if ( empty( $ai['enabled'] ) || $limit <= 0 ) {
			return '';
		}
		if ( ! Budget::can_spend( $limit ) ) {
			return 'budget';
		}
		if ( ! Models::is_valid( $provider, $model ) ) {
			return 'model_unavailable';
		}
		if ( ! $this->client->is_available( $provider, $model, $tools ) ) {
			return 'unsupported';
		}

		/**
		 * Filters whether AI mode answers this message.
		 *
		 * Runs only when AI is turned on, configured and within budget.
		 * Returning false uses the Guided answer (fallback reason "disabled").
		 *
		 * @since 1.0.0
		 *
		 * @param bool                 $enabled Whether AI answers.
		 * @param array<string, mixed> $context { @type string $page_url Current page. @type int $history Earlier turns. }
		 */
		return apply_filters( 'devrivo_chatbot_ai_enabled', true, $context ) ? null : 'disabled';
	}

	/**
	 * AI answer with the tool loop.
	 *
	 * @param string                                  $message    Message.
	 * @param list<array{role: string, text: string}> $history    History.
	 * @param string                                  $page_url   Current page.
	 * @param ToolRegistry                            $tools      Tools.
	 * @param string[]                                $action_ids Public action IDs.
	 * @param array<string, mixed>                    $context    Context.
	 * @return array{mode: string, text: string, sources: list<array{title: string, url: string}>, actions: list<string>}|WP_Error
	 */
	private function ai( string $message, array $history, string $page_url, ToolRegistry $tools, array $action_ids, array $context ): array|WP_Error {
		$ai     = (array) $this->settings->get( 'ai' );
		$model  = (string) $ai['model'];
		$prompt = ( new PromptBuilder( $this->settings, $this->knowledge ) )->build( $message, $page_url );

		$request = array(
			'system'     => $prompt['prompt'],
			'history'    => $history,
			'message'    => $message,
			'tool_steps' => array(),
			'tools'      => $tools->declarations(),
			'provider'   => (string) $ai['provider'],
			'model'      => $model,
			'max_tokens' => WpAiClientAdapter::MAX_TOKENS,
			'timeout'    => WpAiClientAdapter::TIMEOUT,
		);

		$tokens = 0;
		$text   = '';
		for ( $round = 0; $round <= self::MAX_TOOL_ROUNDS; $round++ ) {
			// After MAX_TOOL_ROUNDS tool rounds, one last call without tools.
			if ( self::MAX_TOOL_ROUNDS === $round ) {
				$request['tools'] = array();
			}

			$result = $this->client->generate( $request );
			if ( is_wp_error( $result ) ) {
				self::spend( $tokens );
				return $result;
			}

			$tokens += max( 0, (int) $result['usage']['total'] );

			// Core may switch to another model; we never let it do so silently.
			if ( $result['model'] !== $model ) {
				self::spend( $tokens );
				return new WP_Error( 'devrivo_chatbot_model_mismatch', 'Another model answered.' );
			}

			if ( array() === $result['tool_calls'] || array() === $request['tools'] ) {
				$text = $result['text'];
				break;
			}

			$results = array();
			foreach ( $result['tool_calls'] as $call ) {
				$results[] = array(
					'id'       => $call['id'],
					'name'     => $call['name'],
					'response' => $tools->call( $call['name'], $call['args'] ),
				);
			}
			$request['tool_steps'][] = array(
				'calls'   => $result['tool_calls'],
				'results' => $results,
				'message' => $result['message'] ?? null,
			);
		}

		self::spend( $tokens );

		if ( '' === trim( $text ) ) {
			return new WP_Error( 'devrivo_chatbot_empty_reply', 'The model returned no text.' );
		}

		/**
		 * Filters an AI answer before the output checks.
		 *
		 * The text is then stripped of HTML and of links to other sites, and
		 * sources and actions are limited to this request's results.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, mixed> $response { @type string $text, @type array $sources, @type string[] $actions }
		 * @param array<string, mixed> $context  { @type string $page_url Current page. @type int $history Earlier turns. }
		 */
		$response = (array) apply_filters(
			'devrivo_chatbot_ai_response',
			array(
				'text'    => $text,
				'sources' => array_merge( $tools->sources(), $prompt['sources'] ),
				'actions' => $tools->actions(),
			),
			$context
		);

		$allowed_sources = array_column( array_merge( $tools->sources(), $prompt['sources'] ), 'url' );
		$sources         = array_values(
			array_filter(
				(array) ( $response['sources'] ?? array() ),
				static fn( $source ): bool => is_array( $source ) && in_array( (string) ( $source['url'] ?? '' ), $allowed_sources, true )
			)
		);

		$clean = OutputCheck::text( (string) ( $response['text'] ?? '' ) );
		if ( '' === $clean ) {
			return new WP_Error( 'devrivo_chatbot_empty_reply', 'The model returned no text.' );
		}

		return array(
			'mode'    => 'ai',
			'text'    => $clean,
			'sources' => OutputCheck::sources( $sources ),
			'actions' => OutputCheck::actions( (array) ( $response['actions'] ?? array() ), $action_ids ),
		);
	}

	/**
	 * Guided answer from the site search (as in Step 6).
	 *
	 * @param string   $message    Message.
	 * @param string[] $action_ids Public action IDs.
	 * @return array{mode: string, text: string, sources: list<array{title: string, url: string}>, actions: list<string>}
	 */
	public function guided( string $message, array $action_ids ): array {
		$results = $this->knowledge->search()->run( mb_substr( $message, 0, 100 ) );

		if ( array() === $results ) {
			$fallback = (string) ( $this->settings->get( 'behaviour' )['fallback_action'] ?? '' );

			return array(
				'mode'    => 'guided',
				'text'    => __( 'I couldn’t find that on our website. Would you like the team to get back to you about it?', 'devrivo-chatbot' ),
				'sources' => array(),
				'actions' => in_array( $fallback, $action_ids, true ) ? array( $fallback ) : array(),
			);
		}

		$pages = array_values( array_filter( $results, static fn( array $r ): bool => 'page' === $r['type'] && '' !== $r['url'] ) );

		return array(
			'mode'    => 'guided',
			'text'    => 'faq' === $results[0]['type'] ? $results[0]['excerpt'] : __( 'Here is what I found on our website:', 'devrivo-chatbot' ),
			'sources' => OutputCheck::sources( $pages ),
			'actions' => array(),
		);
	}

	/**
	 * Fallback reason for an AI error (error messages are never passed on).
	 *
	 * @param WP_Error $error Error.
	 */
	public static function reason( WP_Error $error ): string {
		$data   = $error->get_error_data();
		$status = is_array( $data ) ? (int) ( $data['status'] ?? 0 ) : 0;

		return match ( $error->get_error_code() ) {
			'prompt_client_error'           => 429 === $status ? 'rate_limited' : 'provider_error',
			'prompt_network_error'          => 'timeout',
			'prompt_prevented'              => 'disabled',
			'prompt_invalid_argument',
			'devrivo_chatbot_model_mismatch' => 'model_unavailable',
			'devrivo_chatbot_ai_unavailable' => 'unsupported',
			default                         => 'provider_error',
		};
	}

	/**
	 * Records tokens spent by this request (only numbers are stored).
	 *
	 * @param int $tokens Tokens.
	 */
	private static function spend( int $tokens ): void {
		Budget::record( $tokens );
	}
}
