<?php
/**
 * This file contains the definition of the DeepSeekTextGenerationModel class.
 *
 * @package    Sajjad67\AiProviderForDeepSeek
 * @subpackage Sajjad67\AiProviderForDeepSeek/src
 * @author     Sajjad Hossain Sagor <sagorh672@gmail.com>
 */

declare( strict_types=1 );

namespace Sajjad67\AiProviderForDeepSeek\Models;

use Sajjad67\AiProviderForDeepSeek\Provider\DeepSeekProvider;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\OpenAiCompatibleImplementation\AbstractOpenAiCompatibleTextGenerationModel;

/**
 * Class for an DeepSeek text generation model using the OpenAI-compatible chat completions API.
 *
 * @since 1.0.0
 */
class DeepSeekTextGenerationModel extends AbstractOpenAiCompatibleTextGenerationModel {
	/**
	 * {@inheritDoc}
	 *
	 * @since  1.0.0
	 * @param  HttpMethodEnum $method  The HTTP method to use for the request.
	 * @param  string         $path    The API endpoint path (e.g., 'v1/models').
	 * @param  array          $headers Optional. Array of HTTP headers. Default empty array.
	 * @param  mixed          $data    Optional. The data to be sent in the request body. Default null.
	 * @return Request                 The constructed Request object.
	 */
	protected function createRequest( HttpMethodEnum $method, string $path, array $headers = array(), $data = null ): Request {
		// DeepSeek supports OpenAI-compatible endpoints at /v1/.
		return new Request(
			$method,
			DeepSeekProvider::url( $path ),
			$headers,
			$data,
			$this->getRequestOptions()
		);
	}

	/**
	 * Prepares the API request parameters and re-attaches `reasoning_content` to
	 * prior assistant turns so DeepSeek's thinking-mode tool-call contract is met.
	 *
	 * DeepSeek's thinking mode (the default for `deepseek-v4-flash` and
	 * `deepseek-v4-pro`, and the legacy `deepseek-reasoner` alias) emits a
	 * top-level `reasoning_content` string on each assistant message. When that
	 * assistant turn invoked a tool, every subsequent request in the same
	 * conversation MUST include the original `reasoning_content` verbatim on the
	 * assistant message, otherwise the API returns:
	 *
	 *     400 - The `reasoning_content` in the thinking mode must be passed
	 *           back to the API.
	 *
	 * See https://api-docs.deepseek.com/guides/thinking_mode#tool-calls.
	 *
	 * The shared OpenAI-compatible base class deliberately drops thought-channel
	 * parts from outgoing messages because the plain OpenAI Chat Completions
	 * spec does not accept them. DeepSeek requires the opposite behaviour, so
	 * we override here: keep the base class's content/tool_calls shape and add
	 * the `reasoning_content` sibling field on every assistant entry.
	 *
	 * @since 1.0.1
	 *
	 * @param  list<Message>       $prompt The prompt to generate text for.
	 * @return array<string,mixed>         The parameters for the API request.
	 */
	protected function prepareGenerateTextParams( array $prompt ): array {
		$params = parent::prepareGenerateTextParams( $prompt );

		if ( ! isset( $params['messages'] ) || ! is_array( $params['messages'] ) ) {
			return $params;
		}

		// Walk the input prompt and collect the concatenated thought-channel
		// text for every model-role Message that will be emitted as a wire
		// `assistant` entry. Function-response-only model messages are excluded
		// because the base class transforms them into a `tool` entry, not an
		// `assistant` one (see prepareMessagesParam in the base class).
		$thoughts = array();
		foreach ( $prompt as $message ) {
			if ( ! $message instanceof Message ) {
				continue;
			}
			if ( $message->getRole() !== MessageRoleEnum::model() ) {
				continue;
			}

			$parts = $message->getParts();
			if ( count( $parts ) === 1 && $parts[0]->getType()->isFunctionResponse() ) {
				continue;
			}

			$thought = '';
			foreach ( $parts as $part ) {
				if ( $part->getType()->isText() && $part->getChannel()->isThought() ) {
					$thought .= $part->getText();
				}
			}
			$thoughts[] = $thought;
		}

		// Attach in order: the i-th collected thought belongs to the i-th wire
		// `assistant` entry. The two lists are guaranteed to be the same length
		// when the base class behaves as documented; we still guard against
		// mismatch so a future SDK change cannot crash the request.
		$idx = 0;
		foreach ( $params['messages'] as $wire_index => $entry ) {
			if ( ! is_array( $entry ) || ! isset( $entry['role'] ) || 'assistant' !== $entry['role'] ) {
				continue;
			}
			if ( ! isset( $thoughts[ $idx ] ) ) {
				break;
			}

			$thought = $thoughts[ $idx ];
			++$idx;

			if ( '' === $thought ) {
				continue;
			}

			$params['messages'][ $wire_index ]['reasoning_content'] = $thought;
		}

		return $params;
	}
}
