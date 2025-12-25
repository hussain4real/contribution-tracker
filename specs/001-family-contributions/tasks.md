# Tasks: Family Contribution Tracker

**Input**: Design documents from `/specs/001-family-contributions/`  
**Prerequisites**: plan.md ✅, spec.md ✅, research.md ✅, data-model.md ✅, contracts/api.md ✅

## Format: `[ID] [P?] [Story?] Description`

- **[P]**: Can run in parallel (different files, no dependencies)
- **[Story]**: Which user story this task belongs to (e.g., US1, US2)
- All amounts stored in kobo (₦1 = 100 kobo)
- TDD approach: Tests requested per spec

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: Project initialization, enums, and database schema

- [X] T001 Create Role enum with permission methods in app/Enums/Role.php
- [X] T002 [P] Create MemberCategory enum with amount methods in app/Enums/MemberCategory.php
- [X] T003 [P] Create PaymentStatus enum with color/label methods in app/Enums/PaymentStatus.php
- [X] T004 Create migration to add role, category, archived_at columns to users table in database/migrations/
- [X] T005 Create contributions table migration in database/migrations/
- [X] T006 Create payments table migration in database/migrations/
- [X] T007 Run migrations and verify schema with `php artisan migrate`

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Core models, relationships, factories, policies, and routes that ALL user stories depend on

**⚠️ CRITICAL**: No user story work can begin until this phase is complete

### Models & Relationships

- [X] T008 Extend User model with role/category casts, relationships, scopes in app/Models/User.php
- [X] T009 [P] Create Contribution model with relationships, scopes, status accessor in app/Models/Contribution.php
- [X] T010 [P] Create Payment model with relationships, scopes in app/Models/Payment.php

### Factories

- [X] T011 Extend UserFactory with superAdmin, financialSecretary, member, employed, student states in database/factories/UserFactory.php
- [X] T012 [P] Create ContributionFactory with currentMonth, forMonth states in database/factories/ContributionFactory.php
- [X] T013 [P] Create PaymentFactory with partial state in database/factories/PaymentFactory.php

### Authorization

- [X] T014 Create ContributionPolicy with view, viewAny, create methods in app/Policies/ContributionPolicy.php
- [X] T015 [P] Create PaymentPolicy with view, create, delete methods in app/Policies/PaymentPolicy.php
- [X] T016 Register policies in bootstrap/app.php or AppServiceProvider

### Routes & Controllers (Stubs)

- [X] T017 Create route groups for /dashboard, /members, /contributions, /payments, /reports in routes/web.php
- [X] T018 [P] Create DashboardController stub with index method in app/Http/Controllers/DashboardController.php
- [X] T019 [P] Create MemberController stub with CRUD + restore methods in app/Http/Controllers/MemberController.php
- [X] T020 [P] Create ContributionController stub with index, my, show methods in app/Http/Controllers/ContributionController.php
- [X] T021 [P] Create PaymentController stub with index, create, store, destroy methods in app/Http/Controllers/PaymentController.php
- [X] T022 [P] Create ReportController stub with index, monthly, annual methods in app/Http/Controllers/ReportController.php

### Seeders

- [X] T023 Create FamilyMemberSeeder with test users (admin, financial_secretary, member) in database/seeders/FamilyMemberSeeder.php
- [X] T024 Register seeder in DatabaseSeeder and run with `php artisan db:seed`

### Generate Wayfinder Routes

- [X] T025 Run `php artisan wayfinder:generate` to create TypeScript route functions

**Checkpoint**: Foundation ready - user story implementation can now begin

---

## Phase 3: User Story 1 - Financial Secretary Records a Payment (Priority: P1) 🎯 MVP

**Goal**: Allow Financial Secretary to record payments for members with full/partial payment support

**Independent Test**: Log in as Financial Secretary, select a member, record ₦4,000 payment, verify status changes to "Paid"

### Tests for User Story 1

