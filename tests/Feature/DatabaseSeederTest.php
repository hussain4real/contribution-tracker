<?php

declare(strict_types=1);

use App\Enums\InvitationDeliveryMethod;
use App\Enums\PaymentMethod;
use App\Enums\PaymentSource;
use App\Enums\PaymentStatus;
use App\Enums\ReportDeliveryStatus;
use App\Enums\ReportFormat;
use App\Enums\ReportScheduleFrequency;
use App\Enums\ReportType;
use App\Enums\Role;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\AuditEvent;
use App\Models\Contribution;
use App\Models\Expense;
use App\Models\Family;
use App\Models\FamilyInvitation;
use App\Models\FamilyMembership;
use App\Models\FamilyMembershipCategoryAssignment;
use App\Models\FinancialReversal;
use App\Models\FundAdjustment;
use App\Models\Payment;
use App\Models\PaymentBatch;
use App\Models\PaystackTransaction;
use App\Models\PlatformPlan;
use App\Models\ReportArtifact;
use App\Models\ReportDelivery;
use App\Models\ReportSchedule;
use App\Models\User;
use App\Models\WhatsAppMessage;
use Database\Seeders\DevelopmentSeeder;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Carbon::setTestNow('2026-07-14 12:00:00');
    Mail::fake();
    Storage::fake('local');
});

afterEach(function () {
    Carbon::setTestNow();
});

it('seeds every implemented product area with coherent demo scenarios', function () {
    $this->artisan('db:seed', ['--no-interaction' => true])->assertSuccessful();

    expect(PlatformPlan::query()->count())->toBe(4)
        ->and(Family::query()->count())->toBe(6)
        ->and(User::query()->count())->toBe(19)
        ->and(FamilyMembership::query()->count())->toBe(19)
        ->and(FamilyMembershipCategoryAssignment::query()->count())->toBeGreaterThanOrEqual(7)
        ->and(Contribution::query()->count())->toBe(30)
        ->and(PaymentBatch::query()->count())->toBe(28)
        ->and(Payment::query()->count())->toBe(29)
        ->and(Expense::query()->count())->toBe(6)
        ->and(FundAdjustment::query()->count())->toBe(4)
        ->and(FinancialReversal::query()->count())->toBe(6)
        ->and(PaystackTransaction::query()->count())->toBe(10)
        ->and(ReportArtifact::query()->count())->toBe(10)
        ->and(ReportSchedule::query()->count())->toBe(5)
        ->and(ReportDelivery::query()->count())->toBe(4)
        ->and(FamilyInvitation::query()->count())->toBe(4)
        ->and(WhatsAppMessage::query()->count())->toBe(4)
        ->and(DatabaseNotification::query()->count())->toBe(2)
        ->and(DB::table('agent_conversations')->count())->toBe(1)
        ->and(DB::table('agent_conversation_messages')->count())->toBe(2)
        ->and(AuditEvent::query()->count())->toBeGreaterThan(40);

    $demoFamily = Family::query()->where('slug', 'demo-family')->firstOrFail();
    $multiFamilyMember = User::query()->where('email', 'multi@family.test')->firstOrFail();
    $multiFamilyMemberships = FamilyMembership::query()
        ->where('user_id', $multiFamilyMember->id)
        ->orderBy('family_id')
        ->get();

    expect($multiFamilyMemberships)->toHaveCount(2)
        ->and($multiFamilyMemberships->pluck('role')->all())->toContain(Role::Member, Role::FinancialSecretary)
        ->and($multiFamilyMemberships->pluck('display_name')->all())->toContain('Ehsan Multi Family', 'Ehsan Organization Treasurer')
        ->and($demoFamily->platformPlan?->slug)->toBe('growth')
        ->and(User::query()->where('email', 'platform@family.test')->firstOrFail()->isSuperAdmin())->toBeTrue()
        ->and(User::query()->where('email', 'managed@family.test')->firstOrFail()->mustChangePassword())->toBeTrue();

    User::query()->get()->each(function (User $user): void {
        expect(Hash::check('password', $user->password))->toBeTrue();
    });

    $statuses = Contribution::query()->with('payments.batch.reversal')->get()
        ->map(fn (Contribution $contribution): PaymentStatus => $contribution->status)
        ->unique()
        ->values()
        ->all();

    expect($statuses)->toContain(PaymentStatus::Paid, PaymentStatus::Partial, PaymentStatus::Unpaid, PaymentStatus::Overdue)
        ->and(PaymentBatch::query()->pluck('method')->all())->toContain(...PaymentMethod::cases())
        ->and(PaymentBatch::query()->pluck('source')->all())->toContain(...PaymentSource::cases())
        ->and(PaystackTransaction::query()->pluck('status')->all())->toContain(...TransactionStatus::cases())
        ->and(PaystackTransaction::query()->pluck('type')->all())->toContain(...TransactionType::cases())
        ->and(ReportArtifact::query()->pluck('type')->all())->toContain(...ReportType::cases())
        ->and(ReportArtifact::query()->pluck('format')->all())->toContain(...ReportFormat::cases())
        ->and(ReportSchedule::query()->pluck('frequency')->all())->toContain(...ReportScheduleFrequency::cases())
        ->and(ReportDelivery::query()->pluck('status')->all())->toContain(...ReportDeliveryStatus::cases())
        ->and(FamilyInvitation::query()->pluck('delivery_method')->all())->toContain(...InvitationDeliveryMethod::cases());

    PaymentBatch::query()->with('allocations')->get()->each(function (PaymentBatch $batch): void {
        expect($batch->allocations->sum('amount'))->toBe($batch->total_amount);
    });
    $receiptNumbers = PaymentBatch::query()
        ->where('family_id', $demoFamily->id)
        ->orderBy('receipt_number')
        ->get(['receipt_number'])
        ->map(fn (PaymentBatch $batch): int => $batch->receipt_number)
        ->all();

    expect($receiptNumbers)->toBe(range(1, count($receiptNumbers)))
        ->and(FinancialReversal::query()->whereNotNull('replacement_id')->count())->toBe(3)
        ->and(FinancialReversal::query()->whereNull('replacement_id')->count())->toBe(3);

    ReportArtifact::query()->get()->each(function (ReportArtifact $artifact): void {
        Storage::disk($artifact->disk)->assertExists($artifact->path);
        expect(Storage::disk($artifact->disk)->size($artifact->path))->toBe($artifact->size);
    });

    $archivedFamily = Family::query()->where('slug', 'archived-family')->firstOrFail();
    $legalHoldFamily = Family::query()->where('slug', 'legal-hold-family')->firstOrFail();
    $archivedMember = User::query()->where('email', 'archived.member@family.test')->firstOrFail();

    expect($archivedFamily->isArchived())->toBeTrue()
        ->and($archivedFamily->purge_after?->isFuture())->toBeTrue()
        ->and($legalHoldFamily->isArchived())->toBeTrue()
        ->and($legalHoldFamily->isOnLegalHold())->toBeTrue()
        ->and($legalHoldFamily->purge_after?->isPast())->toBeTrue()
        ->and(FamilyMembership::query()->where('family_id', $demoFamily->id)->where('user_id', $archivedMember->id)->firstOrFail()->isArchived())->toBeTrue()
        ->and(ReportSchedule::query()->where('family_id', $archivedFamily->id)->where('is_active', true)->exists())->toBeFalse();

    Mail::assertNothingSent();
    Mail::assertNothingQueued();
});

