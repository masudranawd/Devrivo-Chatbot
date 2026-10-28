<?php
/**
 * PSR-4 autoloader for the DevRivo\Chatbot namespace.
 *
 * Composer is used for dev tooling only, so the plugin ships this small loader
 * instead of a vendor/ directory.
 *
 * @package DevRivo\Chatbot
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = 'DevRivo\\Chatbot\\';

		if ( 0 !== strncmp( $class_name, $prefix, strlen( $prefix ) ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( $prefix ) );
		$file     = __DIR__ . '/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);
