<?php
/**
 * Installs WordPress core and the WordPress PHPUnit test library, so the
 * plugin's integration tests run without Docker (Laragon, XAMPP, CI).
 *
 * Usage:
 *   php bin/install-wp-tests.php [--wp=7.0] [--db-name=devrivo_chatbot_tests]
 *       [--db-user=root] [--db-pass=] [--db-host=localhost] [--dir=<path>]
 *       [--force] [--skip-db]
 *
 *   --wp       A version (7.0, 7.0.7), "latest" or "trunk". Default: 7.0.
 *   --db-host  host, host:port or localhost:/path/to/socket.
 *   --dir      Install location. Default: <system temp>/devrivo-chatbot-wp-tests
 *              (tests/php/bootstrap.php looks there when WP_TESTS_DIR is not set).
 *
 * WARNING: the test database is wiped on every test run. Never point it at a
 * real site's database. The script refuses databases that contain non-test tables.
 *
 * Requires: PHP 8.1+ with mysqli, and git on PATH.
 *
 * @package DevRivo\Chatbot
 */

declare( strict_types=1 );

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

$devrivo_options = getopt( '', array( 'wp::', 'db-name::', 'db-user::', 'db-pass::', 'db-host::', 'dir::', 'force', 'skip-db' ) );
$devrivo_opt     = static function ( string $key, string $fallback ) use ( $devrivo_options ): string {
	return isset( $devrivo_options[ $key ] ) && is_string( $devrivo_options[ $key ] ) ? $devrivo_options[ $key ] : $fallback;
};

$devrivo_config = array(
	'wp'      => $devrivo_opt( 'wp', '7.0' ),
	'db_name' => $devrivo_opt( 'db-name', 'devrivo_chatbot_tests' ),
	'db_user' => $devrivo_opt( 'db-user', 'root' ),
	'db_pass' => $devrivo_opt( 'db-pass', '' ),
	'db_host' => $devrivo_opt( 'db-host', 'localhost' ),
	'dir'     => rtrim( $devrivo_opt( 'dir', sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'devrivo-chatbot-wp-tests' ), '/\\' ),
	'force'   => isset( $devrivo_options['force'] ),
	'skip_db' => isset( $devrivo_options['skip-db'] ),
);

/**
 * Prints an error and exits.
 *
 * @param string $message Message.
 */
function devrivo_fail( string $message ): void {
	fwrite( STDERR, "Error: {$message}\n" );
	exit( 1 );
}

/**
 * Prints a progress line.
 *
 * @param string $message Message.
 */
function devrivo_say( string $message ): void {
	fwrite( STDOUT, "{$message}\n" );
}

/**
 * Runs a shell command and fails on a non-zero exit code.
 *
 * @param string $command Command.
 */
function devrivo_run( string $command ): void {
	passthru( $command, $code );
	if ( 0 !== $code ) {
		devrivo_fail( "Command failed ({$code}): {$command}" );
	}
}

/**
 * Deletes a directory tree (handles read-only git files on Windows).
 *
 * @param string $path Path.
 */
function devrivo_rmdir( string $path ): void {
	if ( ! file_exists( $path ) ) {
		return;
	}
	if ( is_file( $path ) || is_link( $path ) ) {
		@chmod( $path, 0666 ); // phpcs:ignore
		unlink( $path );
		return;
	}
	$items = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $items as $item ) {
		$item_path = $item->getPathname();
		@chmod( $item_path, 0777 ); // phpcs:ignore
		$item->isDir() && ! $item->isLink() ? rmdir( $item_path ) : unlink( $item_path );
	}
	rmdir( $path );
}

/**
 * Resolves --wp into git refs for WordPress/WordPress and wordpress-develop.
 *
 * @param string $wp Requested version.
 * @return array{core: string, develop: string, label: string}
 */
