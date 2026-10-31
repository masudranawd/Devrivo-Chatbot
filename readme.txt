=== DevRivo Chatbot ===
Contributors: devrivo
Tags: chatbot, ai, lead generation, customer support, live chat
Requires at least: 7.0
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.9.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A free, customisable AI assistant for any WordPress site. Answers questions from your content, captures leads and guides visitors.

== Description ==

DevRivo Chatbot adds a friendly assistant to your website that helps visitors find information, ask questions, and get in touch. It works on any type of site: business and corporate, healthcare, WooCommerce stores, construction, real estate, portfolios, blogs, and agencies.

It is completely free. There are no licence keys, no subscriptions and no locked features.

**Two ways to run it**

* **Guided mode (no AI needed):** quick-action buttons, guided conversations, forms and keyword search across your site content. Works out of the box with no external services.
* **AI mode:** natural-language answers based on your own pages, posts and products, with links to the right page. Uses the AI provider you connect in WordPress (Settings → Connectors). You stay in control of your provider account and costs.

If AI is unavailable for any reason, the assistant automatically switches to Guided mode, so visitors never see a broken chat.

**Features**

* Floating launcher with quick actions that can open automatically
* Customisable quick actions: links, forms, guided flows, phone, WhatsApp, email and more
* Industry presets to get started fast, all fully editable
* Answers grounded in your own site content, with links to source pages
* Inline forms for enquiries, quotes, callbacks and booking requests
* Booking-link handoff to any booking page
* Leads inbox with email notifications
* Opening-hours awareness
* Appearance settings with live preview, plus light and dark mode
* Display rules: choose pages, post types and devices
* Privacy tools: consent checkbox, data retention, and support for WordPress personal data export and erase
* Lightweight: the widget loads only when needed
* Translation ready

**Source code**

The full, human-readable source (including the uncompiled JavaScript in `assets-src/`) is on GitHub: https://github.com/masudranawd/Devrivo-Chatbot

== External services ==

This plugin can use an external AI service to answer visitor questions. This only happens when the site administrator has **turned on AI mode, chosen a model and set a monthly token limit**, and an AI provider plugin is installed and connected under Settings → Connectors. In Guided mode, no data is sent to any external AI service.

**How:** the plugin never contacts an AI service itself and never stores or reads API keys. Requests are made through the WordPress AI Client by the AI provider plugin the site administrator installed (AI Provider for Anthropic, Google or OpenAI).

**What is sent and when:** each time a visitor sends a chat message in AI mode:

* the visitor's message;
* up to 8 recent messages from the same conversation (kept only in the visitor's browser memory);
* short excerpts of the site's own published pages, posts and FAQs that match the question;
* the current page title and URL (without query string);
* the site's assistant instructions (rules, the business name and the administrator's custom instructions).

**What is not sent or stored:** contact details entered in forms are stored on your own site and are never sent to the AI provider. The assistant is told never to ask for personal details in the chat. This plugin does not store chat messages or AI replies; it only counts the tokens used each month.

Depending on the provider the site administrator connected, the request goes to:

