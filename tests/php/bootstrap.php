<?php
/**
 * PHPUnit bootstrap: loads the WordPress test suite and the plugin.
 *
 * @package DevRivo\Chatbot
 */

declare( strict_types=1 );

$devrivo_chatbot_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $devrivo_chatbot_tests_dir ) {
	$devrivo_chatbot_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( $devrivo_chatbot_tests_dir . '/includes/functions.php' ) ) {
	fwrite( STDERR, "WordPress test suite not found in {$devrivo_chatbot_tests_dir}. Run tests with: npm run test:php\n" );
	exit( 1 );
}

define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__, 2 ) . '/vendor/yoast/phpunit-polyfills' );

require_once $devrivo_chatbot_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		require dirname( __DIR__, 2 ) . '/devrivo-chatbot.php';
	}
);

require $devrivo_chatbot_tests_dir . '/includes/bootstrap.php';
