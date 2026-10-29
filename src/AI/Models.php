<?php
/**
 * AI provider model lists.
 *
 * @package DevRivo\Chatbot
 */

declare( strict_types=1 );

namespace DevRivo\Chatbot\AI;

use WordPress\AiClient\AiClient;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Text generation models a provider offers, from the AI Client registry
 * (the provider class's model metadata directory, as used in the spike).
 *
 * The list is cached for 12 hours. Loading it may call the provider's API,
 * so public requests only read the cache (see is_valid()).
 */
final class Models {

	/**
	 * Cache lifetime in seconds.
	 */
	public const TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * Models of a provider that can generate text.
	 *
	 * @param string $provider Provider ID.
	 * @return list<array{id: string, name: string}>|WP_Error
	 */
	public static function list( string $provider ): array|WP_Error {
		$cached = self::cached( $provider );
		if ( null !== $cached ) {
			return $cached;
		}

		if ( '' === $provider || ! class_exists( AiClient::class ) ) {
			return new WP_Error( 'devrivo_chatbot_models_unavailable', __( 'The WordPress AI Client is not available.', 'devrivo-chatbot' ), array( 'status' => 503 ) );
		}

		try {
			$registry = AiClient::defaultRegistry();
			if ( ! $registry->hasProvider( $provider ) ) {
				return new WP_Error( 'devrivo_chatbot_provider_not_found', __( 'This AI provider is not installed.', 'devrivo-chatbot' ), array( 'status' => 404 ) );
			}

			$class  = $registry->getProviderClassName( $provider );
			$models = array();
			foreach ( $class::modelMetadataDirectory()->listModelMetadata() as $metadata ) {
				foreach ( $metadata->getSupportedCapabilities() as $capability ) {
					if ( $capability->isTextGeneration() ) {
						$models[] = array(
							'id'   => $metadata->getId(),
							'name' => $metadata->getName(),
						);
						break;
					}
				}
			}
		} catch ( \Throwable $e ) {
			// Provider messages may contain details we never pass on.
			return new WP_Error( 'devrivo_chatbot_models_unavailable', __( 'The model list could not be loaded.', 'devrivo-chatbot' ), array( 'status' => 503 ) );
		}

		set_transient( self::key( $provider ), $models, self::TTL );

		return $models;
	}

	/**
	 * Cached list, or null when it isn't cached.
	 *
	 * @param string $provider Provider ID.
	 * @return list<array{id: string, name: string}>|null
	 */
	public static function cached( string $provider ): ?array {
		$cached = '' === $provider ? false : get_transient( self::key( $provider ) );

		return is_array( $cached ) ? array_values( $cached ) : null;
	}

	/**
	 * Whether the model is in the provider's list.
	 *
	 * @param string $provider Provider ID.
	 * @param string $model    Model ID.
	 * @param bool   $remote   Load the list when it isn't cached. Without it, an
	 *                         uncached list counts as valid (it was checked on save).
	 */
	public static function is_valid( string $provider, string $model, bool $remote = true ): bool {
		if ( '' === $provider || '' === $model ) {
			return false;
		}

		$models = $remote ? self::list( $provider ) : self::cached( $provider );
		if ( null === $models ) {
			return true;
		}

		return ! is_wp_error( $models ) && in_array( $model, array_column( $models, 'id' ), true );
	}

	/**
	 * Clears the cached list.
	 *
	 * @param string $provider Provider ID.
	 */
	public static function flush( string $provider ): void {
		delete_transient( self::key( $provider ) );
	}

	/**
	 * Transient name.
	 *
	 * @param string $provider Provider ID.
	 */
	private static function key( string $provider ): string {
		return 'devrivo_chatbot_models_' . md5( $provider );
	}
}
