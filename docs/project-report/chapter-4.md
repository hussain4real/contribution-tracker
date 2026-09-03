# CHAPTER FOUR

## SYSTEM IMPLEMENTATION AND TESTING

### 4.1 Implementation of the System Design

#### 4.1.1 Introduction to the Chapter

This chapter reports how the FamilyFunds design described in Chapter Three is represented in the application and how that representation will be validated. It separates three kinds of evidence that must not be confused. The first is **static implementation evidence**, such as a named service, policy, migration, page, or automated test in the repository. The second is **executed evidence**, such as the output of a fresh test, build, browser, performance, or security run against the final revision. The third is **external evidence**, such as a live AI-provider response, hosted deployment check, populated screenshot, or predictive-model evaluation that depends on data or services outside the source tree.

The repository provides substantial static evidence for the conventional system modules and the predictive implementation pipeline. Fresh post-change evidence was executed on 15 August 2026. The canonical local quality gate passed 1,421 Pest tests with 6,904 assertions and 100.0% PHP coverage in 22.209 seconds; the same gate passed PHPStan, Pint, Prettier, ESLint, and the client and server-side-rendering builds. Its locked Python stage passed 66 machine-learning tests with 100% statement coverage. Focused checks additionally passed 138 Laravel payment-risk tests with 440 assertions and 100% coverage across the 16 payment-risk PHP files; the same 138 tests and 440 assertions passed against a disposable PostgreSQL database; five focused Chromium browser tests passed with 82 assertions; and a combined local payment-risk, subscription, and package-script check passed 171 tests with 517 assertions. These focused results overlap the canonical suite and are therefore not added into one artificial total. Twenty redacted running-system images were also captured from the synthetic development tenant and are presented as Figures 4.1a–4.14. Hosted checks, live-provider verification, performance measurements, recovery evidence, and authorised-data predictive training remain **Partially Met** or **Not Executed**. Commit `a699a821` is a pre-change hosted baseline and is not accepted as final Chapter Four evidence.

#### 4.1.2 Implementation and Evidence Environment

The development environment was inspected directly for this report track. FamilyFunds remains a modular Laravel application with a Vue/Inertia interface, PostgreSQL data design, background jobs, scheduled commands, external payment and messaging integrations, and a controlled AI layer. The local computer, installed command-line versions, and disposable PostgreSQL integration environment can be reported now. The final hosted revision, deployment-service state, browser version, live provider behaviour, and authorised predictive-training environment still require external evidence.

*Table 4.1: Implementation and Evidence Environment*

| Area | Current evidence | Purpose | Evidence status |
| --- | --- | --- | --- |
| Development computer | Apple MacBook Pro, Apple M5 Pro, 15 CPU cores, 24 GB RAM, arm64 | Local development and document preparation | Observed |
| Operating system | macOS 26.6 | Local host environment | Observed |
| PHP runtime | PHP 8.5.8 | Laravel application runtime | Observed |
| Composer | Composer 2.10.1 | PHP dependency management | Observed |
| Backend framework | Laravel 13.24.0 | Routing, policies, models, jobs, services, and commands | Observed from installed dependencies |
| Frontend runtime and tools | Node.js 24.15.0, npm 11.14.1, Vite 8.2.1 | Vue build and browser assets | Observed |
| Frontend frameworks | Vue 3.5.41, Inertia Vue 3.6.1, Tailwind CSS 4.3.3 | Responsive application interface | Observed from installed dependencies |
| Testing | Pest 5.0.4 with Laravel and browser plugins; canonical local run passed 1,421 tests/6,904 assertions at 100.0% PHP coverage; focused runs passed 138 payment-risk tests/440 assertions, 5 browser tests/82 assertions, and 171 combined tests/517 assertions | Unit, feature, integration, and browser specifications | Post-change local gate passed; hosted gate pending |
| Database | PostgreSQL 18.4 on arm64, including a disposable migrated integration database | Persistent family and financial records | Migration smoke and focused payment-risk integration passed: 138 tests/440 assertions |
| Hosted environment | HTTPS application with queues, scheduler, backups, and monitoring required | Deployment and operations | Not Executed for final revision |
| AI provider | Provider- and model-configurable Laravel AI integration | Controlled assistant and summary generation | Live evidence Not Executed |
| Predictive runtime | Python 3.12.3 package plus Laravel artefact installation and scoring services | Logistic regression with training-prevalence and previous-period-late baselines | 66 Python tests passed at 100% coverage; authorised-data training Not Executed |

No authorised private predictive-training CSV matching the twelve-column contract is present in `docs/project-report` or the supplied project evidence. Consequently, no dataset row count, class distribution, coefficient, threshold, accuracy value, confusion matrix, or prediction screenshot is reported. The model must remain inactive until an authorised dataset is supplied and the PRED evidence gates in Section 4.3 are completed.

#### 4.1.3 Design-to-Implementation Traceability

The design in Chapter Three is realised through request middleware, policies, Eloquent models, domain services, controllers, queued jobs, scheduled commands, Vue pages, and automated tests. Shared-schema multi-tenancy is not enforced by a single mechanism. The active family is resolved from the authenticated membership and route context; family-owned queries include family identifiers; policies test membership and role; validation constrains identifiers to the current family; and feature tests exercise cross-family rejection. This layered approach is important because financial isolation would be fragile if it depended only on a hidden form value or one global query scope.

Financial correctness is similarly distributed. Monthly contribution obligations remain separate from payment receipts and allocation rows. `PaymentAllocationService` creates an immutable receipt batch, locks the family and membership records, orders incomplete contributions from oldest to newest, allocates the available amount, and limits advance allocation. Paystack processing verifies signatures and references before settlement, while reporting uses shared query logic so displayed, CSV, PDF, receipt, and scheduled outputs can be compared against the same totals.

The implementation also distinguishes authoritative application decisions from external-service observations. Paystack may attest to a transaction, a mail provider may accept a message, and an AI provider may return generated text, but none of those services owns the family ledger or its permissions. Laravel validates the external response, maps it to a known family-owned record, and applies a domain service or rejects the request. This boundary makes external failures recoverable: a provider timeout should leave a pending job or failed delivery record, not a half-written receipt. The same principle applies to the predictive component. A calculated probability is advisory evidence; it cannot create an expense, alter an obligation, block a member, or override an officer's governed decision.

The database design has grown beyond the entities shown in the original ER diagram. Membership history, payment batches, reversals, report artifacts, report deliveries, bank transactions, reconciliation periods and links, provider settlements, and retention state support requirements that became clearer during implementation. Chapter Three has been amended only where necessary to acknowledge these outcomes. Chapter Four retains the distinction between the original design and later extensions so an examiner can follow the real development path rather than a retrospective idealisation.

*Table 4.2: Design-to-Implementation Traceability*

