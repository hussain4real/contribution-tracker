# FamilyFund Product Completion Roadmap

**Status:** Phase 1 through Phase 3 implemented; Phase 4 through Phase 9 planned
**Last updated:** 2026-07-14

## Summary

Deliver the missing capabilities as nine independently deployable phases. The sequence first fixes tenancy and financial integrity, then builds reporting, reconciliation, campaigns, offline support, and organization features on that foundation.

Plan entitlements:

- All plans: membership isolation, category history, contribution register, immutable financial records, reversals, audit trail, and core offline entry.
- Family: CSV/PDF reports, receipts, member statements, payment proofs, and disputes.
- Growth: bank reconciliation, campaigns, scheduled reports, and Paystack autopay.
- Organization: expense approvals, branches, custom roles, and permissions.

Use additive migrations, Laravel policies, Form Requests, domain actions, Inertia v3 with Wayfinder, queued idempotent jobs, private file storage, and Pennant-backed plan gates.

## Implementation Status

- Phase 1 is complete: family membership is canonical for role, category, display name, and lifecycle; category history and contribution snapshots are live; invitations and managed accounts use family categories; compatibility fields are mirrored during the transition.
- Phase 2 is complete: payment batches, contribution allocations, sequential receipts, immutable financial entries, linked corrections and reversals, audit events, effective-ledger reads, and verified/idempotent Paystack settlement are live.
- Phase 3 is complete: the shared contribution register, reconciled report suite, private CSV/PDF artifacts and receipts, member statements, scheduled email/WhatsApp delivery, and 30-day archive/restore/export/purge lifecycle with platform legal holds are live.
- The additive migrations and chunked compatibility backfill have been applied locally. A replay completed with zero changes, confirming idempotency.
- The full repository quality gate passes with PHPStan, Pint, Prettier, ESLint, client and SSR production builds, 1,198 tests, 5,602 assertions, and 100.0% coverage.
- Phase 4 through Phase 9 remain planned and are not included in the current implementation slice.

## Implementation Phases

### Phase 1 — Membership and Category Foundation

- Make `family_members` the canonical source for family role, category, display name, and lifecycle.
- Add membership-level `display_name`, `archived_at`, `archived_by`, and `archive_reason`. Reserve `users.archived_at` for platform-wide account disabling.
- Family administrators may update membership attributes only; users retain control of their global name and email.
- Replace legacy category usage with `family_category_id` throughout member forms, reports, contribution generation, and filters.
- Add category assignment history with effective start and end dates. Contribution generation resolves the category active for the contribution period.
- Snapshot category ID, name, slug, and expected amount on each contribution so later category edits do not rewrite history.
- Correct category member counts and deletion guards to query memberships rather than global users.
- Keep legacy user family, role, and category fields temporarily as compatibility mirrors, backfill in chunks, switch reads, and remove them only in a later contract deployment.
- Support invitation onboarding plus existing managed accounts; managed accounts must change their temporary password at first sign-in.
- Require active family membership authorization for every family route, action, queued job, export, and file download.

**Acceptance:** One user can belong to multiple families, be archived in one without affecting another, and have different roles, categories, and display names in each.

### Phase 2 — Immutable Financial Ledger and Audit Trail

- Introduce `payment_batches` as the canonical money-in receipt, containing family, membership, amount, date, method, source, reference, recorder, Paystack reference, notes, receipt number, and idempotency key.
- Retain `payments` as contribution-allocation lines linked to a batch. Create batches and allocations atomically under database locks.
- Backfill each legacy payment row into its own batch because historical submissions cannot be grouped reliably.
- Separate Paystack transaction states into initiated, verified, allocated, failed, and reversed. A callback or webhook may not mark funds posted until verification and allocation both succeed.
- Verify webhook signatures, acknowledge quickly, and dispatch idempotent processing jobs, following Paystack's [webhook guidance](https://paystack.com/docs/payments/webhooks/).
- Add append-only `financial_reversals` and `audit_events`. Posted payments, expenses, and adjustments cannot be edited or deleted; correction reverses the original and creates a linked replacement.
- Centralize effective-ledger queries so dashboards, balances, reports, AI context, campaigns, and exports consistently exclude reversed entries.
- Generate family-scoped sequential receipt numbers under a lock.
- Preserve the application's existing whole-Naira storage convention; convert to and from Paystack subunits only in the provider adapter.
- Replace hard-delete UI actions with reverse or void modals requiring a reason and appropriate policy permission.

