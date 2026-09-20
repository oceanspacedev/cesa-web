# WAG Hub integration

CESA owns user permissions, notification drafts, default sender choice, and business history. WAG Hub owns the authenticated number, session lifecycle, capacity, transport outcomes, and WhatsApp authentication. CESA never starts a local engine or selects another sender as a fallback on the v2 path.

## Deploy and configure

1. Back up MySQL and restore a copy for migration rehearsal. Keep the Laravel `APP_KEY` unchanged: integration tokens and webhook secrets are encrypted with it.
2. Use PHP **8.4.1 or newer** for the application, CLI, workers, and CI. Install locked PHP and frontend dependencies and build assets: `composer install`, `npm ci`, `npm run build`.
3. Run `php artisan migrate --force` and ensure recruitment plugin migrations run, including `2026_09_21_000001_add_hub_session_projection.php`. If plugin discovery is disabled, run `php artisan migrate --path=plugins/cesa/rekrutmen/database/migrations --force`.
4. Set `WAG_URL=https://your-hub.example` and `WAG_TOKEN` to an installation credential with the hub full-control preset. Set a public HTTPS `APP_URL`, required to register the callback. Alternatively, an authorized account manager can enter the URL and token in **Akun WhatsApp → Pengaturan administrator**. Setup discovers capabilities, registers the webhook, and imports authoritative sessions automatically.
5. Supervise the CESA Laravel scheduler (`php artisan schedule:work`) and the application's queue workers. Include the default queue for `ProcessWagEvent`, plus existing `notifications` and `whatsapp` queues. Use a durable queue connection in production. The `wag:reconcile` scheduled command runs every minute, refreshes sessions, replays missed events, and dispatches durable inbox entries.
6. Test connectivity with `php artisan wag:status`. The session page and callback `/api/integrations/wag/webhook` must be reachable across the two hosts. Callback requests use timestamped HMAC; no authentication tokens are exposed to browser JavaScript.

No extra CESA integration enable switch is necessary. The recruitment notification toggle controls business notifications independently of session connectivity.

## Migrating existing accounts

The migrations are additive. Existing account primary keys, default preferences, and notification delivery references remain intact. Old saved connection statuses are unverified until a hub observation arrives. Match remote sessions using `external_reference=rekrutmen-{existing-account-id}` from the hub migration command; never match by phone or infer authentication from a stored number. Repeated reconciliation is safe, and the hub UUID has a uniqueness constraint.

Migrate hub-managed legacy sessions using the hub's `engine:migrate-legacy` command before enabling the v2 client. `WAG_URL` now defaults to `/api/v2`. Explicit `WAG_ENGINE_URL` or recruitment engine overrides remain temporary v1 compatibility settings. The UI configuration takes precedence and switches all products to the shared installation.

Stop any legacy CESA local engine and retire its sender path before linking those accounts in the hub. Local authentication cannot be inferred or imported from the CESA account table. A failed v2 request never falls back to the old engine.

Old unknown deliveries without a v2 request record query only the legacy ledger by the original `rekrutmen-{id}` identity. They are never resent through a new v2 ledger. Keep the hub v1 read adapter available until these historical outcomes are resolved. New form-transfer and exit-clearance jobs capture the hub session UUID at enqueue time; changing the default does not retarget pending jobs. Old serialized jobs lacking an explicit v2 sender fail visibly instead of choosing a new default.

## Operations and recovery

**Hubungkan WhatsApp → scan QR → ready** is the first connection flow. Pairing is an alternative inside the challenge view. Notification drafts can open connection in context and return automatically without losing the draft.

Pause retains authentication. Resume reuses it. Unlink removes authentication while preserving the account slot. Remove releases capacity only after hub teardown. A removed or unlinked default requires explicit replacement; paused accounts retain their default designation.

Webhook ingress commits its inbox row before acknowledging. Processing and replay deduplicate by event ID; session revisions plus observation timestamps prevent stale state, and receipt ranks prevent read acknowledgements regressing to delivered. The event replay cursor is durable. An expired cursor logs a recovery warning and replays retained events after session refresh; events outside the hub retention window cannot be reconstructed.

Watch scheduler/queue availability, unprocessed `wag_event_inbox` rows and `last_error`, stale account observations, and unknown `wag_message_requests` outcomes. Treat runner outages as unavailable observations, not logouts. Never create a fresh idempotency key merely to resolve an ambiguous send.

## Verification

Backend checks:

```sh
php artisan test --compact plugins/cesa/rekrutmen/tests/Feature/WagHubV2IntegrationTest.php plugins/cesa/rekrutmen/tests/Feature/WagHubEngineIntegrationTest.php plugins/cesa/rekrutmen/tests/Feature/RecruitmentWhatsAppReliabilityTest.php tests/Unit/WagHubClientTest.php
vendor/bin/pint --dirty --format agent
npm run build
```

Browser checks use a mocked API and send no real WhatsApp messages:

```sh
cd tests/e2e-pw
npm ci
npx playwright test tests/07_man_power/04_whatsappGateway.spec.ts
```

Use `PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH` for an existing Chrome binary, or install Playwright Chromium. Verify live QR/pairing and actual inbound/outbound text/media/acknowledgements on a dedicated account before production cutover. The automated suites do not authenticate a real WhatsApp account.
