# Integrations — manage connections from the panel

Open **Integrations**. It now has four sections: **API tokens**, **Outbound webhooks**, **MCP / AI access**, and **Activity & delivery**. Super Admin and Admin accounts can manage connections. Finance, Operations and Viewer accounts cannot create credentials or change destinations.

## 1. Generate an API token

1. In **API tokens**, click **Create API token**.
2. Give the connection a name, a lowercase identifier such as `website`, an expiry, and permission to receive **clients**, **outreach**, or both.
3. Click **Generate token**. Copy the token from the one-time display and store it in the sending system's secret store.
4. Use the displayed endpoint URL and the header `Authorization: Bearer YOUR_TOKEN`. Keep the token out of public website JavaScript and URLs.
5. Check **Activity & delivery → Inbound** for accepted calls. Last-used and expiry information are shown in the token list. Revoke stops subsequent calls immediately.

You no longer need to manually generate a hash or edit config.php to create a new connection. Existing private-file connectors still work and are labeled as file-managed. To rotate a panel token, create a replacement with the same identifier **under the same owner account**, switch the caller, and revoke the old token. Tokens also stop working when their owner is disabled or the owner's email/password/role/access version changes. They expire after the selected 1–365 days (default 90).

### Receive client data

POST to the **Receive clients** URL shown in the panel (`integrations.php?action=clients`). Set `Content-Type: application/json` and the bearer header:

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

Name, valid email, event ID, and external ID are required. Phone is optional. Consent is a JSON boolean; omission never enables marketing. Stable `(source, external_id)` updates the same imported client. No automatic email is sent by receiving a contact.

HTTP 202 means a new event was accepted; 200 with `duplicate:true` means it was already accepted. Retrying must retain the same event ID and exact request bytes. Different content or another endpoint with the same event ID returns 409. A genuine update uses a new event ID and the same external ID. Keep the returned `client_id` for callback correlation. Imports do not merge unrelated manual contacts by email; changes are applied in receipt order.

### Receive outreach updates

POST to the **Receive outreach status** URL (`integrations.php?action=outreach`) using an outreach-scoped token:

```json
{
  "event_id": "provider-event-90841",
  "client_id": "CLIENT_ID_FROM_THE_IMPORT",
  "recipient_email": "client@example.com",
  "message_id": "provider-message-6381",
  "status": "delivered",
  "occurred_at": "2026-10-25T09:30:00Z"
}
```

All fields are required. Status may be `queued`, `sent`, `delivered`, `opened`, `clicked`, `replied`, `bounced`, `unsubscribed`, or `complained`. Timestamps use RFC3339 with seconds and timezone; values over five minutes in the future are rejected. Use the address of the original recipient, so an old bounce cannot suppress a newly changed address. The client must exist.

Bounces, unsubscribes, and complaints suppress that recipient from outgoing contact handoffs. Late open/delivery events and later contact imports do not remove suppression. This version treats `bounced` as permanent; map provider soft bounces appropriately. There is no automatic resubscription mechanism.

The website's own backend/automation should forward forms with the secret token. These endpoints do not offer public-browser CORS or expose the token in a frontend form. Requests are limited to 64 KB and 120 valid-format requests per source per minute; 429 includes Retry-After 60. Database/application setup failures return the safe diagnostic codes described in README.md.

## 2. Add and test outbound webhooks

1. In **Outbound webhooks**, choose **Add webhook**.
2. Enter a name and a complete HTTPS receiver URL. Add an optional receiver bearer credential. URL query strings are supported for receivers that require them.
3. Save. The URL is structurally checked without sending a request. Only public destinations are allowed; DNS and public IP validation are repeated and pinned when delivering.
4. Choose **Test connection**. This sends only an `integration.test` message with a delivery ID and timestamp, not customer data. The result shows HTTP status or an actionable connection/TLS/DNS/extension error.
5. Edit or disable the receiver from its card. Leaving the bearer field blank preserves the stored credential; the explicit removal checkbox clears it. Secrets are never redisplayed. Changes clear the old test result and require a new test.

You may configure multiple named webhooks. Existing private-file email configuration remains available as the legacy destination. To manage its replacement through the UI, add a named webhook and choose it when sending.

