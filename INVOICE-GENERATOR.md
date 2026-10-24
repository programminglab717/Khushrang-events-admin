# Integrated Khushrang invoice generator

The supplied HTML invoice generator has been adapted into the authenticated React panel. Its business fields, item editor, tax, paid/advance amount, payment methods, notes, logo, template backup format, and A4 invoice layout are preserved. The editor uses the panel's branding; the printable document retains the original clean invoice layout.

## Generate and save

1. Open **Invoice generator** in the left navigation, or **Invoices → Add invoice**.
2. Expand **Template setup** to enter your business information, logo, invoice prefix/next number, currency, default tax, notes, and payment methods. Choose **Save template** to share those defaults with the team.
3. Enter the client (or select a saved client name), optional phone/event text, invoice/due dates, and items with quantities and rates. Set tax and the amount paid/advance if applicable.
4. Choose **Save invoice**, or **Print / save as PDF**. Printing saves first and stops if saving fails. Your browser's print dialog provides Save as PDF.
5. The saved record appears automatically in **Invoices** with its number, total, status, and outstanding balance. Click it to reopen the complete generator. Refreshing the browser preserves the saved details because they are stored in MySQL.

A new invoice uses a server-allocated sequential number. If two people save from the same preview, the server assigns distinct numbers; the preview number is provisional until save. Reprints/edits retain the saved number. Retrying the same request does not duplicate the invoice, and stale concurrent edits are rejected rather than overwriting another person's changes. New invoice starts a separate record; it does not duplicate the previous one.

## Payments and reporting

The generator creates **one linked payment receipt** when Amount paid is greater than zero, dated with the invoice date. Changes to the advance update that same receipt; setting it to zero removes it. Do not manually enter a second receipt for the same advance. Clicking the managed receipt in Finances opens the linked invoice; the generic payment editor cannot overwrite/delete it separately.

The server recomputes line amounts, subtotal, tax, total and balance. Quantities support three decimal places, rates/paid amounts two, and tax percentages three. Tax is one percentage on the subtotal. Full payment marks the invoice Paid automatically (unless cancelled). Draft/Sent/Overdue/Cancelled are selectable; there is no automatic overdue scheduler or jurisdiction-specific tax engine.

Amounts in other three-letter currency codes remain in that currency. The panel's PKR summaries/charts include PKR records only; they do not convert or add foreign-currency amounts to PKR. Generated invoice balances are used directly in the outstanding-collections summary. Existing basic invoice records remain editable in their original form, and are not silently converted.

Use Cancelled when you want to retain a cancelled invoice. Delete invoice removes both the invoice and its managed receipt after confirmation. Previously used invoice numbers remain reserved and are not reused. Cancelling an invoice does not invent a refund; any actual refund/accounting adjustment remains a separate business operation.

## Templates, logos, and backups

Templates are stored in the existing MySQL `records` table, not browser localStorage. Each saved invoice keeps its own template snapshot so future business/logo/notes changes do not change historical invoices. Template changes can be saved for future invoices; editing an invoice snapshot alone does not overwrite the shared template.

Export backup downloads the original `khushrang-invoice-template.json` field format. Import backup accepts that format from your standalone generator, loads it into the editor, and requires Save template to apply it to the shared database. It is a template backup, not an invoice/database backup. Invoice history requires the normal MySQL/media backups.

Logos accept PNG, JPEG or WebP up to 200 KB and 4096×4096 pixels. SVG/external logo URLs are rejected. The template counter cannot move backwards for an unchanged prefix; when importing an old template, adjust its next number to the current available sequence. A new prefix must be saved before issuing new invoices under it.

## Access and deployment

Super Admin, Admin and Finance roles can use the generator. Operations and Viewer accounts cannot read or save invoices or their templates. The generator remains behind the private login; its API checks invoice permissions independently of the interface.

**No database schema change or SQL reimport is needed.** Invoice details, the shared template and number reservations use the existing records table. Replace the deployed public build, including `invoice-generator.php` and `api.php`, and keep your private .env/database/media in place. The deployment ZIP contains the updated PHP file automatically.

## Super Admin protection

In Users & roles, the last active Super Admin's Role and Active controls are disabled with an explanation. Your own Super Admin controls are also locked even if there are other owners. The API independently rejects disabling/demoting the last active owner or your own Super Admin account. Other profile fields remain editable, so saving a profile does not accidentally submit a disabled field as inactive.

## Validation

`test-invoices.mjs` checks server calculations, create/edit idempotency, optimistic version conflicts, numbering, linked advances, paid status, foreign-currency labels, malformed data/logos, and deletion cleanup. `test-invoice-ui.cjs` checks generation, automatic listing, reopening/editing, refresh persistence, template export, mobile layout, a one-page PDF, and locked Super Admin controls. `test-owner-ui.cjs` verifies the exact single-active-owner case. Use a disposable database and the documented private test credentials only. Actual invoice delivery/emailing and a full accounting/tax system are not added by this integration.
