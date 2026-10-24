# Integrations: receiving and sending business data

The static React build now includes a PHP integration API. Credentials and outbound destinations are configured in the **private** `config.php`, never in React, Git, or public JavaScript. An Integrations navigation item displays connection status, the latest 100 inbound requests, outreach activity, and outbound delivery jobs. It also lets administrators queue, retry, or cancel contact handoffs.

## Install on Hostinger

1. Prepare a NEW EMPTY database by manually importing the complete `database-setup.sql` in phpMyAdmin. It includes every integration table and the initial Super Admin. No migration files or migration runner are used. This installer is for initial setup, not an upgrade of an older populated schema.
2. Run `npm run build`; upload the contents of `dist/`. It includes `api.php`, `integrations.php`, `integrations-lib.php`, and `integrations-admin.php`. Keep your existing private configuration and media folder.
3. Add `inbound_connectors` and `email_destination` from `server/config.example.php` to your real private configuration. Never overwrite database credentials while merging these settings.
4. Enable PHP `curl` in addition to the existing PDO MySQL, fileinfo, and mbstring requirements. Outbound HTTPS must reach the exact configured hostname on port 443. No Node service is used.
5. Copy `server/integration-worker.php`, `server/integrations-lib.php`, and `server/config-loader.php` to a **private jobs directory outside public_html**. Keep the worker and public API library copies in sync on updates. Add a Hostinger cron job every minute, using your account's actual PHP path and absolute paths:

   ```sh
   KHUSHRANG_CONFIG=/absolute/private/config.php /usr/bin/php /absolute/private/jobs/integration-worker.php
   ```

   Each invocation processes at most 20 jobs. A failed job schedules its next attempt in MySQL; no persistent daemon is required. Check Hostinger cron output and the panel for failures. If cron is absent, jobs remain queued.
6. Sign in, open Integrations, confirm configured sources and destination hostname, then test using a dedicated test contact and your receiving system's test workflow. Check that that system deduplicates delivery IDs before enabling actual email sends.

The application runs database sessions in UTC. The integrations activity timestamps are displayed as UTC.

## Create inbound keys

Use a different random secret for each trusted server/system. A connector has a stable `source`, allowed `scopes`, and SHA-256 of the secret:

```php
'inbound_connectors' => [
    ['source'=>'website', 'token_sha256'=>'HASH_HERE', 'scopes'=>['clients']],
    ['source'=>'email-platform', 'token_sha256'=>'ANOTHER_HASH', 'scopes'=>['outreach']],
],
```

Generate a 32-byte token locally or over SSH without placing it in shell arguments:

```sh
umask 077
openssl rand -hex 32 | tr -d '\n' > /private/path/website-integration-token
php -r 'echo hash_file("sha256", "/private/path/website-integration-token"), PHP_EOL;'
```

Store the hash in the panel server configuration. Securely give the raw token to the sending backend or its secret store. Do not paste it in browser scripts, public forms, URLs, logs, or chat. Keep the private token file outside the web root or securely remove it after provisioning. The key's raw token is sent as `Authorization: Bearer TOKEN` over HTTPS. The panel never displays hashes or raw tokens. To rotate, add the new hash with the **same source**, switch the sender, then remove the old connector entry. Removing a connector revokes its key.

These are **server-to-server** endpoints: a public website form must submit to your website's own backend, which validates the form and forwards it with the secret. No cross-origin browser credentials or permissive CORS are enabled. If a hosting rewrite strips the Authorization header, preserve it in your Hostinger web server configuration; don't move the token into a query string.

## Receive a client

`POST https://YOUR_DOMAIN/admin/integrations.php?action=clients`

Headers: `Authorization: Bearer TOKEN`, `Content-Type: application/json`.

```json
{
  "event_id": "website-submission-1847-v1",
  "external_id": "website-contact-1847",
  "name": "Example Client",
  "email": "client@example.com",
  "phone": "+92 300 0000000",
  "marketing_consent": false
}
```

Required: `event_id` (1–128 bytes), `external_id` (1–128), `name` (1–190), valid `email` (up to 254). Phone is optional, up to 80 bytes. Consent must be a JSON boolean when supplied; a missing value never enables consent. The stable pair `(source, external_id)` creates or updates the same client record. Status is Active on creation and preserved on updates. Existing manual/other-source clients are not automatically merged by email. Contact fields are applied in receipt order; this endpoint has no external version reconciliation.

