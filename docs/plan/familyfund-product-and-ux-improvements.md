# FamilyFund Product and UX Improvement Plan

## Purpose

Make it easier for members to understand and pay their contributions, and for family officers to manage money transparently. FamilyFund already supports contributions, payments, receipts, statements, reminders and reconciliation. The improvements below build on those capabilities. Related work on expense governance, campaigns and member self-service is covered in the [product completion roadmap](../familyfund-product-completion-roadmap.md).

## Delivery and verification status — November 13, 2026

| Area | Deployed and verified | Outstanding |
| --- | --- | --- |
| Phase 0 | The owner reviewed the synthetic member and officer journeys, screen-reader flow, reminder setup and slow-network journeys. Production platform-to-family navigation and session restoration were exercised. Timed browser tasks passed in the local demo, and the owner reported unaided completion of both browser tasks in production. The live production image passed the 20-user dashboard test with a 2.13 s p95 and zero failures in 100 requests. Production telemetry showed zero confirmed write timeouts across 16 synthetic financial write requests. | Collect timed browser attempts and participant counts by role. Measure write-timeout frequency with a real production cohort and recovery time once a confirmed timeout can be linked to its retry or commit. Review the baseline after more real use and agree numerical targets. |
| Phase 1 | Member contribution and receipt views, payment review, reminder settings, delivery visibility and retry safeguards were released. A staging Paystack test checkout produced a receipt that was checked and reversed. The owner confirmed the production contribution view. The November 12 production refresh again found zero real contribution checkouts and zero real receipts eligible for delivery measurement. [Release #315](https://github.com/hussain4real/contribution-tracker/pull/315) | Measure completion and delivery with a qualifying production cohort. Confirm recovery when a real provider failure occurs. |
| Phase 2 | Dashboard movement and activity, member visibility, expense approvals and private documents were deployed to both environments. Production passed Organization approval settings, request submission, separate approval, single posting and audited reversal. Production browser tests also passed private document upload, byte-for-byte officer download and access denial for a regular member and an officer from another family. [Production #316](https://github.com/hussain4real/contribution-tracker/pull/316) · [Staging #317](https://github.com/hussain4real/contribution-tracker/pull/317) | Production acceptance for private supporting documents is complete. |
| Phase 3 | Goals, setup and AI drafts were deployed to both environments. Production checks covered goal calculations and lifecycle, setup preview and resume, member permissions, and draft review handoffs. Production also passed new-family registration and email verification, invitation acceptance by new and existing accounts, first collection with checklist and receipt verification, reviewed expense draft replay and reversal, and AI expense totals before posting, after posting and after reversal. [Production #318](https://github.com/hussain4real/contribution-tracker/pull/318) · [Staging #319](https://github.com/hussain4real/contribution-tracker/pull/319) | The listed production acceptance checks are complete. |
| Follow-up UX fixes | Mobile More and the AI launcher were retested on staging and production. Production Stop Impersonating restored the owner and opened the platform dashboard. The adjustment form transition was also corrected and covered by CI. [Staging #320](https://github.com/hussain4real/contribution-tracker/pull/320) · [Production #321](https://github.com/hussain4real/contribution-tracker/pull/321) | The impersonation check is covered locally and in production. Staging isolation restricts the platform administration routes used by this journey. |
| Expense budgets extension | Local implementation adds monthly category limits, actual and committed spending, advisory warnings and links to supporting entries. Budget feature tests and two synthetic Chromium journeys pass. This extension is not yet deployed. | Native D1 verification passed; run CI, then release and exercise the hosted journeys on staging and production. |

The marked November 6 production test goal was archived and the owner session was restored. The November 7 follow-up exercised the synthetic ledger entries described below.

## Phase 0 — Reliability and discovery

- Verify session reliability when switching from the platform dashboard to a family dashboard. Investigate write timeouts and test recovery.
- Walk through the main member and officer journeys on mobile and desktop, including assistive technology and slow or interrupted connections. Record friction and validate priorities with representative users.
- Establish baseline measures for dashboard response time, payment completion, receipt delivery and support requests. Define targets using current production measurements; a previous performance target was 20 users and responses under three seconds.

**Acceptance:** Session reliability findings are recorded, the main journeys have been reviewed, and baseline measures and owners are recorded.

### Production baseline

The FamilyFund owner owns the baseline. Use seven-day windows and retain the denominator for every rate. Review the figures after a window with recorded activity.

| Measure | First observation | Next measurement |
| --- | --- | --- |
| Dashboard response time | Nightwatch recorded 183 family dashboard requests from October 31 to November 7 at 07:54 UTC. Mean server duration was 1.38 s and p95 was 2.67 s, with zero recorded 4xx or 5xx responses for this route. This is observed traffic. [Nightwatch Requests](https://nightwatch.laravel.com/eu/environments/a1529fa7-7ff6-403c-ab13-cebac05b258d/requests?period=7d) | Use p95 below 3 s as a provisional route target. The controlled production test is recorded below. |
| Browser task completion | The owner reported that production participants completed both tasks unaided. | Collect eligible starter counts and elapsed times by role to calculate completion rates, medians and ranges. |
| Financial write timeouts | Cloudflare recorded 16 synthetic requests to the monitored production routes. Zero were confirmed timeouts. Recovery time had no qualifying sample. | Count real production attempts on the same routes. For each confirmed timeout, measure from the failed response to a verified retry or commit. |
| Paystack contribution completion | Production D1 recorded zero Paystack attempts from October 31 00:00 to November 7 00:00 UTC. The November 12 refresh also found zero recorded contribution checkouts, verifications or allocations in the latest seven days. | Track started contribution checkouts, provider-verified payments and allocations with a linked receipt separately. Calculate a rate once real attempts are recorded. |
| Receipt delivery and recovery | The first baseline found ten cash receipts, all before the Phase 1 production deployment. The latest seven days had one reversed test receipt and zero real receipts. In an earlier production test, the owner confirmed receipt by email and WhatsApp. | For each channel, count attempts, provider acceptance, confirmed delivery where available, failures and successful retries. Use the first eligible post-release receipts as the cohort. |
| Support requests | The owner reports zero current requests. Support is tracked through the owner's mailbox without a dedicated label. | Record confirmed FamilyFund requests and time to first response as they arrive. |

Local fault-injection tests simulate a failed session save after financial commit. An unchanged retry returns the original receipt or preserves the original expense or reversal without posting a duplicate. The owner also reviewed the slow-network member and officer flows.

### Production write timeouts and recovery — November 9, 2026

From November 2 at 11:00 to November 9 at 10:36 Qatar time, Cloudflare recorded 16 distinct requests to the monitored production financial write routes. The count covers payment, expense and fund adjustment creation and reversal, contribution generation, expense requests and their decisions and payments, document upload, and financial governance settings. It excludes provider webhooks, payment callbacks, background jobs and payment previews. Each request appeared at both the Worker and the container and was counted once. All 16 matched synthetic acceptance work. Fourteen returned HTTP 302 or 303, and two returned HTTP 500. There were **zero confirmed timeouts** in this sample. The window yielded no real-user rate or timeout recovery duration. The available server logs measure surfaced failures and do not show client-only disconnects or internal retries that finish successfully.

The two HTTP 500s occurred on the synthetic Organization's approval settings save. D1 rejected a freshness check that exceeded its SQL expression depth limit. After the fix deployed, the first verified retry returned HTTP 303 and D1 recorded the settings change at 15:58:36 Qatar time on November 7. Recovery took **33 min 39 s from the first failure** and **29 min 40 s from the last failure**. This measures recovery from the query error. The older Nightwatch timeout was an October 30 session save on a dashboard GET outside this window. [Nightwatch #47](https://nightwatch.laravel.com/eu/environments/a1529fa7-7ff6-403c-ab13-cebac05b258d/issues/47) · [Fix #329](https://github.com/hussain4real/contribution-tracker/pull/329) · [Nightwatch #39](https://nightwatch.laravel.com/eu/environments/a1529fa7-7ff6-403c-ab13-cebac05b258d/issues/39)

### Browser tasks and dashboard capacity — November 8, 2026

Browser measurements used synthetic member and officer accounts in the local demo, with warm browser assets and no network throttling. Timing includes automation overhead and starts at the first listed action. Sign-in and setup are outside the timed window.

| Browser task | Completed | Median | Range |
| --- | ---: | ---: | ---: |
| At a 390 px viewport, open My Contributions from the dashboard with the keyboard and find the outstanding balance and the expected receipt link | 5/5 | 232 ms | 229–319 ms |
| On desktop, enter ₦1,000 on the officer payment form and open the review showing the expected October 2026 allocation | 5/5 | 265 ms | 252–278 ms |

The browser did not report a download event during the timed check, so receipt download time is unavailable. A separate authenticated request returned the expected PDF attachment (22,729 bytes). Two existing Chromium tests passed with 23 assertions, covering member receipt access and an officer payment through receipt creation in isolated test data. The browser timings measure automated tasks. A production completion rate requires observations from actual members and officers.

| Dashboard capacity environment | Workload | Correct responses | Median | p95 | Slowest | Under 3 s |
| --- | --- | ---: | ---: | ---: | ---: | ---: |
| Local Herd and SQLite at revision `ff0aab5` | Five rounds of 20 distinct synthetic accounts, one officer and 19 members | 100/100 | 0.43 s | 0.69 s | 0.82 s | 100/100 |
| Earlier isolated Cloudflare D1 candidate, `candidate-managed-v11-bank-pool20-ready` | 30 rounds of 20 synthetic users | 600/600 | 2.03 s | 2.43 s | 3.38 s | 598/600 |

Both runs met the agreed p95 below 3 s with zero failed responses. The local run verified the authenticated account, dashboard component and matching business data for each complete response. It exercised warm sessions with fresh connections and measured dashboard HTTP responses. The earlier Cloudflare result is recorded in the [runtime evidence](../../deployment/d1-proof/production-runtime.json#L218). Its test endpoint returned HTTP 404 on November 8.

### Controlled production dashboard capacity — November 9, 2026

**Passed.** The live production dashboard served 100 correct responses across five rounds of 20 concurrent users. There were zero failures and all 100 responses completed in under 3 s. Median response time was 1.81 s, p95 was 2.13 s and the range was 0.61–2.17 s. The gateway reported zero queue and container-start time on all measured requests.

The test used one financial secretary and 19 members in the synthetic FF322 family, whose fixture had one contribution, one receipt and one expense. Each user signed in separately. The timed requests used warm sessions, fresh connections and no retries. Each response matched the expected authenticated user, family, dashboard component and financial data. Timing covered the complete dashboard HTTP response. Sign-in, browser rendering and writes to the ledger were outside the workload.

Every measured response reported runtime revision `github-83dd640d3d14`. The live Cloudflare container's image digest `sha256:7252a3eccb53de527efd4e0f7ff1fb254c1ae52a0553635bafce50e2857d6883` matched the production deployment receipt. The 20 temporary users and their memberships were removed, and FF322 returned to its three pre-test members with the same ledger counts. The production health check returned HTTP 200 after cleanup. [Production release #329](https://github.com/hussain4real/contribution-tracker/pull/329) · [Deployment](https://github.com/hussain4real/contribution-tracker/actions/runs/42373642836)

### Browser task completion with production users

The FamilyFund owner reported that the member receipt task and the officer payment review task both finished without help and described the experience as “fast and okay.” Participants did not time their attempts, and starter counts by role were not reported. Per-role completion rates, medians and ranges remain pending.

For the timed follow-up, recruit consenting members who have an existing receipt and officers who can review a payment for an existing contribution. Participants use their own production accounts.

Start from the signed-in family dashboard. Participants read the task before starting and use a stopwatch from their first navigation action to the specified finish. Use the first attempt per person and allow up to three minutes. If a participant requests help, stops, or reaches the time limit, record that outcome and the point they reached.

| Role and device | Task | Finish |
| --- | --- | --- |
| Member on their usual phone | Open My Contributions, find the outstanding amount or confirm there is none, and download an existing receipt | The participant has found the balance and opened the receipt PDF |
| Officer on desktop | Open Payments, select an existing member with an outstanding contribution, and enter a valid amount for review | The payment preview shows the intended member, amount and allocation period. Stop at the preview |

Record only an anonymous participant label, role, device and browser, elapsed seconds, outcome and any friction without member names, amounts or screenshots. For each role, report the number who completed unaided out of all eligible starters, and the median and range for successful attempts. Report assisted, abandoned and timed-out attempts separately. Keep the local automated timings as a separate baseline.

## Phase 1 — Make payments clear and dependable

Phase 1 was released to production on November 5, 2026. Member money information, payment review, reminder preferences, channel delivery visibility and eligible retries are available. Staging checkout and receipt reversal were exercised with Paystack test mode, and the owner checked the production member contribution view. Ongoing measurements and real delivery recovery remain follow-up work.

### Production checkout and receipt activity — November 12, 2026

Read-only queries against the D1 database currently bound to the production Worker checked November 5 at 21:27:22 through November 12 at 21:27:22 Qatar time (18:27:22 UTC at each boundary). A separate window from the Phase 1 release on November 5 at 09:14 Qatar through the same cutoff returned the same counts. Windows include their start and exclude their end.

| Measure | Latest seven days | Since Phase 1 release | Rate |
| --- | --- | --- | --- |
| Recorded Paystack contribution checkout starts | 0 | 0 | Not measurable; zero eligible attempts |
| Provider verifications and allocations with linked receipts | 0 | 0 | Not measurable; zero eligible attempts |
| Real receipts created | 0 | 0 | No eligible receipt cohort |
| Real email receipt activity | 0 attempts, 0 provider acceptances | 0 attempts, 0 provider acceptances | Not measurable |
| Real WhatsApp receipt activity | 0 attempts, 0 confirmed deliveries | 0 attempts, 0 confirmed deliveries | Not measurable |

The only new receipt in either window remains FF322's synthetic cash receipt #1, payment batch #208322577, confirmed reversed. Its email delivery row shows an attempt and provider acceptance. Its WhatsApp row remains unavailable with no attempt. Excluding the FF322 and production UAT Organization families leaves zero receipt-delivery activity. Checks also found no updated older checkout records, no activity on older real receipt-delivery records, and no linked WhatsApp status updates for real receipts in either window.

**Measurement remains pending.** Zero eligible attempts gives no completion or delivery percentage, rather than a 0% or 100% result. Email provider acceptance does not prove inbox delivery. Delivery rows retain current state and timestamps rather than a complete attempt history, so future retry-frequency measurements need event evidence. This database refresh covers persisted production records. The subsequent request/application-log check is recorded below. Provider dashboards were not rechecked. The November 9 request-log observations remain historical.

Use the first real post-release checkout and receipt cohorts to calculate rates with explicit denominators. Keep synthetic acceptance activity separate and measure provider-failure recovery only when a qualifying failure and recovery are observed.

### Production initialization log check — November 12, 2026

No Paystack initialization failure was observed in the available production logs. The follow-up checked Cloudflare Workers Observability, including container logs, and HTTP edge-request analytics through November 12 at 21:33:06 Qatar time. The initial request for the full period since Phase 1 was shortened by the telemetry API to its available seven-day window. Daily follow-up searches cover November 5 at 21:36 through November 12 at 21:33:06 Qatar (November 5 18:36 through November 12 18:33:06 UTC). They do not cover the first roughly twelve hours after the Phase 1 release.

| Check | Observed result |
| --- | --- |
| Seven daily account-wide telemetry searches for `/pay/initiate`, `Paystack API error`, `/transaction/initialize`, or `Failed to initialize payment` | No matching events in any daily window. Every daily query reported `abr_level=1`, avoiding the query sampling used by the initial week-wide search. |
| HTTP edge analytics for `familyfunds.app`, visitor requests, and paths containing `/pay/initiate` | No observed rows, across all response statuses, from November 5 at 21:35 through the same cutoff. This analytics dataset uses adaptive sampling. |
| Production logging coverage in the final 24 hours | 1,115 managed-Worker fetch events and 1,238 production-container log events, each query reporting sampling interval 1. The empty initialization searches occurred while production logging was active. |

The production container was matched to the `familyfunds-production-managed` application by the Cloudflare application inventory. Account-wide searches included container logs as well as Worker events, so they did not depend on containers sharing the Worker's service name. A broader `/pay/` search found a 404 for a request to a Vue source-asset path, which is not the checkout initiation endpoint and was excluded from checkout activity.

The inspected repository implementation in `MemberPaymentController::initiate` validates the request and configuration, calculates the contribution amount, and creates the local transaction before calling Paystack. Its handled initialization failure marks that transaction failed. Before-record failures can still occur during authentication, validation, configuration checks, or database creation. There were no observed initiation requests to attribute such failures to in this log window.

**Conclusion:** No evidence of hidden pre-record initialization failures was found. Completion and failure rates remain unmeasurable because no eligible checkout attempts were observed. Query sampling interval 1 does not prove complete ingestion or retention, and browser-only failures before an HTTP request reaches Cloudflare are outside these logs. No payment was initiated, no live failure was injected, and no production configuration was changed for this investigation.

### Production checkout and receipt activity — November 9, 2026

The seven-day production D1 window ran from November 2 at 12:53 to November 9 at 12:53 Qatar time. A separate check from completion of the Phase 1 deployment on November 5 at 09:14 through the same cutoff found the same counts. [Production deployment](https://github.com/hussain4real/contribution-tracker/actions/runs/41977665391)

| Measure | Real production activity | Rate |
| --- | --- | --- |
| Paystack contribution checkout | 0 recorded starts, 0 provider verifications, 0 allocations with a linked receipt | No eligible checkout attempts |
| Receipt delivery | 0 real receipts, 0 email attempts, 0 WhatsApp attempts | No eligible receipt or delivery attempts |

Cloudflare logs showed zero checkout initiation requests and zero receipt retry requests after the Phase 1 deployment. The only logged payment callback lacked a transaction reference. D1 showed zero verifications or allocations of older checkout attempts and zero delivery activity for older receipts during the seven-day window.

The one receipt was FF322's reversed synthetic cash receipt #1. Its email row recorded one attempt and provider acceptance. Its WhatsApp row was unavailable with zero attempts. This test supplies no confirmed delivery or retry recovery measurement. Email acceptance records that the provider accepted the message, and confirmed WhatsApp delivery uses a delivered or read status from Meta. Use the first real post-release checkout and receipt cohorts for completion and channel delivery rates.

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

On November 7, the production test Organization saved an approval threshold of ₦125 with one independent approver, and the settings change appeared in the audit trail. Request #68370433 required a separate approver and stayed out of the ledger through submission and approval. Recording it created exactly one ₦250 expense (#206585868). Reversal #206848003 returned the effective total to ₦0 and preserved the original entry. The approval settings save passed after the D1 guard fix. [Production #329](https://github.com/hussain4real/contribution-tracker/pull/329) · [Deployment](https://github.com/hussain4real/contribution-tracker/actions/runs/42373642836)

The production test Organization uploaded synthetic PNG #70270977 to request #68370433. The upload created an audit event, and the officer downloaded a byte-for-byte match. The same download URL returned HTTP 403 for a regular member of the test Organization and an officer from another family. The other family's URL with the same document ID also returned HTTP 403.

## Phase 3 — Engagement and growth

Phase 3 was deployed to staging and production on November 6, 2026. Its additive migration passed native D1 restore, forced-failure rollback, constraint, down-migration and recovery rehearsals before independent application to both environments. Both deployments passed runtime, image and database integrity checks. Production acceptance covered the journeys listed in the status table above; the marked test goal was archived after verification. [Production release #318](https://github.com/hussain4real/contribution-tracker/pull/318) · [Staging release #319](https://github.com/hussain4real/contribution-tracker/pull/319)

The initial accounting model is a **visual target within the shared fund**. It does not reserve or transfer money. Each goal starts at zero on its start date. Contributions received counts effective payment amounts; net savings counts effective payments plus fund adjustments minus expenses. Entry dates must fall between the start date and deadline, through today. Reversals remove the original entries from progress. Progress can be negative or exceed the target; only the progress bar is clamped to 0–100%. Overlapping goals can use the same entries and must not be added together as separate balances. Supporting links open the matching ledger range. Personal-only visibility hides family goals; summary visibility allows goal totals; transaction visibility allows source entries. Admins manage goals and edits/archive changes retain audit events.

Setup progress is derived from current family records so it can be resumed without separate checklist state. Workspace creation, contribution categories, another active member or pending invitation, and the first effective payment are required steps. Bank details and a custom reminder schedule are optional. The sample member preview is read-only and inserts no financial data.

AI personal arrears defaults to all years, excludes other members and families, and calculates outstanding per contribution so an overpayment cannot cancel another period's dues. Financial read tools recheck active membership and current visibility, include an as-of time, currency, reporting period and authorized source links, and disclose truncated entry lists. Financial tools prepare encrypted, ten-minute drafts bound to the actor, family and action. The user reviews and submits an existing FamilyFund form; chat confirmation cannot post a payment, expense, adjustment or generated contribution. Existing allocation review, approvals and idempotency checks remain authoritative. Invitations retain their existing confirm-first flow.

On November 7, the production AI summary showed zero posted expenses before payment was recorded, one expense totaling ₦250 after posting, and zero effective expenses totaling ₦0 after reversal. The test expense was dated November 1, the first day of the requested reporting period. The saved conversation reopened, and its source links led to the matching activity report. The assistant explained that reversed entries are excluded from the count and total, while the activity history retained the reversal. [Expense summary and telemetry #322](https://github.com/hussain4real/contribution-tracker/pull/322) · [Date range fix #324](https://github.com/hussain4real/contribution-tracker/pull/324) · [AI runtime release #328](https://github.com/hussain4real/contribution-tracker/pull/328)

### 8. Family goals or dedicated funds

- Allow a family to define a goal, target and deadline and view progress. Explore reuse of the roadmap's campaigns and special contributions work.
- Decide first whether a goal is a visual target or a separately accounted fund; define how payments, expenses, reversals and reports affect it.

**Acceptance:** Progress has a documented calculation and reconciles with its underlying entries; the chosen accounting model is clear to users.

### 9. Guided onboarding

- Guide a new family admin through family setup, contribution rules, member invitations and the first payment or collection.
- Show progress, explain optional steps and offer a preview without inserting sample financial records into the live ledger.

**Acceptance:** A new admin can complete or resume setup and invite a member without assistance; no sample transaction is posted as real money.

The hosted production test reached the family dashboard after owner email verification. A newly registered invitee received the email, verified the account, accepted the invitation and reached the same dashboard.

An existing synthetic member of the test Organization accepted invitation #175013898 to the FF322 test family in a session opened through platform impersonation. The same account landed on the invited family's dashboard with exactly one new active membership and the assigned category. Its original membership remained active. This test used a seeded invitation link to exercise acceptance without sending another message.

The FF322 test family's first collection moved the setup checklist from 3 of 4 to 4 of 4 essentials. The downloaded PDF for receipt #1 (payment batch #208322577) showed the ₦1 cash test entry and its single November 2026 allocation. Reversal #208470019 returned effective collections to ₦0 and the checklist to 3 of 4. The receipt, allocation and synthetic member's unpaid November contribution remain in the test family.

### 10. AI assistant improvements

- Support questions about a member's own outstanding contributions and, for authorized officers, family expense summaries. Link answers to the underlying records and reporting period.
- Respect existing family and role permissions, communicate uncertainty and require explicit review and confirmation before any financial action.

**Acceptance:** Answers are grounded in authorized current records, link to supporting views and do not disclose restricted data or execute financial actions without confirmation.

A reviewed ₦1 AI expense draft was submitted in production and produced one expense (#173473804). Reversal #173522947 retained the original entry in the audit trail. [Draft acceptance coverage #322](https://github.com/hussain4real/contribution-tracker/pull/322)

Production draft replay protection passed in the FF322 test family on November 7, 2026. Two submissions of the same reviewed ₦1 draft left one ledger entry (#209371148). Reversal #212877315 returned effective expenses to ₦0 and preserved the original entry for audit. FF322 returned to Free after the test.

## Expense budgets and spending visibility — November 13, 2026

This extension follows the expense governance work and the budget item in the product completion roadmap. It does not depend on real Paystack activity. Family admins create reusable expense categories and set one advisory limit per category and calendar month. Officers can view spending and its supporting entries. Regular members cannot access these officer budget views. A zero limit means no planned spending; an unset limit means no warning applies.

- **Actual:** Effective posted expenses whose expense date falls in the selected month. Reversed expenses are excluded. Corrections keep the category and contribute through the replacement entry.
- **Committed:** Unpaid, unrejected requests dated in the month that meet both their captured approval requirement and the current family policy, using independent approvals. Requests awaiting enough approvals are excluded. Posting moves a request from committed to actual once. Reversing the posted expense removes actual spending without reopening the request as committed.
- **Remaining:** Monthly category budget minus actual and committed spending. Budgets are planning limits and neither reserve funds nor alter the family balance.
- **Warnings:** Direct expense and request forms show projected monthly spending before submission. The request list shows the advisory before approval and payment, without adding an already committed request twice. Exceeding a budget does not prevent a valid expense or bypass existing approval rules.
- **Traceability:** Actual and committed links show the same category, month and total, with links to individual expenses or requests. Family isolation and active officer permissions apply to both the page and preview endpoint.
- **Compatibility:** Existing expenses and requests remain Uncategorized. No historical ledger entries are rewritten. Category and budget changes are audited; family archive exports and retention cleanup include both new record types.

**Acceptance:** Totals reconcile to their drill-down records; month boundaries, reversals and approval-policy changes do not double-count spending; unauthorized users cannot see or change budgets; warnings remain advisory; the migration preserves existing expense data.

**Local verification:** Eleven budget feature tests passed with 201 assertions, including scoped authorization, idempotency, month boundaries, correction/reversal accounting, independent approvals, stronger current/captured approval rules, archive exports and retention cleanup. The SQLite migration test applied, rolled back and reapplied the schema while preserving existing expenses and requests and passing foreign-key checks. Two synthetic Chromium journeys passed with 33 assertions: mobile category/budget creation, separate monthly limits, over-budget direct posting and actual drill-down; and request submission, separate approval, committed spending and the request link. The 390 px budget layout was inspected. PHPStan, TypeScript, targeted ESLint, PHP formatting, and client/SSR builds passed. These are local results, not hosted acceptance or native D1 migration proof.

The broader unit/feature suite ran 1,919 tests: 1,853 passed, 39 were skipped, and 27 stopped in existing signing-key fixtures because the sandbox prevented OpenSSL from writing its random-state file. All 27 passed on a scoped rerun using a temporary writable `RANDFILE`; no application change was needed for that environment issue. Combined verification therefore covers 1,880 passing unit/feature tests, with 39 skips. The focused budget, expense governance, expense CRUD and retention regression run also passed all 62 tests (512 assertions).

**Release status:** Local implementation only. One additive migration creates categories and monthly budgets and adds nullable category references to expenses and requests. Native D1 migration and recovery verification passed on an unbound rehearsal database using the current production schema and synthetic records. The affected staging table definitions match. Forced-failure rollback, up/down/reapply, constraints, spending calculations, trigger preservation, new cache/cutover guards and bookmark recovery passed; see [native D1 evidence](../../deployment/d1-proof/expense-budget-migration.json). The migration uses inline nullable references on SQLite/D1 to avoid rebuilding ledger tables. CI, merge, staging and production application/deployment, and hosted acceptance remain outstanding. Browser task timings and payment/delivery production measurements elsewhere in this plan remain pending.

## Cross-cutting UX and delivery

- Review each phase on small screens and with keyboard and screen-reader navigation. Use readable tables, appropriate touch targets and clear loading, empty and error states.
- Preserve immutable financial records, family isolation and existing authorization. Use private storage for financial documents.
- Deliver in independently testable slices. For each slice, validate behavior locally and in CI, then verify the deployed user journey separately in staging and production as appropriate.
- Measure outcomes against the baseline: payment completion, delivery failures and recovery, dashboard task completion, support requests and response times. Define numerical targets after baseline collection.

## Decisions and remaining product choices

The current release uses visual goals within the shared fund, defaults members to family totals, and gives eligible officers financial review and delivery-retry actions. Family reminder timing and channels and member notification preferences are configurable. Organization plans can enable independent expense approvals.

Outstanding product choices are the numerical outcome targets, any future separately accounted funds or campaigns, and further changes to role access, delivery states or plan entitlements based on observed use and provider capabilities.
