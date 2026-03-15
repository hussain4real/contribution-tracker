# API Contracts: Family Contribution Tracker

**Feature Branch**: `001-family-contributions`  
**Created**: 2025-12-25  
**Status**: Complete

---

## Overview

This document defines the API contracts for the Family Contribution Tracker. All routes use **Inertia.js** responses unless explicitly marked as JSON API endpoints.

### Conventions

- All amounts are in **kobo** (₦1 = 100 kobo)
- Dates use **ISO 8601** format (YYYY-MM-DD)
- Authentication uses Laravel Fortify with optional 2FA
- Authorization is handled via Laravel Policies

---

## Route Groups

| Group | Prefix | Middleware | Description |
|-------|--------|------------|-------------|
| Dashboard | `/dashboard` | auth, verified | Main dashboard |
| Members | `/members` | auth, verified | Member management |
| Contributions | `/contributions` | auth, verified | Contribution views |
| Payments | `/payments` | auth, verified | Payment management |
| Reports | `/reports` | auth, verified | Financial reports |

---

## Dashboard Routes

### GET /dashboard

**Description**: Display role-appropriate dashboard  
**Controller**: `DashboardController@index`  
**Authorization**: Any authenticated user  
**Inertia Page**: `Dashboard/Index`

**Props**:

```typescript
interface DashboardProps {
  // Super Admin & Financial Secretary see this
  summary?: {
    total_members: number
    total_collected_this_month: number // kobo
    total_expected_this_month: number  // kobo
    collection_rate: number           // percentage
    overdue_count: number
  }
  
  // Super Admin & Financial Secretary see this (deferred)
  recent_payments?: Array<{
    id: number
    member_name: string
    amount: number
    paid_at: string
    contribution_month: string
  }>
  
  // Members see this
  personal?: {
    contribution_status: 'paid' | 'partial' | 'unpaid' | 'overdue'
    amount_due: number
    amount_paid: number
    due_date: string
    category: string
    category_amount: number
  }
  
  // Members see aggregate (FR-015)
  family_aggregate?: {
    total_collected: number
    collection_rate: number
  }
}
```

**Deferred Props**: `recent_payments`

---

## Member Routes

### GET /members

**Description**: List all members  
**Controller**: `MemberController@index`  
**Authorization**: Super Admin, Financial Secretary  
**Inertia Page**: `Members/Index`

**Props**:

```typescript
interface MembersIndexProps {
  members: {
    data: Array<{
      id: number
      name: string
      email: string
      role: 'super_admin' | 'financial_secretary' | 'member'
      category: 'employed' | 'unemployed' | 'student' | null
      category_label: string
      monthly_amount: number
      is_archived: boolean
      created_at: string
    }>
    links: PaginationLinks
    meta: PaginationMeta
  }
  filters: {
    search?: string
    category?: string
    status?: 'active' | 'archived'
  }
}
```

**Query Parameters**:
- `search`: string (name or email)
- `category`: 'employed' | 'unemployed' | 'student'
- `status`: 'active' | 'archived'
- `page`: number

---

### GET /members/create

**Description**: Show member creation form  
**Controller**: `MemberController@create`  
**Authorization**: Super Admin only  
**Inertia Page**: `Members/Create`

**Props**:

```typescript
interface MembersCreateProps {
  roles: Array<{ value: string; label: string }>
  categories: Array<{ value: string; label: string; amount: number }>
}
```

---

### POST /members

**Description**: Store new member  
**Controller**: `MemberController@store`  
**Authorization**: Super Admin only

**Request Body**:

```typescript
interface StoreMemberRequest {
  name: string          // required, max:255
  email: string         // required, email, unique:users
  password: string      // required, min:8, confirmed
  password_confirmation: string
  role: 'super_admin' | 'financial_secretary' | 'member'
  category?: 'employed' | 'unemployed' | 'student' // required unless super_admin
}
```

**Response**: Redirect to `/members` with success message

