<?php

declare(strict_types=1);

use App\Enums\ReportFormat;
use App\Enums\ReportType;
use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\Contribution;
use App\Models\Expense;
use App\Models\Family;
use App\Models\FamilyCategory;
use App\Models\FinancialReversal;
use App\Models\FundAdjustment;
use App\Models\Payment;
use App\Models\ReportArtifact;
use App\Models\ReportDelivery;
use App\Models\ReportSchedule;
use App\Models\User;
use App\Policies\ReportArtifactPolicy;
use App\Services\FamilyContributionReviewService;
use App\Services\PaymentAllocationService;
use App\Services\ReportArtifactService;
use App\Support\CsvCellSanitizer;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/** @return array{Family, User, User, FamilyCategory, Contribution} */
function phaseThreeReportingFixture(string $memberName = 'Report Member'): array
{
    $family = Family::factory()->create(['name' => 'Reporting Family', 'currency' => 'NGN']);
    $category = FamilyCategory::factory()->create([
        'family_id' => $family->id,
        'name' => 'Core Members',
        'slug' => 'core-members',
        'monthly_amount' => 5000,
    ]);
    $admin = User::factory()->admin()->create(['family_id' => $family->id]);
    $member = User::factory()->member()->create([
        'family_id' => $family->id,
        'family_category_id' => $category->id,
        'name' => $memberName,
    ]);
    $contribution = Contribution::factory()->forUser($member)->forMonth(2026, 1)->create([
        'family_id' => $family->id,
        'family_category_id' => $category->id,
        'category_name' => $category->name,
        'category_slug' => $category->slug,
        'expected_amount' => 5000,
        'due_date' => '2026-01-28',
    ]);
    Payment::factory()->forContribution($contribution)->create(['amount' => 2000, 'paid_at' => '2026-01-20']);

    return [$family, $admin, $member, $category, $contribution];
}

it('keeps displayed csv pdf and scheduled datasets on identical shared totals', function () {
    Storage::fake('local');
    [$family, $admin] = phaseThreeReportingFixture();
    $filters = ['date_from' => '2026-01-01', 'date_to' => '2026-12-31'];
    $review = app(FamilyContributionReviewService::class);
    $artifacts = app(ReportArtifactService::class);
    $summary = $review->registerSummary($family, $filters);
    $report = $review->report($family, ReportType::ContributionRegister, $filters);
    $csv = $artifacts->generate($family, ReportType::ContributionRegister, ReportFormat::Csv, $filters, $admin);
    $pdf = $artifacts->generate($family, ReportType::ContributionRegister, ReportFormat::Pdf, $filters, $admin);

    expect($report['totals'])->toMatchArray([
        'expected' => $summary['total_expected'],
        'collected' => $summary['total_collected'],
        'outstanding' => $summary['total_outstanding'],
    ])->and($csv->filters)->toBe($pdf->filters)
        ->and($csv->filter_hash)->toBe($pdf->filter_hash)
        ->and(Storage::disk('local')->get($csv->path))->toContain('5000', '2000', '3000')
        ->and(Storage::disk('local')->get($pdf->path))->toStartWith('%PDF-');

    Storage::disk('local')->assertExists($csv->path);
    Storage::disk('local')->assertExists($pdf->path);
    expect($csv->path)->toStartWith("reports/{$family->id}/")
        ->and($pdf->mime_type)->toBe('application/pdf');
});

it('protects streaming csv output against spreadsheet formulas', function () {
    Storage::fake('local');
    [$family, $admin] = phaseThreeReportingFixture('=HYPERLINK("https://example.test")');
    $artifact = app(ReportArtifactService::class)->generate(
        $family,
        ReportType::ContributionRegister,
        ReportFormat::Csv,
        ['date_from' => '2026-01-01', 'date_to' => '2026-12-31'],
        $admin,
    );

    expect(Storage::disk('local')->get($artifact->path))->toContain("'=HYPERLINK");

    $response = $this->actingAs($admin)
        ->get(route('reports.export', [
            'current_family' => $family->slug,
            'type' => ReportType::ContributionRegister->value,
            'format' => ReportFormat::Csv->value,
            'date_from' => '2026-01-01',
            'date_to' => '2026-12-31',
        ]));
    $response->assertOk()
        ->assertHeader('content-type', 'text/csv; charset=UTF-8');
    expect($response->streamedContent())->toContain('5000', '2000', '3000');
});