| Design area | Principal implementation evidence | Verification evidence located | Current conclusion |
| --- | --- | --- | --- |
| Tenant isolation | Family context middleware, membership routing, family-aware policies and queries | `FamilyMembershipRoutingTest`, policy tests, AI tool isolation tests | Met in the canonical local suite |
| Roles and permissions | Role helpers, policies, middleware, request authorisation | `RoleAccessTest`, member and policy tests | Met in the canonical local suite |
| Contributions | Contribution model, generation command/controller, category history | Generation, status, due-date, and Phase One tests | Met in the canonical local suite |
| Payment allocation | `PaymentAllocationService`, immutable batches and allocations | Allocation, balance-first, partial and advance-payment tests | Met in the canonical local suite |
| Paystack | Service, webhook job/processor, settlement service | Service, webhook, ledger, fee, and idempotency tests | Partially Met; live gateway not executed |
| Expenses and adjustments | Controllers, policies, reversal actions, observers | CRUD, policy, model, reversal, and AI tool tests | Met in the canonical local suite |
| Reporting | Shared review query, private artifacts, receipts, schedules | Monthly, annual, Phase Three reporting and scheduled-report tests | Partially Met; rendered final artifacts pending |
| Notifications | Email, Web Push, WhatsApp channels and reminder commands | Manual reminder, channel, webhook, and notification tests | Partially Met; delivery not executed |
| Authentication | Fortify flows, 2FA, passkeys, password and verification settings | Authentication, passkey, 2FA, reset, and verification tests | Met in the canonical local suite; report capture pending |
| Controlled AI | Family assistant, sub-agents, role-aware tools, confirm-first writes | AI agent, tool, middleware, and chat feature tests | Partially Met; live provider evidence not executed |
| Predictive analytics | Offline Python validation/training/evaluation package; portable artefact; Laravel validation, storage and scoring services | 66 Python tests at 100% coverage; 138 Laravel tests/440 assertions at 100% payment-risk-file coverage; PostgreSQL and browser reruns | Partially Met; software checks passed, but private-data training and activation Not Executed |
| Reconciliation extension | Imports, bank transactions, periods, matching, links, settlements | Phase Four and reconciliation unit/browser tests | Post-design extension; local automated checks passed |
| Retention and recovery | Archive, restore, purge, legal hold, backup configuration | Phase Three retention and backup tests | Partially Met; hosted recovery drill not executed |

Reconciliation deserves explicit treatment because it was added after the original Chapter Three requirement set. Iterative development exposed a gap between application records and external bank statements: a verified receipt, expense, adjustment, or provider settlement could be internally valid yet still require comparison with an imported transaction. The later reconciliation workspace introduced import batches, bank transactions, controlled matching, immutable links, closeable periods, and provider-settlement grouping. This is an Agile post-design extension that improves auditability; it is not presented as an original FR that existed before implementation.

### 4.2 Module Integration and Coding

#### 4.2.1 Tenancy, Membership, and Role Governance

FamilyFunds supports users who may belong to more than one family while operating within one current family context at a time. Membership records carry the family-specific role, contribution category, lifecycle state, and display information. Route middleware resolves the selected family, and policies make the final decision for protected records. An ordinary member can inspect personal contributions and payment history, while family administrators and financial secretaries receive wider operational views according to their duties. Platform administration is kept separate from family operations.

The membership model avoids storing all authority as one global property of the user. A person can therefore be a financial secretary in one family and an ordinary member in another without one role leaking across the boundary. Category assignments are dated so that a future contribution-rate change does not rewrite the amount that applied to an older obligation. Invitation acceptance attaches the user to the intended family only after token, expiry, identity, role, and category rules have been checked. Archive state is also membership-aware, allowing a person's relationship with one family to change without deleting the global account or unrelated memberships.

This design introduces more joins than a single `family_id` column on the user record, but it better represents the domain and supports auditability. Compatibility mirrors remain for earlier records, while backfill and routing tests protect the transition. The final evidence run must exercise both a single-family member and a person with multiple memberships because cross-context behaviour is where role leakage would be most likely.

Listing 4.1 shows the small but important context-binding step. It does not, by itself, authorise access; downstream membership checks, policies, validation, and scoped queries remain necessary.

*Listing 4.1: Family Context Binding*

```php
if ($user) {
    $family = $user->currentFamily ?? $user->family;

    if ($family instanceof Family) {
        app()->instance('current-family', $family);
        app()->instance(Family::class, $family);
    }
}
```

#### 4.2.2 Contributions and Oldest-Balance-First Allocation

Contribution categories define monthly expectations for memberships. Generation creates dated obligations, while status logic distinguishes unpaid, partially paid, paid, and overdue records. A receipt is modelled separately from its allocation lines, allowing one incoming amount to satisfy several months while preserving the amount, recorder, source, reference, and receipt number. The allocation service uses a transaction and row locks to prevent concurrent writes from producing inconsistent balances. Existing incomplete contributions are ordered chronologically; any permitted remainder can create future obligations only within the defined advance-payment limit.

*Listing 4.2: Oldest-Balance-First Payment Allocation*

```php
$contributions = Contribution::query()
    ->forUser($member)
    ->where('family_id', $family->id)
    ->where(fn ($query) => $query->incomplete())
    ->oldestFirst()
    ->lockForUpdate()
    ->get();

foreach ($contributions as $contribution) {
    $remainingAmount = $this->allocateLine(
        $batch,
        $contribution,
        $remainingAmount,
        $paidAt,
        $recordedBy,
        $notes,
        $replaces,
    );

    if ($remainingAmount === 0) {
        return 0;
    }
}
```

The listing omits receipt creation and future-period handling so that the core ordering decision remains visible. The full implementation also validates family membership, uses idempotency keys, assigns sequential receipt numbers, supports corrections through replacement batches, and rejects money that would exceed the six-month advance limit.

#### 4.2.3 Paystack, Expenses, Adjustments, Reports, and Notifications

Paystack is integrated as an external processor rather than the authority for family balances. A transaction is initialised within the application, and a signed callback or queued webhook is matched to a known reference before the settlement service is invoked. Duplicate success events must not create duplicate receipts. Amount mismatches, unknown references, missing data, and invalid signatures follow explicit rejection or ignore paths.

*Listing 4.3: Paystack Webhook Reference Verification*

```php
$reference = $data['reference'] ?? null;

if (! is_string($reference) || $reference === '') {
    return;
}

if (! PaystackTransaction::query()
    ->where('reference', $reference)
    ->exists()) {
    Log::info('Paystack webhook ignored an unknown transaction reference.', [
        'reference' => $reference,
    ]);

    return;
}

$this->settlementService->settle($reference, $data);
```

Expenses and fund adjustments use immutable financial records plus explicit reversal or correction actions. Reports draw from contribution, receipt, allocation, expense, adjustment, and reversal data. Private artifacts store their family, requester, type, format, normalised filter hash, path, size, and expiry. CSV cells are sanitised to reduce spreadsheet-formula risk; PDFs and receipts use A4 output; signed recipient links expire. Scheduled jobs can create and deliver the same report definitions without introducing a separate calculation path.

Separating receipt batches from allocation lines also clarifies reports. A family's cash receipt should appear once at its full value, while a member statement may need the individual month-by-month allocation lines. Reversals must remove the effective financial impact without deleting the historical record. The shared review service is intended to keep these interpretations consistent across screen, CSV, PDF, receipt, and scheduled delivery. The Phase Three reporting specifications are valuable static evidence because they compare these surfaces, but the final report must still render and inspect representative PDF and CSV outputs after all current changes.

Report artifacts are private by default. An authenticated, authorised requester may download an artifact through policy checks, while a recipient link uses a time-limited signature. The path is stored on the local private disk rather than a public asset directory. This matters academically because “a report was generated” is incomplete evidence if the report leaks another family or can be guessed indefinitely. The final screenshot set should show the report interface and access controls without exposing real family names, phone numbers, email addresses, bank references, or personal contribution histories.

