/**
 * Launcher pill and the base styles both bundles share. The loader draws it
 * first; the full widget draws the same markup when it takes over.
 */
import { h } from './lib/dom';
import { textOn } from './lib/color';

/**
 * Base styles: host reset, theme tokens and the launcher.
 *
 * Position is physical (left/right) because admins pick a screen corner;
 * everything inside uses logical properties so RTL text mirrors correctly.
 */
export const baseCss = `
:host{all:initial;font-family:inherit}
.dc{
	--dc-primary:#1e3b36;--dc-on-primary:#fff;--dc-radius:22px;
	--dc-surface:#f3f5f4;--dc-card:#fff;--dc-ink:#17201e;--dc-ink-soft:#56635f;
	--dc-line:#dce3e0;--dc-focus:#4f7f71;--dc-danger:#b4412f;--dc-overlay:rgba(16,28,25,.55);
	--dc-gap:24px;
	font-family:ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif;
	font-size:16px;line-height:1.5;color:var(--dc-ink);-webkit-font-smoothing:antialiased;
}
.dc[data-font="inherit"]{font-family:inherit}
.dc *,.dc *::before,.dc *::after{box-sizing:border-box}
.dc [hidden]{display:none!important}
.dc button{font:inherit;cursor:pointer;margin:0}
.dc :focus-visible{outline:3px solid var(--dc-focus);outline-offset:2px}
.dc-launcher{
	position:fixed;right:var(--dc-gap);bottom:calc(var(--dc-gap) + env(safe-area-inset-bottom,0px));z-index:99999;
	display:flex;align-items:center;gap:12px;max-width:calc(100vw - 2 * var(--dc-gap));
	padding-block:6px;padding-inline:22px 6px;border:0;border-radius:999px;
	background:var(--dc-primary);color:var(--dc-on-primary);font-size:1em;font-weight:600;line-height:1.3;
	box-shadow:0 10px 30px rgba(20,40,35,.28);transition:transform .2s ease,opacity .2s ease;
}
.dc[data-position="left"] .dc-launcher{right:auto;left:var(--dc-gap)}
.dc-launcher:hover{transform:translateY(-2px)}
.dc-launcher-label{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.dc-avatar{
	position:relative;flex:none;width:46px;height:46px;border-radius:50%;overflow:hidden;
	background:radial-gradient(circle at 32% 28%,#fff 0 12%,transparent 13%),radial-gradient(circle at 35% 30%,#fff 0%,#f2eef4 30%,#dde8ec 55%,#c9d6da 75%,#afc0c4 100%);
	box-shadow:inset -6px -8px 14px rgba(110,140,150,.35),inset 4px 4px 10px rgba(255,255,255,.7),0 0 0 3px rgba(255,255,255,.9);
}
.dc-avatar img{display:block;width:100%;height:100%;object-fit:cover}
@media (max-width:520px){.dc{--dc-gap:16px}}
@media (prefers-reduced-motion:reduce){.dc *{transition:none!important;animation:none!important}}
`;

/**
 * Applies theme values to the widget root.
 *
 * @param {HTMLElement} root     Root element (.dc).
 * @param {Object}      settings { position, primaryColor, radius? }.
 */
export function applyTheme( root, settings ) {
	root.dataset.position = settings.position;
	root.style.setProperty( '--dc-primary', settings.primaryColor );
	root.style.setProperty(
		'--dc-on-primary',
		textOn( settings.primaryColor )
	);
	if ( typeof settings.radius === 'number' ) {
		root.style.setProperty( '--dc-radius', `${ settings.radius }px` );
	}
}

/**
 * Avatar circle: the uploaded image, or the default pearl.
 *
 * @param {string} url  Safe image URL or ''.
 * @param {string} size '' (46px), 'sm' or 'xs'.
 * @return {HTMLElement} Avatar.
 */
export function avatar( url = '', size = '' ) {
	return h(
		'span',
		{
			class: `dc-avatar${ size ? ` ${ size }` : '' }`,
			'aria-hidden': 'true',
		},
		url ? h( 'img', { src: url, alt: '' } ) : []
	);
}

/**
 * Launcher button.
 *
 * @param {string} label     Visible label.
 * @param {string} avatarUrl Safe image URL or ''.
 * @return {HTMLButtonElement} Launcher.
 */
export function createLauncher( label, avatarUrl = '' ) {
	return h(
		'button',
		{
			type: 'button',
			class: 'dc-launcher',
			'aria-haspopup': 'menu',
			'aria-expanded': 'false',
		},
		[
			h( 'span', { class: 'dc-launcher-label', text: label } ),
			avatar( avatarUrl ),
		]
	);
}
