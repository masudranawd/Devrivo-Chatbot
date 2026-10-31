/**
 * Bundled Figtree font. A shadow root can't load `@font-face` rules declared
 * inside it, so one <style> with the rule goes into the document head. The
 * family name is unique, so it never clashes with a theme's own Figtree.
 */

export const FONT_FAMILY = 'DevRivo Figtree';
export const FONT_STYLE_ID = 'devrivo-chatbot-font';

/**
 * Adds the `@font-face` rule once, only for the "figtree" font setting. The
 * browser downloads the file only when text uses the family.
 *
 * @param {string}   font Font setting: figtree | system | inherit.
 * @param {string}   url  Font URL (already checked to be http(s)), or ''.
 * @param {Document} doc  Document.
 * @return {boolean} Whether a rule was added now.
 */
export function ensureFont( font, url, doc = document ) {
	if (
		font !== 'figtree' ||
		! url ||
		! doc.head ||
		doc.getElementById( FONT_STYLE_ID )
	) {
		return false;
	}

	const style = doc.createElement( 'style' );
	style.id = FONT_STYLE_ID;
	// JSON.stringify quotes and escapes the URL for the CSS string.
	style.textContent = `@font-face{font-family:"${ FONT_FAMILY }";src:url(${ JSON.stringify(
		url
	) }) format("woff2");font-weight:300 900;font-style:normal;font-display:swap}`;
	doc.head.append( style );

	return true;
}