Reminder workflows select unpaid or partially paid obligations and support the configured email, browser-push, and WhatsApp-related channels. Delivery state is operational evidence and cannot be established from source alone. The current report therefore records channel and command coverage as partial while leaving actual message delivery for the final evidence run.

#### 4.2.4 Controlled AI Assistant and Report Summaries

The assistant coordinates specialist agents for contribution, expense, balance, member-status, invitation, generation, and write workflows. Tools query current family data rather than relying on model memory. Their availability is filtered by role. Write operations follow a preview-and-confirm pattern: the initial tool call returns a human-readable description and the same details needed for execution; only a later confirmed call can mutate records. This reduces accidental changes and keeps Laravel policies, validation, and domain services in authority over the language model.

*Listing 4.4: Confirm-First AI Write Governance*

```php
if (! $confirmed) {
    return json_encode([
        'status' => 'confirmation_required',
        'message' => "I'll record a payment of {$formattedAmount} for {$member->name}. Please confirm to proceed.",
        'details' => [
            'member' => $member->name,
            'amount' => $amount,
            'paid_at' => $paidAt,
        ],
    ], JSON_THROW_ON_ERROR);
}
```

Static feature tests cover role-aware tools, family isolation, validation, preview responses, and confirmed execution. They do not prove the quality of a live model response. No provider-backed conversation or final report-summary example was executed for this document revision, so live AI relevance, latency, and wording remain Not Executed.

#### 4.2.5 Reconciliation as a Post-Design Extension

The reconciliation workspace accepts bank-transaction imports, preserves import and period state, proposes candidate internal records, supports explicit links, and locks closed periods. Exact automatic matching is deliberately narrow. A credit is automatically linked only where reference and amount produce one unique unmatched candidate; ambiguity produces suggestions for officer review. Date-and-amount proximity is useful for suggestions but is not sufficient for an automatic authoritative link.

Provider settlements are distinguished from member receipts so that a net bank deposit is not incorrectly matched to a gross contribution. Closed-period guards prevent late mutation unless an authorised reopening process is completed. These behaviours extend the original fund-management design while maintaining its role, tenant, audit, and immutability principles.

#### 4.2.6 Predictive Analytics Implementation Contract

The predictive component is implemented as two connected parts. An offline Python 3.12 package accepts a separately authorised CSV and provenance file, validates them, creates point-in-time features, performs a chronological split, fits and evaluates the approved model, and emits a portable JSON artefact and checksumed evidence files. Laravel validates that artefact again, stores it on a private disk, records immutable model-version metadata, permits activation only when all gates pass, and scores family-owned contribution targets. This separation keeps private training material and Python dependencies out of web requests while allowing deterministic PHP inference.

The approved predictive method is logistic regression only. The target is a binary overdue/on-time outcome for a contribution period: an obligation is overdue when the system still recorded an outstanding balance at the end of its due date. The fourteen implemented features are expected amount, days until due, calendar month and quarter, number of previous mature periods, three-period, six-period and lifetime on-time rates, previous partial-payment rate, mean and median recorded settlement delay, previous outstanding count and amount, and overdue streak. The outstanding-amount feature is operationally the due-date residual summed only for prior obligations still unsettled strictly before the scoring cutoff; this keeps offline and Laravel reconstruction identical under the locked twelve-column export. All features are constructed from data recorded strictly before the target obligation's creation-time cutoff. Direct identity attributes and target-period outcome data are excluded.

The input contract is deliberately strict. The uncommitted source must be `storage/app/private/payment-risk/source/member-periods.csv` and contain exactly: `family_key`, `member_key`, `obligation_key`, `period_start`, `obligation_created_at`, `due_date`, `expected_amount_minor`, `amount_paid_by_due_minor`, `first_payment_recorded_at`, `fully_paid_recorded_at`, `payment_count_by_due`, and `is_backfilled`. The three opaque pseudonymous keys support grouping and split integrity only; they are never predictors. The CSV digest must match its provenance JSON. Provenance records whether consent, de-identification and point-in-time correctness were confirmed. Because this fixed export cannot express the time-varying effect of later reversals, it also requires the explicit `system_known_by_cutoff_no_reversed_allocations` policy: histories containing reversed allocations must be excluded upstream before the minimum-data gate is applied. Backfilled obligations, incomplete periods, PII or extra columns, contradictory payment timestamps, duplicate obligations/member-periods, negative amounts, impossible chronology, non-mature outcomes, and obligations created fewer than seven days before their due date are rejected. The default quality gates require at least 500 valid rows, 50 distinct member histories, 12 complete periods, and 100 rows in each outcome class. Synthetic provenance may exercise the software path but is always activation-ineligible and cannot support real-world performance claims. These are implemented rules, not claims about the unavailable private dataset.

The label and prediction cutoff are fixed before data is examined. A feature such as “days late for the target period” would leak the answer because it becomes known only after the outcome window. By contrast, the previous mature periods' outcomes, settlement delays and outstanding amounts are available at the obligation-creation cutoff. The implemented artefact stores the ordered feature schema, standardisation means and scales, coefficients, intercept, threshold, training window, data-quality report, evaluation summary and human-readable factor labels so that training and inference cannot silently disagree.

Chronological evaluation is required because a random row split could place later records for the same member in training while earlier records appear in testing. The implementation keeps whole contribution periods together and assigns the earliest 60% to training, the next 20% to validation, and the latest 20% to held-out evaluation. Standardisation and the class-balanced L2 logistic regression are fitted only on training data. The decision threshold is selected only on validation data by maximising overdue-class F1 subject to at least 0.70 overdue recall, with precision and threshold as deterministic tie-breakers. The latest partition is not consulted during fitting or selection.

*Listing 4.5: Logistic Regression Training*

```python
scaler = StandardScaler()
training_features = scaler.fit_transform(split.training.features)
classifier = LogisticRegression(
    C=1.0,
    class_weight="balanced",
    max_iter=2_000,
    penalty="l2",
    random_state=RANDOM_SEED,
    solver="liblinear",
)
classifier.fit(training_features, split.training.targets)
validation_probabilities = classifier.predict_proba(
    scaler.transform(split.validation.features)
)[:, 1]
threshold = select_validation_threshold(
    split.validation.targets,
    validation_probabilities,
)
held_out_probabilities = classifier.predict_proba(
    scaler.transform(split.held_out.features)
)[:, 1]
```

Two baselines are computed on the same held-out partition. The training-prevalence baseline assigns every held-out record the late-class prevalence learned from the training partition. The previous-period-late baseline predicts late when the feature snapshot contains a positive overdue streak. Evaluation records accuracy, balanced accuracy, overdue precision, recall and F1, ROC-AUC, PR-AUC, Brier score, confusion matrix, and 95% bootstrap intervals. The training package also writes confusion-matrix, ROC, precision-recall, calibration and coefficient charts. No populated chart is included in this report because the authorised dataset has not been supplied.

*Listing 4.6: Baseline Comparison and Activation Gates*

