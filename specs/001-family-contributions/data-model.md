# Data Model: Family Contribution Tracker

**Feature Branch**: `001-family-contributions`  
**Created**: 2025-12-25  
**Status**: Complete

## Entity Relationship Diagram

```
┌─────────────────────────────────────────────────────────────────────┐
│                              User                                    │
│  (Family Member - extends existing users table)                      │
├─────────────────────────────────────────────────────────────────────┤
│  id: bigint PK                                                       │
│  name: varchar(255)                                                  │
│  email: varchar(255) UNIQUE                                          │
│  password: varchar(255)                                              │
│  role: enum('super_admin','financial_secretary','member')            │
│  category: enum('employed','unemployed','student') NULLABLE          │
│  archived_at: timestamp NULLABLE                                     │
│  created_at: timestamp                                               │
│  updated_at: timestamp                                               │
│  ... (existing Fortify 2FA columns)                                  │
└─────────────────────────────────────────────────────────────────────┘
         │
         │ 1:N
         ▼
┌─────────────────────────────────────────────────────────────────────┐
│                          Contribution                                │
│  (Monthly obligation for a user)                                     │
├─────────────────────────────────────────────────────────────────────┤
│  id: bigint PK                                                       │
│  user_id: bigint FK → users.id                                       │
│  year: smallint                                                      │
│  month: tinyint (1-12)                                               │
│  expected_amount: integer (in kobo)                                  │
│  created_at: timestamp                                               │
│  updated_at: timestamp                                               │
│                                                                      │
│  UNIQUE: (user_id, year, month)                                      │
│  INDEX: (year, month)                                                │
└─────────────────────────────────────────────────────────────────────┘
         │
         │ 1:N
         ▼
┌─────────────────────────────────────────────────────────────────────┐
│                            Payment                                   │
│  (Individual payment record)                                         │
├─────────────────────────────────────────────────────────────────────┤
│  id: bigint PK                                                       │
│  contribution_id: bigint FK → contributions.id                       │
│  amount: integer (in kobo)                                           │
│  paid_at: date                                                       │
│  recorded_by: bigint FK → users.id                                   │
│  notes: text NULLABLE                                                │
│  created_at: timestamp                                               │
│  updated_at: timestamp                                               │
│                                                                      │
│  INDEX: (contribution_id)                                            │
│  INDEX: (paid_at)                                                    │
└─────────────────────────────────────────────────────────────────────┘
```

## Entities

### User (Extended)

Extends the existing `users` table with role and category fields.

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| id | bigint | PK, auto-increment | Primary key |
| name | varchar(255) | NOT NULL | Full name |
| email | varchar(255) | NOT NULL, UNIQUE | Email for login |
| password | varchar(255) | NOT NULL | Hashed password |
| role | enum | NOT NULL, DEFAULT 'member' | User role |
| category | enum | NULLABLE | Contribution category (null for super_admin without contribution) |
| archived_at | timestamp | NULLABLE | Soft archive date (preserves history) |
| email_verified_at | timestamp | NULLABLE | (existing) |
| two_factor_* | various | NULLABLE | (existing Fortify columns) |
| remember_token | varchar(100) | NULLABLE | (existing) |
| created_at | timestamp | | |
| updated_at | timestamp | | |

**Validation Rules**:
- `name`: required, string, max:255
- `email`: required, email, unique:users
- `role`: required, in:super_admin,financial_secretary,member
- `category`: required_if:role,member|financial_secretary, in:employed,unemployed,student

### Contribution

Represents a monthly obligation for a family member.

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| id | bigint | PK, auto-increment | Primary key |
| user_id | bigint | FK → users.id, ON DELETE CASCADE | The member |
| year | smallint | NOT NULL | Year (e.g., 2025) |
| month | tinyint | NOT NULL, 1-12 | Month number |
| expected_amount | integer | NOT NULL | Amount due in kobo |
| created_at | timestamp | | |
| updated_at | timestamp | | |

**Computed Attributes**:
- `status`: PaymentStatus enum (Paid/Partial/Unpaid/Overdue) - calculated from payments
- `total_paid`: Sum of all payments
- `balance`: expected_amount - total_paid
- `due_date`: Carbon date (year-month-28)

