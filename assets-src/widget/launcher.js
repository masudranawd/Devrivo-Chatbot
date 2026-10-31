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
 *
 * Typography is fixed in px, so the theme's root font size never changes it
 * (no rem). Every size, weight, line height and letter spacing is a token;
 * --dc-scale (Text size setting) scales the sizes, never below 12px.
 */
export const baseCss = `
:host{all:initial;font-family:inherit;font-size:15px;line-height:1.5}
.dc{
	--dc-primary:#1e3b36;--dc-on-primary:#fff;--dc-radius:22px;
	--dc-surface:#f3f5f4;--dc-card:#fff;--dc-ink:#17201e;--dc-ink-soft:#56635f;
	--dc-line:#dce3e0;--dc-focus:#4f7f71;--dc-danger:#b4412f;--dc-overlay:rgba(16,28,25,.55);
	--dc-gap:24px;
	--dc-scale:1;
	--dc-font-system:ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,"Helvetica Neue",Arial,"Noto Sans",sans-serif,"Apple Color Emoji","Segoe UI Emoji","Segoe UI Symbol","Noto Color Emoji";
	--dc-fs-greeting:max(12px,calc(24px * var(--dc-scale)));--dc-fw-greeting:700;--dc-lh-greeting:1.2;--dc-ls-greeting:-0.01em;
	--dc-fs-title:max(12px,calc(17px * var(--dc-scale)));--dc-fw-title:600;--dc-lh-title:1.3;--dc-ls-title:0;
	--dc-fs-body:max(12px,calc(15px * var(--dc-scale)));--dc-fw-body:400;--dc-lh-body:1.5;--dc-ls-body:0;
	--dc-fs-button:max(12px,calc(14px * var(--dc-scale)));--dc-fw-button:500;--dc-lh-button:1.35;--dc-ls-button:0;
	--dc-fs-label:max(12px,calc(13px * var(--dc-scale)));--dc-fw-label:400;--dc-lh-label:1.45;--dc-ls-label:0;
	--dc-fs-meta:max(12px,calc(12px * var(--dc-scale)));--dc-fw-meta:500;--dc-lh-meta:1.4;--dc-ls-meta:0;
	font-family:var(--dc-font-system);
	font-size:var(--dc-fs-body);font-weight:var(--dc-fw-body);line-height:var(--dc-lh-body);letter-spacing:var(--dc-ls-body);
	color:var(--dc-ink);font-style:normal;text-transform:none;text-align:start;
	-webkit-font-smoothing:antialiased;-moz-osx-font-smoothing:grayscale;text-size-adjust:100%;-webkit-text-size-adjust:100%;
}
.dc[data-size="small"]{--dc-scale:.93}
.dc[data-size="large"]{--dc-scale:1.13}
.dc[data-font="figtree"]{font-family:"DevRivo Figtree",var(--dc-font-system)}
.dc[data-font="inherit"]{font-family:inherit}
.dc *,.dc *::before,.dc *::after{box-sizing:border-box}
.dc [hidden]{display:none!important}
/* :where() keeps these resets below every component rule. */
.dc :where(button,input,select,textarea){font:inherit;letter-spacing:inherit}
.dc :where(button){cursor:pointer;margin:0}
.dc :where(h2,h3){margin:0;font-size:var(--dc-fs-title);font-weight:var(--dc-fw-title);line-height:var(--dc-lh-title);letter-spacing:var(--dc-ls-title)}
.dc :focus-visible{outline:3px solid var(--dc-focus);outline-offset:2px}
.dc-launcher{
	position:fixed;right:var(--dc-gap);bottom:calc(var(--dc-gap) + env(safe-area-inset-bottom,0px));z-index:99999;
	display:flex;align-items:center;gap:12px;max-width:calc(100vw - 2 * var(--dc-gap));
	padding-block:6px;padding-inline:22px 6px;border:0;border-radius:999px;
	background:var(--dc-primary);color:var(--dc-on-primary);font-size:var(--dc-fs-button);font-weight:var(--dc-fw-title);line-height:var(--dc-lh-button);letter-spacing:var(--dc-ls-button);
	box-shadow:0 10px 30px rgba(20,40,35,.28);transition:transform .2s ease,opacity .2s ease;
}
.dc[data-position="left"] .dc-launcher{right:auto;left:var(--dc-gap)}
.dc-launcher:hover{transform:translateY(-2px)}
.dc-launcher-label{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.dc-avatar{
	position:relative;flex:none;width:46px;height:46px;border-radius:50%;
	background:radial-gradient(circle at 32% 28%,#fff 0 12%,transparent 13%),radial-gradient(circle at 35% 30%,#fff 0%,#f2eef4 30%,#dde8ec 55%,#c9d6da 75%,#afc0c4 100%);
	box-shadow:inset -6px -8px 14px rgba(110,140,150,.35),inset 4px 4px 10px rgba(255,255,255,.7),0 0 0 3px rgba(255,255,255,.9);
}
.dc-avatar img{display:block;width:100%;height:100%;border-radius:50%;object-fit:cover}
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
