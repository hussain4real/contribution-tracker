# FamilyFund Product and UX Improvement Plan

**Status:** In progress — Phase 0 discovery and Phase 1 implementation; validation and release remain incomplete.

**Date:** 2026-10-30

## Purpose

Make it easier for members to understand and pay their contributions, and for family officers to manage money transparently. This plan is a set of proposed enhancements, not a statement that each feature is absent or an authorization to deploy changes.

FamilyFund already has contributions, online payments, downloadable receipts and statements, scheduled reminders, expenses, reconciliation, reports, invitations and an AI assistant. Payment receipts were recently verified through email and WhatsApp in production. Build on these capabilities rather than duplicating them.

The existing [product completion roadmap](../familyfund-product-completion-roadmap.md) contains related work on expense governance, campaigns and member self-service. Reconcile its status and entitlements with the current application before implementation; its recorded status may be out of date.

## Phase 0 — Reliability and discovery

- Investigate the database session-write timeout observed during the production platform-to-family dashboard test. Determine its cause and verify any correction; a successful retry alone does not establish that the issue is resolved.
- Walk through the main member and officer journeys on mobile and desktop, including assistive technology and slow or interrupted connections. Record friction and validate priorities with representative users.
- Establish baseline measures for dashboard response time, payment completion, receipt delivery and support requests. Define targets using current production measurements; a previous performance target was 20 users and responses under three seconds.

**Acceptance:** The timeout has an evidence-backed disposition, the main journeys have been reviewed, and baseline measures and owners are recorded.

### Discovery record — 2026-10-30

- **Representative member and family officer:** the product owner, Aminu Hussain, volunteered to validate both journeys. The walkthrough and priority validation are still pending.
- **Local walkthrough:** On 2026-10-30, an isolated SQLite demo showed a partial member's outstanding balance, receipt history and reminder preferences. A financial-secretary demo reviewed a ₦2,000 cash payment allocated to the oldest unpaid month, then saw its receipt and queued channel states. The officer payment form was also inspected at 390 × 844 pixels. This is developer observation, not representative-user validation or a delivery test.
- **Member mobile view:** At 390 × 844 pixels, the demo member could see the outstanding amount, unavailable-online-payment explanation, statement link, receipt links and reminder preference controls. The demo has no payment provider configured, so this check did not exercise an online payment.
- **Keyboard and interrupted review:** In the isolated demo, an offline review showed an error and did not post a payment; retrying after reconnection produced the allocation summary. A throttled review showed a disabled preparing state, then the summary. Keyboard activation initially lost focus when the review appeared; the review heading now receives focus, verified in the browser and a focused browser test. This is not a complete assistive-technology or slow-network audit.
- **Local schema rehearsal:** A copy of the synthetic SQLite database survived rollback and reapplication of the four new migrations, with integrity and foreign-key checks passing and the synthetic payment retained. This does not verify the Cloudflare D1 migration or authorize a release.
- **Local D1 emulator:** Wrangler 4.156.0 applied the eight SQL statements emitted by Laravel's migration preview to a minimal synthetic D1 schema, and separately to a synthetic pre-migration database with 45 tables, 383 rows and 110 indexes. The latter retained all 29 payment batches and 30 contributions, including the synthetic test payment; integrity and foreign-key checks passed. The minimal rehearsal also verified writable delivery rows and JSON settings and rejected an invalid foreign key. Actual staging and production data, migration tracking, read-cache triggers and sequence setup still require verification before release.
- **D1 read-only preflight:** On 2026-10-30, neither staging nor production contained the new delivery tables or reminder columns. The full table definitions of the four affected parent tables matched between staging, production and the local pre-migration SQLite schema; production's 22 explicit indexes for those tables also matched local. This check did not compare staging indexes, triggers or data and does not authorize migration.
- **D1 rollout dependency:** Both remote databases had 64 Laravel migration records, none for the four new migrations, and had the D1 sequence and read-revision tables. Production enables the read snapshot cache. A reviewed rollout must verify a recoverable backup and exact target, apply the equivalent schema and Laravel migration records, install sequence entries and all three read-revision triggers for each new table, then verify integrity and application behavior before accepting the release fingerprint. Repeat preflight immediately before any write; the observed counts are not a lock or a backup.
- **D1 runtime setup test:** The local staging-runtime adapter test installed sequence entries and six read-revision triggers for the two new delivery tables, then confirmed inserts advanced the revision. This does not establish that those runtime objects exist on either remote database.
- **Baseline owner:** Aminu Hussain. Baselines for payment completion, receipt delivery and support requests still need a defined observation window, data sources and measurements; no target has yet been set from current data.
- **Session timeout:** Nightwatch production issue #39 recorded a 30-second connection timeout while writing a D1 session on the family dashboard. A refresh on 2026-10-30 reported one occurrence, last seen at 02:29:40 UTC. The underlying cause of the stalled D1 request has not been established. Main includes session-save recovery in PR #275, and production reported revision `github-437ce415df7b` on 2026-10-30, which contains that change. The local recovery suite passed; an actual production failure-and-recovery event has not been verified.
- **Dashboard speed:** Nightwatch issue #40 was last seen at 14:44:21 UTC on 2026-10-30 and records requests over three seconds; its slowest example was 30,661 ms, associated with the session timeout. This is an incident example, not a representative latency baseline. Collect a defined-window distribution before selecting a performance target.
- **Proposed measurement window:** use the same 30-day production window for dashboard latency (request count, median and 95th percentile), payment completion (successful payments divided by initiated attempts), receipt delivery (confirmed deliveries and failures by channel, with unknown outcomes separate), and support requests (count and topic). Confirm available data and event definitions first; where an initiation, provider confirmation or support source is missing, record the measure as unavailable rather than estimating it. Aminu owns the baseline and subsequent targets.
- **Partial production measurement:** A read-only aggregate for 2026-09-30 15:23 to 2026-10-30 15:23 UTC found no Paystack transaction records, seven manually recorded payment batches, and one outbound WhatsApp message with template name `payment_receipt` and status `read`. The manual count may include test entries; the template query may omit differently named templates. The Paystack completion rate has no denominator, and this message count does not establish a receipt-delivery rate or email delivery. Dashboard latency distribution and support-request counts remain unmeasured.

