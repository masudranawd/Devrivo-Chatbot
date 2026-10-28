<?php
/**
 * Uninstall entry point.
 *
 * @package DevRivo\Chatbot
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/src/autoload.php';

\DevRivo\Chatbot\Core\Uninstaller::uninstall();
