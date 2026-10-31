<?php
/**
 * Capability isolation for tests.
 *
 * @package DevRivo\Chatbot
 */

declare( strict_types=1 );

namespace DevRivo\Chatbot\Tests\Support;

use DevRivo\Chatbot\Core\Capabilities;

/**
 * Puts the plugin capability back to its installed state (administrators
 * only) after each test.
 *
 * Roles live in an option, which the test transaction rolls back, but also
 * in the in-memory WP_Roles object, which it does not. A test that removes
 * the capability (e.g. Uninstaller::uninstall()) or grants it to another
 * role would otherwise leak into later tests, depending on test order.
 */
trait RestoresCapabilities {

	/**
	 * Runs before the parent tear_down, so the option writes are rolled back
	 * with everything else while memory keeps the installed state.
	 */
	protected function restore_capabilities(): void {
		Capabilities::remove_all();
		Capabilities::grant_defaults();
	}
}
