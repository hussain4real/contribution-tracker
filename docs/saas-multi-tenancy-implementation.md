# SaaS Multi-Tenancy Transformation — Technical Implementation

## Overview

This changeset transforms FamilyFund from a single-family contribution tracker into a multi-tenant SaaS platform. Any family can sign up, create their own group, invite members, and customize categories/amounts/currency/due dates. **227 files changed, 26,212 insertions, 3,281 deletions. 291 tests pass.**

---

## 1. Data Architecture

### New Tables

| Table | Purpose | Key Columns |
|-------|---------|-------------|
| `families` | Tenant model. Each family is an isolated group. | `name`, `slug` (unique), `currency` (default `NGN`), `due_day` (1–28), `created_by` (FK→users), `plan` (default `free`), `trial_ends_at`, `max_members` |
| `family_categories` | Per-family contribution tiers (replaces hardcoded `MemberCategory` enum for definitions). | `family_id` (FK cascade), `name`, `slug`, `monthly_amount`, `sort_order`. Unique on `(family_id, slug)` |
| `family_invitations` | Token-based invitation system. | `family_id` (FK cascade), `email`, `role`, `token` (unique, 64 chars), `invited_by`, `accepted_at`, `expires_at`. Indexed on `(family_id, email)` and `token` |

### Schema Changes to Existing Tables

| Table | Added Columns | Indexes |
|-------|--------------|---------|
| `users` | `family_id` (FK cascade), `family_category_id` (FK null on delete), `is_super_admin` (bool, default false) | `family_id` |
| `contributions` | `family_id` (FK cascade), `due_date` (date, immutable snapshot) | Composite `(family_id, year, month)` |
| `expenses` | `family_id` (FK cascade) | Composite `(family_id, spent_at)` |
| `fund_adjustments` | `family_id` (FK cascade) | Composite `(family_id, recorded_at)` |

### Migrations (in execution order)

| # | File | Purpose |
|---|------|---------|
| 1 | `2026_03_18_000001_create_families_table.php` | Creates `families` table |
| 2 | `2026_03_18_000002_create_family_categories_table.php` | Creates `family_categories` table |
| 3 | `2026_03_18_000003_create_family_invitations_table.php` | Creates `family_invitations` table |
| 4 | `2026_03_18_000004_add_family_columns_to_users_table.php` | Adds `family_id`, `family_category_id`, `is_super_admin` to `users` |
| 5 | `2026_03_18_000005_add_family_id_to_contributions_table.php` | Adds `family_id`, `due_date` to `contributions` |
| 6 | `2026_03_18_000006_add_family_id_to_expenses_and_fund_adjustments_table.php` | Adds `family_id` to `expenses` and `fund_adjustments` |
| 7 | `2026_03_18_000007_migrate_existing_data_to_families.php` | Data migration (see below) |
| 8 | `2026_03_18_155228_add_billing_columns_to_families_table.php` | Adds `plan`, `trial_ends_at`, `max_members` to `families` |

### Data Migration (Migration #7)

- Renames `super_admin` role → `admin` in existing user rows
- Creates a "Default Family" with 3 default categories matching the original `MemberCategory` amounts (Employed ₦4,000 / Unemployed ₦2,000 / Student ₦1,000)
- Assigns all existing users, contributions, expenses, and fund adjustments to this family
- Maps each user's `category` enum to the corresponding `family_category_id`
- Backfills `due_date` on all existing contributions using the 28th of each month
- Fully reversible `down()` method

### Cascade Delete Strategy

All `family_id` foreign keys use `ON DELETE CASCADE` — deleting a family cleanly removes all associated users, contributions, expenses, fund adjustments, categories, and invitations.

---

## 2. Role Architecture — Two-Level Split

**Before:** A single `Role` enum with `SuperAdmin`, `FinancialSecretary`, `Member`.

**After:**

| Level | Mechanism | Values |
|-------|-----------|--------|
| **Platform** | `is_super_admin` boolean on `users` | `true` / `false` |
| **Family** | `Role` enum (renamed) | `Admin`, `FinancialSecretary`, `Member` |

