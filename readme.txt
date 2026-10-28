=== DevRivo Chatbot ===
Contributors: devrivo
Tags: chatbot, ai, lead generation, customer support, live chat
Requires at least: 7.0
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.2.0
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

This plugin can connect to an external AI service to answer visitor questions. This only happens when **AI mode** is enabled by the site administrator **and** an AI provider has been connected in WordPress under Settings → Connectors. In Guided mode, no data is sent to any external AI service.

**What is sent and when:** each time a visitor sends a message in AI mode, the plugin sends the visitor's message, recent messages from the same conversation, relevant excerpts of the site's own published content, the current page title and URL, and the site's assistant instructions to the AI provider selected in Connectors. Contact details entered in forms are stored on your own site and are not sent to the AI provider.

The request is made through the WordPress core AI Client to whichever provider the site administrator has connected. Depending on that choice, this may be:

* Anthropic (Claude): [Commercial terms](https://www.anthropic.com/legal/commercial-terms), [Privacy policy](https://www.anthropic.com/legal/privacy)
* OpenAI: [Terms](https://openai.com/policies/business-terms), [Privacy policy](https://openai.com/policies/privacy-policy)
* Google (Gemini): [Gemini API terms](https://ai.google.dev/gemini-api/terms), [Privacy policy](https://policies.google.com/privacy)

Site owners are responsible for reviewing their chosen provider's terms and for describing this processing in their own privacy policy. The plugin adds suggested text to Settings → Privacy to help with this.

== Installation ==

1. In your WordPress admin, go to Plugins → Add New and search for "DevRivo Chatbot".
2. Click Install, then Activate.
3. Follow the setup wizard under DevRivo Chatbot in the admin menu. It scans your site and suggests settings.
4. Optional: to use AI mode, connect an AI provider under Settings → Connectors, then enable AI mode in the plugin's AI settings.

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

In your own WordPress database. You can set how long they are kept, and they are included in WordPress's personal data export and erase tools.

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

= 0.2.0 =
* Settings storage, validation and REST API.
* New capability devrivo_chatbot_manage, granted to administrators.

= 0.1.0 =
* Initial development version.

== Upgrade Notice ==

= 0.2.0 =
Development version: adds settings storage.

= 0.1.0 =
Initial development version.