```python
training_prevalence = float(np.mean(split.training.targets))
prevalence_probabilities = np.full_like(
    split.held_out.targets,
    training_prevalence,
    dtype=np.float64,
)
prevalence_baseline = _binary_metrics(
    split.held_out.targets,
    prevalence_probabilities,
    0.5,
)
overdue_streak_index = FEATURE_NAMES.index("overdue_streak")
previous_period_probabilities = (
    split.held_out.features[:, overdue_streak_index] > 0
).astype(np.float64)
previous_period_baseline = _binary_metrics(
    split.held_out.targets,
    previous_period_probabilities,
    0.5,
)

activation_checks = {
    "consented_anonymized_provenance": provenance.source_type == "consented_anonymized"
    and provenance.consent_confirmed,
    "data_quality_gate_passed": data_quality["gate_passed"],
    "validation_recall_at_least_0_70": validation["recall_overdue"]
    >= MINIMUM_VALIDATION_RECALL,
    "held_out_f1_beats_both_baselines": held_out["overdue_f1"] > baseline_f1,
    "held_out_balanced_accuracy_above_0_5": held_out["balanced_accuracy"] > 0.5,
    "held_out_brier_beats_training_prevalence": held_out["brier_score"]
    < prevalence_baseline["brier_score"],
}
```

Activation is intentionally stricter than successful fitting. The training command can produce a candidate artefact, but the Laravel installer continues to reject activation unless the Python decision and independent PHP checks agree. The private artefact is identified by schema and model versions, ordered features, SHA-256 digest, training window, trained timestamp, threshold, dataset summary and metrics. The repository refuses an overwritten version with a different digest, prevents mutation or deletion of installed metadata and predictions, accepts only one active version, revalidates the artefact before scoring, and rejects a stale training window.

Runtime scoring also handles sparse history explicitly. Fewer than three mature periods produces an unavailable result; three to five periods use the training-prevalence pooled baseline instead of an individual probability; six to eleven periods are labelled experimental; and twelve or more periods receive a standard logistic advisory. The application records the immutable cutoff, history tier, feature-snapshot hash, model version, advisory band and top coefficient contributions. These results are designed for routine or priority review only and cannot directly penalise a member or mutate the ledger.

The implemented interface is officer-only through the report permission, subscription entitlement and predictive feature flag. Its readiness state reports why no active model can be used, while a ready state exposes the version, training window, threshold, period/band filters, summary counts, member history tier, advisory band and explanatory factors. The page states that the output is not a credit score and cannot send reminders, restrict access, change contribution terms or automate an action. A focused Chromium run passed five tests with 82 assertions, covering administrator and financial-secretary access, member denial, tenant switching, refresh-safe filters, synthetic populated and unavailable states, keyboard use, dark mode and a 390-pixel layout without JavaScript, accessibility or smoke errors. The running-system unavailable state is shown in Figure 4.12. This verifies the interface contract and fail-closed presentation, but it does not substitute for an authorised active-model result.

No authorised private predictive-training CSV, executed training output, fitted coefficient set, candidate artefact, active model, or real-model populated predictive screenshot was available to this report task. The populated state used by the browser test was synthetic test state and is not model-performance evidence. It would be misleading to include representative coefficients or fabricated results. Listings 4.5 and 4.6 are repository implementation excerpts, not evidence that training or activation occurred. PRED-01–PRED-10 define the exact evidence that must be added after an authorised dataset is available.

#### 4.2.7 Interface and Screenshot Evidence Register

The captured interface sequence follows the user journey and the financial control flow rather than presenting disconnected pages. All twenty assets came from the operating local application and its synthetic development tenant. They contain no production records or credentials, and no payment, reminder, schedule, reconciliation link or external-provider request was submitted during capture. Where a view depends on evidence that was unavailable, the figure shows the genuine fail-closed or read-only state instead of a mock result.

*Table 4.3: Chapter Four Interface Evidence Register*

| Figure | Required running-system view | Primary requirements | Current status |
| --- | --- | --- | --- |
| 4.1 | Login, password confirmation, and account-security settings | FR1, NFR3 | Executed; three panels |
| 4.2 | Family administrator dashboard | FR2, FR15 | Executed |
| 4.3 | Member, role, and category management | FR3–FR5 | Executed |
| 4.4 | Contribution register with paid, partial, unpaid, and overdue states | FR6–FR7 | Executed |
| 4.5 | Oldest-first allocation, member statement, and receipt | FR8, FR11 | Executed; three panels |
| 4.6 | Paystack self-pay review state | FR9–FR10 | Interface executed; live provider transaction Not Executed |
| 4.7 | Expense and fund-adjustment records | FR12–FR13 | Executed; two panels |
| 4.8 | Reports, exports, schedules, and generated financial output | FR14 | Executed; two panels |
| 4.9 | Seeded contribution-reminder notification | FR16 | Interface executed; no delivery request sent |
| 4.10 | Subscription entitlements and family limits | FR17 | Executed |
| 4.11 | Existing controlled-AI conversation | FR18–FR19 | Interface executed; no live provider call made |
| 4.12 | Predictive model-unavailable state | FR20, NFR13–NFR14 | Executed; populated model result Not Executed |
| 4.13 | Reconciliation workspace and settlement candidate | Post-design extension | Executed; no statement import or link submitted |
| 4.14 | Responsive member dashboard at 390 pixels | NFR6 | Executed |

Figures 4.1a–4.1c record the authentication boundary from the initial sign-in screen through password confirmation to the security controls. The sequence matters because sensitive settings are not presented as an extension of a public login form; the authenticated user must re-establish intent before changing password, two-factor or passkey settings.

![Figure 4.1a: Login Entry for the Synthetic Demonstration Tenant](screenshots/figure-4-01-login-security.png)

![Figure 4.1b: Password Confirmation Before a Sensitive Setting](screenshots/figure-4-01-password-confirmation.png)

![Figure 4.1c: Account Security, Two-Factor and Passkey Settings](screenshots/figure-4-01-security-settings.png)

Figure 4.2 shows the administrator's current-family dashboard, where contribution and balance information is summarised without exposing another tenant. Figure 4.3 then shows the management context in which roles and contribution categories are assigned. Together they connect the tenancy and RBAC design to the officer's normal operating view.

![Figure 4.2: Family Administrator Dashboard](screenshots/figure-4-02-admin-dashboard.png)

![Figure 4.3: Member, Role and Contribution-Category Management](screenshots/figure-4-03-members-roles-categories.png)

Figures 4.4 and 4.5 show the ledger rule from both sides. The register exposes paid, partial, unpaid and overdue obligations, while the payment screen explains how an amount is applied to the oldest open periods. This visible ordering supports the same deterministic rule exercised by the automated tests.

![Figure 4.4: Contribution Register and Payment States](screenshots/figure-4-04-contribution-register.png)

![Figure 4.5: Oldest-First Payment Allocation Review](screenshots/figure-4-05-payment-oldest-first.png)

Figures 4.6a–4.6c follow the resulting member evidence. The statement presents the obligation history, the receipt records how one batch was split across periods, and the Paystack page shows the self-pay review state before any external request. The last panel is interface evidence only; no live transaction was initiated during capture.

![Figure 4.6a: Member Contribution Statement](screenshots/figure-4-06-member-statement.png)

![Figure 4.6b: Receipt Showing Split Allocation Across Periods](screenshots/figure-4-06-payment-receipt.png)

![Figure 4.6c: Paystack Self-Pay Review State Before Submission](screenshots/figure-4-06-paystack-self-pay.png)

Figures 4.7a and 4.7b show that spending and balance corrections are recorded as separate operations rather than being hidden inside a contribution entry. This separation supports clearer authorisation, reversal and audit treatment.

![Figure 4.7a: Expense Register](screenshots/figure-4-07-expenses.png)

![Figure 4.7b: Fund-Adjustment Register](screenshots/figure-4-08-fund-adjustments.png)