* Anthropic (Claude): [Commercial terms](https://www.anthropic.com/legal/commercial-terms), [Privacy policy](https://www.anthropic.com/legal/privacy)
* OpenAI: [Services agreement](https://openai.com/policies/services-agreement/), [Privacy policy](https://openai.com/policies/privacy-policy/)
* Google (Gemini): [Gemini API terms](https://ai.google.dev/gemini-api/terms), [Privacy policy](https://policies.google.com/privacy)

Site owners are responsible for reviewing their chosen provider's terms and for describing this processing in their own privacy policy. The plugin adds suggested text to Settings → Privacy to help with this.

== Installation ==

1. In your WordPress admin, go to Plugins → Add New and search for "DevRivo Chatbot".
2. Click Install, then Activate.
3. Follow the setup wizard under DevRivo Chatbot in the admin menu. It scans your site and suggests settings.
4. Optional: to use AI mode, install an AI provider plugin (AI Provider for Anthropic, Google or OpenAI), add your key under Settings → Connectors, then in the plugin's AI settings choose the provider, a model and a monthly token limit, and turn AI on.

== Frequently Asked Questions ==

= Is it really free? =

Yes. Every feature is free. There are no licence keys, subscriptions or paid add-ons.

= Do I need an AI account? =

No. Guided mode works without any AI service. AI mode is optional and uses your own AI provider account, connected in Settings → Connectors.

= Who pays for AI usage? =

The site owner, directly to their chosen AI provider. The plugin includes usage limits to help keep costs predictable.

= Will it slow down my site? =

The widget is designed to be lightweight. It loads only on pages where it is shown, and the full chat loads only when a visitor interacts or the browser is idle.

= Does it work with WooCommerce? =

WooCommerce support (product search, product cards and order status) is planned for an upcoming version.

= Where are leads and conversations stored? =

In your own WordPress database (leads in their own table). IP addresses are not stored with leads. You can set how long they are kept, and they are included in WordPress's personal data export and erase tools.

Questions visitors ask in Guided mode are searched against your own pages and FAQs on your site. They are not stored or logged by the plugin, and they are sent in the request body, so they don't appear in URLs or server access logs.

= My site is behind Cloudflare or a proxy. Does spam protection still work? =

Yes. Form submissions are limited per visitor session first, so visitors who share an IP address don't block each other, with a looser limit per IP address on top. By default the plugin uses the connecting IP address and ignores headers such as X-Forwarded-For, because anyone can fake them. If all traffic reaches your server through a proxy you trust (for example Cloudflare, with your server only accepting Cloudflare's IP ranges), a developer can pass the real visitor IP with the `devrivo_chatbot_client_ip` filter.

= Can developers extend it? =

Yes. The plugin provides documented actions and filters for custom modules, action types, presets and more.

== Screenshots ==

1. Launcher with quick actions open on page load.
2. Assistant home panel with greeting and quick actions.
3. Chat with an answer and a link to the source page.
4. Inline enquiry form with consent.
5. Setup wizard suggesting a preset.
6. Quick actions editor.
7. Appearance settings with live preview.
8. Leads inbox.

== Changelog ==

= 0.9.0 =
* Admin app: dashboard, appearance with live preview, display rules, behaviour and opening hours, quick actions, AI settings, leads inbox with CSV export.

= 0.8.0 =
* AI answers from your own site content through the WordPress AI Client, with tools, guardrails, monthly token limit and automatic fallback to Guided mode.

= 0.7.0 =
* Knowledge index and site search: answers from your pages, posts and FAQs (works with Elementor).

= 0.6.0 =
* Enquiry forms with consent, leads storage, email notifications, privacy export/erase and retention.

= 0.5.0 =
* Working quick actions (link, call, WhatsApp, email) and guided conversation flows; opening hours.

= 0.4.0 =
* Front-end widget: launcher, quick-action popover, Home and Offers panel, display rules.

= 0.3.0 =
* Industry presets (Business, Healthcare, Portfolio/Blog, Agency) with apply, merge and undo.

= 0.2.0 =
* Settings storage, validation and REST API.
* New capability devrivo_chatbot_manage, granted to administrators.

= 0.1.0 =
* Initial development version.

== Upgrade Notice ==

= 0.9.0 =
Development version: adds the admin app under DevRivo Chatbot in the admin menu, with a live preview and a leads inbox with CSV export.

= 0.8.0 =
Development version: adds optional AI answers through the WordPress AI Client. AI stays off until you choose a provider, a model and a monthly token limit.

= 0.7.0 =
Development version: adds the knowledge index (a new table, built in the background) and "Ask a question" answers from your site.

= 0.6.0 =
Development version: adds enquiry forms, a leads table (created automatically) and privacy tools.

= 0.5.0 =
Development version: adds working quick actions, guided flows and opening hours.

= 0.4.0 =
Development version: adds the front-end widget.

= 0.3.0 =
Development version: adds industry presets.

= 0.2.0 =
Development version: adds settings storage.

= 0.1.0 =
Initial development version.
