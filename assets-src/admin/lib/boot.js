/**
 * Boot data printed by AdminPage (window.devrivoChatbotAdmin), with safe
 * defaults so screens never read undefined.
 */

const DEFAULTS = {
	version: '',
	siteName: '',
	homeUrl: '',
	connectorsUrl: '',
	privacyExportUrl: '',
	privacyEraseUrl: '',
	privacySettingsUrl: '',
	privacyPolicyUrl: '',
	postTypes: [],
	timezones: [],
	siteTimezone: '',
	actionTypes: [],
	previewWidgetUrl: '',
	leadsExportUrl: '',
};

/**
 * Boot data.
 *
 * @return {typeof DEFAULTS} Boot data.
 */
export function boot() {
	const raw =
		typeof window !== 'undefined' && window.devrivoChatbotAdmin
			? window.devrivoChatbotAdmin
			: {};
	return { ...DEFAULTS, ...raw };
}