function devrivo_resolve_version( string $wp ): array {
	if ( in_array( $wp, array( 'trunk', 'nightly' ), true ) ) {
		return array(
			'core'    => 'master',
			'develop' => 'trunk',
			'label'   => 'trunk',
		);
	}

	if ( 'latest' === $wp ) {
		$json = @file_get_contents( 'https://api.wordpress.org/core/version-check/1.7/' ); // phpcs:ignore
		$data = is_string( $json ) ? json_decode( $json, true ) : null;
		$wp   = is_array( $data ) ? (string) ( $data['offers'][0]['current'] ?? '' ) : '';
		if ( '' === $wp ) {
			// Fallback: newest release tag of WordPress/WordPress.
			exec( 'git ls-remote --tags --refs https://github.com/WordPress/WordPress.git', $lines );
			$tags = array();
			foreach ( $lines as $line ) {
				if ( preg_match( '#refs/tags/(\d+\.\d+(?:\.\d+)?)$#', $line, $match ) ) {
					$tags[] = $match[1];
				}
			}
			usort( $tags, 'version_compare' );
			$wp = (string) end( $tags );
		}
		if ( '' === $wp ) {
			devrivo_fail( 'Could not look up the latest WordPress version.' );
		}
	}

	if ( ! preg_match( '/^\d+\.\d+(\.\d+)?$/', $wp ) ) {
		devrivo_fail( "Invalid --wp value \"{$wp}\". Use e.g. 7.0, 7.0.7, latest or trunk." );
	}

	$parts = explode( '.', $wp );

	return array(
		// WordPress/WordPress tags X.Y.0 releases as "X.Y"; wordpress-develop always uses X.Y.Z.
		'core'    => ( 3 === count( $parts ) && '0' === $parts[2] ) ? $parts[0] . '.' . $parts[1] : $wp,
		'develop' => 2 === count( $parts ) ? $wp . '.0' : $wp,
		'label'   => $wp,
	);
}

/**
 * Creates the test database if needed and refuses real-site databases.
 *
 * @param array<string, mixed> $config Config.
 */
function devrivo_prepare_database( array $config ): void {
	if ( ! extension_loaded( 'mysqli' ) ) {
		devrivo_fail( 'The mysqli PHP extension is required.' );
	}
	if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $config['db_name'] ) ) {
		devrivo_fail( 'Database name may only contain letters, numbers and underscores.' );
	}

	$host   = $config['db_host'];
	$port   = null;
	$socket = null;
	if ( str_contains( $host, ':' ) ) {
		list( $host, $extra ) = explode( ':', $host, 2 );
		if ( ctype_digit( $extra ) ) {
			$port = (int) $extra;
		} else {
			$socket = $extra;
		}
	}

	mysqli_report( MYSQLI_REPORT_OFF );
	$db = @new mysqli( $host, $config['db_user'], $config['db_pass'], '', (int) $port, $socket ); // phpcs:ignore
	if ( $db->connect_errno ) {
		devrivo_fail( 'Cannot connect to MySQL: ' . $db->connect_error . '. Is the database server running?' );
	}

	$name = $config['db_name'];
	if ( ! $db->query( "CREATE DATABASE IF NOT EXISTS `{$name}` CHARACTER SET utf8mb4" ) ) {
		devrivo_fail( 'Could not create database: ' . $db->error );
	}

	$result = $db->query( "SHOW TABLES FROM `{$name}`" );
	if ( $result instanceof mysqli_result ) {
		while ( $row = $result->fetch_row() ) { // phpcs:ignore
			if ( ! str_starts_with( (string) $row[0], 'wptests_' ) ) {
				devrivo_fail( "Database \"{$name}\" contains non-test tables ({$row[0]}). Use a dedicated, empty test database." );
			}
		}
	}

	$db->close();
	devrivo_say( "Database \"{$name}\" ready." );
}

/**
 * Writes wp-tests-config.php for the test library.
 *
 * @param array<string, mixed> $config Config.
 * @param string               $core   Path to WordPress core (trailing slash).
 * @param string               $lib    Path to the test library.
 */
