# DevRivo Chatbot

A free, universal AI assistant plugin for WordPress: quick actions, guided flows, forms and answers grounded in the site's own content. Works without AI (Guided mode) or with the site owner's own AI provider through WordPress 7.0 Connectors (AI mode).

- **Status:** Phase 0 (foundations). Not ready for production use.
- **Requires:** WordPress 7.0+, PHP 8.1+
- **Licence:** GPLv2 or later
- **Price:** free. No licence keys, subscriptions or locked features.

## Documentation

- [Development setup](docs/development.md)
- [Hooks reference](docs/hooks.md)
- [AI Client spike](docs/spikes/ai-client.md)

The WordPress.org readme is [`readme.txt`](readme.txt).

## Quick start

Clone into a local WordPress 7.0+ site's `wp-content/plugins/devrivo-chatbot` (Laragon works; no Docker needed):

```bash
git clone https://github.com/masudranawd/Devrivo-Chatbot.git devrivo-chatbot
cd devrivo-chatbot
composer install && npm ci && npm run build
composer test:install && composer test
```

See [docs/development.md](docs/development.md) for details.