> **TDD: Write these tests FIRST, ensure they FAIL before implementation**
> **Use Inertia endpoint testing**: `assertInertia()` with `has()`, `where()`, `missing()`, `etc()` assertions

- [X] T026 [P] [US1] Unit test for Contribution status calculation (Paid/Partial/Unpaid/Overdue) in tests/Unit/ContributionStatusTest.php
- [X] T026a [P] [US1] Unit test for Contribution.due_date accessor always returns 28th of month in tests/Unit/ContributionDueDateTest.php
- [X] T027 [P] [US1] Unit test for MemberCategory monthly amounts in tests/Unit/MemberCategoryAmountTest.php
- [X] T028 [P] [US1] Feature test for recording full payment using assertInertia to verify contribution props in tests/Feature/Payments/RecordPaymentTest.php
- [X] T029 [P] [US1] Feature test for recording partial payment with assertInertia->where('contribution.status', 'partial') in tests/Feature/Payments/RecordPartialPaymentTest.php
- [X] T029a [P] [US1] Feature test for balance-first rule: new payment completes oldest incomplete month before future months (FR-020) in tests/Feature/Payments/BalanceFirstRuleTest.php
- [X] T030 [P] [US1] Feature test for authorization (only FS/SA can record) with assertForbidden in tests/Feature/Payments/PaymentAuthorizationTest.php
- [X] T031 [US1] Browser test for payment recording flow in tests/Browser/PaymentRecordingFlowTest.php (requires pest-plugin-browser)
- [X] T031a [P] [US1] Feature test for advance payments up to 6 months ahead (FR-018) in tests/Feature/Payments/AdvancePaymentTest.php
- [X] T031b [P] [US1] Feature test for rejecting advance payments beyond 6 months in tests/Feature/Payments/AdvancePaymentLimitTest.php

### Implementation for User Story 1

- [X] T032 [US1] Create StorePaymentRequest with validation rules (including target_month for advance payments up to 6 months) in app/Http/Requests/StorePaymentRequest.php
- [X] T033 [US1] Implement PaymentController@create to show payment form with month selector (current + next 6 months) in app/Http/Controllers/PaymentController.php
- [X] T034 [US1] Implement PaymentController@store with balance-first logic: auto-apply to oldest incomplete month before target month (FR-020, FR-018) in app/Http/Controllers/PaymentController.php
- [X] T034a [US1] Create PaymentAllocationService to handle balance-first distribution logic in app/Services/PaymentAllocationService.php
- [X] T035 [US1] Create Payments/Create.vue page with Inertia Form component and month selector dropdown in resources/js/pages/Payments/Create.vue
- [X] T036 [P] [US1] Create StatusBadge.vue component for payment status display in resources/js/components/contributions/StatusBadge.vue
- [X] T037 [US1] Implement ContributionController@show with payment recording link in app/Http/Controllers/ContributionController.php
- [X] T038 [US1] Create Contributions/Show.vue page with payment history in resources/js/pages/Contributions/Show.vue

### Run Tests for User Story 1

- [X] T039 [US1] Run `php artisan test --filter=Payment` and verify all tests pass
- [X] T040 [US1] Run `vendor/bin/pint --dirty` to format PHP code

**Checkpoint**: Financial Secretary can record payments. User Story 1 complete and testable.

---

## Phase 4: User Story 2 - View Contribution Dashboard (Priority: P1)

**Goal**: Display role-appropriate dashboard with contribution status and aggregate stats

**Independent Test**: Log in as each role and verify correct data visibility (admin sees all, member sees aggregate only)

### Tests for User Story 2

> **Use Inertia endpoint testing**: `assertInertia()->component('Dashboard/Index')`, `loadDeferredProps()` for async data