Figures 4.8a and 4.8b connect report controls to a rendered financial output. The first panel shows filters, export choices and schedule management; the second shows the generated report based on the same underlying family records. This is stronger evidence than an isolated download button because the displayed result can be compared with the application totals.

![Figure 4.8a: Reports, Exports and Schedule Controls](screenshots/figure-4-09-reports-exports-schedules.png)

![Figure 4.8b: Generated Financial Report Output](screenshots/figure-4-09-financial-report-output.png)

Figure 4.9 contains an existing seeded reminder notification and does not claim a newly delivered message. Figure 4.10 shows the plan-entitlement comparison with Growth identified as the current assigned plan, demonstrating how paid features are communicated after the shared-plan defect discovered during capture was corrected and regressed.

![Figure 4.9: Seeded Contribution-Reminder Notification](screenshots/figure-4-10-reminder-notification.png)

![Figure 4.10: Current Growth Plan and Feature Entitlements](screenshots/figure-4-11-subscription-entitlements.png)

Figure 4.11 shows an existing seeded assistant conversation in the controlled interface. It demonstrates the conversation layout and family context, but it is not counted as a live-provider acceptance result because no provider request was made during this evidence pass.

![Figure 4.11: Existing Controlled-AI Conversation in the Synthetic Tenant](screenshots/figure-4-12-controlled-ai-conversation.png)

Figure 4.12 records the predictor's actual fail-closed response: without an installed, validated and active private-data model, the page reports that payment-risk insights are unavailable. Figure 4.13 shows the reconciliation workspace and an existing settlement candidate without importing a statement or creating a link. These are useful boundary results because they show what the system refuses to infer or mutate when prerequisite evidence is missing.

![Figure 4.12: Predictive Analytics Model-Unavailable State](screenshots/figure-4-13-payment-risk-unavailable.png)

![Figure 4.13: Reconciliation Workspace and Seeded Settlement Candidate](screenshots/figure-4-13-reconciliation-workspace.png)

Finally, Figure 4.14 shows the member dashboard at a 390-pixel viewport. Navigation, summary cards and contribution information remain contained within the mobile width, supporting the focused Chromium result without treating one viewport as a complete usability study.

![Figure 4.14: Responsive Member Dashboard at 390 Pixels](screenshots/figure-4-14-responsive-mobile-dashboard.png)

### 4.3 Testing Strategy and Procedures

#### 4.3.1 Test Plan

Testing is organised by risk. Unit tests isolate calculations and matching rules. Feature tests exercise routes, policies, validation, database changes, jobs, and service integration. Browser tests cover rendered user journeys and responsive behaviour. External integration tests fake or stub providers where deterministic automated proof is required; final live checks are reported separately because they depend on credentials, network access, and provider state. Security evidence includes cross-family denial, role denial, signature validation, signed-link expiry, immutable financial records, and confirmation-before-write behaviour. Performance evidence must measure a declared dataset and environment rather than relying on subjective impressions.

Developer-authored automated tests are the only tester evidence currently identified. No peer-testing session, end-user acceptance session, usability survey, or controlled comparative user study is claimed. If those activities occur later, the participants, task script, consent treatment, sample size, and results must be recorded before they are discussed.

The final verification sequence should minimise evidence drift. Focused machine-learning, Laravel, PostgreSQL and browser checks, the canonical local quality gate, and the redacted screenshot journeys have been completed against the post-change worktree. The next evidence stage is to commit the reviewed revision and obtain a fresh hosted gate. Performance probes, recovery exercises and live integrations must identify that same revision. If a later fix changes application behaviour, affected evidence must be repeated; an earlier successful screenshot or hosted run cannot certify a newer commit.

For each TC row, the final “Actual” field should describe the observed state rather than repeat the expected result. A suitable entry might name a returned status, created record, denied response, unchanged count, or rendered total, together with its test or capture reference. A failed case should remain in the table with the failure, corrective action, and rerun result. This structure makes the test reproducible and avoids the weak statement that a page “works.”

*Table 4.4: Testing Strategy and Evidence Sources*

| Test level | Objective | Primary evidence source | Final evidence required | Current status |
| --- | --- | --- | --- | --- |
| Unit | Verify isolated allocation, fee, enum, matching, and configuration behaviour | Pest and pytest unit specifications | Canonical local gate passed within 1,421 Pest tests/6,904 assertions at 100.0% PHP coverage; 66 Python tests passed at 100% coverage | Met locally |
| Feature | Verify authorised application workflows and persistence | Pest feature specifications | Canonical local gate passed; focused payment-risk suite also passed 138 tests/440 assertions at 100% payment-risk-file coverage | Met locally |
| Integration | Verify payments, reports, jobs, channels, AI tools, and reconciliation boundaries | Service/feature specifications with fakes and PostgreSQL smoke | Canonical integrations passed; disposable PostgreSQL migration and focused rerun passed 138 tests/440 assertions | Met locally; live providers remain separate |
| Browser/system | Verify complete responsive user journeys | Pest browser specifications and redacted running-system captures | Focused predictive browser run passed 5 tests/82 assertions; 20 synthetic-tenant images captured and inspected | Met for the recorded local journeys |
| Security | Verify tenant, role, signature, immutable-record, and signed-link controls | Policies and negative-path specifications | Canonical local negative paths passed; focused predictive authorisation, tenant, immutability, and advisory-only paths also passed | Met for automated scope |
| External/live | Verify Paystack, message delivery, hosted services, and AI provider | Authorised test accounts and hosted environment | Time-stamped final observations | Not Executed |
| Predictive | Verify data contract, baselines, logistic regression, metrics, artefact, and activation | PRED-01–PRED-10 | Software verification passed in Python, Laravel, PostgreSQL and Chromium; authorised CSV and evaluation artefacts absent | Partially Met |
| Performance | Quantify response time and resource use against NFRs | Declared benchmark dataset/environment | Raw results, summary table, and chart | Not Executed |

#### 4.3.2 Structured System Test Cases

The following cases cover the functional requirements and the major post-design controls. “Met” means the relevant deterministic checks passed in the fresh canonical local run. “Partially Met” means the automated portion passed but the case also requires external-provider, hosted, rendered, recovery, or performance evidence that was not executed. It does not convert an absent live observation into a pass.

*Table 4.5: Structured System Test Cases and Current Results*

