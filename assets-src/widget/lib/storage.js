/**
 * Remembers, for the current visit, that the visitor closed the quick actions,
 * so they don't auto-open again. Storage can throw (privacy modes, blocked
 * site data), so every access is guarded.
 */

const KEY = 'devrivoChatbotDismissed';

/**
 * sessionStorage, or null when it is unavailable.
 *
 * @return {Storage|null} Storage.
 */
function session() {
	try {
		return window.sessionStorage;
	} catch {
		return null;
	}
}

/**
 * Whether the visitor dismissed the quick actions during this visit.
 *
 * @param {Storage|null} storage Storage (defaults to sessionStorage).
 * @return {boolean} Dismissed.
 */
export function wasDismissed( storage = session() ) {
	try {
		return !! storage && storage.getItem( KEY ) === '1';
	} catch {
		return false;
	}
}

/**
 * Remembers the dismissal for this visit.
 *
 * @param {Storage|null} storage Storage (defaults to sessionStorage).
 */
export function rememberDismissed( storage = session() ) {
	try {
		if ( storage ) {
			storage.setItem( KEY, '1' );
		}
	} catch {
		// Storage is full or blocked: the popover may auto-open again, which is fine.
	}
}