**Indexes**:
- `UNIQUE (user_id, year, month)` - One contribution per member per month
- `INDEX (year, month)` - Query current month contributions

### Payment

Represents an individual payment transaction.

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| id | bigint | PK, auto-increment | Primary key |
| contribution_id | bigint | FK → contributions.id, ON DELETE CASCADE | Parent contribution |
| amount | integer | NOT NULL, > 0 | Payment amount in kobo |
| paid_at | date | NOT NULL | Date payment was received |
| recorded_by | bigint | FK → users.id, ON DELETE SET NULL | Who recorded this payment |
| notes | text | NULLABLE | Optional notes |
| created_at | timestamp | | |
| updated_at | timestamp | | |

**Validation Rules**:
- `contribution_id`: required, exists:contributions,id (contribution must be within 6 months ahead for advance payments per FR-018)
- `amount`: required, integer, min:1
- `paid_at`: required, date, before_or_equal:today
- `notes`: nullable, string, max:500

**Advance Payments (FR-018)**: Payments can be recorded for contributions up to 6 months in the future. The Financial Secretary selects the target month when recording; the system creates the contribution record if it doesn't exist.

---

## Enums

### Role

```php
enum Role: string
{
    case SuperAdmin = 'super_admin';
    case FinancialSecretary = 'financial_secretary';
    case Member = 'member';
}
```

### MemberCategory

```php
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
    
    public function label(): string
    {
        return match($this) {
            self::Employed => 'Employed',
            self::Unemployed => 'Unemployed',
            self::Student => 'Student',
        };
    }
}
```

### PaymentStatus

```php
enum PaymentStatus: string
{
    case Paid = 'paid';
    case Partial = 'partial';
    case Unpaid = 'unpaid';
    case Overdue = 'overdue';
    
    public function label(): string
    {
        return match($this) {
            self::Paid => 'Paid',
            self::Partial => 'Partial',
            self::Unpaid => 'Unpaid',
            self::Overdue => 'Overdue',
        };
    }
    
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

---

## Relationships

### User

```php
// User has many contributions
public function contributions(): HasMany
{
    return $this->hasMany(Contribution::class);
}

// User has many payments they recorded
public function recordedPayments(): HasMany
{
    return $this->hasMany(Payment::class, 'recorded_by');
}

// User's own payments (through contributions)
public function payments(): HasManyThrough
{
    return $this->hasManyThrough(Payment::class, Contribution::class);
}
```

### Contribution

```php
// Contribution belongs to a user
public function user(): BelongsTo
{
    return $this->belongsTo(User::class);
}

// Contribution has many payments
public function payments(): HasMany
{
    return $this->hasMany(Payment::class);
}
```

### Payment

```php
// Payment belongs to a contribution
public function contribution(): BelongsTo
{
    return $this->belongsTo(Contribution::class);
}

// Payment was recorded by a user
public function recorder(): BelongsTo
{
    return $this->belongsTo(User::class, 'recorded_by');
}
```

---

## Query Scopes

### User Scopes

```php
public function scopeActive(Builder $query): Builder
{
    return $query->whereNull('archived_at');
}

public function scopeMembers(Builder $query): Builder
{
    return $query->where('role', Role::Member);
}

public function scopeWithCategory(Builder $query, MemberCategory $category): Builder
{
    return $query->where('category', $category);
}
```

### Contribution Scopes

```php
public function scopeForMonth(Builder $query, int $year, int $month): Builder
{
    return $query->where('year', $year)->where('month', $month);
}

public function scopeCurrentMonth(Builder $query): Builder
{
    return $query->forMonth(now()->year, now()->month);
}

public function scopeForUser(Builder $query, User $user): Builder
{
    return $query->where('user_id', $user->id);
}
```

### Payment Scopes

```php
public function scopeCurrentMonth(Builder $query): Builder
{
    return $query->whereHas('contribution', fn($q) => 
        $q->currentMonth()
    );
}