- [X] T041 [P] [US2] Feature test for Super Admin dashboard using assertInertia->has('summary')->has('memberStatuses') in tests/Feature/Dashboard/AdminDashboardTest.php
- [X] T042 [P] [US2] Feature test for Financial Secretary dashboard using assertInertia->component('Dashboard/Index') in tests/Feature/Dashboard/FinancialSecretaryDashboardTest.php
- [X] T043 [P] [US2] Feature test for Member dashboard using assertInertia->has('family_aggregate')->missing('memberStatuses') in tests/Feature/Dashboard/MemberDashboardTest.php
- [X] T044 [P] [US2] Feature test for overdue highlighting using assertInertia->where('summary.overdue_count', fn($v) => $v > 0) in tests/Feature/Dashboard/OverdueHighlightingTest.php
- [X] T045 [US2] Browser test for dashboard navigation and member click-through in tests/Browser/DashboardFlowTest.php

### Implementation for User Story 2

- [X] T046 [US2] Implement DashboardController@index with role-conditional props and deferred loading in app/Http/Controllers/DashboardController.php
- [X] T047 [US2] Create Dashboard/Index.vue with Deferred components for stats in resources/js/pages/Dashboard/Index.vue
- [X] T048 [P] [US2] Create SummaryCards.vue for aggregate statistics in resources/js/components/dashboard/SummaryCards.vue
- [X] T049 [P] [US2] Create RecentPayments.vue for admin view in resources/js/components/dashboard/RecentPayments.vue
- [X] T050 [P] [US2] Create MemberContributionStatus.vue for member's own status in resources/js/components/dashboard/MemberContributionStatus.vue
- [X] T051 [P] [US2] Create AggregateStats.vue for family totals (visible to members) in resources/js/components/contributions/AggregateStats.vue
- [X] T052 [US2] Add polling to dashboard for auto-refresh every 30 seconds in resources/js/pages/Dashboard/Index.vue

### Run Tests for User Story 2

- [X] T053 [US2] Run `php artisan test --filter=Dashboard` and verify all tests pass

**Checkpoint**: Role-appropriate dashboard working. User Stories 1 AND 2 complete.

---

## Phase 5: User Story 3 - Super Admin Manages Family Members (Priority: P2)

**Goal**: Allow Super Admin to add, edit, archive, and restore family members

**Independent Test**: Add a new member with category "Student", verify ₦1,000 expected amount, edit to "Employed", verify ₦4,000

### Tests for User Story 3

> **Use Inertia endpoint testing**: `assertInertia()->component()`, `has()`, `where()` for prop validation

- [X] T054 [P] [US3] Feature test for creating new member using assertInertia on redirect, assertDatabaseHas in tests/Feature/Members/CreateMemberTest.php
- [X] T055 [P] [US3] Feature test for editing member category using assertInertia->where('member.category', 'employed') in tests/Feature/Members/UpdateMemberTest.php
- [X] T056 [P] [US3] Feature test for archiving member (soft delete) with assertSoftDeleted in tests/Feature/Members/ArchiveMemberTest.php
- [X] T057 [P] [US3] Feature test for restoring archived member in tests/Feature/Members/RestoreMemberTest.php
- [X] T058 [P] [US3] Feature test for authorization using assertForbidden for non-Super Admin in tests/Feature/Members/MemberAuthorizationTest.php
- [X] T059 [US3] Browser test for member management flow in tests/Browser/MemberManagementFlowTest.php
- [X] T059a [P] [US3] Feature test for category change taking effect next month (FR-017) in tests/Feature/Members/CategoryChangeNextMonthTest.php

### Implementation for User Story 3

- [X] T060 [US3] Create StoreMemberRequest with validation rules in app/Http/Requests/StoreMemberRequest.php
- [X] T061 [P] [US3] Create UpdateMemberRequest with validation rules in app/Http/Requests/UpdateMemberRequest.php
- [X] T062 [US3] Implement MemberController full CRUD + restore in app/Http/Controllers/MemberController.php
- [X] T063 [US3] Create Members/Index.vue with filters and pagination in resources/js/pages/Members/Index.vue
- [X] T064 [P] [US3] Create Members/Create.vue with Inertia Form component in resources/js/pages/Members/Create.vue
- [X] T065 [P] [US3] Create Members/Edit.vue with Inertia Form component in resources/js/pages/Members/Edit.vue
- [X] T066 [US3] Create Members/Show.vue with contribution history in resources/js/pages/Members/Show.vue
- [X] T067 [P] [US3] Create MemberListItem.vue component in resources/js/components/contributions/MemberListItem.vue

