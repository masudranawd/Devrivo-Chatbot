<?php
/**
 * Plugin Name:       DevRivo Chatbot
 * Plugin URI:        https://github.com/masudranawd/Devrivo-Chatbot
 * Description:       A free, customisable AI assistant for any WordPress site. Answers questions from your content, captures leads and guides visitors.
 * Version:           0.9.0
 * Requires at least: 7.0
 * Requires PHP:      8.1
 * Author:            DevRivo
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       devrivo-chatbot
 *
 * @package DevRivo\Chatbot
 */

/*
 * This file must stay parseable on old PHP versions so the version notice can
 * show instead of a fatal error. Keep modern syntax inside src/ only.
 */

defined( 'ABSPATH' ) || exit;

// Another copy of the plugin is already loaded (e.g. a dev copy and the directory copy).
if ( defined( 'DEVRIVO_CHATBOT_VERSION' ) ) {
	return;
}

define( 'DEVRIVO_CHATBOT_VERSION', '0.9.0' );
define( 'DEVRIVO_CHATBOT_SCHEMA_VERSION', 4 );
define( 'DEVRIVO_CHATBOT_MIN_WP', '7.0' );
define( 'DEVRIVO_CHATBOT_MIN_PHP', '8.1' );
define( 'DEVRIVO_CHATBOT_FILE', __FILE__ );
define( 'DEVRIVO_CHATBOT_PATH', plugin_dir_path( __FILE__ ) );
define( 'DEVRIVO_CHATBOT_URL', plugin_dir_url( __FILE__ ) );
define( 'DEVRIVO_CHATBOT_BASENAME', plugin_basename( __FILE__ ) );

if ( version_compare( PHP_VERSION, DEVRIVO_CHATBOT_MIN_PHP, '<' ) ) {
	add_action(
		'admin_notices',
		static function () {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}
			printf(
				'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: 1: Required PHP version. 2: Current PHP version. */
						__( 'DevRivo Chatbot requires PHP %1$s or newer. This site runs PHP %2$s, so the plugin stays inactive until PHP is updated.', 'devrivo-chatbot' ),
						DEVRIVO_CHATBOT_MIN_PHP,
						PHP_VERSION
					)
				)
			);
		}
	);
	return;
}

require_once DEVRIVO_CHATBOT_PATH . 'src/autoload.php';

register_activation_hook( __FILE__, array( \DevRivo\Chatbot\Core\Activator::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \DevRivo\Chatbot\Core\Deactivator::class, 'deactivate' ) );

add_action( 'plugins_loaded', array( \DevRivo\Chatbot\Core\Plugin::class, 'bootstrap' ) );
