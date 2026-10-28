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

```bash
git clone https://github.com/masudranawd/Devrivo-Chatbot.git devrivo-chatbot
cd devrivo-chatbot
nvm use && composer install && npm ci
npm run build && npm run env:start
```

Open http://localhost:8888/wp-admin (user `admin`, password `password`).
