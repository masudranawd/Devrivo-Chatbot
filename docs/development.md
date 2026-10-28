# Development setup

How to run, test and ship DevRivo Chatbot locally. Keep this file updated when tooling changes.

## Prerequisites

- **Git**
- **PHP 8.1+ and Composer 2** on the host (for PHPCS, PHPStan and installing PHPUnit; WordPress itself runs in Docker)
- **Node 22** via nvm (`.nvmrc`)
- **Docker** (Docker Desktop on macOS/Windows), with at least 4 GB of memory

### Windows

- Use **WSL2 (Ubuntu)** and run every command inside the WSL terminal.
- Clone the repo inside the WSL filesystem (`~/projects/...`), not under `/mnt/c/`, because file access across the boundary is very slow.
- Docker Desktop: enable "Use the WSL 2 based engine" and the integration for your distro.
- Install PHP, Composer and nvm inside WSL, not on Windows.
- Run `git config --global core.autocrlf input`. `.gitattributes` forces LF, but this avoids noisy diffs.

## First run

The folder **must** be named `devrivo-chatbot`, because wp-env mounts the plugin under the folder name. The clone command below sets the name explicitly.

```bash
git clone https://github.com/masudranawd/Devrivo-Chatbot.git devrivo-chatbot
cd devrivo-chatbot
nvm use
composer install
npm ci
npm run build
npm run env:start
```

| Site | URL | Login |
|---|---|---|
| Development | http://localhost:8888/wp-admin | admin / password |
| Tests | http://localhost:8889 | (used by PHPUnit) |

The plugin and Plugin Check are installed automatically. Activate DevRivo Chatbot under Plugins.

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

# Tests and checks
npm run test:php         # PHPUnit inside wp-env
npm run plugin-check     # Plugin Check on the dev site
npm run plugin-zip       # release zip (same as CI)

# Environment
npm run env:start
npm run env:stop
npx wp-env run cli wp <command>        # WP-CLI on the dev site
npx wp-env run cli cat wp-content/debug.log
```

## Testing other PHP or WordPress versions

Create `.wp-env.override.json` (gitignored), then run `npx wp-env start --update`.

```json
{ "phpVersion": "8.4" }
```

```json
{ "core": null }
```

`core: null` means the latest WordPress release. For the nightly build use `"core": "https://wordpress.org/nightly-builds/wordpress-latest.zip"`. Delete the override file to return to the default (WP 7.0, PHP 8.1).

## Using an existing local site (LocalWP, Laragon, etc.)

wp-env is the reference environment (tests and CI use it). For quick manual checks you can also link the repo into another local site that runs WordPress 7.0+ and PHP 8.1+:

```bash
# macOS / Linux / WSL
ln -s "$(pwd)" /path/to/site/wp-content/plugins/devrivo-chatbot
```

On Windows without WSL, use `mklink /D` from an admin prompt. Run `npm run build` first; the plugin folder name must stay `devrivo-chatbot`.

## Working with AI locally

AI mode needs two things on the site: an **AI provider plugin** (AI Provider for Anthropic, Google or OpenAI) and a key entered in **Settings → Connectors**. Use a low-cost model and a provider account with a spending limit. Never commit keys or paste them into issues, logs or screenshots.

## Branches and pull requests

- `main` is always releasable. Never commit to it directly.
- Branch names: `feature/<short-name>`, `fix/<short-name>`, `chore/<short-name>`, `spike/<short-name>`.
- Open a pull request even when working solo, and complete the checklist in the PR template.
- Merge only when all required CI checks pass.

## Staging deploy (Phase 0)

The staging site is a separate WordPress 7.0+ install with PHP 8.1+, not the Pearl dev site, until v1.0.

1. Open the latest green **Build** run on GitHub Actions and download the `devrivo-chatbot-zip` artifact.
2. Unzip the artifact to get `devrivo-chatbot.zip`.
3. Install it:
   - in wp-admin, go to Plugins → Add New → Upload Plugin and choose **Replace current with uploaded**, or
   - with WP-CLI: `wp plugin install devrivo-chatbot.zip --force --activate`.
4. Check `wp-content/debug.log` for new notices.

Staging settings: `WP_DEBUG` and `WP_DEBUG_LOG` on, `WP_DEBUG_DISPLAY` off, and Settings → Reading → **Discourage search engines** enabled.

## Troubleshooting

- **Port already in use.** Add `{ "port": 8890, "testsPort": 8891 }` to `.wp-env.override.json`.
- **"WordPress test suite not found".** Run tests with `npm run test:php` (inside wp-env), not `composer test` on the host.
- **Strange environment state.** Run `npx wp-env clean all` to reset the databases. If that doesn't help, run `npx wp-env destroy` and then `npm run env:start`.
- **Docker errors on start.** Make sure Docker is running. On Windows, check that WSL integration is on for your distro.
- **PHPCS cache is stale.** Delete `.phpcs-cache`.

## Related docs

- `docs/hooks.md`: public actions and filters (update with every hook change)
- `docs/spikes/ai-client.md`: WordPress 7.0 AI Client findings
- `PROGRESS.md`: project status (kept in the Claude Project)
