# Khushrang MCP / AI access

This is a real PHP **stateless Streamable HTTP MCP server**, exposed at the URL shown in **Integrations → MCP / AI access** (normally `https://admin.khushrangevents.com/mcp.php`). It needs no Node.js process on Hostinger. Node and the official MCP SDK are used only for development/testing.

## Create your connection

1. Sign in as a Super Admin or Admin and open **Integrations → MCP / AI access**.
2. Choose **Create MCP token**. Name the connection and give it a stable identifier.
3. Select readable modules. Read-only is the default. Explicitly grant write/upload or delete rights only where desired. Write requires read; delete requires write. You cannot grant more than your own account allows.
4. Generate the token, copy it once into the AI client's secure configuration, and copy the MCP URL. **Test MCP connection** in that one-time window performs a real initialize request against your server.
5. After closing the window, only metadata is available. To replace a lost token create a new one and revoke the old one. Use the same identifier and owner for stable import IDs across rotations.

Tokens expire on the chosen date and can be revoked immediately. Owner disablement, password/email/role/access changes invalidate previously created tokens. Tokens never grant user-account administration, integration settings, credentials, raw SQL, shell access, or external email sends.

## Claude Code

Use a client supporting HTTP MCP with a custom Authorization header. Set `KHUSHRANG_MCP_TOKEN` in your local client environment through a secure mechanism, then run the command copied from the panel, for example:

```sh
claude mcp add --transport http khushrang https://admin.khushrangevents.com/mcp.php --header "Authorization: Bearer $KHUSHRANG_MCP_TOKEN"
```

The panel also provides this Claude Code-style JSON configuration:

```json
{
  "mcpServers": {
    "khushrang": {
      "type": "http",
      "url": "https://admin.khushrangevents.com/mcp.php",
      "headers": {"Authorization": "Bearer YOUR_MCP_TOKEN"}
    }
  }
}
```

Adapt field names to your client's documented configuration. The endpoint is not a browser login page. OAuth-only web connectors need a separate OAuth gateway; **this implementation does not provide OAuth discovery, authorization-code login, or legacy HTTP+SSE transport**. Do not paste a private MCP token into public code or URL parameters.

## Available tools

Tools are filtered to the token's scope, and every call rechecks scopes and the current owner role on the server.

| Tool | Function |
|---|---|
| workspace_status | Show permitted modules/actions; no secrets |
| list_records | Search/page through authorized events, clients, vendors, invoices, payments, expenses, tasks or media (up to 100/page) |
| get_record | Read an individual record, including itemized invoice details and its revision/version |
| save_record | Create or partially update ordinary business records |
| import_records | Atomically import up to 50 JSON rows with stable external IDs or existing IDs/revisions |
| get_invoice_template | Read invoice defaults without logo image data |
| save_invoice | Create/edit itemized invoices through the same calculator, numbering and linked-advance logic used by the panel |
| upload_media | Store a PDF, JPEG, PNG, WebP or MP4 from base64 (up to 5 MB decoded) |
| read_media | Read a private PDF/image as an embedded resource (up to 2 MB), or fetch base64 chunks up to 256 KB |
| financial_summary | Summarize one currency's payments, expenses, cash and open invoices; needs all three financial read scopes |
| delete_record | Delete an authorized record only with a separate delete grant, latest revision and confirm=true |

The server exposes **business data**, not an unrestricted computer. Tool data can contain untrusted customer/vendor text; it must be treated as data rather than instructions. Any human confirmation prompts are controlled by the AI client; granting a write/delete token authorizes those server actions within scope.

### Import client/vendor/finance lists

Ask the AI to parse your CSV or spreadsheet in its own environment and map rows to the tool schema (`name`, `email`, `phone`, `type`, `date`, `amount`, `currency`, `status`, etc.). The server does not execute spreadsheets or infer financial values. Import up to 50 rows at a time, with one new operation ID per batch. An invalid row rolls back the whole batch.

