## What and why

<!-- One or two sentences. Link the issue: Closes #… -->

## How to test

1.

## Self-review checklist

- [ ] Stays within the current phase's scope
- [ ] Input sanitised, output escaped, nonces and capability checks in place
- [ ] Every REST route has a real `permission_callback`
- [ ] Custom SQL uses `$wpdb->prepare()`; DB changes go through the migration runner
- [ ] User-facing strings translatable (`devrivo-chatbot`)
- [ ] No industry-specific text hard-coded (it belongs in presets)
- [ ] No remote assets, tracking or upsells
- [ ] Assets enqueued only where needed; loader still under 15 KB
- [ ] Accessible: keyboard, focus, ARIA, reduced motion
- [ ] New or changed hooks documented in `docs/hooks.md` with docblocks
- [ ] Tests added or updated; `composer lint`, `composer analyse`, `npm run test:php` pass locally
- [ ] Changelog line and version bump noted (if releasable)