it('is rerunnable without duplicating canonical demo data or private artifacts', function () {
    $this->artisan('db:seed', ['--no-interaction' => true])->assertSuccessful();

    $counts = [
        'users' => User::query()->count(),
        'families' => Family::query()->count(),
        'contributions' => Contribution::query()->count(),
        'payment_batches' => PaymentBatch::query()->count(),
        'financial_reversals' => FinancialReversal::query()->count(),
        'report_artifacts' => ReportArtifact::query()->count(),
        'audit_events' => AuditEvent::query()->count(),
    ];

    $this->artisan('db:seed', ['--no-interaction' => true])->assertSuccessful();

    expect(User::query()->count())->toBe($counts['users'])
        ->and(Family::query()->count())->toBe($counts['families'])
        ->and(Contribution::query()->count())->toBe($counts['contributions'])
        ->and(PaymentBatch::query()->count())->toBe($counts['payment_batches'])
        ->and(FinancialReversal::query()->count())->toBe($counts['financial_reversals'])
        ->and(ReportArtifact::query()->count())->toBe($counts['report_artifacts'])
        ->and(AuditEvent::query()->count())->toBe($counts['audit_events']);
});

it('isolates explicit testing Artisan commands from the development PostgreSQL database', function () {
    $testingEnvironment = parse_ini_file(base_path('.env.testing'));

    expect($testingEnvironment)->toBeArray()
        ->and($testingEnvironment['APP_ENV'] ?? null)->toBe('testing')
        ->and($testingEnvironment['DB_CONNECTION'] ?? null)->toBe('sqlite')
        ->and($testingEnvironment['DB_DATABASE'] ?? null)->toBe(':memory:');
});

it('allows development demo data to be seeded in staging', function () {
    $application = app();
    $originalEnvironment = $application->environment();
    $application['env'] = 'staging';

    try {
        $this->artisan('db:seed', ['--no-interaction' => true])
            ->assertSuccessful();

        expect(Family::query()->where('slug', 'demo-family')->exists())->toBeTrue()
            ->and(User::query()->where('email', 'admin@family.test')->exists())->toBeTrue();
    } finally {
        $application['env'] = $originalEnvironment;
    }
});

it('refuses to seed development demo data in production', function () {
    $application = app();
    $originalEnvironment = $application->environment();
    $application['env'] = 'production';

    try {
        expect(fn () => $application->make(DevelopmentSeeder::class)->run())
            ->toThrow(
                RuntimeException::class,
                'Development demo data may only be seeded in local, testing, or staging environments.',
            );

        expect(Family::query()->where('slug', 'demo-family')->exists())->toBeFalse();
    } finally {
        $application['env'] = $originalEnvironment;
    }
});