## Phase 1 — Make payments clear and dependable

### 1. Member money home

- Show the next contribution, outstanding balance and latest payment, with a clear payment action where available.
- Bring receipt access and the existing statement download together. Make partial payments and the periods they cover understandable.
- Explain empty states, unavailable online payments and unsuccessful payments without implying that a payment was recorded.

**Acceptance:** A member can identify what is due, initiate an available payment, and find their receipt and statement on a small screen. All amounts match the authoritative ledger and respect family membership permissions.

**Uncertain online-payment outcome:** The member payment flow now advises checking payment history or contacting the family admin before trying again when an outcome cannot be confirmed. It no longer promises automatic confirmation or claims support has been notified. Paystack callback tests and frontend checks pass locally; an actual online payment remains to be verified in a suitable test environment.

### 2. Receipt and reminder delivery visibility

- Show channel-specific states such as queued, sent, delivered or failed, only where the provider actually supplies the state. Distinguish provider acceptance from confirmed delivery.
- Let an authorized officer retry a failed delivery without creating another payment or receipt; display a useful failure reason without exposing secrets.
- Provide members with a way to access their receipt even when message delivery fails.

**Acceptance:** An officer can understand and safely retry a failed delivery, and a member can retrieve the correct receipt. Retries do not duplicate financial entries.

**Local reliability verification:** Receipt jobs and their delivery records are now persisted in the payment transaction, including retries; tests cover rollback when queue insertion fails. A D1 staging-adapter test also verifies that a receipt job and a simulated financial write commit or roll back together. Replaying an already accepted receipt or reminder job does not resend it in the tested scenarios. A provider may accept a message before the application records the response, so an unconfirmed attempt can still produce a duplicate message if retried; it must not create another financial entry.