function devrivo_write_tests_config( array $config, string $core, string $lib ): void {
	$export   = static fn( string $value ): string => var_export( $value, true ); // phpcs:ignore
	$contents = "<?php\n"
		. "// Generated by bin/install-wp-tests.php. The database below is wiped on every test run.\n"
		. 'define( \'ABSPATH\', ' . $export( $core ) . " );\n"
		. "define( 'WP_DEFAULT_THEME', 'default' );\n"
		. "define( 'WP_DEBUG', true );\n"
		. 'define( \'DB_NAME\', ' . $export( $config['db_name'] ) . " );\n"
		. 'define( \'DB_USER\', ' . $export( $config['db_user'] ) . " );\n"
		. 'define( \'DB_PASSWORD\', ' . $export( $config['db_pass'] ) . " );\n"
		. 'define( \'DB_HOST\', ' . $export( $config['db_host'] ) . " );\n"
		. "define( 'DB_CHARSET', 'utf8mb4' );\n"
		. "define( 'DB_COLLATE', '' );\n"
		. "\$table_prefix = 'wptests_';\n"
		. "define( 'WP_TESTS_DOMAIN', 'example.org' );\n"
		. "define( 'WP_TESTS_EMAIL', 'admin@example.org' );\n"
		. "define( 'WP_TESTS_TITLE', 'Test Blog' );\n"
		. 'define( \'WP_PHP_BINARY\', ' . $export( PHP_BINARY ) . " );\n"
		. "define( 'WPLANG', '' );\n";

	file_put_contents( $lib . DIRECTORY_SEPARATOR . 'wp-tests-config.php', $contents );
}

// ---------------------------------------------------------------------------

$devrivo_version = devrivo_resolve_version( $devrivo_config['wp'] );
$devrivo_dir     = $devrivo_config['dir'];
$devrivo_core    = $devrivo_dir . DIRECTORY_SEPARATOR . 'wordpress';
$devrivo_lib     = $devrivo_dir . DIRECTORY_SEPARATOR . 'tests-lib';
$devrivo_marker  = $devrivo_dir . DIRECTORY_SEPARATOR . 'version.txt';

devrivo_say( "WordPress {$devrivo_version['label']} test suite -> {$devrivo_dir}" );

$devrivo_installed = is_file( $devrivo_marker ) ? trim( (string) file_get_contents( $devrivo_marker ) ) : '';

if ( $devrivo_config['force'] || $devrivo_installed !== $devrivo_version['label'] || ! is_dir( $devrivo_lib . DIRECTORY_SEPARATOR . 'includes' ) ) {
	devrivo_rmdir( $devrivo_core );
	devrivo_rmdir( $devrivo_lib );
	if ( ! is_dir( $devrivo_dir ) ) {
		mkdir( $devrivo_dir, 0777, true );
	}

	devrivo_say( "Downloading WordPress core ({$devrivo_version['core']})..." );
	devrivo_run(
		'git -c advice.detachedHead=false clone --quiet --depth 1 --branch ' . escapeshellarg( $devrivo_version['core'] )
		. ' https://github.com/WordPress/WordPress.git ' . escapeshellarg( $devrivo_core )
	);
	devrivo_rmdir( $devrivo_core . DIRECTORY_SEPARATOR . '.git' );

	devrivo_say( "Downloading the test library ({$devrivo_version['develop']})..." );
	$devrivo_tmp = $devrivo_dir . DIRECTORY_SEPARATOR . 'develop-tmp';
	devrivo_rmdir( $devrivo_tmp );
	devrivo_run(
		'git -c advice.detachedHead=false clone --quiet --depth 1 --filter=blob:none --sparse --branch ' . escapeshellarg( $devrivo_version['develop'] )
		. ' https://github.com/WordPress/wordpress-develop.git ' . escapeshellarg( $devrivo_tmp )
	);
	devrivo_run( 'git -C ' . escapeshellarg( $devrivo_tmp ) . ' sparse-checkout set tests/phpunit/includes tests/phpunit/data' );

	mkdir( $devrivo_lib, 0777, true );
	foreach ( array( 'includes', 'data' ) as $devrivo_part ) {
		rename(
			$devrivo_tmp . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'phpunit' . DIRECTORY_SEPARATOR . $devrivo_part,
			$devrivo_lib . DIRECTORY_SEPARATOR . $devrivo_part
		);
	}
	devrivo_rmdir( $devrivo_tmp );

	file_put_contents( $devrivo_marker, $devrivo_version['label'] );
} else {
	devrivo_say( 'Already installed. Use --force to reinstall.' );
}

devrivo_write_tests_config( $devrivo_config, $devrivo_core . DIRECTORY_SEPARATOR, $devrivo_lib );

if ( ! $devrivo_config['skip_db'] ) {
	devrivo_prepare_database( $devrivo_config );
}

devrivo_say( 'Done. Run the tests with: composer test' );