A new event returns HTTP **202**:

```json
{"accepted":true,"duplicate":false,"client_id":"EXT-..."}
```

Keep the returned `client_id` in your external system to correlate outreach callbacks. Retry the same `event_id` with the **exact same request bytes**: it returns HTTP 200 with `duplicate:true` without applying it again. Changing the content or endpoint while reusing that event ID returns 409. For a genuine contact update use a new event ID and the same external ID. Event IDs are unique per source across both inbound endpoints.

## Receive outreach updates

`POST https://YOUR_DOMAIN/admin/integrations.php?action=outreach`

Use a token with the `outreach` scope and the same headers.

```json
{
  "event_id": "provider-event-90841",
  "client_id": "EXT-ID_RETURNED_BY_CLIENT_ENDPOINT",
  "recipient_email": "client@example.com",
  "message_id": "provider-message-6381",
  "status": "delivered",
  "occurred_at": "2026-10-24T09:30:00Z"
}
```

All fields are required. `client_id` must exist. `recipient_email` is the address to which the original message was sent; this prevents a delayed bounce from suppressing a different, newly updated email address. Message IDs are up to 190 bytes. Allowed statuses: `queued`, `sent`, `delivered`, `opened`, `clicked`, `replied`, `bounced`, `unsubscribed`, `complained`. Use an RFC3339 timestamp with seconds and `Z` or a numeric timezone offset; values more than five minutes in the future are rejected.

Events are stored as a timeline, sorted by occurrence time. Late `delivered`/`opened` events do not clear an unsubscribe. Bounces, unsubscribes, and complaints suppress the original recipient address from **all** outbound contact handoffs. Suppressions survive subsequent contact imports and consent edits; this version has no automatic resubscription mechanism. All bounce statuses are treated as suppression; a provider adapter should map only appropriate permanent-bounce events to `bounced`.

## Send client data to an email/automation system

Configure a generic receiver in the private configuration:

```php
'email_destination' => [
    'url' => 'https://automation.example.com/hooks/khushrang',
    'allowed_hosts' => ['automation.example.com'],
    'bearer_token' => 'SET_PRIVATELY_IF_THE_RECEIVER_REQUIRES_IT',
],
```

The URL must use HTTPS, port 443, and an exact allowed hostname that resolves to public IPv4 addresses. User info, query strings, fragments, private addresses, redirects, and non-HTTPS URLs are rejected. TLS verification remains enabled; DNS answers are checked and pinned to the connection. Secrets belong in the optional bearer credential, not URL query strings. IPv6-only receivers and receivers requiring query-string credentials need an adapter.

In the panel choose **Integrations → Send client data**, select a client and purpose, and queue a delivery. Marketing requires explicit **Marketing email consent = Yes** in the client editor; missing consent is No. Both purposes require an active client, valid email, and no suppression. Eligibility is checked again immediately before dispatch. A deleted client, changed recipient address, revoked consent, or suppression blocks a queued delivery.

The worker posts this JSON:

```json
{
  "event": "client.sync",
  "delivery_id": "STABLE_DELIVERY_ID",
  "purpose": "marketing",
  "client": {
    "id": "CLIENT_ID",
    "name": "Example Client",
    "email": "client@example.com",
    "phone": "+92 300 0000000",
    "email_opt_in": "Yes"
  }
}
```

Headers: `Content-Type: application/json`, `Idempotency-Key: STABLE_DELIVERY_ID`, `X-Khushrang-Delivery: STABLE_DELIVERY_ID`, and optional `Authorization: Bearer ...`.

Only allowlisted contact fields are sent. Invoices, media, internal credentials, and other business data are excluded. The payload is a snapshot at queue time. If the recipient changes, cancel and queue again. The external receiver maps this payload to its contact and email workflow; **queuing a handoff does not itself compose or send an email**. Native Brevo/Mailchimp/GHL/etc. payloads and signature formats are not assumed: use an automation webhook or a provider-specific adapter.