| ID | Test objective | Preconditions | Test input(s) | Expected output | Actual output/evidence | Status |
| --- | --- | --- | --- | --- | --- | --- |
| TC-01 | Authenticate and route a verified user | Registered verified account | Valid and invalid credentials | Valid login reaches current family; invalid login is rejected | Authentication, registration, reset, and email-verification cases passed in the canonical 1,421-test run; Figures 4.1a–4.1c record the interface boundary | Met |
| TC-02 | Enforce 2FA and passkey ownership | Authenticated account with security setup | Valid challenge, invalid challenge, another user's passkey | Valid challenge succeeds; invalid/foreign credential fails | Passkey, 2FA, challenge, and allowed-origin cases passed in the canonical run | Met |
| TC-03 | Create and switch isolated family workspaces | User with two memberships | Family slug/current-family switch | Context changes only to a joined family | Membership-routing, family-switching, and predictive tenant-switch browser cases passed | Met |
| TC-04 | Invite a member and assign permitted role/category | Family administrator; active category | Valid, expired, duplicate, and invalid-role invitations | Only valid invitation creates membership with permitted role/category | Invitation and role/category cases passed; external message delivery was not exercised | Partially Met |
| TC-05 | Prevent cross-family and ordinary-member administration | Two families and roles | Foreign record URL and restricted form submission | Request is denied without data exposure or mutation | Role, policy, membership, report, AI-isolation, and predictive member-denial cases passed | Met |
| TC-06 | Generate monthly obligations without duplicates | Active paying memberships | Month generation repeated for same period | One obligation per eligible membership/period | Generation, category-history, and idempotency cases passed in the canonical run | Met |
| TC-07 | Calculate contribution status at boundaries | Paid, partial, unpaid, due, and overdue records | Amounts around balance and dates around due date | Correct status and balance are shown | Due-date, status, partial-payment, and dashboard cases passed in the canonical run | Met |
| TC-08 | Allocate partial and lump-sum payments oldest first | Member with several incomplete months | Partial amount and multi-month amount | Oldest balances receive money first; totals equal receipt | Allocation, balance-first, and partial-payment cases passed in the canonical run | Met |
| TC-09 | Enforce advance-payment and invalid-amount limits | Member with current obligations | Zero, excessive, and within-limit future amounts | Invalid amounts fail; permitted amount allocates within limit | Advance-limit and payment-allocation boundary cases passed in the canonical run | Met |
| TC-10 | Restrict manual payment recording by role | Admin, secretary, and member accounts | Same valid payment request from each role | Admin/secretary permitted; member denied | Payment policy, request validation, and recording cases passed in the canonical run | Met |
| TC-11 | Verify Paystack before applying contribution | Known initiated reference | Valid, invalid-signature, unknown, duplicate, and mismatched events | Only valid known matching event settles once | Deterministic service, webhook, ledger, fee, mismatch, and duplicate-processing cases passed; live gateway not executed | Partially Met |
| TC-12 | Record and reverse expenses/adjustments immutably | Authorised officer and existing record | Valid create, direct update, correction/reversal | Create succeeds; direct mutation denied; reversal remains auditable | CRUD, policy, observer, reversal, and governed AI-tool cases passed in the canonical run | Met |
| TC-13 | Generate consistent monthly/annual reports | Family with financial records | Same filters for screen, CSV, PDF, scheduled output | Formats use identical shared totals and private access rules | Monthly, annual, Phase Three, export, private-artifact, and schedule cases passed; Figures 4.8a–4.8b record the controls and generated output | Met for local evidence |
| TC-14 | Limit member dashboard/history to authorised records | Member and officer accounts | Own and another member's history | Member sees own records; officer access follows role | Dashboard, member-history, contribution, and payment-policy cases passed in the canonical run | Met |
| TC-15 | Send reminders only through eligible channels | Overdue contribution and configured/unconfigured channels | Email, push, WhatsApp, invalid selection | Valid eligible selection sends; unavailable choice is reported | Reminder command, channel, notification, webhook, and MCP cases passed with fakes; external delivery not executed | Partially Met |
| TC-16 | Enforce subscription features and member limits | Families on different plans | Feature request and add-member at/under limit | Plan rules allow or reject consistently | Subscription, strict Payment Risk entitlement, and plan-limit cases passed in the canonical run | Met |
| TC-17 | Keep AI reads tenant- and role-scoped | Configured test user and family data | Balance, contribution, expense, and member queries | Tools use only permitted family data | AI tool, privacy, family-context, and role cases passed with deterministic fakes; live provider not executed | Partially Met |
| TC-18 | Require confirmation and permission for AI writes | Officer/member accounts | Preview, confirmed repeat, unauthorised write | Preview does not mutate; exact confirmed authorised request may execute | AI preview, confirmation, idempotency, role, payment, expense, adjustment, generation, and invitation cases passed; live provider not executed | Partially Met |
| TC-19 | Reconcile only unique exact matches automatically | Imported credit with unique, ambiguous, and absent references | Match request for each case | Unique exact candidate links; ambiguity remains suggested/unlinked | Matching-service, Phase Four, and reconciliation-browser cases passed in the canonical run | Met |
| TC-20 | Guard closed reconciliation periods and provider settlements | Open/closed periods and settlement records | Mutation, close, reopen, settlement match | Closed period blocks mutation; authorised reopen is auditable | Period, mutation, reopening, provider-settlement, and browser cases passed in the canonical run | Met |
| TC-21 | Archive, retain, restore, and purge under policy | Active and archived family/member records | Archive, restore, legal hold, expired retention | Lifecycle follows retention and legal-hold rules | Archive, restore, retention, purge, legal-hold, and backup-configuration cases passed; hosted recovery drill not executed | Partially Met |
| TC-22 | Demonstrate responsive hosted operation | Final deployed revision with populated test family | Mobile/desktop journeys, queues, scheduler, backup, `/up` and performance probes | Pages are readable, services healthy, and declared thresholds met | Focused predictive Chromium run passed 5 tests/82 assertions, including 390-pixel and dark-mode checks; hosted SHA, report screenshots, service checks, and measurements are unavailable | Partially Met |

#### 4.3.3 Predictive Test and Evidence Gates

The predictive cases are separated because application tests cannot substitute for a real training dataset and held-out evaluation. Each case must retain its raw configuration or machine-readable output in the final evidence package.

*Table 4.6: Predictive Analytics Test and Evidence Matrix*

| ID | Test objective | Preconditions | Input(s) | Expected output | Actual output/evidence | Status |
| --- | --- | --- | --- | --- | --- | --- |
| PRED-01 | Validate authorised dataset presence and provenance | Approved private export | CSV path and provenance record | Readable dataset with documented owner, period, and privacy treatment | No authorised predictive-training CSV is present | Not Executed |
| PRED-02 | Validate schema, types, history, and both classes | PRED-01 complete | Contribution/payment fields and provenance | Invalid or insufficient data fails closed with reasons | Python quality-gate tests passed within the 66-test, 100%-coverage run; no private-data output | Partially Met |
| PRED-03 | Build leakage-safe chronological partitions | PRED-02 complete | Dated labelled records | Training, validation, and untouched test periods with no future features | Point-in-time and complete-period 60/20/20 split tests passed; no private split manifest | Partially Met |
| PRED-04 | Evaluate training-prevalence baseline | PRED-03 complete | Training prevalence and held-out labels | Accuracy, balanced accuracy, overdue precision/recall/F1, PR-AUC, ROC-AUC when defined, Brier score, support, prevalence, and confusion matrix | Baseline metric and serialization tests passed; no private held-out result | Partially Met |
| PRED-05 | Evaluate previous-period-late baseline | PRED-03 complete | Prior outcome and held-out labels | The same complete metric set and confusion matrix on the identical held-out partition | Previous-period baseline tests passed on controlled fixtures; no private result | Partially Met |
| PRED-06 | Fit approved logistic regression only | PRED-03 complete | Training features/labels and fixed configuration | Versioned coefficients, intercept, scaler and threshold | Deterministic L2 training, artefact serialization and Python/PHP golden-score parity passed; no fitted private artefact | Partially Met |
| PRED-07 | Select and evaluate without test leakage | PRED-04–06 complete | Validation selection then one held-out evaluation | Full metric table, curves, class distribution, intervals, and confusion matrix | Threshold, held-out protocol, bootstrap and chart tests passed within the 66-test run; no private evaluation artefacts | Partially Met |
| PRED-08 | Record error and coefficient analysis | PRED-07 complete | False positives/negatives and fitted coefficients | Interpretable limitations without causal overclaiming | Aggregate chart and coefficient-factor tests passed; no private error analysis | Partially Met |
| PRED-09 | Activate only a passing versioned artefact | PRED-07 complete and gates satisfied | Model metadata and inference request | Authorised result or explicit unavailable reason; no automatic sanction | Laravel validation, checksum, immutability, activation, idempotency and advisory-only tests passed within 138 tests/440 assertions; no active artefact | Partially Met |
| PRED-10 | Capture populated UI and performance evidence | PRED-09 complete | Sufficient-history and cold-start members | Captioned result/unavailable screenshots and timed inference | Focused Chromium run passed 5 tests/82 assertions for synthetic populated and unavailable states; real-model screenshot and performance results absent | Partially Met |