Receiver URLs and bearer credentials are AES-256-GCM encrypted in the database. The first webhook save creates **`integration.key` beside the private .env** (outside public_html); PHP must be able to create that file once. Keep its permissions private and **back it up with MySQL and .env**. Losing it makes saved webhook credentials unreadable. It is excluded from Git and deployment bundles. API/MCP tokens store only hashes and do not require this encryption key.

### Send a contact and see failures

In **Activity & delivery**, choose a client, a webhook and a transactional/marketing purpose, then **Send now**. The panel queues and processes that specific job immediately; cron is not required for this manual action. The outgoing JSON is:

```json
{
  "event": "client.sync",
  "delivery_id": "STABLE_DELIVERY_ID",
  "purpose": "transactional",
  "client": {
    "id": "CLIENT_ID",
    "name": "Example Client",
    "email": "client@example.com",
    "phone": "+92 300 0000000",
    "email_opt_in": "No"
  }
}
```

A destination version is also included for internal delivery consistency. Headers include Content-Type, Idempotency-Key, X-Khushrang-Delivery, and the optional bearer credential. The receiver maps this contract to its own email/contact workflow. **A 2xx acceptance is not proof of email delivery**; actual delivery comes through outreach callbacks. The connector does not compose email content or automatically map every provider's native API.

The client must be active, have a valid email, and not be suppressed. Marketing additionally requires Marketing email consent = Yes. Eligibility is rechecked immediately before sending. Changes to the client email block the old snapshot. Changing/disabling a webhook blocks queued snapshots for its former configuration; queue a new job after reviewing the new receiver rather than silently redirecting customer data.

Attempts, HTTP codes and sanitized failure reasons are visible under Outbound activity. Response bodies are discarded. Network/TLS errors, 408, 429 and 5xx automatically retry up to five attempts with 1/2/4/8-minute delays when a worker runs. Other 4xx errors fail directly. Manual Send now can retry a failed/retry job; accepted jobs cannot be resent through that action. Cancel stops queued jobs. At-least-once delivery is possible after a timeout/crash: the receiver must deduplicate the stable delivery ID.

### Configure automatic retries in Hostinger

Expand **Set up automatic retries in Hostinger** on the Activity page and copy the command generated for your actual server path. Create an hPanel cron job running that PHP command **every minute** using PHP 8.1+.

The updated deployment includes `integration-worker.php` alongside the application PHP files. It is **CLI-only and returns 404 through HTTP**. No separate Node service or private worker dependency copying is required. If you configured an older cron job pointing to a private copy, replace it with the new command. New jobs use isolated queue states so an obsolete worker cannot send them to an old destination. The new worker handles current configuration, batches up to 20 jobs, reclaims expired leases and reports its last run to the panel.

The worker also removes rate buckets older than one day and MCP audit entries older than 90 days. Idempotency receipts are retained so old writes cannot be silently replayed. Stop obsolete workers; do not run outdated and new application code together.

## 3. Connect an AI client with MCP

See **[MCP.md](MCP.md)** for token scopes, client configuration, tools, batch imports, file handling, and compatibility. The **MCP / AI access** page displays the URL, creates the token, offers a one-click initial connection test, and supplies Claude Code/JSON configuration examples.

## Deploy this update

Replace the public build, including all PHP helper files, `mcp.php`, CLI worker, and favicon. **No SQL reimport or new tables are required**: connections, encrypted settings, audit and operation receipts use the existing records table. Keep .env, integration.key, MySQL and media in place. If a custom .htaccess exists, merge the included bearer-header forwarding block with your rules rather than overwriting them.

Webhooks require PHP cURL and OpenSSL; the UI reports missing cURL or key-file errors. Native MCP/API use bearer Authorization headers. The build includes an Apache/LiteSpeed forwarding block; if a valid token still returns 401, check that your host preserves that header instead of putting the token in a URL.

## Verification

Real local API and browser tests cover token creation/revocation, scope boundaries, expiry/owner changes, credential non-disclosure, named webhook creation/edit/disable, inline failures, private-key encryption, destination-version binding, queue isolation, and mobile layout. Outgoing delivery uses an injected transport in tests: **no real provider/customer emails were sent**. The official MCP SDK performs real local initialize/discovery/tool calls; those are not mocked. Validate actual Hostinger header forwarding, extensions, receiver DNS/TLS, cron, and provider mapping after deployment.