it('builds all roadmap report types including a reconciled fund statement', function () {
    [$family, $admin] = phaseThreeReportingFixture();
    Expense::factory()->recordedBy($admin)->create(['family_id' => $family->id, 'amount' => 400, 'description' => 'Venue', 'spent_at' => '2026-01-22']);
    FundAdjustment::factory()->recordedBy($admin)->create(['family_id' => $family->id, 'amount' => 100, 'description' => 'Opening correction', 'recorded_at' => '2026-01-21']);
    $service = app(FamilyContributionReviewService::class);
    $filters = ['date_from' => '2026-01-01', 'date_to' => '2026-12-31'];

    foreach (ReportType::cases() as $type) {
        if ($type === ReportType::Receipt) {
            continue;
        }

        expect($service->report($family, $type, $filters)['title'])->toBe($type->label());
    }

    expect($service->report($family, ReportType::Receipt, $filters)['title'])
        ->toBe(ReportType::MemberStatement->label());

    $statement = $service->report($family, ReportType::FundStatement, $filters);
    expect($statement['totals'])->toMatchArray([
        'opening_balance' => 0,
        'posted_payments' => 2000,
        'adjustments' => 100,
        'expenses' => 400,
        'closing_balance' => 1700,
        'reconciliation_variance' => 0,
    ]);
});

it('generates immutable private receipts only for the member and family officers', function () {
    Storage::fake('local');
    [$family, $admin, $member] = phaseThreeReportingFixture();
    $otherMember = User::factory()->member()->create(['family_id' => $family->id]);
    $batch = app(PaymentAllocationService::class)->createBatch(
        $member,
        1000,
        now(),
        $admin,
        family: $family,
        idempotencyKey: 'phase-three-receipt',
    );

    $memberResponse = $this->actingAs($member)
        ->get(route('payment-batches.receipt', ['current_family' => $family->slug, 'payment_batch' => $batch]))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
    expect($memberResponse->streamedContent())->toStartWith('%PDF-');

    $this->actingAs($otherMember)
        ->get(route('payment-batches.receipt', ['current_family' => $family->slug, 'payment_batch' => $batch]))
        ->assertForbidden();

    $this->actingAs($admin)
        ->get(route('payment-batches.receipt', ['current_family' => $family->slug, 'payment_batch' => $batch]))
        ->assertOk();

    $artifact = ReportArtifact::query()->where('type', ReportType::Receipt)->firstOrFail();
    expect(Storage::disk('local')->get($artifact->path))->toStartWith('%PDF-')
        ->and($artifact->filters['member_id'])->toBe($member->id)
        ->and($batch->refresh()->exists)->toBeTrue();
});

it('allows members to download only their own statement', function () {
    Storage::fake('local');
    [$family, $admin, $member] = phaseThreeReportingFixture();
    $otherMember = User::factory()->member()->create(['family_id' => $family->id]);
    $parameters = [
        'current_family' => $family->slug,
        'type' => ReportType::MemberStatement->value,
        'format' => ReportFormat::Pdf->value,
        'date_from' => '2026-01-01',
        'date_to' => '2026-12-31',
    ];

    $this->actingAs($member)
        ->get(route('contributions.my-statement', [...$parameters, 'member_id' => $member->id]))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    $this->get(route('contributions.my-statement', [...$parameters, 'member_id' => $otherMember->id]))
        ->assertForbidden();

    $adminResponse = $this->actingAs($admin)
        ->get(route('reports.export', [...$parameters, 'member_id' => $member->id]))
        ->assertOk();
    expect($adminResponse->streamedContent())->toStartWith('%PDF-');

    $artifact = ReportArtifact::query()
        ->where('type', ReportType::MemberStatement)
        ->where('requested_by', $member->id)
        ->firstOrFail();

    expect($artifact->filters)->toMatchArray(['member_id' => (string) $member->id])
        ->and(Storage::disk('local')->get($artifact->path))->toStartWith('%PDF-');
});

it('allows authenticated artifacts or seven-day recipient links and rejects expired signatures', function () {
    Storage::fake('local');
    [$family, $admin] = phaseThreeReportingFixture();
    $artifact = app(ReportArtifactService::class)->generate(
        $family,
        ReportType::ContributionRegister,
        ReportFormat::Csv,
        ['date_from' => '2026-01-01', 'date_to' => '2026-12-31'],
        $admin,
    );
    $schedule = ReportSchedule::factory()->create(['family_id' => $family->id, 'created_by' => $admin->id]);
    $delivery = ReportDelivery::factory()->create(['report_schedule_id' => $schedule->id, 'report_artifact_id' => $artifact->id]);

    $artifactResponse = $this->actingAs($admin)
        ->get(route('reports.artifacts.show', ['current_family' => $family->slug, 'reportArtifact' => $artifact]))
        ->assertOk();
    expect($artifactResponse->streamedContent())->toContain('5000', '2000', '3000');

    auth()->logout();
    $validUrl = URL::temporarySignedRoute('reports.deliveries.download', now()->addDays(7), ['reportDelivery' => $delivery]);
    $expiredUrl = URL::temporarySignedRoute('reports.deliveries.download', now()->subMinute(), ['reportDelivery' => $delivery]);
    $deliveryResponse = $this->get($validUrl)->assertOk();
    expect($deliveryResponse->streamedContent())->toContain('5000', '2000', '3000');
    $this->get($expiredUrl)->assertForbidden();
});

