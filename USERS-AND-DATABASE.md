# Initial database and team access

## Prepare the database first — one SQL file

1. In Hostinger create a **new, empty** MySQL database and a database user with access to it.
2. Open phpMyAdmin, select that database, choose Import, and upload **`database-setup.sql`**.
3. The file creates all application, integration, and account tables, then seeds the initial **Super Admin**. No migration directory, migration runner, stored procedures, or SSH command is required. The SQL does not create the database itself; Hostinger handles that first.
4. Put those database credentials and the absolute private media directory in the private `.env` described in README.md.
5. Deploy the build, open the panel, and sign in using the email and temporary password from **`INITIAL-LOGIN.txt`**. The initial login identifier is `admin@khushrangevents.com`; it does not require a working mailbox. The password is randomly generated for this setup and is stored only in that private file. The SQL stores its bcrypt hash, never its plaintext.
6. The panel immediately requires a new password. Business data and account-management API requests are blocked until that change is complete. Then open **Users & roles** to change your account name/email and create your team.

Keep `INITIAL-LOGIN.txt`, `.env`, and the SQL installer outside `public_html`; none is included in the public Hostinger deployment ZIP. The credentials file is also ignored by Git. Keep it privately until initial setup is complete, then remove the temporary-password copy. The database has no sample business data.

The installer is for **initial setup on an empty database**. It uses `CREATE TABLE IF NOT EXISTS` and never resets an existing owner's password or inserts another default owner when an active Super Admin already exists. Reimporting it after changing your password does not restore the temporary password. It does not alter older existing table structures; do not use it to silently upgrade a populated older schema. Preserve any existing database before changing deployment/database settings.

## Fixed permission levels

| Role | Can read | Can edit | User management | Integrations |
|---|---|---|---|---|
| Super Admin | All business modules | All business modules | Create/edit users, roles, activation, password resets | Yes |
| Admin | All business modules | All business modules | No | Yes |
| Operations | Events, clients, vendors, tasks, media | Those same modules | No | No |
| Finance | Events, clients, invoices, payments, expenses | Invoices, payments, expenses | No | No |
| Viewer | Events, clients, vendors, tasks, media | None | No | No |

Event booking amounts are part of event records and visible to roles allowed to read events. Operations/Viewer accounts cannot access invoice, payment, or expense records. CSV exports contain only data accessible to the signed-in role. Media downloads require media read permission; uploads and deletion require write permission. The overview hides the financial payment chart and collection totals for operational/read-only roles.

These are fixed role presets, not a custom permission builder or per-event/team assignment system. All users see records within their allowed modules; record-level ownership restrictions are not implemented. There is no self-service public signup.

## Add and manage accounts

As a Super Admin open **Users & roles → Add user**. Enter a name, unique email, role, active state, and temporary password. Passwords must be 12–72 bytes long. Give the credentials to the staff member through your normal secure channel. Their first login requires a password change.

Click an account to edit its name, email, role, or active state. To reset a password, supply a new temporary password; leave the password field blank to preserve the existing one. Account/password changes that affect access invalidate the user's other sessions, and disabled accounts cannot log in. A changed email requires signing in with the new email.

Users can change their own password under **Settings → Account security** by entering the current password. This rotates the active session and signs out other sessions. No forgotten-password email flow is included; a Super Admin can reset staff passwords. If every Super Admin loses access, account recovery requires authorized direct database administration rather than a public recovery endpoint.

You cannot disable or demote your own Super Admin account. The API also preserves at least one active Super Admin. Accounts are disabled rather than deleted so ownership/history can be retained. Roles are checked from MySQL on every request; hiding navigation is only the UI convenience, not the permission boundary. Password hashes and session version counters are never returned by user-list endpoints.

## API actions

All mutations require an authenticated same-origin session. Inbound integration API keys cannot manage users.

- `GET api.php?action=me`: current profile, role, permission lists, and password-change requirement.
- `POST api.php?action=password_change`: `current_password` and `new_password`.
- `GET api.php?action=users_list`: Super Admin only; returns safe user fields and role names.
- `POST api.php?action=users_save`: Super Admin only; `id` for edits, `name`, `email`, `role`, boolean `active`, and optional `password`. Password is required for new accounts. Account creation/reset always requires a password change on next login.

## Verification

The standalone SQL was imported into a new local MySQL-compatible database. Tests exercised the SQL-seeded account, forced password change, direct API access for every role, financial filtering, unauthorized escalation, disabling/demoting users, password resets, and protection against disabling/demoting the current Super Admin. Reimporting the SQL was checked to preserve the changed password.

For an independently created disposable database, the test is `scripts/test-users.mjs`; use a private Super Admin test credential JSON, set `INTEGRATION_TEST_ALLOW_MUTATIONS=1`, and provide `API_TEST_CREDENTIALS_FILE`. `INITIAL_LOGIN_FILE` optionally tests the untouched seeded owner; it changes that owner's password in the disposable database. Never run mutation tests on production. Real Hostinger/PHP/MySQL permissions and sessions still require deployment verification.
