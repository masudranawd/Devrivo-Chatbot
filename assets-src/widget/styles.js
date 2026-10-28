/**
 * Full widget styles (inside the Shadow DOM, added after baseCss).
 *
 * Theme values come from CSS custom properties set on .dc (primary colour,
 * radius) and data attributes (data-scheme, data-font, data-position,
 * data-state). Logical properties keep the layout RTL safe.
 */

const dark = `--dc-surface:#161d1b;--dc-card:#1e2725;--dc-ink:#eaf0ee;--dc-ink-soft:#a6b3af;--dc-line:#2e3a37;--dc-focus:#8fc0b1;--dc-danger:#f08a78;--dc-overlay:rgba(0,0,0,.6);`;

export const widgetCss = `
@media (prefers-color-scheme:dark){.dc[data-scheme="auto"]{${ dark }}}
.dc[data-scheme="dark"]{${ dark }}
.dc{--dc-primary-2:color-mix(in srgb,var(--dc-primary) 82%,#fff);--dc-card-radius:min(16px,var(--dc-radius))}

.dc-overlay{position:fixed;inset:0;z-index:99996;background:var(--dc-overlay);opacity:0;visibility:hidden;transition:opacity .25s ease,visibility 0s .25s}
.dc[data-state="pop"] .dc-overlay{opacity:1;visibility:visible;transition-delay:0s}

.dc[data-state="panel"] .dc-launcher{transform:scale(.9);opacity:0;visibility:hidden}

/* Quick-action popover. "peek" = auto-opened, no overlay; "pop" = opened by the visitor. */
.dc-pop{
	position:fixed;right:var(--dc-gap);bottom:calc(var(--dc-gap) + 72px + env(safe-area-inset-bottom,0px));z-index:99997;
	display:grid;justify-items:right;gap:10px;max-width:min(360px,calc(100vw - 2 * var(--dc-gap)));
	visibility:hidden;transition:visibility 0s .22s;
}
.dc[data-position="left"] .dc-pop{right:auto;left:var(--dc-gap);justify-items:left}
.dc:is([data-state="pop"],[data-state="peek"]) .dc-pop{visibility:visible;transition-delay:0s}
.dc-pop > *{opacity:0;transform:translateY(10px) scale(.98);transition:opacity .22s ease,transform .22s ease}
.dc:is([data-state="pop"],[data-state="peek"]) .dc-pop > *{opacity:1;transform:none}
.dc-pop > :nth-child(2){transition-delay:.03s}
.dc-pop > :nth-child(3){transition-delay:.06s}
.dc-pop > :nth-child(4){transition-delay:.09s}
.dc-pop > :nth-child(5){transition-delay:.12s}
.dc-pop > :nth-child(n+6){transition-delay:.15s}
.dc-pop-close{width:44px;height:44px;border-radius:50%;border:0;background:var(--dc-card);color:var(--dc-ink);display:grid;place-items:center;margin-block-end:6px;box-shadow:0 6px 18px rgba(0,0,0,.18)}
.dc-chip{border:0;background:var(--dc-card);color:var(--dc-ink);font-size:.95em;font-weight:500;line-height:1.35;padding:12px 16px;border-radius:14px 14px 4px 14px;box-shadow:0 6px 18px rgba(0,0,0,.14);text-align:start}
.dc[data-position="left"] .dc-chip{border-radius:14px 14px 14px 4px}
.dc-chip:hover{background:var(--dc-surface)}
.dc-urgent{color:var(--dc-danger)}

/* Panel */
.dc-panel{
	position:fixed;right:var(--dc-gap);bottom:calc(var(--dc-gap) + env(safe-area-inset-bottom,0px));z-index:99998;
	width:400px;max-width:calc(100vw - 2 * var(--dc-gap));height:min(680px,calc(100vh - 2 * var(--dc-gap)));
	background:var(--dc-surface);border-radius:var(--dc-radius);box-shadow:0 24px 60px rgba(10,30,25,.32);
	display:flex;flex-direction:column;overflow:hidden;
	opacity:0;transform:translateY(16px) scale(.98);transform-origin:bottom right;visibility:hidden;
	transition:opacity .25s ease,transform .25s ease,visibility 0s .25s;
}
.dc[data-position="left"] .dc-panel{right:auto;left:var(--dc-gap);transform-origin:bottom left}
.dc[data-state="panel"] .dc-panel{opacity:1;transform:none;visibility:visible;transition-delay:0s}
.dc-view{display:none;flex:1;min-height:0;flex-direction:column}
.dc-view.is-active{display:flex}
.dc-icon-btn{flex:none;width:36px;height:36px;border-radius:50%;border:0;background:rgba(255,255,255,.16);color:inherit;display:grid;place-items:center}
.dc-icon-btn:hover{background:rgba(255,255,255,.26)}
.dc-panel :focus-visible{outline-offset:1px}

/* Home */
.dc-home{overflow-y:auto}
.dc-hero{position:relative;padding:22px 22px 70px;color:var(--dc-on-primary);background:linear-gradient(180deg,var(--dc-primary) 0%,var(--dc-primary-2) 70%,var(--dc-surface) 100%)}
.dc-hero-top{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-block-end:28px;min-height:36px}
.dc-brand{font-weight:700;font-size:.95em}
.dc-greet{font-size:1.5em;font-weight:700;line-height:1.2;margin:0;opacity:.85}
.dc-welcome{font-size:1.5em;font-weight:800;line-height:1.2;margin:2px 0 0;letter-spacing:-.01em}
.dc-home-body{padding:0 18px 18px;margin-block-start:-50px;position:relative;display:flex;flex-direction:column;gap:14px}
.dc-ask{display:flex;align-items:center;gap:14px;width:100%;padding:16px;border:0;border-radius:var(--dc-card-radius);background:var(--dc-card);color:var(--dc-ink);text-align:start;box-shadow:0 8px 24px rgba(20,40,35,.1)}
.dc-ask strong{display:block;font-size:1.02em}
.dc-ask span span{font-size:.85em;color:var(--dc-ink-soft)}
.dc-ask svg{margin-inline-start:auto;color:var(--dc-ink-soft)}
.dc-list{margin:0;padding:0;list-style:none;background:var(--dc-card);border-radius:var(--dc-card-radius);overflow:hidden;box-shadow:0 2px 10px rgba(20,40,35,.05)}
.dc-list li + li{border-block-start:1px solid var(--dc-line)}
.dc-row{display:flex;align-items:center;justify-content:space-between;gap:12px;width:100%;padding:15px 16px;border:0;background:none;color:var(--dc-ink);font-size:.95em;text-align:start}
.dc-row:hover{background:var(--dc-surface)}
.dc-row svg{flex:none;color:var(--dc-ink-soft)}
.dc-row.dc-urgent{color:var(--dc-danger)}
.dc-flip:dir(rtl){transform:scaleX(-1)}

/* Tabs */
.dc-tabs{display:flex;justify-content:center;gap:48px;padding:10px 0 12px;background:var(--dc-card);border-block-start:1px solid var(--dc-line)}
.dc-tab{border:0;background:none;color:var(--dc-ink-soft);display:flex;flex-direction:column;align-items:center;gap:3px;font-size:.8em;font-weight:600}
.dc-tab[aria-selected="true"]{color:var(--dc-ink)}

/* Offers */
.dc-view-head{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:20px;background:var(--dc-primary);color:var(--dc-on-primary)}
.dc-view-head h2{margin:0;font-size:1.25em;line-height:1.3}
.dc-offers{flex:1;overflow-y:auto;margin:0;padding:16px;list-style:none;display:flex;flex-direction:column;gap:12px}
.dc-offer{background:var(--dc-card);border-radius:var(--dc-card-radius);padding:18px;border:1px solid var(--dc-line)}
.dc-offer h3{margin:0 0 6px;font-size:1.05em;line-height:1.3}
.dc-offer p{margin:0 0 14px;color:var(--dc-ink-soft);font-size:.9em;white-space:pre-line}
.dc-offer p:last-child{margin-block-end:0}
.dc-offer.is-featured{background:linear-gradient(135deg,var(--dc-primary),var(--dc-primary-2));color:var(--dc-on-primary);border:0}
.dc-offer.is-featured p{color:inherit;opacity:.88}
.dc-cta{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:12px 16px;border-radius:12px;background:var(--dc-card);color:var(--dc-ink);border:1px solid var(--dc-line);font-weight:700;font-size:.92em}
.dc-offer.is-featured .dc-cta{background:var(--dc-card);color:var(--dc-ink);border:0}

/* Chat */
.dc-chat-head{display:flex;align-items:center;gap:12px;padding:14px;background:var(--dc-primary);color:var(--dc-on-primary)}
.dc-chat-title{flex:1;min-width:0;font-weight:700;font-size:.98em;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.dc-avatar.sm{width:36px;height:36px;box-shadow:inset -4px -6px 10px rgba(110,140,150,.35),inset 3px 3px 8px rgba(255,255,255,.7)}
.dc-msgs{flex:1;overflow-y:auto;padding:18px 14px 8px;display:flex;flex-direction:column;gap:10px}
.dc-composer{display:flex;gap:8px;align-items:center;padding:10px 12px 12px;background:var(--dc-card);border-block-start:1px solid var(--dc-line)}
.dc-composer input{flex:1;min-width:0;font:inherit;font-size:.95em;border:1px solid var(--dc-line);background:var(--dc-surface);color:var(--dc-ink);border-radius:999px;padding:11px 16px}
.dc-composer input:focus-visible{outline:2px solid var(--dc-focus);border-color:transparent}
.dc-send{flex:none;width:42px;height:42px;border-radius:50%;border:0;background:var(--dc-primary);color:var(--dc-on-primary);display:grid;place-items:center}

@media (max-width:520px){
	.dc-panel,.dc[data-position="left"] .dc-panel{inset:0;width:100%;max-width:none;height:100%;border-radius:0;padding-block:env(safe-area-inset-top,0px) env(safe-area-inset-bottom,0px)}
}
`;