it('enforces artifact policy boundaries and exposes report relationships', function () {
    [$family, $admin, $member] = phaseThreeReportingFixture();
    $otherFamily = Family::factory()->create();
    $outsider = User::factory()->member()->create(['family_id' => $otherFamily->id]);
    $register = ReportArtifact::factory()->create([
        'family_id' => $family->id,
        'requested_by' => $admin->id,
        'type' => ReportType::ContributionRegister,
    ]);
    $statement = ReportArtifact::factory()->create([
        'family_id' => $family->id,
        'requested_by' => $member->id,
        'type' => ReportType::MemberStatement,
        'filters' => ['member_id' => (string) $member->id],
    ]);
    $schedule = ReportSchedule::factory()->create(['family_id' => $family->id, 'created_by' => $admin->id]);
    $delivery = ReportDelivery::factory()->create([
        'report_schedule_id' => $schedule->id,
        'report_artifact_id' => $register->id,
    ]);
    $policy = app(ReportArtifactPolicy::class);

    expect($policy->view($outsider, $register))->toBeFalse()
        ->and($policy->view($admin, $register))->toBeTrue()
        ->and($policy->view($member, $register))->toBeFalse()
        ->and($policy->view($member, $statement))->toBeTrue()
        ->and($family->reportArtifacts()->count())->toBe(2)
        ->and($register->family->is($family))->toBeTrue()
        ->and($register->requester?->is($admin))->toBeTrue()
        ->and($register->deliveries->first()?->is($delivery))->toBeTrue()
        ->and($delivery->schedule->is($schedule))->toBeTrue();
});

it('uses the artifact family role when a user belongs to multiple families', function () {
    $currentFamily = Family::factory()->create();
    $artifactFamily = Family::factory()->create();
    $currentOfficer = User::factory()->financialSecretary()->create(['family_id' => $currentFamily->id]);
    $currentOfficer->ensureFamilyMembership($artifactFamily, Role::Member);
    $artifactFamilyOfficer = User::factory()->member()->create(['family_id' => $currentFamily->id]);
    $artifactFamilyOfficer->ensureFamilyMembership($artifactFamily, Role::FinancialSecretary);
    $userWithoutMembership = User::factory()->member()->create(['family_id' => $currentFamily->id]);
    $userWithoutMembership->forceFill(['current_family_id' => $artifactFamily->id])->save();
    $artifact = ReportArtifact::factory()->create(['family_id' => $artifactFamily->id]);
    $policy = app(ReportArtifactPolicy::class);

    expect($policy->view($currentOfficer, $artifact))->toBeFalse()
        ->and($policy->view($userWithoutMembership, $artifact))->toBeFalse()
        ->and($policy->view($artifactFamilyOfficer, $artifact))->toBeFalse();

    $this->actingAs($currentOfficer)
        ->get(route('reports.artifacts.show', [
            'current_family' => $currentFamily->slug,
            'reportArtifact' => $artifact,
        ]))
        ->assertForbidden();

    $artifactFamilyOfficer->switchFamily($artifactFamily);

    expect($policy->view($artifactFamilyOfficer, $artifact))->toBeTrue();
});

it('includes reversal and audit events throughout the report end date', function () {
    [$family, $admin] = phaseThreeReportingFixture();
    $expense = Expense::factory()->recordedBy($admin)->create(['family_id' => $family->id]);
    FinancialReversal::factory()->create([
        'family_id' => $family->id,
        'reversible_type' => Expense::MORPH_TYPE,
        'reversible_id' => $expense->id,
        'reversed_by' => $admin->id,
        'created_at' => '2026-06-30 18:30:00',
    ]);
    AuditEvent::factory()->create([
        'family_id' => $family->id,
        'actor_id' => $admin->id,
        'auditable_type' => Expense::MORPH_TYPE,
        'auditable_id' => $expense->id,
        'created_at' => '2026-06-30 23:59:59',
    ]);
    $filters = ['date_from' => '2026-06-30', 'date_to' => '2026-06-30'];
    $service = app(FamilyContributionReviewService::class);

    expect($service->report($family, ReportType::Reversals, $filters)['rows'])->toHaveCount(1)
        ->and($service->report($family, ReportType::AuditActivity, $filters)['rows'])->toHaveCount(1);
});

it('sanitizes null boolean structured and formula csv cells', function () {
    $sanitizer = app(CsvCellSanitizer::class);

    expect($sanitizer->sanitize(null))->toBe('')
        ->and($sanitizer->sanitize(true))->toBe('Yes')
        ->and($sanitizer->sanitize(false))->toBe('No')
        ->and($sanitizer->sanitize(['status' => 'paid']))->toBe('{"status":"paid"}')
        ->and($sanitizer->sanitize('@SUM(A1:A2)'))->toBe("'@SUM(A1:A2)");
});