Key change in `app/Enums/Role.php`: `SuperAdmin` case → `Admin`. All permission methods (`canManageMembers`, `canManageRoles`, `canRecordPayments`, `canGenerateReports`) updated to reference `Admin`.

The `User` model gains:

- `isSuperAdmin(): bool` — checks `$this->is_super_admin`
- `isAdmin(): bool` — checks `$this->role === Role::Admin`

---

## 3. Models

### Family (new) — `app/Models/Family.php`

- Relationships: `owner()` (BelongsTo User via `created_by`), `members()`, `categories()`, `contributions()`, `expenses()`, `fundAdjustments()`, `invitations()`
- Fillable fields include billing prep: `plan`, `trial_ends_at`, `max_members`
- Casts: `due_day` → integer, `trial_ends_at` → datetime, `max_members` → integer

### FamilyCategory (new) — `app/Models/FamilyCategory.php`

- Belongs to `Family`. Has many `Users` (via `family_category_id`).
- Used by the family admin to define custom contribution tiers.

### FamilyInvitation (new) — `app/Models/FamilyInvitation.php`

- Belongs to `Family`. Has `inviter()` (BelongsTo User).
- Helper methods: `isPending()`, `isAccepted()`, `isExpired()`
- Query scope: `scopePending()` — filters to non-accepted, non-expired invitations

### Modified: User — `app/Models/User.php`

- New relationships: `family()` (BelongsTo), `familyCategory()` (BelongsTo)
- New method: `getMonthlyAmount()` — returns family category amount when assigned, falls back to `MemberCategory` enum amount
- New helpers: `isSuperAdmin()`, `isAdmin()`

### Modified: Contribution, Expense, FundAdjustment

- All gain `family()` (BelongsTo) relationship
- `family_id` added to `$fillable`

---

## 4. Middleware

| Middleware | File | Purpose |
|-----------|------|---------|
| `SetFamilyContext` | `app/Http/Middleware/SetFamilyContext.php` | Resolves the authenticated user's family and binds it to the container as `'current-family'` and `Family::class`. Registered globally in `bootstrap/app.php`. |
| `EnsurePlatformSuperAdmin` | `app/Http/Middleware/EnsurePlatformSuperAdmin.php` | Guards `/platform/*` routes — returns 403 unless `$user->isSuperAdmin()` |
| `EnsureFamilySubscription` | `app/Http/Middleware/EnsureFamilySubscription.php` | **Pass-through stub.** Contains commented-out enforcement logic ready to activate when billing is enabled (checks `plan` and `trial_ends_at`). |

---

## 5. Controller Scoping

Every controller that queries data now explicitly scopes by `family_id`. **No global scopes are used** — all tenancy boundaries are visible and auditable.

| Controller | Scoping Details |
|-----------|----------------|
| **DashboardController** | `$currentMonthContributions`, `$allContributions` filtered by family. `getRecentPayments()` uses `whereHas('contribution', fn($q) => $q->where('family_id', ...))`. `calculateFundBalance()` sums payments (via contribution family), adjustments, and expenses all by family_id. |
| **MemberController** | `index()` queries `->where('family_id', ...)` for both active and archived members. `store()` sets `family_id` on new user. |
| **ContributionController** | `my()` scopes the family aggregate query by family_id. |
| **PaymentController** | `index()` replaced `->where('id', '!=', 1)` with `->where('family_id', ...)->whereNotNull('category')`. |
| **ExpenseController** | `index()` + `store()` scoped by family_id. |
| **FundAdjustmentController** | `index()` + `store()` scoped by family_id. |
| **ReportController** | Both `monthly()` and `annual()` extract `$familyId = $currentUser->family_id` and apply it to every `Contribution::query()`, `User::query()`, and category breakdown query. |

### Common Pattern

```php
/** @var \App\Models\User $user */
$user = $request->user();
$familyId = $user->family_id;

$query = Model::query()
    ->where('family_id', $familyId)
    // ...rest of query
```

---

## 6. Authorization (Policies)