Receiver HTTP 2xx marks the outbound job **accepted**. That means the external system accepted the handoff, **not** that an email reached an inbox. Actual delivery is recorded through the outreach callback. Network/TLS errors, 408, 429, and 5xx retry up to five attempts with delays of 1, 2, 4, and 8 minutes. Other non-2xx statuses fail immediately. Administrators may manually retry failed/retry jobs or cancel pending/retry/failed jobs; accepted/processing jobs cannot be edited this way. Manual retry allows a further attempt without resetting recorded attempt counts.

A worker lease expires after five minutes if a process crashes. Delivery is **at least once**: the receiver must deduplicate the stable delivery ID, including after an ambiguous timeout or worker crash. Response bodies are discarded rather than logged. Status, attempt count, and sanitized errors are available in the panel.

## Admin API actions

These use `api.php`, an authenticated Super Admin or Admin PHP session, and same-origin checks. Temporary-password users must change their password first. Do not use inbound keys for admin actions.

| Method/action | Body / response |
|---|---|
| GET `?action=integration_overview` | Connection metadata and latest 100 rows per activity type; no credentials |
| POST `?action=integration_enqueue` | `{"client_id":"...","purpose":"transactional","idempotency_key":"unique-operation-id"}` |
| POST `?action=integration_retry` | `{"id":"delivery-id"}` |
| POST `?action=integration_cancel` | `{"id":"delivery-id"}` |

Retain an enqueue idempotency key across uncertain retries of the same operation. A new key intentionally creates a new handoff. The panel generates a fresh key for each explicit queue action. Inbound imports do **not** automatically trigger external emails.

## Inbound responses and limits

- 200: duplicate already accepted; 202: new event accepted.
- 400: invalid payload; 401: missing/invalid key; 403: wrong scope/HTTPS required.
- 404: unknown endpoint/client; 409: event-ID conflict or blocked delivery.
- 413: more than 64 KB; 415: incorrect content type; 429: more than 120 valid-format requests per source per minute (Retry-After 60).
- 500/503: configuration/schema/service problem; inspect private server logs and retry with the same event ID after correction.

For authenticated inbound 5xx or network timeouts, retry with backoff and the same bytes/event ID. Do not retry invalid payloads unchanged. Each source's rate limit is shared across its rotated keys. Keep receipt/delivery IDs when troubleshooting; don't log raw bearer tokens or full customer payloads.

## Testing and maintenance

Use disposable data only. The authenticated browser smoke test checks Integrations navigation and verifies that no portal or demonstration entry is mounted before login. `scripts/test-integrations.php` exercises idempotency, consent, suppression, retries, permanent failures, leases, recipient changes, and unsafe URLs with an injected outbound transport. It never sends real emails:

```sh
KHUSHRANG_CONFIG=/private/disposable-config.php INTEGRATION_TEST_ALLOW_MUTATIONS=1 php scripts/test-integrations.php
```

The disposable config needs a nonempty dummy email destination. The test intentionally exercises the queue; do not run it on a production database. `scripts/test-integration-http.mjs` additionally tests real HTTP auth/scopes and the admin endpoints. Its private credential JSON needs `email`, `password`, and `inbound_tokens: {"clients":"raw-test-key","outreach":"raw-other-test-key"}` matching the configured test sources:

```sh
API_TEST_CREDENTIALS_FILE=/private/test.json INTEGRATION_TEST_ALLOW_MUTATIONS=1 node scripts/test-integration-http.mjs
```

Local PHP/MySQL-compatible integration tests and browser tests passed. Real provider connectivity, TLS/authentication, payload mapping, and actual Hostinger cron must still be tested after your provider is configured. No external customer emails were sent during development.

Back up integration tables with the rest of MySQL. Outbound snapshots contain contact data and should follow your retention policy. Keep inbox idempotency receipts for at least as long as upstream systems can replay events; deleting receipts allows old events to apply again. Periodically remove `integration_rate_limits` rows older than one day. Avoid deleting active pending/retry/processing jobs. This version supports one outbound email destination and multiple inbound sources, not an arbitrary workflow designer.

Database credentials and the media path may now be supplied by the private `.env` described in README.md. Connector hashes and email destination configuration remain in private `config.php`; the loader merges both. For dotenv-only cron setup, set `KHUSHRANG_ENV_FILE` to the absolute private file path.