#### 4.3.4 Performance, Security, and Usability Procedure

Final performance measurement must declare the hardware, hosted revision, database, record counts, warm-up method, number of repetitions, and percentile reported. The prescribed benchmark uses synthetic families of 25, 75 and 250 members over 12 and 24 months. After a documented warm-up, each case is repeated three times. Evidence records batch duration, per-score median and p95, page and refresh p50/p95, database-query count, peak memory, private artefact size, and 30-second concurrency probes. The operational acceptance thresholds are a page and refresh p95 below two seconds for the 75-member case, a 250-member scoring batch below 30 seconds, bounded query growth without an N+1 pattern, and zero request errors. These are declared operational thresholds, not retrospectively attributed to the original Chapter Three NFRs.

The benchmark dataset must reflect ordinary family sizes without containing private production records. Its construction, member count, contribution periods, receipt allocations, expenses, adjustments, bank transactions, and report rows should be recorded. Cold and warm measurements should not be mixed. Query-cache, application-cache, and browser-cache conditions need to be stated, and enough repetitions should be retained to show a distribution rather than one unusually fast response. Predictive inference timing is meaningful only after an activated artifact exists; a controller returning “model unavailable” measures the guard path, not model performance.

Security validation should combine automated negative paths with configuration review. Tenant and role denials, signed URLs, Paystack signatures, AI confirmation, predictive authorisation, immutable records, secret handling, HTTPS, backup configuration, queue/scheduler operation, and basic dependency/security checks are in scope. Usability evidence is limited to responsive browser workflows unless real participants are recruited. No satisfaction percentage or Likert result may be added without an actual instrument and sample.

Basic vulnerability checking should be reported with its scope and date rather than as a claim that the system is “secure.” Dependency advisories, insecure transport, exposed secrets, missing authorisation, mass assignment, cross-family identifiers, forged webhooks, expired links, and direct mutation routes are relevant checks. Backup configuration alone is not recovery evidence: a final operational claim requires a controlled restore or equivalent provider proof using non-production material. Where a live check cannot be performed safely, the limitation should remain visible.

### 4.4 Test Results and Discussion

#### 4.4.1 Current Results and Non-Functional Evaluation

The current evidence supports a cautious but stronger conclusion. The repository contains implementation and tests for the core family-fund functions, including later reporting, retention, reconciliation, and browser workflows. On 15 August 2026, the canonical local gate passed 1,421 Pest tests with 6,904 assertions and 100.0% PHP coverage in 22.209 seconds. PHPStan, Pint, Prettier, ESLint, and both client and server-side-rendering builds also passed. The locked predictive package passed 66 Python tests with 100% statement coverage. Focused evidence further recorded 138 Laravel payment-risk tests with 440 assertions and 100% coverage across the 16 payment-risk PHP files, the same 138 tests and 440 assertions on PostgreSQL, five Chromium tests with 82 assertions, and 171 combined Laravel tests with 517 assertions. Those focused runs overlap the canonical gate and are reported separately. The twenty inspected running-system images provide additional local interface evidence. No post-change hosted SHA is claimed; the older hosted baseline `a699a821` predates this work and must not be substituted.

*Table 4.7: Non-Functional Requirement Evaluation*

| Requirement | Evidence currently available | Required final result | Status |
| --- | --- | --- | --- |
| NFR1 Tenant isolation | Canonical family-routing, policy, report, AI-isolation, and predictive tenant-switch checks passed | Fresh negative-path suite with no cross-family exposure | Met for automated scope |
| NFR2 Role restriction | Canonical role, policy, request, and predictive officer/member checks passed | Fresh suite and browser proof for each role | Met for automated scope |
| NFR3 Strong authentication | Canonical Fortify, verification, 2FA, passkey, and browser checks passed | Fresh feature and browser proof | Met for automated scope |
| NFR4 Deterministic allocation | Canonical oldest-first, partial, lump-sum, balance, and boundary checks passed | Fresh allocation suite including boundary cases | Met |
| NFR5 Paystack idempotency | Reference/signature/settlement logic and webhook tests | Fresh suite plus authorised live test transaction | Partially Met |
| NFR6 Responsive usability | Focused Chromium run passed mobile, dark-mode, keyboard, refresh and error checks for predictive states | Complete report screenshot set and wider journey review | Partially Met |
| NFR7 Understandable reports | Shared report service, labels, CSV/PDF/receipt generation | Rendered final report inspection and, if conducted, user feedback | Partially Met |
| NFR8 Performance | No final measurements | Declared benchmark dataset, response percentiles, and thresholds | Not Executed |
| NFR9 Maintainability | Separate services, actions, policies, jobs, and components; canonical static analysis, formatting, lint, builds, and 100% PHP/Python coverage passed | Clean canonical full gate | Met locally |
| NFR10 Auditability | Canonical immutable-batch, reversal, artifact, reconciliation-link, and period checks passed | Fresh audit/reconciliation tests and rendered trail | Met for automated scope; report capture pending |
| NFR11 AI privacy | Family-scoped tools and role-aware source/tests | Fresh tests plus authorised live conversation review | Partially Met |
| NFR12 Availability | Deployment, backup, queue, scheduler configuration/tests | Final hosted health, worker, scheduler, and recovery evidence | Not Executed |
| NFR13 Model governance | Python 66-test/100%-coverage run and Laravel 138-test/440-assertion run verified provenance/schema validation, chronological training, baselines, checksumed artefact and activation gates | Versioned private dataset, executed split, model and evaluation evidence | Partially Met |
| NFR14 Model safety | Laravel, PostgreSQL and Chromium runs verified history tiers, immutable advisory records, unavailable/pooled paths, role denial and no action side effects | Authorised active-model inference and real populated screenshot | Partially Met |

#### 4.4.2 Predictive Training and Experimental Results

Predictive training and experimental results are **Not Executed**. This status is itself an important finding. Without the approved private predictive-training CSV, the system cannot establish record count, history length, outcome distribution, temporal partitions, baseline performance, fitted logistic-regression coefficients, or held-out quality. Generating synthetic rows solely to obtain favourable accuracy would answer a different question: whether the pipeline can recover patterns planted by the developer. It would not demonstrate prediction of real family payment behaviour.

*Table 4.8: Predictive Training and Evaluation Evidence Status*

