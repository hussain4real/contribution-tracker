# Research: Family Contribution Tracker

**Feature Branch**: `001-family-contributions`  
**Created**: 2025-12-25  
**Status**: Complete

## Research Summary

This document consolidates research findings for implementing the family contribution tracker. All unknowns from Technical Context have been resolved.

---

## 1. Inertia v2 Features for This Project

### Decision: Use Inertia v2 Modern Features

**Rationale**: Inertia v2 provides significant UX improvements for this dashboard-heavy application.

### Features to Implement

| Feature | Use Case in This Project |
|---------|--------------------------|
| **Deferred Props** | Dashboard statistics (aggregate totals) loaded after initial render |
| **Once Prop** | Category list (static data) fetched once and cached |
| **Form Component** | Payment recording form, member creation form |
| **Partial Reloads** | Refresh payment status without reloading entire page |
| **Merging Props** | Payment history infinite scroll |
| **WhenVisible** | Lazy-load member payment details on scroll |
| **Prefetching** | Prefetch member details on hover |
| **Polling** | Dashboard auto-refresh every 30 seconds for Financial Secretary |
| **Shared Data** | Current user role, flash messages |

### Code Patterns

```php
// Controller with deferred props for dashboard
public function index(): Response
{
    return Inertia::render('Dashboard', [
        // Immediate props
        'user' => fn() => auth()->user()->only('id', 'name', 'role'),
        
        // Deferred props (loaded after initial render)
        'aggregateStats' => Inertia::defer(fn() => $this->calculateAggregateStats()),
        'memberStatuses' => Inertia::defer(fn() => $this->getMemberStatuses())
            ->group('dashboard'),
        
        // Once prop (cached, doesn't change)
        'categories' => Inertia::once(fn() => MemberCategory::cases()),
    ]);
}
```

```vue
<!-- Vue component with deferred prop skeleton -->
<script setup>
import { Deferred } from '@inertiajs/vue3'
import { Skeleton } from '@/components/ui/skeleton'
</script>

<template>
    <Deferred data="aggregateStats">
        <template #fallback>
            <Skeleton class="h-24 w-full" />
        </template>
        <AggregateStats :stats="aggregateStats" />
    </Deferred>
</template>
```

**Alternatives Considered**:
- Traditional eager loading: Rejected because dashboard would be slow with multiple aggregate queries
- API-based loading: Rejected because Inertia v2 deferred props provide the same benefit with less code

---

## 2. Role-Based Authorization

### Decision: Laravel Policies + Enum-Based Roles

**Rationale**: Policies provide declarative authorization. Enum roles are simple for 3 fixed roles.

### Implementation Pattern

```php
// app/Enums/Role.php
enum Role: string
{
    case SuperAdmin = 'super_admin';
    case FinancialSecretary = 'financial_secretary';
    case Member = 'member';
    
    public function canRecordPayments(): bool
    {
        return match($this) {
            self::SuperAdmin, self::FinancialSecretary => true,
            default => false,
        };
    }
    
    public function canManageMembers(): bool
    {
        return $this === self::SuperAdmin;
    }
    
    public function canViewAllMembers(): bool
    {
        return match($this) {
            self::SuperAdmin, self::FinancialSecretary => true,
            default => false,
        };
    }
}
```

```php
// app/Policies/PaymentPolicy.php
class PaymentPolicy
{
    public function create(User $user): bool
    {
        return $user->role->canRecordPayments();
    }
    
    public function view(User $user, Payment $payment): bool
    {
        return $user->role->canViewAllMembers() 
            || $payment->contribution->user_id === $user->id;
    }
}
```

**Alternatives Considered**:
- Spatie Permission package: Rejected because overkill for 3 fixed roles
- Database-stored roles: Rejected because roles don't change at runtime

---

## 3. Contribution/Payment Data Model

### Decision: Contributions Table + Payments Table

**Rationale**: Separating monthly obligations from actual payments allows tracking partial payments and multiple payments per month.

### Key Design Decisions

1. **Contributions are pre-generated**: Monthly contributions created at start of each month (or on-demand)
2. **Multiple payments per contribution**: Supports partial payments and overpayments
3. **Status computed dynamically**: Payment status (Paid/Partial/Unpaid/Overdue) calculated from payment sum vs expected amount

```php
// Payment status calculation
public function getStatusAttribute(): PaymentStatus
{
    $paid = $this->payments->sum('amount');
    $expected = $this->expected_amount;
    $dueDate = Carbon::create($this->year, $this->month, 28);
    
    return match(true) {
        $paid >= $expected => PaymentStatus::Paid,
        $paid > 0 => PaymentStatus::Partial,
        now()->greaterThan($dueDate) => PaymentStatus::Overdue,
        default => PaymentStatus::Unpaid,
    };
}
```

**Alternatives Considered**:
- Single payments table with month column: Rejected because can't track partial payments easily
- Status stored in database: Rejected because computed status is always accurate

---

## 4. Dashboard Visibility by Role

### Decision: Role-Conditional Rendering with Backend Filtering

**Rationale**: Security requires backend enforcement; frontend adapts UI accordingly.

### Implementation

