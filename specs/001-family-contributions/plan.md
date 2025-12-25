# Implementation Plan: Family Contribution Tracker

**Branch**: `001-family-contributions` | **Date**: 2025-12-25 | **Spec**: [spec.md](./spec.md)
**Input**: Feature specification from `/specs/001-family-contributions/spec.md`

## Summary

Build a family contribution tracking system that allows Super Admins to manage family members across three categories (Employed: ₦4,000, Unemployed: ₦2,000, Student: ₦1,000), Financial Secretaries to record monthly payments, and Members to view their own payment history and aggregate family totals. Uses Laravel 12 + Inertia v2 + Vue 3 with Reka UI components, leveraging deferred props for dashboard data, Form component for payment recording, and Wayfinder for type-safe routing.

## Technical Context

**Language/Version**: PHP 8.3.11, TypeScript 5.x  
**Primary Dependencies**: Laravel 12, Inertia.js v2, Vue 3, Reka UI, Tailwind CSS v4, Laravel Wayfinder, Laravel Fortify (with OTP support)  
**Storage**: SQLite (development), PostgreSQL/MySQL (production)  
**Testing**: Pest v4 (Unit, Feature, Browser with Playwright)  
**Target Platform**: Web (responsive, supports mobile viewports)  
**Project Type**: Web application (Laravel monolith with Inertia SPA)  
**Performance Goals**: < 200ms TTFB, < 10 database queries per page, real-time dashboard updates  
**Constraints**: < 3 seconds dashboard load, payments recorded < 30 seconds, role-based access strictly enforced  
**Scale/Scope**: ~50 family members, ~600 payments/year, 3 roles, 6 main pages

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Principle | Status | Notes |
|-----------|--------|-------|
| I. Laravel Best Practices | ✅ PASS | Will use Eloquent, Form Requests, type hints, Artisan make commands |
| II. Test-First Development | ✅ PASS | TDD approach with Pest v4 (Unit, Feature, Browser tests) |
| III. User Experience Consistency | ✅ PASS | Inertia + Vue + Reka UI + Tailwind v4 + Wayfinder + dark mode support |
| IV. Performance Requirements | ✅ PASS | Eager loading, deferred props for dashboard, indexed queries |
| V. Code Quality Standards | ✅ PASS | Pint formatting, ESLint/Prettier, descriptive naming |

**Gate Status**: ✅ PASSED - No violations. Proceed to Phase 0.

## Project Structure

### Documentation (this feature)

```text
specs/001-family-contributions/
├── plan.md              # This file
├── research.md          # Phase 0 output
├── data-model.md        # Phase 1 output
├── quickstart.md        # Phase 1 output
├── contracts/           # Phase 1 output (API contracts)
│   └── api.md
└── tasks.md             # Phase 2 output (created by /speckit.tasks)
```

### Source Code (repository root)

```text
app/
├── Enums/
│   ├── MemberCategory.php       # Employed, Unemployed, Student with amounts
│   ├── PaymentStatus.php        # Paid, Partial, Unpaid, Overdue
│   └── Role.php                 # SuperAdmin, FinancialSecretary, Member
├── Http/
│   ├── Controllers/
│   │   ├── DashboardController.php
│   │   ├── MemberController.php
│   │   ├── PaymentController.php
│   │   ├── ContributionController.php
│   │   └── ReportController.php
│   ├── Requests/
│   │   ├── StoreMemberRequest.php
│   │   ├── UpdateMemberRequest.php
│   │   └── StorePaymentRequest.php
│   └── Middleware/
│       └── (use Laravel Gate/Policy instead)
├── Models/
│   ├── User.php                 # Extended with role, category, archived_at
│   ├── Contribution.php         # Monthly obligation per member
│   └── Payment.php              # Individual payment records
├── Policies/
│   ├── ContributionPolicy.php
│   └── PaymentPolicy.php
└── Providers/
    └── AuthServiceProvider.php  # Register policies and gates

database/
├── migrations/
│   ├── *_add_role_and_category_to_users_table.php
│   ├── *_create_contributions_table.php
│   └── *_create_payments_table.php
├── factories/
│   └── (extend UserFactory, add ContributionFactory, PaymentFactory)
└── seeders/
    └── FamilyMemberSeeder.php

resources/js/
├── pages/
│   ├── Dashboard.vue            # Role-aware dashboard with deferred props
│   ├── Members/
│   │   ├── Index.vue            # Member list (Super Admin only)
│   │   ├── Create.vue           # Add member form
│   │   ├── Edit.vue             # Edit member form
│   │   └── Show.vue             # Member profile with payment history
│   ├── Payments/
│   │   ├── Create.vue           # Record payment form (Financial Secretary)
│   │   └── Index.vue            # Payment history list
│   ├── Reports/
│   │   └── Index.vue            # Report generation page
│   └── Contributions/
│       └── My.vue               # Member's own history view
├── components/
│   ├── contributions/
│   │   ├── ContributionCard.vue
│   │   ├── StatusBadge.vue
│   │   ├── MemberListItem.vue
│   │   └── AggregateStats.vue
│   └── ui/                      # Existing Reka UI components
└── composables/
    └── useContributions.ts      # Shared contribution logic

tests/
├── Feature/
│   ├── MemberManagementTest.php
│   ├── PaymentRecordingTest.php
│   ├── DashboardTest.php
│   ├── AuthorizationTest.php
│   └── ReportGenerationTest.php
├── Unit/
│   ├── ContributionCalculationTest.php
│   └── PaymentStatusTest.php
└── Browser/
    ├── DashboardFlowTest.php
    ├── PaymentRecordingFlowTest.php
    └── MemberManagementFlowTest.php
```

**Structure Decision**: Laravel monolith with Inertia SPA. Backend controllers serve Inertia responses; frontend uses Vue 3 + Reka UI. Tests organized into Feature (HTTP), Unit (isolated logic), and Browser (Playwright E2E).

## Complexity Tracking

> No Constitution Check violations requiring justification.

---

## Phase Completion Status

### Phase 0: Outline & Research ✅

**Output**: [research.md](./research.md)

- Resolved all technical decisions
- Documented Inertia v2 feature mapping
- Confirmed authorization strategy (Enum + Policies)
- Finalized data model approach

### Phase 1: Design & Contracts ✅

**Outputs**:

- [data-model.md](./data-model.md) - Database schema, models, relationships, factories
- [contracts/api.md](./contracts/api.md) - API routes, request/response formats, Wayfinder integration
- [quickstart.md](./quickstart.md) - Development setup, testing commands, file structure

### Phase 2: Implementation Tasks

**Status**: Ready for `/speckit.tasks` command

---

## Constitution Re-Check (Post-Design)

| Principle | Status | Notes |
|-----------|--------|-------|
| I. Laravel Best Practices | ✅ PASS | Eloquent models, Form Requests, Policies, Enums defined |
| II. Test-First Development | ✅ PASS | Test structure defined in plan, factories ready |
| III. User Experience Consistency | ✅ PASS | All pages use Inertia + Reka UI + Tailwind, role-aware dashboards |
| IV. Performance Requirements | ✅ PASS | Indexed queries, deferred props, eager loading planned |
| V. Code Quality Standards | ✅ PASS | TypeScript contracts, PHPDoc, Pint formatting |

**Gate Status**: ✅ ALL PASSED - Ready for implementation
