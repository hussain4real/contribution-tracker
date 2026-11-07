# FamilyFund Product and UX Improvement Plan

## Purpose

Make it easier for members to understand and pay their contributions, and for family officers to manage money transparently. FamilyFund already supports contributions, payments, receipts, statements, reminders and reconciliation. The improvements below build on those capabilities. Related work on expense governance, campaigns and member self-service is covered in the [product completion roadmap](../familyfund-product-completion-roadmap.md).

## Delivery and verification status — November 7, 2026

| Area | Deployed and verified | Outstanding |
| --- | --- | --- |
| Phase 0 | The owner reviewed the synthetic member and officer journeys, screen-reader flow, reminder setup and slow-network journeys. Production platform-to-family navigation and session restoration were exercised. The first production baseline and simulated recovery results are recorded below. | Review the baseline after more real use and agree numerical targets. |
| Phase 1 | Member contribution and receipt views, payment review, reminder settings, delivery visibility and retry safeguards were released. A staging Paystack test checkout produced a receipt that was checked and reversed. The owner confirmed the production contribution view. [Release #315](https://github.com/hussain4real/contribution-tracker/pull/315) | Measure completion and delivery with a qualifying production cohort. Confirm recovery when a real provider failure occurs. |
| Phase 2 | Dashboard movement and activity, member visibility, expense approvals and private documents were deployed to both environments after migration and recovery checks. Local browser tests passed for request submission, separate approval, posting and visibility. Local HTTP tests verified byte-for-byte private document download and denied access for members and other families. [Production #316](https://github.com/hussain4real/contribution-tracker/pull/316) · [Staging #317](https://github.com/hussain4real/contribution-tracker/pull/317) | Verify browser upload and download against deployed private storage and complete production approval acceptance when an eligible Organization family is available. The November 7 production query found zero active Organization families. |
| Phase 3 | Goals, setup and AI drafts were deployed to both environments. Production checks covered goal calculations and lifecycle, setup preview and resume, member permissions, grounded answers and draft review handoffs. A local HTTP journey covers registration, email verification, the queued invitation link, member acceptance and resumed setup. A local browser test posted a reviewed expense draft once, replayed it and reversed it while retaining the original. [Production #318](https://github.com/hussain4real/contribution-tracker/pull/318) · [Staging #319](https://github.com/hussain4real/contribution-tracker/pull/319) | Verify new-family invitation delivery and a reviewed draft submission and reversal in an appropriate hosted test. Verify the assistant's expense reversal wording after release. |
| Follow-up UX fixes | Mobile More and the AI launcher were retested on staging and production. Production Stop Impersonating restored the owner and opened the platform dashboard. The adjustment form transition was also corrected and covered by CI. [Staging #320](https://github.com/hussain4real/contribution-tracker/pull/320) · [Production #321](https://github.com/hussain4real/contribution-tracker/pull/321) | The impersonation check is covered locally and in production. Staging isolation restricts the platform administration routes used by this journey. |

The November 6 production test goal is clearly marked and archived. No test payment, receipt, expense, adjustment or contribution was posted during that Phase 3 test. The owner session was restored.

## Phase 0 — Reliability and discovery

- Verify session reliability when switching from the platform dashboard to a family dashboard. Investigate write timeouts and test recovery.
- Walk through the main member and officer journeys on mobile and desktop, including assistive technology and slow or interrupted connections. Record friction and validate priorities with representative users.
- Establish baseline measures for dashboard response time, payment completion, receipt delivery and support requests. Define targets using current production measurements; a previous performance target was 20 users and responses under three seconds.

**Acceptance:** Session reliability findings are recorded, the main journeys have been reviewed, and baseline measures and owners are recorded.

### Production baseline

The FamilyFund owner owns the baseline. Use seven-day windows and retain the denominator for every rate. Review the figures after a window with recorded activity.

| Measure | First observation | Next measurement |
| --- | --- | --- |
| Dashboard response time | Nightwatch recorded 183 family dashboard requests from October 31 to November 7 at 07:54 UTC. Mean server duration was 1.38 s and p95 was 2.67 s, with zero recorded 4xx or 5xx responses for this route. This is observed traffic. [Nightwatch Requests](https://nightwatch.laravel.com/eu/environments/a1529fa7-7ff6-403c-ab13-cebac05b258d/requests?period=7d) | Use p95 below 3 s as a provisional route target. Measure browser task completion and the earlier 20-user capacity target separately. |
| Paystack contribution completion | Production D1 recorded zero Paystack attempts from October 31 00:00 to November 7 00:00 UTC. There were ten cash receipts in that window, all before the Phase 1 production deployment. | Track started contribution checkouts, provider-verified payments and allocations with a linked receipt separately. Report counts before calculating a rate. |
| Receipt delivery and recovery | Production D1 had zero tracked receipt delivery rows and no receipts created since the Phase 1 deployment began through the end of the window. In an earlier production test, the owner confirmed receipt by email and WhatsApp. | For each channel, count attempts, provider acceptance, confirmed delivery where available, failures and successful retries. Use the first eligible post-release receipts as the cohort. |
| Support requests | The owner reports zero current requests. Support is tracked through the owner's mailbox without a dedicated label. | Record confirmed FamilyFund requests and time to first response as they arrive. |

Local fault-injection tests simulate a failed session save after financial commit. An unchanged retry returns the original receipt or preserves the original expense or reversal without posting a duplicate. The owner also reviewed the slow-network member and officer flows. The live timeout rate and recovery time still need a production sample.

## Phase 1 — Make payments clear and dependable

Phase 1 was released to production on November 5, 2026. Member money information, payment review, reminder preferences, channel delivery visibility and eligible retries are available. Staging checkout and receipt reversal were exercised with Paystack test mode, and the owner checked the production member contribution view. Ongoing measurements and real delivery recovery remain follow-up work.

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

Phase 2 was released to staging and production on November 5, 2026 through PRs #316 and #317, after the five additive migrations and native D1 recovery checks passed. It includes monthly money movement, activity history, configurable member visibility, expense requests with independent approvals, and private supporting documents.

Member visibility defaults to family totals. Admins can select personal records only or opt members into transaction history. Private supporting documents are restricted to family officers. Organization owners can enable an approval threshold and distinct approver count. Requests retain their required approvals, and payment checks the stronger of the captured and current rules.

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

Phase 3 was deployed to staging and production on November 6, 2026. Its additive migration passed native D1 restore, forced-failure rollback, constraint, down-migration and recovery rehearsals before independent application to both environments. Both deployments passed runtime, image and database integrity checks. Production acceptance covered the journeys listed in the status table above; the marked test goal was archived after verification. [Production release #318](https://github.com/hussain4real/contribution-tracker/pull/318) · [Staging release #319](https://github.com/hussain4real/contribution-tracker/pull/319)

The initial accounting model is a **visual target within the shared fund**. It does not reserve or transfer money. Each goal starts at zero on its start date. Contributions received counts effective payment amounts; net savings counts effective payments plus fund adjustments minus expenses. Entry dates must fall between the start date and deadline, through today. Reversals remove the original entries from progress. Progress can be negative or exceed the target; only the progress bar is clamped to 0–100%. Overlapping goals can use the same entries and must not be added together as separate balances. Supporting links open the matching ledger range. Personal-only visibility hides family goals; summary visibility allows goal totals; transaction visibility allows source entries. Admins manage goals and edits/archive changes retain audit events.

Setup progress is derived from current family records so it can be resumed without separate checklist state. Workspace creation, contribution categories, another active member or pending invitation, and the first effective payment are required steps. Bank details and a custom reminder schedule are optional. The sample member preview is read-only and inserts no financial data.

AI personal arrears defaults to all years, excludes other members and families, and calculates outstanding per contribution so an overpayment cannot cancel another period's dues. Financial read tools recheck active membership and current visibility, include an as-of time, currency, reporting period and authorized source links, and disclose truncated entry lists. Financial tools prepare encrypted, ten-minute drafts bound to the actor, family and action. The user reviews and submits an existing FamilyFund form; chat confirmation cannot post a payment, expense, adjustment or generated contribution. Existing allocation review, approvals and idempotency checks remain authoritative. Invitations retain their existing confirm-first flow.

The expense-summary follow-up gives the assistant the ledger's explicit reversal policy. The local tool regression verifies the reported total and entries against a mix of effective and reversed expenses. The deployed model's wording still needs a hosted check.

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

## Decisions and remaining product choices

The current release uses visual goals within the shared fund, defaults members to family totals, and gives eligible officers financial review and delivery-retry actions. Family reminder timing and channels and member notification preferences are configurable. Organization plans can enable independent expense approvals.

Outstanding product choices are the numerical outcome targets, any future separately accounted funds or campaigns, and further changes to role access, delivery states or plan entitlements based on observed use and provider capabilities.
