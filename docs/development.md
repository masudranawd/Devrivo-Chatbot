# Development setup

How to run, test and ship DevRivo Chatbot locally. Keep this file updated when tooling changes.

**Docker is optional.** Everything below works on Windows with Laragon (or any local PHP + MySQL). Docker is only needed if you prefer wp-env.

## Prerequisites

- **Git**
- **PHP 8.1+** with the `mysqli` extension, and **Composer 2**
- **MySQL or MariaDB** (Laragon includes it)
- **Node 22** (`.nvmrc`)
- **WP-CLI** (for the AI spike and handy admin tasks)

Laragon: Menu → Tools → Path → **Add Laragon to Path**, then restart VS Code so `php`, `composer`, `mysql` and `node` work in its terminal.

Run once so Git keeps LF line endings: `git config --global core.autocrlf input`.

## First run (Laragon)

The repo lives inside a local site's plugins folder, so the site doubles as a manual test site:

```
D:\laragon\www\<site>\wp-content\plugins\devrivo-chatbot
```

The folder **must** be named `devrivo-chatbot`.

```bash
composer install
npm ci
npm run build
composer test:install     # WordPress + WP test library, test DB "devrivo_chatbot_tests"
composer test             # PHPUnit
```

Then activate **DevRivo Chatbot** in the site's wp-admin. The site must run WordPress 7.0+.

## Automated tests (no Docker)

`composer test:install` runs `bin/install-wp-tests.php`. It downloads WordPress and the official WordPress PHPUnit library with git (into your system temp folder) and creates a **dedicated** test database.

```bash
composer test:install                                   # WordPress 7.0 (minimum), Laragon defaults (root, no password)
composer test:install -- --wp=latest                    # latest release
composer test:install -- --wp=trunk                     # development version
composer test:install -- --db-user=root --db-pass=secret --db-host=localhost
composer test:install -- --force                        # reinstall
composer test
```

> ⚠️ The test database is **wiped on every test run**. Never point `--db-name` at a real site's database. The installer refuses databases that contain non-test tables.

CI runs exactly the same installer against a MySQL service, on PHP 8.1–8.4 × WordPress 7.0 / latest / trunk.

## Daily commands

```bash
# Assets
npm run start            # watch and rebuild assets
npm run build            # production build
npm run check:size       # loader size budget (15 KB)

# Quality
composer lint            # PHPCS
composer lint:fix        # auto-fix what PHPCS can
composer analyse         # PHPStan
npm run lint:js          # ESLint + Prettier rules

# Tests and packaging
composer test            # PHPUnit
npm run plugin-zip       # release zip (same as CI)
```

## Plugin Check

CI runs Plugin Check on the built zip for every pull request. To run it locally, install the **Plugin Check** plugin on your Laragon site and run:

```bash
wp plugin check devrivo-chatbot --exclude-directories=node_modules,vendor,tests,bin,spikes,scripts,docs
```

## Working with AI locally

AI mode needs two things on the site: an **AI provider plugin** (AI Provider for Anthropic, Google or OpenAI) and a key entered in **Settings → Connectors**. Use a low-cost model and a provider account with a spending limit. Never commit keys or paste them into issues, logs or screenshots.

### AI Client spike (Phase 0)

1. Copy `spikes/ai-client-spike` to `wp-content/plugins/ai-client-spike` (WordPress only detects plugins one level deep).
2. Install and activate one AI provider plugin, and add its key in Settings → Connectors.
3. In the site folder:
   ```bash
   wp plugin activate ai-client-spike
   wp devrivo-spike env > spike-env.txt
   wp devrivo-spike all --provider=anthropic > spike-anthropic.txt
   ```
4. Share the output files (they never contain keys), then deactivate and delete `ai-client-spike`.

## Optional: wp-env (requires Docker)

```bash
npm run env:start
npm run env:test
npm run env:plugin-check
```

## Branches and pull requests

- `main` is always releasable. Never commit to it directly.
- Branch names: `feature/<short-name>`, `fix/<short-name>`, `chore/<short-name>`, `spike/<short-name>`.
- One step = one branch + one pull request. Complete the checklist in the PR template.
- Merge only when all required CI checks pass.

## Staging deploy (Phase 0)

1. Open the latest green **Build** run on GitHub Actions and download the `devrivo-chatbot-zip` artifact.
2. Unzip it to get `devrivo-chatbot.zip`, then install it on the staging site (Plugins → Add New → Upload Plugin → Replace current, or `wp plugin install devrivo-chatbot.zip --force --activate`).
3. Check `wp-content/debug.log` for new notices.

Staging settings: `WP_DEBUG` and `WP_DEBUG_LOG` on, `WP_DEBUG_DISPLAY` off, Settings → Reading → **Discourage search engines** on.

## Troubleshooting

- **"WordPress test suite not found".** Run `composer test:install` first.
- **"Cannot connect to MySQL".** Start MySQL in Laragon, or pass `--db-host`, `--db-user`, `--db-pass`.
- **"contains non-test tables".** You pointed the installer at a real database. Use the default `devrivo_chatbot_tests`.
- **`php`, `composer` or `mysql` not found in VS Code.** Laragon → Tools → Path → Add Laragon to Path, then restart VS Code.
- **PHPCS cache is stale.** Delete `.phpcs-cache`.

## Related docs

- `docs/hooks.md`: public actions and filters (update with every hook change)
- `docs/spikes/ai-client.md`: WordPress AI Client findings
- `CLAUDE.md`: rules for Claude Code