**WhatsApp status ordering:** [Meta's webhook documentation](https://www.postman.com/meta/whatsapp-business-platform/request/rgtfq23/message-status-update-notifications) says status notifications may arrive out of order. The handler now locks the message while applying status transitions and preserves confirmed delivery or read states against older sent/failure updates. Focused tests cover those sequences, a later delivery confirmation after failure, and an out-of-order update through the D1 staging adapter. An early callback that arrives before its message record exists can still be missed and remains a verification gap.

### 3. Configurable reminders

- Extend existing scheduled reminders so families can choose timing and supported channels. Give members appropriate notification preferences and a preview of the message.
- Recheck the outstanding amount before sending; avoid sending reminders for fully paid contributions and prevent duplicate scheduled sends.

**Acceptance:** A family can configure its schedule, preferences are respected, and paid or duplicate reminders are not sent in tested scenarios.

**Local worker verification:** A queued reminder was suppressed after full payment or after the member disabled its channel, and a WhatsApp reminder queued before a partial payment used the remaining balance when the worker sent it. These use synthetic data and a mocked provider; actual delivery remains a separate staging check.

### 4. Payment review

- Before posting a manual payment, summarize the member, amount, method and proposed allocation by contribution period.
- Flag possible duplicate references and overpayments, while allowing an authorized officer to proceed when legitimate.
- After posting, show the receipt and available delivery states; explain correction through reversal.

**Acceptance:** The review matches the posted receipt and allocations, warnings are clear, and submitting or retrying cannot create duplicate entries.

## Phase 2 — Dashboard clarity and trust

### 5. Actionable family dashboard

- Prioritize available balance, contributions received, outstanding contributions and recent expenses, each linked to its underlying records.
- Label the reporting period and explain balance calculations. Give officers a concise action list, such as items requiring reconciliation.
- Keep information appropriate to the member's role and make loading, empty and error states clear.

**Acceptance:** Dashboard totals reconcile with the ledger and linked views for the same period, and role-specific actions are accessible on mobile.

### 6. Family activity timeline and financial transparency

- Provide a searchable, permission-aware history of payments, expenses, reversals and significant settings changes, using existing audit information where suitable.
- Present money in, money out and balance in plain language, with links to supporting transactions and monthly summaries. Let families configure what members can see.
- Show who performed an action and why a financial entry was reversed, while preserving the original record.

**Acceptance:** Authorized users can trace a displayed balance to its entries and understand a reversal; users cannot access another family's records or restricted details.

### 7. Expense approvals and documents

- Reconcile this work with the expense governance phase of the existing roadmap. Allow private supporting documents and optional approval rules, including a family-defined threshold and independent approvers.
- Keep requests separate from posted expenses; record submissions, decisions and reversals in the activity history.

**Acceptance:** Approval rules are enforced across all entry points, pending requests do not affect balances, documents are accessible only to authorized family members, and retries cannot double-post expenses.

## Phase 3 — Engagement and growth

### 8. Family goals or dedicated funds

- Allow a family to define a goal, target and deadline and view progress. Explore reuse of the roadmap's campaigns and special contributions work.
- Decide first whether a goal is a visual target or a separately accounted fund; define how payments, expenses, reversals and reports affect it.

**Acceptance:** Progress has a documented calculation and reconciles with its underlying entries; the chosen accounting model is clear to users.

### 9. Guided onboarding

- Guide a new family admin through family setup, contribution rules, member invitations and the first payment or collection.
- Show progress, explain optional steps and offer a preview without inserting sample financial records into the live ledger.

**Acceptance:** A new admin can complete or resume setup and invite a member without assistance; no sample transaction is posted as real money.

### 10. AI assistant improvements

- Support questions about a member's own outstanding contributions and, for authorized officers, family expense summaries. Link answers to the underlying records and reporting period.
- Respect existing family and role permissions, communicate uncertainty and require explicit review and confirmation before any financial action.

**Acceptance:** Answers are grounded in authorized current records, link to supporting views and do not disclose restricted data or execute financial actions without confirmation.

## Cross-cutting UX and delivery

- Review each phase on small screens and with keyboard and screen-reader navigation. Use readable tables, appropriate touch targets and clear loading, empty and error states.
- Preserve immutable financial records, family isolation and existing authorization. Use private storage for financial documents.
- Deliver in independently testable slices. For each slice, validate behavior locally and in CI, then verify the deployed user journey separately in staging and production as appropriate.
- Measure outcomes against the baseline: payment completion, delivery failures and recovery, dashboard task completion, support requests and response times. Define numerical targets after baseline collection.

## Decisions before implementation

1. Which member and officer journeys should be prioritized after the user walkthrough?
2. Which delivery states are available from each provider, and who may retry messages?
3. Which reminder settings and notification preferences should families and individual members control?
4. What visibility and approval rules should apply to each family and subscription plan?
5. Are goals informational targets or separately accounted funds?
6. Which AI questions and actions should be allowed for each role?
