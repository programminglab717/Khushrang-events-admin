# Khushrang · Business Studio

The UI follows the Khushrang website palette: deep teal `#004c43`, warm cream `#fff4df`, and magenta `#a20d49`, with a persistent left navigation panel and responsive mobile navigation. Brand styling is centralized in `src/theme.css`.

A responsive React + TypeScript admin panel, built to static files with Vite. Node is needed **only for development/building**, never on Hostinger. A PHP 8.1+ API connects to Hostinger MySQL. Direct MySQL access from browser JavaScript would expose credentials, so the PHP layer is required.

## Local frontend

Requires Node 22+ and npm. Use the existing checkout; cloud tasks are already isolated and do not need worktrees.

```sh
npm ci
npm run dev
npm run build
npm run preview
```

Open the development server locally. The panel defaults to a clearly labeled demo workspace; sample edits persist in this browser's localStorage. Demo records are never imported into the live database. The frontend can be built and explored without PHP or credentials.

Included: overview with calculated payments/expenses chart, event/order pipeline, client directory, vendor directory, invoices, payment and expense records, task checklists, search/status filtering, CSV exports, CRUD forms, and backend-enabled media uploads. PKR is the current currency. Each invoice is a single amount record; multi-line accounting, tax calculations, email delivery, audit logs, event-linked galleries, and customer payment processing require further work. Payment allocations are currently by client rather than invoice; overview collections are an estimate, not an accounting ledger. Live accounts have server-enforced Super Admin, Admin, Operations, Finance, and Viewer roles. See USERS-AND-DATABASE.md.

## Database and media configuration with `.env`

A local `.env` template is provided and ignored by Git; `.env.example` is the shareable template. Fill in `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`, and `STORAGE_PATH`. `STORAGE_PATH` must be an **absolute server filesystem path**, not a website URL. Never use `VITE_` prefixes for database credentials: these are read only by the PHP backend, never by browser JavaScript.

On Hostinger place the real file at `~/domains/YOUR_DOMAIN/khushrang-private/.env`, **outside `public_html` and outside the admin deployment folder**. The default `/admin/` layout discovers it automatically. Restrict its permissions (typically 600). `.env` alone supplies the database and storage settings; `config.php` is optional unless you need its integration connector/destination settings. If both exist, `.env` database/storage values override those PHP values while preserving integration settings. Actual server environment variables take precedence over `.env`.

For a custom layout, set `KHUSHRANG_ENV_FILE` in the server/cron environment to the absolute `.env` path. The default `.env` sits beside the private `config.php` path; setting `KHUSHRANG_CONFIG` therefore also controls its default directory. Neither file is included in the deployment ZIP. Do not upload your local `.env` into `dist/` or `public_html`.

Use single quotes for literal passwords containing `#`, `$`, spaces, backslashes, or double quotes. Double-quoted values support escaped backslashes, quotes, newline, carriage return, and tab. There is no variable interpolation. Put comments on separate lines when using quoted values.

For local PHP development, fill the local `.env`, then run the built frontend/API with:

```sh
KHUSHRANG_ENV_FILE=/absolute/path/to/Khushrang-events-admin/.env php -S 127.0.0.1:8080 -t dist
```

If copying the cron helper to a private directory, also copy `server/config-loader.php` beside it. Run `php scripts/test-config.php` to test configuration parsing without a database or real credentials.

## Hostinger layout

Example for a panel deployed at `https://YOUR_DOMAIN/admin/`:

```
~/domains/YOUR_DOMAIN/
  khushrang-private/          # outside public_html AND panel deployment/repository
    .env                     # database/storage settings; never in Git
    config.php               # optional legacy config / integration settings
    media/                   # durable private uploads; never replaced by deployment
  public_html/
    admin/
      index.html             # contents of dist/
      assets/
      api.php                # copied from server/api.php
```

The API resolves configuration at `dirname(__DIR__, 2)/khushrang-private/config.php`, matching this layout. For a different layout set the server environment variable `KHUSHRANG_CONFIG` to an absolute private path or adjust the deployment copy of the lookup. Do not place configuration in a publicly reachable folder. Media is intentionally filesystem storage **outside Git**, not a repository of private client photographs. Updates replace only `public_html/admin/`. Back up the private media directory separately.