| Evidence item | Required evidence | Actual result | Status |
| --- | --- | --- | --- |
| Dataset and provenance | Dataset identifier, owner/consent basis, SHA-256 digest, time window, row/member/period counts and class distribution | No authorised private CSV or provenance file supplied | Not Executed |
| Chronological split | Training, validation and held-out periods, rows and class counts | No private split manifest produced | Not Executed |
| Logistic regression | Configuration, threshold, coefficients, intercept and held-out metrics with intervals | No fitted private artefact or metrics produced | Not Executed |
| Training-prevalence baseline | Same held-out metrics and confusion matrix | No private baseline result produced | Not Executed |
| Previous-period-late baseline | Same held-out metrics and confusion matrix | No private baseline result produced | Not Executed |
| Calibration, discrimination and error analysis | Figures 4.15–4.18, false-positive/negative review and limitations | No populated figures or error analysis produced | Not Executed |
| Artefact and activation | Model/schema versions, checksum, evidence package and activation decision | No candidate or active artefact installed | Not Executed |
| Inference and interface | Authorised populated result, insufficient-history state and timing | No populated screenshot or performance measurement | Not Executed |

If an authorised dataset becomes available in a later revision, this subsection should add the observed class-distribution figure (Figure 4.15), evaluation curves (Figure 4.16), confusion matrix (Figure 4.17), calibration and standardised-coefficient evidence (Figure 4.18), baseline table, and error analysis. The training-prevalence baseline and previous-period-late baseline must be evaluated on the same held-out period as logistic regression. Results must not be compared across different samples or after tuning on the test partition. If logistic regression does not improve meaningfully upon the transparent baselines, FR20 should remain Partially Met or be classified Not Met, and the feature should remain inactive.

#### 4.4.3 Discussion of Results

The implementation and executed test evidence indicate that FamilyFunds addresses the central engineering gap identified in Chapter Two more directly than notebooks, spreadsheets, or messaging threads. The application formalises family workspaces and roles, separates obligations from receipts, applies one deterministic allocation rule, records spending and corrections, and produces reviewable outputs. The canonical gate and predictive-focused tenant, role, persistence, PostgreSQL, and browser checks provide fresh local evidence. Production-readiness conclusions still depend on the hosted, live-provider, screenshot, recovery, performance, and authorised-data evidence identified in this chapter.

The tenant and role design is consistent with RBAC and multi-tenant literature: permissions are adapted to family administrator, financial secretary, member, and platform roles rather than copied from a commercial bank. Payment handling also responds to a domain-specific gap left by payment gateways. Paystack can report that a transaction succeeded, but it does not decide which family obligation the money satisfies. The oldest-balance-first service keeps that decision inside the governed application and makes partial and lump-sum payments reproducible.

The reporting and reconciliation work demonstrates the value of iterative development. Chapter Three originally focused on reports, expenses, adjustments, and payment verification. Implementation later showed that an audit-friendly system also needs a controlled comparison between internal records and external bank transactions. Reconciliation is therefore a defensible extension of the auditability objective. Its narrow exact-match rule avoids treating an approximate suggestion as an authoritative financial link, which is particularly important where several receipts may share an amount or nearby date.

The controlled AI design addresses the fourth research question only partially. Static tests and source structure show role-aware tools, family-scoped queries, and confirmation before writes. They do not establish that a configured live provider will always produce accurate, understandable language or acceptable latency. This limitation is consistent with literature warning that fluent financial text may still contain unsupported claims. Final evaluation requires authorised provider-backed examples grounded in known report data, followed by comparison of the response with the source totals. Until that occurs, live AI relevance and usefulness remain Not Executed.

The fifth research question cannot yet be answered empirically. Logistic regression was selected for interpretability, and the two baselines provide meaningful reference points, but method selection is not model evidence. The absence of a private CSV prevents training and evaluation. This avoids an overclaim: a family-level prediction model may face sparse histories, class imbalance, recurring member patterns, and changes in contribution expectations. A high accuracy score could merely reproduce the majority on-time class. The final judgment must therefore consider late-class recall, precision, F1, PR-AUC, errors, and improvement over both baselines. Prediction must remain advisory even if those gates are met.

The sixth research question is partially addressed by the clean canonical gate and the predictive-focused Python, Laravel, PostgreSQL and Chromium checks covering normal, boundary, invalid, authorisation, idempotency, immutability, and browser scenarios. Populated report screenshots, the performance run, hosted services, live integrations, and recovery evidence still prevent a complete operational claim. Honest status reporting remains preferable to importing historical counts from an earlier revision because the evidential question is whether the integrated system passes at each claimed boundary.

#### 4.4.4 Objectives and Research Questions Verdicts

*Table 4.9: Objectives and Research Questions Verdict Matrix*

| Item | Current evidence | Verdict |
| --- | --- | --- |
| Objective 1: Review related practice and literature | Chapter Two concepts, empirical works, existing systems, and gap | Met for report scope |
| Objective 2: Design the multi-tenant system | Chapter Three requirements, architecture, database, algorithms, and diagrams | Met for design scope |
| Objective 3: Implement the proposed system | Broad current source, clean canonical local gate, and predictive-focused Laravel, PostgreSQL and browser evidence | Partially Met overall; local implementation gate passed, hosted evidence pending |
| Objective 4: Integrate controlled AI | Role-aware agents/tools and static tests | Partially Met; live provider evidence pending |
| Objective 5: Implement and evaluate logistic regression | Training, evaluation, artefact and inference implementation exists; private-data training and PRED evidence absent | Partially Met; evaluation Not Executed |
| Objective 6: Test key requirements | TC evidence mapping plus clean canonical, Python, PostgreSQL and Chromium checks and twenty inspected interface images | Partially Met; external, recovery, and performance evidence pending |
| RQ1: Required design features | Chapters Two and Three plus implementation traceability | Answered at design level |
| RQ2: Tenant and role protection | Layered implementation plus canonical and predictive route, tenant-switching and member-denial checks | Validated in the local automated scope; hosted review pending |
| RQ3: Consistent payment allocation | Transactional oldest-first implementation and canonical normal/boundary checks | Validated in the local automated scope |
| RQ4: Controlled AI usefulness | Governance design and static tests, no live output | Partially answered |
| RQ5: Logistic regression versus baselines | No authorised training/evaluation evidence | Not answered empirically |
| RQ6: Functional, security, reliability, and usability testing | Broad specification set plus fresh focused local and PostgreSQL/browser results; external and performance evidence absent | Partially answered |

#### 4.4.5 Summary of the Chapter

This chapter connected the Chapter Three design to identifiable FamilyFunds modules, code listings, and test specifications. The application contains substantial static evidence for tenant-aware membership, role control, contribution generation, deterministic payment allocation, Paystack handling, expenses, adjustments, reports, reminders, controlled AI tools, retention, and the later reconciliation extension. It also defined twenty-two system cases and ten predictive evidence gates.

The chapter did not convert pending work into claimed success. The canonical local application gate passed 1,421 tests, 6,904 assertions and 100.0% PHP coverage, while 66 Python tests reached 100% statement coverage. Focused Laravel, PostgreSQL and Chromium checks also passed, and twenty redacted synthetic-tenant images provide running-system evidence for the recorded journeys. Hosted checks, performance measurements, recovery proof, live-provider acceptance, and authorised-data predictive training remain pending or Not Executed. No private predictive CSV was available, so logistic-regression and baseline metrics were not invented and the feature cannot be treated as activated. The conventional application is locally verified, but operational readiness and the predictive objective remain partially met at the current evidence boundary; predictive evaluation itself is Not Executed. Chapter Five must preserve these verdicts unless newer, reproducible evidence replaces them.

---

> **References:** All citations in this chapter are listed in the centralized [References](references.md) file.