```json
{
  "kind": "vendors",
  "operation_id": "vendor-import-2026-10-25-batch-1",
  "records": [
    {"external_id":"vendor-001","fields":{"name":"Example Florist","type":"Florals","status":"Preferred"}},
    {"external_id":"vendor-002","fields":{"name":"Example Caterer","type":"Catering","status":"Active"}}
  ]
}
```

Stable external IDs are namespaced by module, connection identifier and owner. A genuine update to an existing record requires its ID and latest `_revision` from get_record. Generated invoices use their `version` and save_invoice instead. Name is required on creation; dates are required for events/tasks/financial records, and financial records require nonnegative amounts with up to two decimals. Invoice creation defaults to the current Pakistan business date, the shared default tax, and zero paid when omitted.

### Avoid duplicates and stale edits

Every mutation needs a stable `operation_id`. If a response is lost, retry the **same ID and arguments**. Do not generate a new operation ID just because a request timed out. A different action must use a different ID. Reusing an ID for different arguments returns a conflict.

Receipts store only record IDs/revisions and minimal result metadata, not uploaded bytes or full contact/invoice snapshots. A replay returns these IDs with `duplicate:true`; use get_record to fetch current details. Narrower tokens cannot retrieve former broader-operation results. Receipts do not recreate a deleted record. Keep receipt metadata while callers can replay old operations.

Updates require `expected_revision`; stale/missing revisions fail instead of overwriting concurrent edits. For generated invoice edits send `id` and `version`, and only the invoice fields you want to change. The server preserves company/logo defaults or the existing invoice snapshot; MCP cannot alter shared bank/template settings. Advance amounts reconcile one linked receipt. Managed receipts cannot be edited independently.

### Upload invoice files

Upload_media archives the original file in private storage. It does **not** perform OCR or automatically turn a PDF into ledger entries. The AI can read the document and call save_invoice/import_records with the interpreted values, then retain the PDF through upload_media. Use distinct operation IDs for uploading and creating the invoice.

Media paths are never exposed or user-selectable. File MIME is checked, names are randomized, and read/delete requires media scope. Existing normal panel uploads still support their own 25 MB limit; the MCP limit is lower to bound JSON/base64 requests.

## Protocol and operations

The server implements initialize, initialized notifications (202/no body), ping, tools/list and tools/call. It negotiates `2025-11-25`, `2025-06-18`, or `2025-03-26`, with single JSON-RPC request bodies and JSON responses. Clients send `Accept: application/json, text/event-stream`, Content-Type application/json, and the negotiated MCP-Protocol-Version after initialization. No session ID or persistent daemon is required. GET/SSE and DELETE return 405 after authentication; this is expected for the stateless transport, not a broken URL. JSON-RPC batches are not accepted.

HTTPS is required except local loopback. Foreign Origin headers are rejected. A maximum request body of 8 MB and 120 requests/minute per token apply. Authorization checks occur before operation receipt lookups. The service has no arbitrary SQL, URL-fetch, path-read or shell tools. Calls are recorded as metadata under **Activity & delivery → MCP calls**; full arguments, files and tokens are not logged. The retry worker trims audit entries older than 90 days.

The public deployment includes mcp.php and its guarded helper files. .env and integration.key remain private. No database import/update is necessary for an existing panel.

## Tests and limits

`scripts/test-mcp.mjs` uses the **official MCP client SDK** against the actual PHP/MySQL-compatible instance. It tests negotiation/discovery, scope boundaries (including narrower rotated credentials), idempotency, revisions, atomic imports, pagination, invoice generation, embedded media/chunks, audit, revocation and origin checks. `test-connections-ui.cjs` verifies that UI-generated credentials really work and selected scopes are enforced. Use disposable data only.

Actual Claude Code installation, your live Hostinger header forwarding/TLS, and external provider connectivity must still be verified after deployment. The application supports its existing business modules; it does not add audited accounting, autonomous email campaigns, general code execution, or custom per-record ownership rules.
