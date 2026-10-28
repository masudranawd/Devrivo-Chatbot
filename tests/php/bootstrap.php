<?php
/**
 * PHPUnit bootstrap: loads the WordPress test suite and the plugin.
 *
 * @package DevRivo\Chatbot
 */

declare( strict_types=1 );

$devrivo_chatbot_tests_dir = getenv( 'WP_TESTS_DIR' );

// Default location used by bin/install-wp-tests.php.
if ( ! $devrivo_chatbot_tests_dir ) {
	$devrivo_chatbot_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/devrivo-chatbot-wp-tests/tests-lib';
}

if ( ! file_exists( $devrivo_chatbot_tests_dir . '/includes/functions.php' ) ) {
	fwrite( STDERR, "WordPress test suite not found in {$devrivo_chatbot_tests_dir}. Install it first: composer test:install\n" );
	exit( 1 );
}

// Constant name is required by the WordPress test suite.
define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__, 2 ) . '/vendor/yoast/phpunit-polyfills' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound

require_once $devrivo_chatbot_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		require dirname( __DIR__, 2 ) . '/devrivo-chatbot.php';
	}
);

require $devrivo_chatbot_tests_dir . '/includes/bootstrap.php';
