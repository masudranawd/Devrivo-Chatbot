<?php
/**
 * AI client contract.
 *
 * @package DevRivo\Chatbot
 */

declare( strict_types=1 );

namespace DevRivo\Chatbot\AI;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * One text generation call, in plain arrays, so the chat engine never
 * touches AI Client objects and tests can use a fake client.
 *
 * Request:
 * - system:     system instruction.
 * - history:    earlier turns: list of { role: user|assistant, text }.
 * - message:    the visitor's message.
 * - tool_steps: tool rounds of this request, in order: list of { calls, results, message }
 *               (calls as returned below; results: list of { id, name, response };
 *               message: the opaque "message" value returned with the calls, or null).
 * - tools:      list of { name, description, parameters (JSON schema) }; empty for none.
 * - provider, model, max_tokens, timeout (seconds).
 *
 * Result:
 * - text:       reply text ('' when the model only called tools).
 * - tool_calls: list of { id, name, args }.
 * - usage:      { prompt, completion, total } tokens.
 * - provider, model: what actually answered.
 * - message:    opaque value to pass back in tool_steps (keeps provider data such as
 *               Gemini thought signatures intact); may be null.
 */
interface AiClientInterface {

	/**
	 * Generates a reply. Never throws.
	 *
	 * @param array<string, mixed> $request Request.
	 * @return array{text: string, tool_calls: list<array{id: string, name: string, args: array<string, mixed>}>, usage: array{prompt: int, completion: int, total: int}, provider: string, model: string, message: mixed}|WP_Error
	 */
	public function generate( array $request ): array|WP_Error;

	/**
	 * Whether the provider and model can run a prompt with these tools. Makes
	 * no generation call.
	 *
	 * @param string                           $provider Provider ID.
	 * @param string                           $model    Model ID.
	 * @param array<int, array<string, mixed>> $tools    Tool declarations.
	 */
	public function is_available( string $provider, string $model, array $tools = array() ): bool;
}