### Run Tests for User Story 3

- [X] T068 [US3] Run `php artisan test --filter=Member` and verify all tests pass

**Checkpoint**: Super Admin can manage members. User Stories 1, 2, AND 3 complete.

---

## Phase 6: User Story 4 - Super Admin Manages Roles (Priority: P2)

**Goal**: Allow Super Admin to assign/revoke Financial Secretary role

**Depends On**: Phase 5 (US3) - Reuses Members/Edit.vue created in US3

**Independent Test**: Assign Financial Secretary role, log in as that user, verify payment recording access

### Tests for User Story 4

- [X] T069 [P] [US4] Feature test for assigning Financial Secretary role in tests/Feature/Members/AssignRoleTest.php
- [X] T070 [P] [US4] Feature test for revoking Financial Secretary role in tests/Feature/Members/RevokeRoleTest.php
- [X] T071 [P] [US4] Feature test for verifying role-based access after assignment in tests/Feature/Authorization/RoleAccessTest.php
- [X] T071a [P] [US4] Feature test for warning when removing last Financial Secretary (FR-019) in tests/Feature/Members/LastFinancialSecretaryWarningTest.php

### Implementation for User Story 4

- [X] T072 [US4] Add role selection to Members/Edit.vue with confirmation dialog in resources/js/pages/Members/Edit.vue
- [X] T073 [US4] Update UpdateMemberRequest to handle role changes in app/Http/Requests/UpdateMemberRequest.php
- [X] T074 [US4] Add role change flash message in MemberController@update in app/Http/Controllers/MemberController.php
- [X] T074a [US4] Add last Financial Secretary warning check in MemberController@update (FR-019) in app/Http/Controllers/MemberController.php

### Run Tests for User Story 4

- [X] T075 [US4] Run `php artisan test --filter=Role` and verify all tests pass

**Checkpoint**: Role management complete. User Stories 1-4 complete.

---

## Phase 7: User Story 5 - Member Views Own Contribution History (Priority: P3)

**Goal**: Allow members to view their personal payment history across all months

**Independent Test**: Log in as member, view profile, verify payment history shows all months with correct amounts

### Tests for User Story 5

> **Use Inertia endpoint testing**: Verify props with `assertInertia`, test FR-015/FR-016 visibility rules

- [ ] T076 [P] [US5] Feature test using assertInertia->has('contributions')->has('family_aggregate') in tests/Feature/Contributions/MyContributionsTest.php
- [ ] T077 [P] [US5] Feature test using assertInertia->where('contributions.0.status', 'partial')->has('contributions.0.balance') in tests/Feature/Contributions/PartialPaymentDetailsTest.php
- [ ] T078 [US5] Browser test for member contribution history navigation in tests/Browser/MemberHistoryFlowTest.php

### Implementation for User Story 5

- [ ] T079 [US5] Implement ContributionController@my for personal history in app/Http/Controllers/ContributionController.php
- [ ] T080 [US5] Create Contributions/My.vue with payment history list in resources/js/pages/Contributions/My.vue
- [ ] T081 [P] [US5] Create ContributionCard.vue component in resources/js/components/contributions/ContributionCard.vue
- [ ] T082 [P] [US5] Create PaymentHistory.vue component in resources/js/components/contributions/PaymentHistory.vue
- [ ] T083 [US5] Add family aggregate stats to my contributions page (FR-015) in resources/js/pages/Contributions/My.vue

### Run Tests for User Story 5

- [ ] T084 [US5] Run `php artisan test --filter=MyContributions` and verify all tests pass

**Checkpoint**: Members can view own history. User Stories 1-5 complete.

---

## Phase 8: User Story 6 - Generate Contribution Reports (Priority: P3)