public function scopeRecordedBy(Builder $query, User $user): Builder
{
    return $query->where('recorded_by', $user->id);
}
```

---

## Migrations

### Add Role and Category to Users

```php
Schema::table('users', function (Blueprint $table) {
    $table->string('role')->default('member')->after('password');
    $table->string('category')->nullable()->after('role');
    $table->timestamp('archived_at')->nullable()->after('updated_at');
    
    $table->index('role');
    $table->index('category');
    $table->index('archived_at');
});
```

### Create Contributions Table

```php
Schema::create('contributions', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->smallInteger('year');
    $table->tinyInteger('month');
    $table->integer('expected_amount'); // in kobo
    $table->timestamps();
    
    $table->unique(['user_id', 'year', 'month']);
    $table->index(['year', 'month']);
});
```

### Create Payments Table

```php
Schema::create('payments', function (Blueprint $table) {
    $table->id();
    $table->foreignId('contribution_id')->constrained()->cascadeOnDelete();
    $table->integer('amount'); // in kobo
    $table->date('paid_at');
    $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
    $table->text('notes')->nullable();
    $table->timestamps();
    
    $table->index('contribution_id');
    $table->index('paid_at');
});
```

---

## Factories

### UserFactory (extend existing)

```php
public function superAdmin(): static
{
    return $this->state(fn(array $attributes) => [
        'role' => Role::SuperAdmin,
        'category' => null,
    ]);
}

public function financialSecretary(): static
{
    return $this->state(fn(array $attributes) => [
        'role' => Role::FinancialSecretary,
        'category' => fake()->randomElement(MemberCategory::cases()),
    ]);
}

public function member(): static
{
    return $this->state(fn(array $attributes) => [
        'role' => Role::Member,
        'category' => fake()->randomElement(MemberCategory::cases()),
    ]);
}

public function employed(): static
{
    return $this->state(fn(array $attributes) => [
        'category' => MemberCategory::Employed,
    ]);
}

public function unemployed(): static
{
    return $this->state(fn(array $attributes) => [
        'category' => MemberCategory::Unemployed,
    ]);
}

public function student(): static
{
    return $this->state(fn(array $attributes) => [
        'category' => MemberCategory::Student,
    ]);
}

public function archived(): static
{
    return $this->state(fn(array $attributes) => [
        'archived_at' => now(),
    ]);
}
```

### ContributionFactory

```php
class ContributionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'year' => now()->year,
            'month' => now()->month,
            'expected_amount' => MemberCategory::Employed->monthlyAmountInKobo(),
        ];
    }
    
    public function currentMonth(): static
    {
        return $this->state(fn(array $attributes) => [
            'year' => now()->year,
            'month' => now()->month,
        ]);
    }
    
    public function forMonth(int $year, int $month): static
    {
        return $this->state(fn(array $attributes) => [
            'year' => $year,
            'month' => $month,
        ]);
    }
}
```

### PaymentFactory

```php
class PaymentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'contribution_id' => Contribution::factory(),
            'amount' => MemberCategory::Employed->monthlyAmountInKobo(),
            'paid_at' => now(),
            'recorded_by' => User::factory()->financialSecretary(),
            'notes' => null,
        ];
    }
    
    public function partial(): static
    {
        return $this->state(fn(array $attributes) => [
            'amount' => (int) ($attributes['amount'] ?? 400000) / 2,
        ]);
    }
}
```

---

## State Transitions

### Contribution Status Flow

```
┌──────────┐     Payment > 0     ┌──────────┐
│  Unpaid  │ ─────────────────▶  │ Partial  │
└──────────┘                     └──────────┘
     │                                │
     │  After 28th                    │  Payment sum >= expected
     ▼                                ▼
┌──────────┐                     ┌──────────┐
│ Overdue  │ ─────────────────▶  │   Paid   │
└──────────┘  Payment sum >=     └──────────┘
              expected
```

### User Archiving

```
Active ──────▶ Archived
   │              │
   │              │ (preserves all contributions & payments)
   │              │
   ▼              ▼
Can login     Cannot login
Can pay       Removed from dashboard
Visible       Hidden from member list (unless filtered)
```

---

**Next Steps**: Generate API contracts in `contracts/api.md`