```php
// DashboardController.php
public function index(): Response
{
    $user = auth()->user();
    
    return Inertia::render('Dashboard', [
        'canViewAllMembers' => $user->role->canViewAllMembers(),
        
        // All roles see aggregate stats
        'aggregateStats' => Inertia::defer(fn() => [
            'totalExpected' => Contribution::currentMonth()->sum('expected_amount'),
            'totalCollected' => Payment::currentMonth()->sum('amount'),
            'totalOutstanding' => /* calculation */,
        ]),
        
        // Only admins see individual member details
        'memberStatuses' => $user->role->canViewAllMembers()
            ? Inertia::defer(fn() => $this->getMemberStatuses())
            : null,
        
        // All members see their own status
        'myStatus' => Inertia::defer(fn() => $this->getMyStatus($user)),
    ]);
}
```

```vue
<!-- Dashboard.vue -->
<template>
    <!-- Everyone sees aggregate stats -->
    <AggregateStats :stats="aggregateStats" />
    
    <!-- Only admins see member list -->
    <MemberStatusList v-if="canViewAllMembers" :members="memberStatuses" />
    
    <!-- Regular members see their own status -->
    <MyContributionCard v-else :status="myStatus" />
</template>
```

---

## 5. Wayfinder Integration

### Decision: Named Imports for Tree-Shaking

**Rationale**: Per Laravel Boost guidelines, named imports enable tree-shaking.

### Usage Pattern

```vue
<script setup>
import { Form } from '@inertiajs/vue3'
import { store } from '@/actions/App/Http/Controllers/PaymentController'
</script>

<template>
    <Form v-bind="store.form()">
        <input type="hidden" name="contribution_id" :value="contributionId" />
        <input type="number" name="amount" v-model="amount" />
        <Button type="submit">Record Payment</Button>
    </Form>
</template>
```

```vue
<!-- Using with useForm for more control -->
<script setup>
import { useForm } from '@inertiajs/vue3'
import { store } from '@/actions/App/Http/Controllers/PaymentController'

const form = useForm({
    contribution_id: props.contributionId,
    amount: props.expectedAmount,
})

function submit() {
    form.submit(store())
}
</script>
```

---

## 6. Browser Testing with Pest v4

### Decision: Playwright via Pest Browser Plugin

**Rationale**: Pest v4 browser testing integrates with Laravel features (factories, assertions).

### Test Structure

```php
// tests/Browser/PaymentRecordingFlowTest.php
it('allows financial secretary to record a payment', function () {
    $user = User::factory()->financialSecretary()->create();
    $member = User::factory()->member()->create();
    Contribution::factory()->for($member)->currentMonth()->create([
        'expected_amount' => 4000,
    ]);
    
    $page = visit('/login');
    
    $page->fill('email', $user->email)
         ->fill('password', 'password')
         ->click('Sign In')
         ->assertSee('Dashboard')
         ->click($member->name)
         ->assertSee('Record Payment')
         ->fill('amount', '4000')
         ->click('Record Payment')
         ->assertSee('Payment recorded successfully');
    
    $this->assertDatabaseHas('payments', [
        'amount' => 4000,
        'recorded_by' => $user->id,
    ]);
});
```

### Configuration

```php
// tests/Pest.php
pest()->extend(Tests\TestCase::class)
    ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Feature', 'Browser');

pest()->browser()
    ->timeout(10000)
    ->headed(); // For debugging
```

---

## 7. Overdue Detection

### Decision: Compute Status Dynamically, No Scheduled Jobs

**Rationale**: For ~50 members, computing status on-demand is performant and simpler than scheduled jobs.

### Implementation

```php
// app/Enums/PaymentStatus.php
enum PaymentStatus: string
{
    case Paid = 'paid';
    case Partial = 'partial';
    case Unpaid = 'unpaid';
    case Overdue = 'overdue';
    
    public function color(): string
    {
        return match($this) {
            self::Paid => 'green',
            self::Partial => 'yellow',
            self::Unpaid => 'gray',
            self::Overdue => 'red',
        };
    }
}
```

**Alternatives Considered**:
- Scheduled job to mark overdue: Rejected because adds complexity for small scale
- Store status in DB: Rejected because computed is always accurate

---

## 8. Currency Handling

### Decision: Store in Kobo (smallest unit), Display in Naira

**Rationale**: Integer storage avoids floating-point precision issues.

### Implementation

```php
// Migration
$table->integer('amount'); // Stored in kobo (smallest unit)
$table->integer('expected_amount');

// Model accessor
public function getAmountInNairaAttribute(): float
{
    return $this->amount / 100;
}

// Constants in MemberCategory enum
enum MemberCategory: string
{
    case Employed = 'employed';
    case Unemployed = 'unemployed';
    case Student = 'student';
    
    public function monthlyAmountInKobo(): int
    {
        return match($this) {
            self::Employed => 400000,    // ₦4,000
            self::Unemployed => 200000,  // ₦2,000
            self::Student => 100000,     // ₦1,000
        };
    }
}
```

---

## Summary of Decisions

| Area | Decision | Rationale |
|------|----------|-----------|
| Inertia Features | Deferred props, Form component, WhenVisible | Performance + UX |
| Authorization | Enum roles + Policies | Simple for 3 fixed roles |
| Data Model | Contributions + Payments tables | Supports partial payments |
| Dashboard | Role-conditional backend filtering | Security + UX |
| Routing | Wayfinder named imports | Tree-shaking + type safety |
| Testing | Pest v4 Feature + Browser | TDD with E2E coverage |
| Overdue | Computed dynamically | Simple for small scale |
| Currency | Store in kobo | Avoid floating-point issues |

---

**Next Steps**: Proceed to Phase 1 (data-model.md, contracts/, quickstart.md)