**Goal**: Allow Financial Secretary and Super Admin to generate monthly and yearly reports

**Independent Test**: Generate December 2025 report, verify totals match dashboard, export works

### Tests for User Story 6

> **Use Inertia endpoint testing**: `assertInertia()->component('Reports/Monthly')`, verify aggregation props

- [ ] T085 [P] [US6] Feature test using assertInertia->has('summary.total_expected')->has('by_category') in tests/Feature/Reports/MonthlyReportTest.php
- [ ] T086 [P] [US6] Feature test using assertInertia->has('monthly_breakdown', 12)->has('total') in tests/Feature/Reports/AnnualReportTest.php
- [ ] T087 [P] [US6] Feature test for report authorization using assertForbidden for Member role in tests/Feature/Reports/ReportAuthorizationTest.php

### Implementation for User Story 6

- [ ] T088 [US6] Implement ReportController@index for report dashboard in app/Http/Controllers/ReportController.php
- [ ] T089 [US6] Implement ReportController@monthly for monthly summary in app/Http/Controllers/ReportController.php
- [ ] T090 [US6] Implement ReportController@annual for yearly breakdown in app/Http/Controllers/ReportController.php
- [ ] T091 [US6] Create Reports/Index.vue with report selection in resources/js/pages/Reports/Index.vue
- [ ] T092 [P] [US6] Create Reports/Monthly.vue with category breakdown in resources/js/pages/Reports/Monthly.vue
- [ ] T093 [P] [US6] Create Reports/Annual.vue with month-by-month chart in resources/js/pages/Reports/Annual.vue
- [ ] T094 [US6] Create Payments/Index.vue with payment history list in resources/js/pages/Payments/Index.vue

### Run Tests for User Story 6

- [ ] T095 [US6] Run `php artisan test --filter=Report` and verify all tests pass

**Checkpoint**: Reports working. ALL user stories complete.

---

## Phase 9: Polish & Cross-Cutting Concerns

**Purpose**: Final cleanup, validation, and cross-story enhancements

- [ ] T096 [P] Add navigation menu with role-based items in resources/js/layouts/AppLayout.vue
- [ ] T097 [P] Add dark mode support to all new pages (follow existing Tailwind pattern)
- [ ] T098 [P] Add loading skeletons for all Deferred components
- [ ] T099 [P] Add flash message support for success/error notifications
- [ ] T100 Run full test suite with `php artisan test`
- [ ] T101 Run `vendor/bin/pint` to format all PHP code
- [ ] T102 Run `npm run lint:fix` to format all TypeScript/Vue code
- [ ] T103 Validate quickstart.md instructions work on fresh setup
- [ ] T104 Run `php artisan wayfinder:generate` to ensure routes are current

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: No dependencies - can start immediately
- **Foundational (Phase 2)**: Depends on Setup - BLOCKS all user stories
- **User Stories (Phases 3-8)**: All depend on Foundational completion
  - Can proceed in priority order (P1 → P2 → P3)
  - Or in parallel if team capacity allows
- **Polish (Phase 9)**: Depends on all user stories

### User Story Dependencies

| Story | Priority | Can Start After | Notes |
|-------|----------|-----------------|-------|
| US1: Record Payment | P1 | Phase 2 | Core MVP - no dependencies |
| US2: Dashboard | P1 | Phase 2 | Uses payment data from US1 |
| US3: Manage Members | P2 | Phase 2 | Independent of US1/US2 |
| US4: Manage Roles | P2 | Phase 2 | Uses member edit from US3 |
| US5: View History | P3 | Phase 2 | Uses contribution display components |
| US6: Reports | P3 | Phase 2 | Aggregates all contribution data |

### Parallel Opportunities Within Each Story

- All tests marked [P] can run in parallel
- All component creations marked [P] can run in parallel
- Models/factories marked [P] can run in parallel

---

## Parallel Example: User Story 1