All policies — `ContributionPolicy`, `ExpensePolicy`, `FundAdjustmentPolicy`, `PaymentPolicy` — inject `family_id` checks. A user can only view/modify/delete records belonging to their own family.

---

## 7. Registration & Onboarding

`CreateNewUser` (`app/Actions/Fortify/CreateNewUser.php`) now handles two flows:

### Flow A — New Family Registration

1. User provides `family_name`, `name`, `email`, `password`
2. In a DB transaction:
   - Creates `Family` record with slug + currency defaults
   - Creates 3 default `FamilyCategory` rows (Employed/Unemployed/Student)
   - Creates `User` with `Role::Admin` and the new `family_id`
3. The registering user becomes the family admin

### Flow B — Invitation Acceptance

1. User provides `invitation_token`, `name`, `email`, `password`
2. Validates token → looks up `FamilyInvitation`
3. Verifies invitation is pending and not expired
4. Creates `User` with the invitation's `role` and `family_id`
5. Marks invitation as accepted (`accepted_at` = now)

### Validation Rules

```php
'family_name' => ['required_without:invitation_token', 'nullable', 'string', 'max:255'],
'invitation_token' => ['nullable', 'string'],
```

---

## 8. New Controllers

### FamilySettingsController — `app/Http/Controllers/FamilySettingsController.php`

Admin-only. Manages family name, currency, due_day, and CRUD for contribution categories.

| Action | Route | Method | Purpose |
|--------|-------|--------|---------|
| `edit()` | `/family/settings` | GET | Show family settings + categories |
| `update()` | `/family/settings` | PUT | Update name, currency, due_day |
| `storeCategory()` | `/family/categories` | POST | Add category |
| `updateCategory()` | `/family/categories/{category}` | PUT | Edit category |
| `destroyCategory()` | `/family/categories/{category}` | DELETE | Delete (only if no members assigned) |

### InvitationController — `app/Http/Controllers/InvitationController.php`

Admin-only (except `accept` which is public).

| Action | Route | Method | Purpose |
|--------|-------|--------|---------|
| `index()` | `/family/invitations` | GET | List family invitations |
| `store()` | `/family/invitations` | POST | Send invitation (checks duplicate pending) |
| `destroy()` | `/family/invitations/{invitation}` | DELETE | Cancel invitation |
| `accept($token)` | `/invitations/{token}/accept` | GET | **Public** — show acceptance page |

### PlatformAdminController — `app/Http/Controllers/PlatformAdminController.php`

Super-admin only (guarded by `EnsurePlatformSuperAdmin` middleware).

| Action | Route | Method | Purpose |
|--------|-------|--------|---------|
| `index()` | `/platform` | GET | Platform stats + recent families |
| `families()` | `/platform/families` | GET | Paginated list of all families |
| `showFamily(Family)` | `/platform/families/{family}` | GET | Detail view (owner, categories, members) |

---

## 9. Routes — `routes/web.php`

### New Public Routes

```
GET  /invitations/{token}/accept  →  InvitationController@accept
```

### New Authenticated Routes (auth + verified)

```
GET    /family/settings                  →  FamilySettingsController@edit
PUT    /family/settings                  →  FamilySettingsController@update
POST   /family/categories               →  FamilySettingsController@storeCategory
PUT    /family/categories/{category}     →  FamilySettingsController@updateCategory
DELETE /family/categories/{category}     →  FamilySettingsController@destroyCategory
GET    /family/invitations               →  InvitationController@index
POST   /family/invitations              →  InvitationController@store
DELETE /family/invitations/{invitation}  →  InvitationController@destroy
```

### New Platform Admin Routes (auth + verified + EnsurePlatformSuperAdmin)

```
GET  /platform                      →  PlatformAdminController@index
GET  /platform/families             →  PlatformAdminController@families
GET  /platform/families/{family}    →  PlatformAdminController@showFamily
```

---

## 10. Inertia Shared Data — `HandleInertiaRequests`

Added to every page response via `share()`:

```php
'auth' => [
    'user' => [
        // ...existing fields...
        'family_id' => $user->family_id,           // NEW
        'is_super_admin' => $user->is_super_admin,  // NEW
    ],
],
'family' => $user?->family ? [  // NEW — entire block
    'id'       => $user->family->id,
    'name'     => $user->family->name,
    'currency' => $user->family->currency,
    'due_day'  => $user->family->due_day,
] : null,
```

---

## 11. Frontend Changes

### TypeScript Types — `resources/js/types/index.d.ts`

```typescript
// NEW interface
export interface Family {
    id: number;
    name: string;
    currency: string;
    due_day: number;
}

// UPDATED — added to AppPageProps
export type AppPageProps = {
    // ...existing...
    family: Family | null;  // NEW
};

// UPDATED — added to User
export interface User {
    // ...existing...
    family_id: number | null;    // NEW
    is_super_admin: boolean;     // NEW
}
```

### New Vue Pages

| Page | Route | Purpose |
|------|-------|---------|
| `Family/Settings.vue` | `/family/settings` | Inline edit family name/currency/due_day + CRUD categories with edit-in-place |
| `Family/Invitations.vue` | `/family/invitations` | Send/cancel invitations with status badges (Pending/Accepted/Expired) |
| `auth/AcceptInvitation.vue` | `/invitations/{token}/accept` | Pre-filled registration form for invited users with hidden `invitation_token` field |
| `Platform/Dashboard.vue` | `/platform` | Stats cards (families, users, payments, expenses) + recent families list |
| `Platform/Families.vue` | `/platform/families` | Paginated table of all families with owner, member count, currency |
| `Platform/FamilyDetail.vue` | `/platform/families/{id}` | Family detail with overview cards, categories list, members table |

### Modified Pages

| File | Changes |
|------|---------|
| `auth/Register.vue` | Added `family_name` input field as the first field (required), updated tab indexes |
| `Welcome.vue` | Replaced 12 hardcoded references: removed ₦ amounts, 28th due date, "Nigerian families" text. Generalized to SaaS messaging ("Custom amounts", "Flexible due dates", "families everywhere") |
| `AppSidebar.vue` | Added "Family Settings" nav item (visible when `user.role === 'admin'`, uses `Settings` icon). Added "Platform Admin" nav item (visible when `user.is_super_admin`, uses `Globe` icon). |

---

## 12. Factories & Test Fixes

### Factory Updates

All factories updated to include `family_id => Family::factory()` in their default definitions:

- `UserFactory` — default `family_id => Family::factory()`
- `ContributionFactory` — default `family_id => Family::factory()`, `forUser()` state copies user's `family_id`
- `ExpenseFactory` — default `family_id => Family::factory()`, `recordedBy()` state copies user's `family_id`
- `FundAdjustmentFactory` — default `family_id => Family::factory()`, `recordedBy()` state copies user's `family_id`

### New Factories

- `FamilyFactory` — creates family with random name, slug, NGN currency, due_day 28
- `FamilyCategoryFactory` — creates category tied to a family
- `FamilyInvitationFactory` — creates invitation with random token, 7-day expiry

### Test Fixes (4 tests)

| Test File | Issue | Fix |
|-----------|-------|-----|
| `RegistrationTest` | Missing `family_name` field after `CreateNewUser` was updated | Added `'family_name' => 'Test Family'` to registration payload |
| `AdminDashboardTest` | Users created in separate families (each factory creates its own) | Create shared `Family::factory()`, pass `family_id` to all users |
| `FinancialSecretaryDashboardTest` | Same issue — users in different families | Same fix — shared family |
| `FundBalanceTest` | Contribution not in same family as admin user | Used `forUser()` factory state instead of `for()`, shared family for all models |

---

## 13. Billing Preparation (Phase 6)

Architecture-only, no enforcement active:

| Column on `families` | Type | Default | Purpose |
|----------------------|------|---------|---------|
| `plan` | `string` | `'free'` | Subscription tier identifier |
| `trial_ends_at` | `timestamp` | `null` | Trial expiration datetime |
| `max_members` | `unsigned int` | `null` | Member cap per plan |

The `EnsureFamilySubscription` middleware contains commented-out enforcement logic:

```php
// TODO: Activate billing enforcement when plans are enabled.
// $family = $request->user()?->family;
// if ($family && $family->plan !== 'free' && $family->trial_ends_at?->isPast()) {
//     return redirect()->route('family.settings')->with('error', 'Your subscription has expired.');
// }
```

---

## 14. What's NOT Changed

- **No global scopes** — all tenancy is explicit `where('family_id', ...)` clauses
- **`MemberCategory` enum still exists** — used for the legacy `category` column on users. New families use `family_categories` table for definitions.
- **No breaking URL changes** — all existing routes (`/dashboard`, `/members`, `/contributions/my`, etc.) work as before
- **`PaymentAllocationService`** — already scoped by family_id in its queries
- **No external package dependencies added** for multi-tenancy (no Stancl/Tenancy, no spatie/multitenancy)
- **No SSR changes** — server-side rendering configuration unchanged

---

## 15. File Inventory

### New Files (30)

```
app/Models/Family.php
app/Models/FamilyCategory.php
app/Models/FamilyInvitation.php
app/Http/Controllers/FamilySettingsController.php
app/Http/Controllers/InvitationController.php
app/Http/Controllers/PlatformAdminController.php
app/Http/Middleware/SetFamilyContext.php
app/Http/Middleware/EnsurePlatformSuperAdmin.php
app/Http/Middleware/EnsureFamilySubscription.php
database/migrations/2026_03_18_000001_create_families_table.php
database/migrations/2026_03_18_000002_create_family_categories_table.php
database/migrations/2026_03_18_000003_create_family_invitations_table.php
database/migrations/2026_03_18_000004_add_family_columns_to_users_table.php
database/migrations/2026_03_18_000005_add_family_id_to_contributions_table.php
database/migrations/2026_03_18_000006_add_family_id_to_expenses_and_fund_adjustments_table.php
database/migrations/2026_03_18_000007_migrate_existing_data_to_families.php
database/migrations/2026_03_18_155228_add_billing_columns_to_families_table.php
database/factories/FamilyFactory.php
database/factories/FamilyCategoryFactory.php
database/factories/FamilyInvitationFactory.php
resources/js/pages/Family/Settings.vue
resources/js/pages/Family/Invitations.vue
resources/js/pages/Platform/Dashboard.vue
resources/js/pages/Platform/Families.vue
resources/js/pages/Platform/FamilyDetail.vue
resources/js/pages/auth/AcceptInvitation.vue
```

### Modified Files (36+)

```
app/Actions/Fortify/CreateNewUser.php
app/Enums/Role.php
app/Models/User.php
app/Models/Contribution.php
app/Models/Expense.php
app/Models/FundAdjustment.php
app/Models/Payment.php
app/Http/Controllers/DashboardController.php
app/Http/Controllers/MemberController.php
app/Http/Controllers/ContributionController.php
app/Http/Controllers/PaymentController.php
app/Http/Controllers/ExpenseController.php
app/Http/Controllers/FundAdjustmentController.php
app/Http/Controllers/ReportController.php
app/Http/Middleware/HandleInertiaRequests.php
app/Policies/ContributionPolicy.php
app/Policies/ExpensePolicy.php
app/Policies/FundAdjustmentPolicy.php
app/Policies/PaymentPolicy.php
app/Services/PaymentAllocationService.php
bootstrap/app.php
database/factories/UserFactory.php
database/factories/ContributionFactory.php
database/factories/ExpenseFactory.php
database/factories/FundAdjustmentFactory.php
database/seeders/ProductionSeeder.php
database/seeders/FamilyMemberSeeder.php
routes/web.php
resources/js/types/index.d.ts
resources/js/components/AppSidebar.vue
resources/js/pages/auth/Register.vue
resources/js/pages/Welcome.vue
tests/Feature/Auth/RegistrationTest.php
tests/Feature/Dashboard/AdminDashboardTest.php
tests/Feature/Dashboard/FinancialSecretaryDashboardTest.php
tests/Feature/Dashboard/FundBalanceTest.php
```
