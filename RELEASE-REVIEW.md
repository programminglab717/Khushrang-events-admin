# Khushrang release review — updated 25 October 2026

Status: locally verified first release, ready for UI feedback and Hostinger deployment verification. No production deployment or real email delivery was performed.

## UI previews

All screenshots show sample data, not customer information.

- [Desktop dashboard](docs/ui-review/dashboard-desktop.png)
- [Login screen](docs/ui-review/login-desktop.png)
- [Mobile dashboard](docs/ui-review/dashboard-mobile.png)
- [Users and role guide](docs/ui-review/users-desktop.png)
- [Integrations](docs/ui-review/integrations-desktop.png)

![Desktop dashboard](docs/ui-review/dashboard-desktop.png)

## Checks completed

| Check | Result |
|---|---|
| Clean lockfile installation and TypeScript/Vite production build | Passed |
| Dependency audit (`npm audit`) | Zero reported vulnerabilities at review time |
| Desktop/mobile smoke: CRUD, persistence, search, CSV, navigation, tasks | Passed |
| Mobile menu button, backdrop, Escape dismissal and page overflow | Passed |
| Actual production-build browser login, user creation and forced password change | Passed |
| Server permissions for Super Admin, Admin, Operations, Finance, Viewer | Passed |
| Financial data filtering and direct-API privilege escalation attempts | Passed |
| Account disabling, demotion, password resets and session invalidation | Passed |
| Session restoration after reload, HttpOnly/SameSite cookies, simulated idle expiry | Passed |
| Single SQL installer imported into a fresh MySQL-compatible MariaDB database | Passed |
| SQL-seeded owner login and mandatory password replacement | Passed |
| Reimport installer after password change preserves owner credentials | Passed |
| Business API: seven CRUD modules, validation, authenticated media upload/read/delete | Passed |
| Inbound integration keys, scopes, idempotency, callbacks, suppression | Passed |
| Outbound queue/retry/lease handling with mocked transport | Passed; no external emails sent |
| Dotenv parsing, special-character passwords, precedence and legacy compatibility | Passed |
| Upcoming-event date/status filtering and six-month charts separated by year | Passed |
| Invoice print layout | Generated a one-page A4 PDF; editor hidden |
| Public deployment archive | Verified to exclude `.env`, initial credentials, SQL and private worker |

During review the mobile navigation gained explicit close/backdrop/Escape controls. The chart now follows the latest six months in the business timezone rather than a hard-coded range, and past/completed/cancelled events are excluded from the upcoming list. Regression tests are in `scripts/smoke.cjs` and `scripts/test-dashboard.cjs`.

## Deployment handoff

1. Create a new empty Hostinger database and import `database-setup.sql` manually in phpMyAdmin. There is no migration runner.
2. Fill the private `.env` with Hostinger database credentials and the absolute private media directory. Keep it outside public_html.
3. Upload the contents of `dist/` (or extract the supplied `khushrang-hostinger.zip`) into the panel's public folder. Do not publish the SQL installer or `INITIAL-LOGIN.txt` there.
4. Sign in with the private initial credentials, change the password, and add staff under Users & roles.
5. On Hostinger verify HTTPS, PHP extensions/session storage, actual MySQL connection, media permissions, and authenticated downloads. Check that a frontend replacement preserves uploads, and test database/media backups.
6. For outbound email integration configure the real receiver privately and schedule the worker in Hostinger cron. Test receiver authentication, payload mapping and idempotency with a test contact before enabling its email workflow.

Hostinger-specific checks and the actual external email service remain outstanding because this review ran locally. The provider-neutral connector transfers contact data; composing/sending the email is configured in the receiving system. This release has fixed role presets, single-total invoices and a simple client-based collection estimate; advanced tax/accounting, custom permissions, audit history, and event-linked media albums remain outside the current implementation. See README.md, USERS-AND-DATABASE.md, and INTEGRATIONS.md for the detailed setup and scope.

## Private-login correction — 25 October

The earlier overlay/demo entrance has been removed. The entire unauthenticated page is now white, with only the sign-in form, inline errors, and branding. Dashboard components are not mounted while checking a session, after a failed login, after logout, or during mandatory password replacement. Public demo access and localStorage business records have been removed from both development and production.

The user identified an incorrect database name in the deployed configuration. Correcting DB_NAME in the private .env is required; the public folder name does not need changing. The backend now supports multiple standard Hostinger folder depths and returns safe, specific configuration/database error codes instead of one generic error. Actual HTTP tests reproduced missing configuration, rejected MySQL access, missing database, and invalid storage paths. The standalone login, failed-login isolation, mobile layout, authenticated CRUD/session restoration, role restrictions, integrations, and configuration-discovery tests passed locally. Live-host validation still depends on the corrected deployed .env and updated public files.