**Validation Errors**:

```typescript
interface ValidationErrors {
  name?: string[]
  email?: string[]
  password?: string[]
  role?: string[]
  category?: string[]
}
```

---

### GET /members/{user}

**Description**: View member details  
**Controller**: `MemberController@show`  
**Authorization**: Super Admin, Financial Secretary, or self  
**Inertia Page**: `Members/Show`

**Props**:

```typescript
interface MembersShowProps {
  member: {
    id: number
    name: string
    email: string
    role: string
    category: string | null
    category_label: string
    monthly_amount: number
    is_archived: boolean
    created_at: string
  }
  
  // Deferred props (infinite scroll)
  contributions: Array<{
    id: number
    month: string // "January 2025"
    expected_amount: number
    total_paid: number
    balance: number
    status: 'paid' | 'partial' | 'unpaid' | 'overdue'
    payments: Array<{
      id: number
      amount: number
      paid_at: string
      recorded_by: string
    }>
  }>
}
```

---

### GET /members/{user}/edit

**Description**: Show member edit form  
**Controller**: `MemberController@edit`  
**Authorization**: Super Admin only  
**Inertia Page**: `Members/Edit`

**Props**:

```typescript
interface MembersEditProps {
  member: {
    id: number
    name: string
    email: string
    role: string
    category: string | null
  }
  roles: Array<{ value: string; label: string }>
  categories: Array<{ value: string; label: string; amount: number }>
}
```

---

### PUT /members/{user}

**Description**: Update member  
**Controller**: `MemberController@update`  
**Authorization**: Super Admin only

**Request Body**:

```typescript
interface UpdateMemberRequest {
  name: string          // required, max:255
  email: string         // required, email, unique:users,id
  role: 'super_admin' | 'financial_secretary' | 'member'
  category?: 'employed' | 'unemployed' | 'student'
}
```

**Response**: Redirect to `/members/{user}` with success message

---

### DELETE /members/{user}

**Description**: Archive member (soft delete)  
**Controller**: `MemberController@destroy`  
**Authorization**: Super Admin only

**Response**: Redirect to `/members` with success message

---

### POST /members/{user}/restore

**Description**: Restore archived member  
**Controller**: `MemberController@restore`  
**Authorization**: Super Admin only

**Response**: Redirect to `/members/{user}` with success message

---

## Contribution Routes

### GET /contributions

**Description**: List contributions for current month  
**Controller**: `ContributionController@index`  
**Authorization**: Super Admin, Financial Secretary  
**Inertia Page**: `Contributions/Index`

**Props**:

```typescript
interface ContributionsIndexProps {
  contributions: {
    data: Array<{
      id: number
      member: {
        id: number
        name: string
        category: string
      }
      expected_amount: number
      total_paid: number
      balance: number
      status: 'paid' | 'partial' | 'unpaid' | 'overdue'
      due_date: string
    }>
    links: PaginationLinks
    meta: PaginationMeta
  }
  
  summary: {
    total_expected: number
    total_collected: number
    paid_count: number
    partial_count: number
    unpaid_count: number
    overdue_count: number
  }
  
  filters: {
    year: number
    month: number
    status?: string
    category?: string
  }
  
  available_months: Array<{ year: number; month: number; label: string }>
}
```

**Query Parameters**:
- `year`: number (default: current year)
- `month`: number (default: current month)
- `status`: 'paid' | 'partial' | 'unpaid' | 'overdue'
- `category`: 'employed' | 'unemployed' | 'student'

---

### GET /contributions/my

**Description**: View authenticated member's own contributions  
**Controller**: `ContributionController@my`  
**Authorization**: Any authenticated user  
**Inertia Page**: `Contributions/My`

**Props**:

```typescript
interface MyContributionsProps {
  contributions: Array<{
    id: number
    month: string
    year: number
    expected_amount: number
    total_paid: number
    balance: number
    status: 'paid' | 'partial' | 'unpaid' | 'overdue'
    payments: Array<{
      id: number
      amount: number
      paid_at: string
    }>
  }>
  
  current_category: {
    name: string
    monthly_amount: number
  }
  
  // FR-015: Family aggregate visible to members
  family_aggregate: {
    total_collected_this_month: number
    collection_rate: number
  }
}
```

---

### GET /contributions/{contribution}

**Description**: View single contribution details  
**Controller**: `ContributionController@show`  
**Authorization**: Super Admin, Financial Secretary, or owner  
**Inertia Page**: `Contributions/Show`

**Props**:

```typescript
interface ContributionShowProps {
  contribution: {
    id: number
    member: {
      id: number
      name: string
      category: string
    }
    year: number
    month: number
    month_label: string
    expected_amount: number
    total_paid: number
    balance: number
    status: 'paid' | 'partial' | 'unpaid' | 'overdue'
    due_date: string
    payments: Array<{
      id: number
      amount: number
      paid_at: string
      recorded_by: {
        id: number
        name: string
      }
      notes: string | null
      created_at: string
    }>
  }
  
  can_record_payment: boolean
}
```

---

## Payment Routes

### GET /payments

**Description**: List all payments  
**Controller**: `PaymentController@index`  
**Authorization**: Super Admin, Financial Secretary  
**Inertia Page**: `Payments/Index`

**Props**:

```typescript
interface PaymentsIndexProps {
  payments: {
    data: Array<{
      id: number
      amount: number
      paid_at: string
      notes: string | null
      contribution: {
        id: number
        month_label: string
        member: {
          id: number
          name: string
        }
      }
      recorded_by: {
        id: number
        name: string
      }
      created_at: string
    }>
    links: PaginationLinks
    meta: PaginationMeta
  }
  
  filters: {
    year?: number
    month?: number
    member_id?: number
  }
}
```

---

### GET /contributions/{contribution}/payments/create

**Description**: Show payment recording form  
**Controller**: `PaymentController@create`  
**Authorization**: Super Admin, Financial Secretary  
**Inertia Page**: `Payments/Create`

**Props**:

```typescript
interface PaymentCreateProps {
  contribution: {
    id: number
    member_name: string
    month_label: string
    expected_amount: number
    total_paid: number
    balance: number
  }
}
```

---

### GET /members/{user}/payments/create

**Description**: Show payment recording form with month selector for advance payments (FR-018)  
**Controller**: `PaymentController@create`  
**Authorization**: Super Admin, Financial Secretary  
**Inertia Page**: `Payments/Create`

**Props**:

```typescript
interface PaymentCreateWithMonthProps {
  member: {
    id: number
    name: string
    category: string
    monthly_amount: number
  }
  // Available months for payment (current + next 6 months)
  available_months: Array<{
    value: string        // "2025-12"
    label: string        // "December 2025"
    contribution_id: number | null  // null if not yet created
    expected_amount: number
    total_paid: number
    balance: number
    status: 'paid' | 'partial' | 'unpaid' | 'overdue'
  }>
  selected_month?: string  // Pre-selected month if any
}
```

---

### POST /contributions/{contribution}/payments

**Description**: Record new payment  
**Controller**: `PaymentController@store`  
**Authorization**: Super Admin, Financial Secretary

**Request Body**:

```typescript
interface StorePaymentRequest {
  amount: number       // required, integer, min:1
  paid_at: string      // required, date, before_or_equal:today
  notes?: string       // nullable, max:500
}
```

**Response**: Redirect to `/contributions/{contribution}` with success message

---

### POST /members/{user}/payments

**Description**: Record payment for specific month (supports advance payments FR-018, balance-first rule FR-020)  
**Controller**: `PaymentController@storeForMember`  
**Authorization**: Super Admin, Financial Secretary

**Request Body**:

```typescript
interface StoreAdvancePaymentRequest {
  target_month: string   // required, format "YYYY-MM", max 6 months ahead
  amount: number         // required, integer, min:1
  paid_at: string        // required, date, before_or_equal:today
  notes?: string         // nullable, max:500
}
```

**Balance-First Rule (FR-020)**:
Before applying payment to `target_month`, the system MUST:
1. Find all incomplete contributions for this member (oldest first)
2. If any incomplete month exists BEFORE `target_month`, auto-allocate payment to complete it first
3. Remaining amount (if any) applies to `target_month`
4. If payment exceeds `target_month` balance, excess applies to next month (up to 6 months ahead)

**Example**: Member owes ₦2,000 for November (partial) and wants to pay for December.
- Payment of ₦6,000 → ₦2,000 completes November, ₦4,000 goes to December
- Response shows allocation breakdown

**Validation**:
- `target_month` must be between current month and 6 months in the future
- System auto-creates Contribution record if it doesn't exist

**Response**: Redirect to `/contributions/{contribution}` with success message and allocation summary

**Validation Errors**:

```typescript
interface ValidationErrors {
  amount?: string[]
  paid_at?: string[]
  notes?: string[]
}
```

---

### DELETE /payments/{payment}

**Description**: Delete a payment record  
**Controller**: `PaymentController@destroy`  
**Authorization**: Super Admin only

**Response**: Redirect back with success message

---

## Report Routes

### GET /reports

**Description**: Reports dashboard  
**Controller**: `ReportController@index`  
**Authorization**: Super Admin, Financial Secretary  
**Inertia Page**: `Reports/Index`

**Props**:

```typescript
interface ReportsIndexProps {
  available_years: number[]
  current_year: number
}
```

---

### GET /reports/monthly

**Description**: Monthly collection report  
**Controller**: `ReportController@monthly`  
**Authorization**: Super Admin, Financial Secretary  
**Inertia Page**: `Reports/Monthly`

**Props**:

```typescript
interface MonthlyReportProps {
  year: number
  month: number
  month_label: string
  
  summary: {
    total_members: number
    total_expected: number
    total_collected: number
    collection_rate: number
  }
  
  by_category: Array<{
    category: string
    member_count: number
    expected: number
    collected: number
    rate: number
  }>
  
  by_status: {
    paid: number
    partial: number
    unpaid: number
    overdue: number
  }
}
```

---

### GET /reports/annual

**Description**: Annual summary report  
**Controller**: `ReportController@annual`  
**Authorization**: Super Admin, Financial Secretary  
**Inertia Page**: `Reports/Annual`

**Props**:

```typescript
interface AnnualReportProps {
  year: number
  
  monthly_breakdown: Array<{
    month: number
    month_label: string
    expected: number
    collected: number
    rate: number
  }>
  
  total: {
    expected: number
    collected: number
    rate: number
  }
}
```

---

## Wayfinder Integration

All routes are accessible via Wayfinder-generated TypeScript functions:

```typescript
// Members
import { index, create, store, show, edit, update, destroy, restore } 
  from '@/actions/App/Http/Controllers/MemberController'

// Usage examples
index()                           // { url: "/members", method: "get" }
store.form()                      // { action: "/members", method: "post" }
show(1)                           // { url: "/members/1", method: "get" }
update(1)                         // { url: "/members/1", method: "put" }

// Payments
import { create as createPayment, store as storePayment } 
  from '@/actions/App/Http/Controllers/PaymentController'

createPayment({ contribution: 5 }) // { url: "/contributions/5/payments/create", method: "get" }
storePayment.form({ contribution: 5 }) // { action: "/contributions/5/payments", method: "post" }
```

---

## Shared Types

```typescript
interface PaginationLinks {
  first: string
  last: string
  prev: string | null
  next: string | null
}

interface PaginationMeta {
  current_page: number
  from: number
  last_page: number
  path: string
  per_page: number
  to: number
  total: number
}
```

---

**Next Steps**: Generate `quickstart.md` with development setup instructions