**Acceptance:** Every balance is reproducible from posted entries and reversals, duplicate requests cannot double-post funds, and every financial mutation records actor, family, request ID, safe before-and-after metadata, and time.

### Phase 3 — Contribution Register, Reporting, Receipts, and Retention

- Complete the contribution register using the existing family contribution review service as the shared query layer.
- Provide filters for period or date range, member, category snapshot, paid, partial, unpaid, or overdue status, outstanding amount, and text search.
- Use stable query-string pagination with 25 rows by default and 50 or 100 row options. Defer summary cards and expensive aggregates with skeleton states.
- Add reports for contribution aging, member statements, category performance, fund statement, cash flow, expense and category totals, reversals, and audit activity.
- Fund statements show opening balance, posted payments, adjustments, expenses, reversals, closing balance, and reconciliation variance.
- Add streaming CSV exports with spreadsheet-formula injection protection.
- Add server-rendered PDF reports and receipts using `spatie/laravel-pdf` v2 with the DOMPDF driver and dedicated static Blade templates. The selected stack supports the installed Laravel and PHP versions without a browser binary; templates must use DOMPDF-compatible table and block CSS. See the official [requirements](https://spatie.be/docs/laravel-pdf/v2/requirements) and [DOMPDF driver guidance](https://spatie.be/docs/laravel-pdf/v2/drivers/using-the-dompdf-driver).
- Store generated report artifacts privately. Downloads require authorization or temporary signed links valid for at most seven days.
- Restrict member statements and receipts to the member, authorized family officers, and explicitly authorized recipients.
- Add scheduled report definitions and delivery history. One scheduler command dispatches unique jobs per schedule and period; email attaches the artifact and WhatsApp receives an expiring authenticated link.
- Family deletion archives the tenant immediately, permits restore and export for 30 days, then runs an idempotent purge job covering database rows and private files. Archived families allow only restore and export. A platform legal hold may pause the purge.

**Acceptance:** Displayed totals, CSV, PDF, receipt, and scheduled report totals match the same ledger query for identical filters.

### Phase 4 — Money-In and Money-Out Reconciliation

- Add reconciliation imports, normalized bank transactions, reconciliation links, reconciliation periods, and provider settlement groups.
- Import CSV statements through a column-mapping preview. Store uploads privately, validate type and size, stream large files, and fingerprint rows per family to prevent duplicate imports.
- Normalize credit or debit direction, date, amount, reference, description, and source account without modifying the source file.
- Match credits to payment batches or positive adjustments; match debits to expenses or negative adjustments.
- Auto-match only an exact unique Paystack or reference-and-amount match. Amount and date similarities produce suggestions but never post or link automatically.
- Allow manual one-to-one, many-to-one, and split links while enforcing that linked amounts cannot exceed either side.
- Group multiple Paystack transactions and fees against aggregate bank settlements and report settlement differences separately.
- Provide unmatched, suggested, matched, ignored, and disputed queues.
- Closing a reconciliation period stores an immutable opening, closing, and variance snapshot. Reopening requires a reason, elevated permission, and audit event.

**Acceptance:** Importing the same statement twice is harmless, partial settlements reconcile correctly, and a closed period remains reproducible.

### Phase 5 — Expense Governance and Budgets

- Keep `expenses` as posted money-out entries and add expense categories, requests, approvals, attachments, budgets, and recurring templates.
- Family and Growth retain direct posting. Organization may enable approval workflows in settings.
- Enabling approvals requires the owner to configure the monetary threshold and required approver counts.
- The requester cannot approve their own request. Each approver is distinct; approval uniqueness is enforced by the database.
- Requests and rejections do not affect balances. Final approval and payment create the posted expense exactly once.
- Recurring templates generate draft requests, never posted expenses.
- Keep attachments private and require family authorization. Validate MIME type, extension, and size.
- Budgets support category and period limits, warnings, committed-request totals, posted totals, and variance reporting.
- Posted expenses use reversal and replacement rather than editing or deletion.

**Acceptance:** Approval rules cannot be bypassed through direct endpoints, retries cannot create duplicate expenses, and pending requests never alter the fund balance.

### Phase 6 — Campaigns, Special Contributions, and Households

- Add campaigns, campaign obligations, obligation waivers, payment allocations, households, and household memberships.
- Campaigns support draft, active, closed, and cancelled states; one-time or installment schedules; target amounts; due dates; and assignments to all members, selected categories, selected members, or households.
- A household has members and an optional primary payer. Household obligations are paid once for the household rather than duplicated across its members.
- Activating a campaign idempotently materializes immutable obligation snapshots. Changes to an active schedule affect only future obligations.
- Campaign payments use the common payment-batch layer and separate campaign allocation lines.
- Allow authorized waivers with a required reason and audit event.
- Cancellation preserves obligations, payments, waivers, receipts, and history.
- Add campaign progress, overdue obligations, household progress, collection velocity, and outstanding reports.

**Acceptance:** Activation and payment retries are idempotent, household totals are not double-counted, and campaign funds reconcile to payment batches.

### Phase 7 — Member Self-Service and Paystack Autopay

- Add partial online payments, payment-proof submissions, disputes, notification preferences, and saved payment authorizations.
- Payment proofs remain pending until an authorized reviewer approves or rejects them. Approval creates a payment batch atomically; rejection records a reason.
- Members may dispute only their own contribution or payment records. Provide open, responded, resolved, and rejected states with private correspondence.
- Notification preferences cover channels, reminder types, digest frequency, and quiet hours.
- Autopay is explicit opt-in per family membership, with amount policy, maximum charge, schedule, next attempt, pause and cancellation controls, and consent history.
- Save the complete reusable Paystack authorization context encrypted, including the original authorization email and instrument signature; never expose authorization codes to the client.
- Queue scheduled charges with unique references, bounded retries, and failure notifications. Handle challenge and redirect responses without treating the charge as successful.
- Allocate only after a verified successful webhook. Paystack's recurring-charge flow requires a reusable authorization, its original email, and provider-subunit amounts; see [Recurring Charges](https://paystack.com/docs/payments/recurring-charges/) and the [Transaction API](https://paystack.com/docs/api/transaction/).
- Isolate autopay behind a Growth feature flag until live-mode webhook, retry, cancellation, and reconciliation scenarios pass.

**Acceptance:** Consent can be revoked immediately, duplicate jobs or webhooks cannot double-charge or double-allocate, and failed charges leave contribution balances unchanged.

### Phase 8 — Reliable Offline Manual Entry

- Partition service-worker caches and IndexedDB by authenticated user and family; clear both on logout, account change, and family switch.
- Queue only manual payments, direct expenses or expense requests, and fund adjustments. Exclude Paystack, approvals, reversals, reconciliation closing, file uploads, and organization administration.
- Assign every offline mutation a UUID `client_request_id`. The server stores family-scoped idempotency keys and returns the original result for duplicates.
- Synchronize sequentially per family on reconnect or app open. Background Sync is an optional enhancement, not the only synchronization path.
- Reauthorize every mutation and recalculate balances server-side.
- Display pending, syncing, completed, and conflict states. Archived members, closed periods, changed permissions, and invalid amounts become visible conflicts rather than silent failures.
- Editing a rejected offline entry creates a new idempotency key; canonical posted entries remain immutable.
- Never optimistically alter canonical balance totals; show pending amounts separately.

**Acceptance:** Repeated reconnects do not duplicate entries, queued data cannot leak between users or families, and all conflicts are recoverable from the UI.

### Phase 9 — Organization Branches and Custom Roles

- Add family branches, branch memberships, custom roles, and role permissions.
- When branches are enabled, create a default "Head Office" branch and backfill existing memberships and financial records into it.
- Add optional `branch_id` to contributions, payment batches, expenses, adjustments, campaigns, reconciliation periods, and reports.
- Custom permissions cover members, payments, expenses, approvals, reports, exports, reconciliation, campaigns, schedules, audits, and branch administration.
- Preserve immutable built-in Owner, Administrator, Financial Secretary, and Member role templates. The owner role cannot be removed or stripped of ownership permissions.
- Allow branch-restricted officers and cross-branch reporting only with explicit permission.
- Migrate legacy membership roles to built-in role records before enabling custom assignments.

**Acceptance:** Branch-restricted users cannot infer or access another branch through pages, exports, signed links, jobs, or manipulated route parameters.

## Interfaces and Data Flow

- Use dedicated Form Requests, policies, domain actions, and queued jobs; controllers remain orchestration-only.
- All family identifiers originate from authorized active membership. Jobs and notifications carry an explicit `family_id` and revalidate access and state before executing.
- Expose Inertia index props as `filters`, paginated `data`, deferred `summary`, `permissions`, and `entitlements`.
- Use Wayfinder-generated controller actions and named routes for all Vue submissions; use Inertia's built-in form and HTTP facilities rather than Axios.
- Standardize PHP enums and matching frontend string unions for financial status and source, contribution status, reconciliation state, expense request state, campaign state, and delivery state.
- Offline-capable mutation requests add `client_request_id`; online requests may omit it and receive a server-generated value.
- Financial exports accept the same validated filter object as their corresponding screen to prevent UI and export drift.
- Use composite indexes for family, branch, status, and date lookups and `chunkById`, cursors, or streamed responses for backfills, imports, reports, and exports.

## Testing and Rollout

- Each phase follows expand, backfill, switch reads and writes, then contract. Never mix large data backfills into schema migrations.
- Gate unfinished capabilities with Pennant and enforce entitlements in both policies and UI; hidden buttons are not authorization.
- Add Pest feature tests for every policy boundary, cross-family and cross-branch isolation, reversal behavior, category history, idempotency, concurrent posting, imports, matching, approvals, campaigns, signed downloads, scheduled jobs, deletion restoration and purge, and webhook replay.
- Add unit tests for allocation, effective-ledger totals, reconciliation matching, budget calculations, campaign materialization, receipt numbering, and Paystack subunit conversion.
- Add browser tests for the contribution register, PDF and CSV flow, reconciliation wizard, expense approvals, campaign activation, mobile member journeys, offline reconnect and conflicts, and branch switching.
- Verify private files cannot be accessed through public URLs or another family's signed route.
- Run affected tests during each implementation slice, then the full existing `composer ci:check` gate: PHPStan, Pint, Prettier, ESLint, production build, and 100% test coverage.
- Deploy migrations before workers that consume the new schema; restart queue workers after each compatible release. Enable features family by family, monitor failed jobs, webhooks, and imports, then promote the plan entitlement globally.

## Assumptions

- Existing payment and contribution amounts remain whole-Naira decimal values; only Paystack adapters use kobo.
- Financial immutability applies while a tenant exists; the approved 30-day family purge permanently removes tenant data unless a legal hold is set.
- Expense approvals are optional and Organization-only.
- Households ship with the campaign phase.
- Server PDF generation uses the approved Spatie PDF and DOMPDF dependencies.
- Existing Paystack self-payment remains operational while the new batch ledger is introduced; autopay launches later under an isolated Growth flag.
- No new top-level architectural folders are introduced; implementation follows the repository's existing Laravel and Inertia structure.