```bash
# Launch all US1 tests together:
tests/Unit/ContributionStatusTest.php
tests/Unit/MemberCategoryAmountTest.php
tests/Feature/Payments/RecordPaymentTest.php
tests/Feature/Payments/RecordPartialPaymentTest.php
tests/Feature/Payments/PaymentAuthorizationTest.php

# Then launch parallel components:
resources/js/components/contributions/StatusBadge.vue
```

---

## Implementation Strategy

### MVP First (User Stories 1 + 2)

1. Complete Phase 1: Setup
2. Complete Phase 2: Foundational (CRITICAL)
3. Complete Phase 3: User Story 1 - Record Payments
4. Complete Phase 4: User Story 2 - Dashboard
5. **STOP and VALIDATE**: Test full payment flow end-to-end
6. Deploy/demo MVP

### Incremental Delivery

| Increment | Stories | Capability Added |
|-----------|---------|------------------|
| MVP | US1 + US2 | Record payments, view dashboard |
| +Management | US3 + US4 | Add/edit members, assign roles |
| +Self-Service | US5 + US6 | Member history, reports |

---

## Task Summary

| Phase | Task Count | Parallel Tasks |
|-------|------------|----------------|
| Setup | 7 | 2 |
| Foundational | 18 | 10 |
| US1: Record Payment | 20 | 11 |
| US2: Dashboard | 13 | 6 |
| US3: Manage Members | 16 | 9 |
| US4: Manage Roles | 9 | 4 |
| US5: View History | 9 | 4 |
| US6: Reports | 11 | 5 |
| Polish | 9 | 5 |
| **Total** | **112** | **56** |

---

## Notes

- All amounts in kobo (₦1 = 100 kobo) - display conversion in Vue components
- Use `php artisan make:` commands per Laravel conventions
- Follow TDD: write failing tests → implement → verify passing
- Commit after each task or logical group
- Run Pint after each phase to maintain formatting

---

## Inertia Endpoint Testing Reference

All feature tests MUST use Inertia's endpoint testing assertions. Import:

```php
use Inertia\Testing\AssertableInertia as Assert;
```

### Basic Pattern

```php
it('displays dashboard with correct props', function () {
    $user = User::factory()->superAdmin()->create();

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard/Index')
            ->has('summary', fn (Assert $page) => $page
                ->has('total_members')
                ->has('total_collected_this_month')
                ->where('collection_rate', fn ($rate) => $rate >= 0 && $rate <= 100)
                ->etc()
            )
            ->has('recent_payments')
        );
});
```

### Testing Visibility Rules (FR-015/FR-016)

```php
it('hides individual details from members', function () {
    $member = User::factory()->member()->create();

    $this->actingAs($member)
        ->get('/dashboard')
        ->assertInertia(fn (Assert $page) => $page
            ->has('family_aggregate')     // FR-015: CAN see aggregate
            ->missing('memberStatuses')   // FR-016: CANNOT see individual details
            ->has('personal')             // CAN see own status
        );
});
```

### Testing Deferred Props

```php
it('loads deferred props correctly', function () {
    $this->actingAs($user)
        ->get('/dashboard')
        ->assertInertia(fn (Assert $page) => $page
            ->has('summary')
            ->missing('recent_payments')  // Deferred, not in initial
            ->loadDeferredProps(fn (Assert $reload) => $reload
                ->has('recent_payments', 5)
            )
        );
});
```

### Testing Pagination

```php
it('returns paginated members', function () {
    User::factory()->count(25)->member()->create();

    $this->actingAs($admin)
        ->get('/members')
        ->assertInertia(fn (Assert $page) => $page
            ->has('members.data', 15)  // Default per_page
            ->has('members.links')
            ->has('members.meta.total', 25)
        );
});
```

### Authorization Test Pattern

```php
it('forbids members from recording payments', function () {
    $member = User::factory()->member()->create();
    $contribution = Contribution::factory()->create();

    $this->actingAs($member)
        ->post("/contributions/{$contribution->id}/payments", [
            'amount' => 400000,
            'paid_at' => now()->toDateString(),
        ])
        ->assertForbidden();
});
```