1. Enable PHP 8.1+ with `pdo_mysql`, `fileinfo`, and `mbstring`. Enable HTTPS. Set `upload_max_filesize=25M`, `post_max_size=28M` or higher; the API enforces a 25 MB file limit. Ensure PHP session storage is writable.
2. Create a dedicated NEW EMPTY MySQL database/user in Hostinger. In phpMyAdmin select that database and import `database-setup.sql`. This single file creates every table and the initial Super Admin. No migration files, migration runner, or SSH command are needed. Business tables start empty.
3. Create `khushrang-private/media/`; make it writable by the PHP account only (typically directories 700 and configuration 600; adjust ownership for Hostinger's PHP account). Copy `.env.example` to private `.env` and enter database credentials and the absolute storage path securely there. Optionally copy `server/config.example.php` to private `config.php` for integration settings; `.env` overrides its database/storage values. Keep error logs private.
4. Read the private `INITIAL-LOGIN.txt` supplied with this setup for the initial Super Admin email and temporary password. The SQL contains only its hash. Sign in and complete the mandatory password change, then open Users & roles to add your team. Keep the credentials file and SQL installer outside public_html. No CLI provisioning is required.
5. Run `npm ci && npm run build` locally. Upload **contents** of `dist/` to `public_html/admin/`; the build already includes `api.php`. Do not upload source configuration, SQL, or the provisioning helper. Relative assets support subfolder hosting without rewrite rules.
6. Open the panel over HTTPS. In Settings choose **Sign in to live workspace**. Live data starts empty. Add a client and event; reload/reconnect to verify persistence. Add an invoice, received payment, expense, and task. Test edits, status filters, and CSV exports.
7. Upload a small JPEG and verify it opens while signed in. Sign out and confirm a direct media URL returns 401. Upload another file, deploy a fresh frontend build, reconnect, and verify both files remain. Back up and restore MySQL plus private media together before real business use.

Authentication uses PHP sessions with HttpOnly / SameSite Strict cookies, session rotation, a one-hour idle expiry, a shared database login rate limit, and same-origin checks on mutations. All CRUD and file reads require authentication. Uploaded filenames are randomized, MIME types checked, and files served through the authenticated API. Supported media: JPEG, PNG, WebP, MP4, PDF. No public gallery URLs. Do not enable permissive CORS. Keep Hostinger/PHP patched and test backups. Simultaneous edits use last-write-wins; a future version should add optimistic locking and audit history before large-team use.

## Development with PHP + MySQL

Import `database-setup.sql` into a new disposable MySQL database and configure the private path. Build the frontend, use the PHP files included by the build, then run:

```sh
KHUSHRANG_CONFIG=/absolute/path/config.php php -S 127.0.0.1:8080 -t dist
```

Visit `http://127.0.0.1:8080` (local loopback is allowed without TLS). Do not run Vite and the PHP API on different origins without an explicitly configured development proxy. The API does not support cross-origin cookies. Never use production credentials or private customer data for local demos.

## Validation status

See the delivery notes for checks executed in the build environment. A frontend build alone does not verify MySQL, PHP sessions, uploads, or Hostinger configuration. Run the deployment checklist on the actual host before using live data.

## Browser smoke test

With the frontend dev server running, run `npx playwright install chromium` once and `npm run test:smoke`. To use system Chromium: `CHROMIUM_PATH=/usr/bin/chromium npm run test:smoke`. `TEST_BASE_URL` overrides the default dev server URL. The test uses a fresh browser context and only changes local demo data. It covers CRUD, persistence, search, CSV, navigation, task completion, and mobile overflow/navigation.

## API integration test

Only against a **disposable database**: create a Super Admin test account and complete its first-login password change, place `{"email":"…","password":"…"}` in a private JSON file outside the repository, then run `API_TEST_CREDENTIALS_FILE=/private/test-credentials.json node scripts/test-api.mjs`. `API_TEST_URL` overrides the loopback PHP server default. This creates and deletes test business records and files; do not point it at production. The test covers authentication, same-origin enforcement, seven CRUD record types, invalid amounts, file MIME checks, authenticated downloads, deletion, and logged-out access denial.

Verified in this environment: production TypeScript/Vite build; Chromium desktop/mobile smoke tests; PHP 8.4 syntax; API integration against a disposable MariaDB 11.8 database using the MySQL protocol. The production build served by PHP also passed a browser login and MySQL-backed client CRUD check. Actual Hostinger MySQL, TLS, directory permissions, PHP configuration, backups, and deployment remain to be verified on your hosting account.

## External integrations

See [INTEGRATIONS.md](INTEGRATIONS.md) for authenticated inbound client/outreach endpoints, the outbound email-system queue, consent/suppression handling, retry jobs, payloads, and Hostinger cron setup. The complete `database-setup.sql` includes integration and account tables; no migration files or runner are used. The frontend build includes the public PHP integration files. Keep the cron worker and credentials private.

## Team accounts and initial database

See [USERS-AND-DATABASE.md](USERS-AND-DATABASE.md) for the single-file SQL import, initial login, fixed role permissions, account creation, password reset, and session revocation. The private `INITIAL-LOGIN.txt` is excluded from Git and deployment bundles. Never upload it or the SQL installer into the public panel folder.
