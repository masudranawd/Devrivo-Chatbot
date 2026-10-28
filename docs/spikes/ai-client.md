# Spike: WordPress AI Client (Phase 0)

**Goal:** confirm how DevRivo Chatbot talks to AI through WordPress core, and answer Open decision #4 (tool calling and fallback adapter).
**Status:** desk research done (2026-10-28). Live runs pending.

## 1. Desk research (verified against official sources)

- Entry point `wp_ai_client_prompt()` returns `WP_AI_Client_Prompt_Builder`. Generation methods return `WP_Error` on failure; the builder converts SDK exceptions to `WP_Error`.
- `generate_text_result()` returns `GenerativeAiResult` with `getTokenUsage()`, `getProviderMetadata()` and `getModelMetadata()`.
- `is_supported_*()` checks make no API calls.
- **Providers are separate plugins** (AI Provider for Anthropic / Google / OpenAI). Keys are entered in Settings → Connectors. Plugins using the AI Client never handle credentials.
- Without a model preference, the first suitable model is used, and providers sort newer models first, which is a **cost risk**. `using_model_preference()` falls back to any compatible model.
- Tool calling: `using_function_declarations( FunctionDeclaration ... )`, `FunctionCall` / `FunctionResponse` DTOs, `with_history()` for the loop. There is also `using_abilities()` with `WP_AI_Client_Ability_Function_Resolver`, which changed to instance-based with an explicit allow-list after the initial merge (r61795).
- Hooks: `wp_ai_client_prevent_prompt` (global), `wp_ai_client_default_request_timeout` (global, default 30 s), before/after-generate events. Per-request timeout: `using_request_options()`.
- Client-side JS AI API isn't in core and is admin-only. The recommended pattern is a plugin's own REST endpoint per feature.
- Keys are stored as options and masked on read. There is no public API to read them.
- Current core: 7.1 released, 7.2 in progress.

Sources:
- https://make.wordpress.org/core/2026/03/24/introducing-the-ai-client-in-wordpress-7-0/
- https://developer.wordpress.org/reference/classes/wordpress-aiclient-builders-promptbuilder/usingfunctiondeclarations/
- https://core.trac.wordpress.org/changeset/61795
- https://gist.github.com/jonathanbossenger/fd63914ee6e355171ae5ef2da2b45caa (community guide, pre-release; verify)

## 2. Live results (fill in)

Run on WP 7.0 and on latest. One column per provider.

| Check | Anthropic | OpenAI | Google |
|---|---|---|---|
| env: function declarations supported | | | |
| env: JSON schema supported | | | |
| env: history supported | | | |
| env: temperature supported | | | |
| env: streaming methods | | | |
| basic: default model chosen / latency | | | |
| basic: token usage reported | | | |
| history: PASS/FAIL | | | |
| tools: rounds / parallel calls / call IDs present | | | |
| tools: PASS/FAIL | | | |
| json: valid runs | | | |
| injection: resisted runs | | | |
| errors: unknown provider (code / thrown?) | | | |
| errors: prevented (code) | | | |
| errors: network failure (code / data) | | | |
| errors: HTTP 429 / 500 (code / status in data) | | | |
| errors: unknown model → model actually used | | | |
| errors: 1 s timeout (code / ms) | | | |
| invalid key in Connectors (manual) | | | |

Differences between WP 7.0 and latest:

## 3. Decision (draft, confirm after runs)

1. **AI path:** native function declarations through `wp_ai_client_prompt()` (Tier 1), if `tools` passes on all providers.
2. **Per-request capability check (free):** if `using_function_declarations()` isn't supported for the configured model, use the JSON protocol (Tier 2) if `json` passes. Otherwise answer from pre-retrieved content without tools. Otherwise fall back to Guided mode.
3. **Tier 3 (direct HTTP adapter) dropped.** There's no public credential API and keys are masked, so it would depend on core internals. The "no AI keys stored by the plugin" decision stays intact.
4. **No Abilities API in v1.0.** Tools run for anonymous visitors and stay private to our REST endpoint. Abilities are site-wide and discoverable; they remain in v2.0 as planned.
5. **Always set a model preference** (admin choice; cheap default per provider).
6. **Per-request timeout** (around 20 s) via `using_request_options()`. Never use global filters to impose our limits, since they affect other plugins.
7. **Budgets** from `getTokenUsage()`.
8. **Streaming:** (fill in). If none, use a typing indicator only.

## 4. Impacts on other docs

- `readme.txt`: installation, FAQ and External services must mention installing a provider plugin.
- Setup wizard step 4: three states: no provider plugin / provider not connected / ready.
