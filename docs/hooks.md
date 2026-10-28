# DevRivo Chatbot — Hooks Reference

> Public actions and filters for extending DevRivo Chatbot.
> **Status:** hooks marked **In dev** are implemented on the development branch; the rest are **planned**. Update the Status column as each hook ships, and never rename or remove a shipped hook without the deprecation process below.

## Conventions

- Every hook starts with `devrivo_chatbot_`.
- Filters always return the same type they receive.
- Hooks receive data as arguments; never rely on globals.
- Each hook is documented here **and** with a docblock at the point where it fires (`@since`, `@param`, `@return`).
- **Deprecation policy:** a shipped hook stays working for at least two minor versions after it is deprecated, using `apply_filters_deprecated()` / `do_action_deprecated()`, with a notice in debug mode and a changelog entry.

---

## Actions

| Hook | Fires when | Arguments | Since | Status |
|---|---|---|---|---|
| `devrivo_chatbot_loaded` | Plugin has loaded core services and modules | `Plugin $plugin` | 1.0.0 | Planned |
| `devrivo_chatbot_register_modules` | Before modules are booted; register custom modules | `ModuleRegistry $registry` | 1.0.0 | Planned |
| `devrivo_chatbot_message_received` | A visitor message passed the security gate | `array $message`, `array $context` | 1.0.0 | Planned |
| `devrivo_chatbot_response_sent` | A response was returned to the widget | `array $response`, `array $context` | 1.0.0 | Planned |
| `devrivo_chatbot_lead_created` | A lead was saved (after consent) | `int $lead_id`, `array $lead_data` | 1.0.0 | Planned |
| `devrivo_chatbot_form_submitted` | Any widget form was submitted successfully | `string $form_id`, `array $data` | 1.0.0 | Planned |
| `devrivo_chatbot_custom_action` | A quick action of type `custom_hook` was triggered | `string $action_id`, `array $config`, `array $context` | 1.0.0 | Planned |
| `devrivo_chatbot_post_indexed` | A post was added or updated in the knowledge index | `int $post_id`, `int $chunk_count` | 1.0.0 | Planned |
| `devrivo_chatbot_index_rebuilt` | A full index rebuild finished | `array $stats` | 1.0.0 | Planned |
| `devrivo_chatbot_ai_fallback` | AI mode failed and Guided mode was used | `string $reason`, `array $context` | 1.0.0 | Planned |
| `devrivo_chatbot_data_purged` | Retention cron removed old conversations/leads | `array $counts` | 1.0.0 | Planned |
| `devrivo_chatbot_preset_applied` | A preset was applied to the settings (replace or merge) | `string $preset_id`, `string $mode`, `array $settings` | 1.0.0 | In dev |

## Filters

### Widget and display

| Hook | Filters | Arguments | Since | Status |
|---|---|---|---|---|
| `devrivo_chatbot_should_display` | Whether the widget loads on the current request | `bool $display`, `array $context` | 1.0.0 | Planned |
| `devrivo_chatbot_widget_config` | Config JSON sent to the widget (actions, appearance, behaviour) | `array $config`, `array $context` | 1.0.0 | Planned |
| `devrivo_chatbot_quick_actions` | Quick actions shown in popover and Home view | `array $actions`, `array $context` | 1.0.0 | Planned |
| `devrivo_chatbot_greeting` | Greeting text (time-based by default) | `string $greeting`, `array $context` | 1.0.0 | Planned |

### Extensibility

| Hook | Filters | Arguments | Since | Status |
|---|---|---|---|---|
| `devrivo_chatbot_modules` | Registered module class names | `string[] $modules` | 1.0.0 | Planned |
| `devrivo_chatbot_action_types` | Available action types and their handlers | `array $types` | 1.0.0 | Planned |
| `devrivo_chatbot_presets` | Available industry presets: definition arrays, or paths to `.php` (returns an array) or `.json` files. Invalid presets are skipped with `_doing_it_wrong()` | `array $presets` | 1.0.0 | In dev |
| `devrivo_chatbot_form_fields` | Field definitions for a form before render | `array $fields`, `string $form_id` | 1.0.0 | Planned |
| `devrivo_chatbot_cards` | Registered card types (link, product, property, post) | `array $cards` | 1.0.0 | Planned |

### AI

| Hook | Filters | Arguments | Since | Status |
|---|---|---|---|---|
| `devrivo_chatbot_system_prompt` | Final system prompt before it is sent | `string $prompt`, `array $context` | 1.0.0 | Planned |
| `devrivo_chatbot_ai_tools` | Tools the AI may call for this request | `array $tools`, `array $context` | 1.0.0 | Planned |
| `devrivo_chatbot_ai_enabled` | Whether AI mode is used for this request | `bool $enabled`, `array $context` | 1.0.0 | Planned |
| `devrivo_chatbot_ai_response` | AI response before output checks | `array $response`, `array $context` | 1.0.0 | Planned |

### Knowledge index

| Hook | Filters | Arguments | Since | Status |
|---|---|---|---|---|
| `devrivo_chatbot_index_post_types` | Post types included in the index | `string[] $post_types` | 1.0.0 | Planned |
| `devrivo_chatbot_should_index_post` | Whether a single post is indexed | `bool $index`, `WP_Post $post` | 1.0.0 | Planned |
| `devrivo_chatbot_post_content` | Plain-text content extracted from a post before chunking | `string $content`, `WP_Post $post` | 1.0.0 | Planned |
| `devrivo_chatbot_knowledge_sources` | Registered content sources | `array $sources` | 1.0.0 | Planned |
| `devrivo_chatbot_search_results` | Results returned by knowledge search | `array $results`, `string $query` | 1.0.0 | Planned |

### Leads, security and privacy

| Hook | Filters | Arguments | Since | Status |
|---|---|---|---|---|
| `devrivo_chatbot_lead_data` | Lead data before saving | `array $data`, `string $form_id` | 1.0.0 | Planned |
| `devrivo_chatbot_notification_recipients` | Email recipients for a lead | `string[] $emails`, `array $lead` | 1.0.0 | Planned |
| `devrivo_chatbot_webhook_payload` | Payload sent to the webhook | `array $payload`, `array $lead` | 1.3.0 | Planned |
| `devrivo_chatbot_rate_limit` | Rate-limit settings for the public chat endpoint | `array $limits`, `array $context` | 1.0.0 | Planned |
| `devrivo_chatbot_retention_days` | Days to keep conversations and leads | `int $days` | 1.0.0 | Planned |

---

## Example: registering a custom module

```php
add_filter( 'devrivo_chatbot_modules', function ( array $modules ): array {
	$modules[] = \MyAgency\ChatbotGym\GymModule::class; // implements DevRivo\Chatbot\Modules\Module
	return $modules;
} );
```

## Example: hiding the widget on checkout

```php
add_filter( 'devrivo_chatbot_should_display', function ( bool $display ): bool {
	if ( function_exists( 'is_checkout' ) && is_checkout() ) {
		return false;
	}
	return $display;
} );
```
