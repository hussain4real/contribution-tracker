# Quickstart: Family Contribution Tracker

**Feature Branch**: `001-family-contributions`  
**Created**: 2025-12-25  
**Status**: Ready for Development

---

## Prerequisites

- PHP 8.3+
- Node.js 20+
- Composer 2.x
- SQLite (included in PHP)

---

## 1. Branch Setup

```bash
# Checkout the feature branch
git checkout 001-family-contributions

# Install dependencies
composer install
npm install
```

---

## 2. Database Setup

```bash
# Run existing migrations + new feature migrations
php artisan migrate

# Seed with test data (creates Super Admin, members, contributions)
php artisan db:seed --class=ContributionSeeder
```

### Default Test Users

| Role | Email | Password | Category |
|------|-------|----------|----------|
| Super Admin | admin@family.test | password | - |
| Financial Secretary | treasurer@family.test | password | Employed |
| Member | member@family.test | password | Student |

---

## 3. Development Server

```bash
# Start Laravel + Vite in one command
composer run dev

# Or separately:
# Terminal 1: php artisan serve
# Terminal 2: npm run dev
```

Access the application at: **http://localhost:8000**

---

## 4. Running Tests

### Run All Tests

```bash
php artisan test
```

### Run Feature-Specific Tests

```bash
# All contribution tracker tests
php artisan test --filter=Contribution

# Specific test file
php artisan test tests/Feature/Contributions/RecordPaymentTest.php

# Specific test name
php artisan test --filter="can record payment for member"
```

### Run Browser Tests

```bash
# Ensure browser is installed
npx playwright install chromium

# Run browser tests
php artisan test tests/Browser/
```

### Run with Coverage

```bash
php artisan test --coverage
```

---

## 5. Code Quality

### Format PHP Code

```bash
# Check formatting
vendor/bin/pint --test

# Fix formatting
vendor/bin/pint --dirty
```

### Lint TypeScript/Vue

```bash
npm run lint

# Fix auto-fixable issues
npm run lint:fix
```

---

## 6. Generate Wayfinder Routes

After modifying controllers or routes:

```bash
php artisan wayfinder:generate
```

> Note: Vite plugin auto-generates on HMR if configured in `vite.config.ts`

---

## 7. File Structure Reference

### Backend (app/)

```
app/
├── Enums/
│   ├── MemberCategory.php    # employed, unemployed, student
│   ├── PaymentStatus.php     # paid, partial, unpaid, overdue
│   └── Role.php              # super_admin, financial_secretary, member
├── Http/
│   ├── Controllers/
│   │   ├── DashboardController.php
│   │   ├── MemberController.php
│   │   ├── ContributionController.php
│   │   ├── PaymentController.php
│   │   └── ReportController.php
│   └── Requests/
│       ├── StoreMemberRequest.php
│       ├── UpdateMemberRequest.php
│       └── StorePaymentRequest.php
├── Models/
│   ├── User.php              # Extended with role, category
│   ├── Contribution.php
│   └── Payment.php
└── Policies/
    ├── ContributionPolicy.php
    └── PaymentPolicy.php
```

### Frontend (resources/js/)

```
resources/js/
├── pages/
│   ├── Dashboard/
│   │   └── Index.vue
│   ├── Members/
│   │   ├── Index.vue
│   │   ├── Create.vue
│   │   ├── Show.vue
│   │   └── Edit.vue
│   ├── Contributions/
│   │   ├── Index.vue
│   │   ├── My.vue
│   │   └── Show.vue
│   ├── Payments/
│   │   ├── Index.vue
│   │   └── Create.vue
│   └── Reports/
│       ├── Index.vue
│       ├── Monthly.vue
│       └── Annual.vue
├── components/
│   ├── contributions/
│   │   ├── ContributionCard.vue
│   │   ├── StatusBadge.vue
│   │   └── PaymentHistory.vue
│   └── dashboard/
│       ├── SummaryCards.vue
│       ├── RecentPayments.vue
│       └── MemberContributionStatus.vue
└── composables/
    └── useContributions.ts
```

### Tests (tests/)

```
tests/
├── Feature/
│   ├── Contributions/
│   │   ├── ViewContributionsTest.php
│   │   ├── RecordPaymentTest.php
│   │   └── MyContributionsTest.php
│   ├── Members/
│   │   ├── CreateMemberTest.php
│   │   ├── UpdateMemberTest.php
│   │   └── ArchiveMemberTest.php
│   └── Dashboard/
│       └── DashboardVisibilityTest.php
├── Browser/
│   ├── ContributionFlowTest.php
│   └── PaymentRecordingTest.php
└── Unit/
    ├── ContributionStatusTest.php
    ├── MemberCategoryAmountTest.php
    └── OverdueDetectionTest.php
```

---

## 8. Key Implementation Order

### Phase 1: Foundation (Days 1-2)

1. Create enums (`Role`, `MemberCategory`, `PaymentStatus`)
2. Create migrations (add user columns, contributions, payments)
3. Create models with relationships
4. Create factories

### Phase 2: Core Features (Days 3-5)

5. Member management (CRUD)
6. Contribution viewing
7. Payment recording
8. Dashboard (role-based)

### Phase 3: Polish (Days 6-7)

9. Reports
10. Browser tests
11. Edge cases & error handling

---

## 9. Environment Variables

No new environment variables required for this feature. Uses existing:

- `DB_CONNECTION=sqlite`
- `APP_URL=http://localhost:8000`

---

## 10. Helpful Commands

```bash
# Clear all caches
php artisan optimize:clear

# Refresh database with seeds
php artisan migrate:fresh --seed

# Generate IDE helper files
php artisan ide-helper:models -M

# View all routes
php artisan route:list --path=contributions
```

---

## Ready to Implement

All planning artifacts are complete:

- ✅ [spec.md](spec.md) - Feature specification
- ✅ [research.md](research.md) - Technical decisions
- ✅ [data-model.md](data-model.md) - Database schema
- ✅ [contracts/api.md](contracts/api.md) - API contracts
- ✅ [plan.md](plan.md) - Implementation plan

**Start development with**: `speckit implement`
