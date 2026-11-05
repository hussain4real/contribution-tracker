# FamilyFund Product and UX Improvement Plan

## Purpose

Make it easier for members to understand and pay their contributions, and for family officers to manage money transparently. FamilyFund already supports contributions, payments, receipts, statements, reminders and reconciliation. The improvements below build on those capabilities. Related work on expense governance, campaigns and member self-service is covered in the [product completion roadmap](../familyfund-product-completion-roadmap.md).

## Phase 0 — Reliability and discovery

- Verify session reliability when switching from the platform dashboard to a family dashboard. Investigate write timeouts and test recovery.
- Walk through the main member and officer journeys on mobile and desktop, including assistive technology and slow or interrupted connections. Record friction and validate priorities with representative users.
- Establish baseline measures for dashboard response time, payment completion, receipt delivery and support requests. Define targets using current production measurements; a previous performance target was 20 users and responses under three seconds.

**Acceptance:** Session reliability findings are recorded, the main journeys have been reviewed, and baseline measures and owners are recorded.

## Phase 1 — Make payments clear and dependable

### 1. Member money home

- Show the next contribution, outstanding balance and latest payment, with a clear payment action where available.
- Bring receipt access and the existing statement download together. Make partial payments and the periods they cover understandable.
- Explain empty states, unavailable online payments and unsuccessful payments without implying that a payment was recorded.

**Acceptance:** A member can identify what is due, initiate an available payment, and find their receipt and statement on a small screen. All amounts match the authoritative ledger and respect family membership permissions.

### 2. Receipt and reminder delivery visibility

- Show channel-specific states such as queued, sent, delivered or failed, only where the provider actually supplies the state. Distinguish provider acceptance from confirmed delivery.
- Let an authorized officer retry a failed delivery without creating another payment or receipt; display a useful failure reason without exposing secrets.
- Provide members with a way to access their receipt even when message delivery fails.

**Acceptance:** An officer can understand and safely retry a failed delivery, and a member can retrieve the correct receipt. Retries do not duplicate financial entries.

### 3. Configurable reminders

- Extend existing scheduled reminders so families can choose timing and supported channels. Give members appropriate notification preferences and a preview of the message.
- Recheck the outstanding amount before sending; avoid sending reminders for fully paid contributions and prevent duplicate scheduled sends.

**Acceptance:** A family can configure its schedule, preferences are respected, and paid or duplicate reminders are not sent in tested scenarios.

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
