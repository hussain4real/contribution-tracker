<?php

declare(strict_types=1);

use App\Enums\ReportDeliveryStatus;
use App\Enums\ReportFormat;
use App\Enums\ReportScheduleFrequency;
use App\Enums\ReportType;
use App\Enums\Role;
use App\Jobs\GenerateScheduledReport;
use App\Mail\ScheduledReportMail;
use App\Models\Contribution;
use App\Models\Family;
use App\Models\ReportArtifact;
use App\Models\ReportDelivery;
use App\Models\ReportSchedule;
use App\Models\User;
use App\Policies\ReportSchedulePolicy;
use App\Services\ReportArtifactService;
use App\Services\WhatsAppService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/** @return array{Family, User} */
function phaseThreeScheduleFixture(): array
{
    $family = Family::factory()->create();
    $admin = User::factory()->admin()->create(['family_id' => $family->id]);
    $member = User::factory()->member()->create(['family_id' => $family->id]);
    Contribution::factory()->forUser($member)->forMonth(2026, 6)->create([
        'family_id' => $family->id,
        'expected_amount' => 4000,
        'due_date' => '2026-06-28',
    ]);

    return [$family, $admin];
}

it('creates validated schedules and dispatches one unique job per due period', function () {
    Bus::fake();
    [$family, $admin] = phaseThreeScheduleFixture();

    $this->actingAs($admin)->post(route('reports.schedules.store', ['current_family' => $family->slug]), [
        'name' => 'Monthly register',
        'report_type' => ReportType::ContributionRegister->value,
        'format' => ReportFormat::Csv->value,
        'filters' => ['date_from' => '2026-06-01', 'date_to' => '2026-06-30'],
        'channels' => ['email'],
        'recipients' => ['treasurer@example.test'],
        'frequency' => ReportScheduleFrequency::Monthly->value,
        'timezone' => 'Asia/Qatar',
        'next_run_at' => now('Asia/Qatar')->addMinute()->toDateTimeString(),
    ])->assertRedirect();

    $schedule = ReportSchedule::query()->firstOrFail();
    $schedule->forceFill(['next_run_at' => now()->subMinute()])->save();
    $this->artisan('reports:dispatch-scheduled')->assertSuccessful();

    Bus::assertDispatchedTimes(GenerateScheduledReport::class, 1);
    Bus::assertDispatched(GenerateScheduledReport::class, function (GenerateScheduledReport $job) use ($schedule): bool {
        return $job->scheduleId === $schedule->id
            && $job->uniqueId() === "{$schedule->id}:{$job->periodStart}:{$job->periodEnd}"
            && $job->backoff() === [60, 300, 900];
    });
});

it('generates one private artifact and records idempotent email delivery history', function () {
    Storage::fake('local');
    Mail::fake();
    [$family, $admin] = phaseThreeScheduleFixture();
    $schedule = ReportSchedule::factory()->create([
        'family_id' => $family->id,
        'created_by' => $admin->id,
        'report_type' => ReportType::ContributionRegister,
        'format' => ReportFormat::Csv,
        'filters' => ['date_from' => '2026-06-01', 'date_to' => '2026-06-30'],
        'channels' => ['email'],
        'recipients' => ['treasurer@example.test'],
        'frequency' => ReportScheduleFrequency::Monthly,
        'next_run_at' => now(),
    ]);
    $job = new GenerateScheduledReport($schedule->id, '2026-06-01', '2026-06-30');

    $job->handle(app(ReportArtifactService::class), app(WhatsAppService::class));
    $job->handle(app(ReportArtifactService::class), app(WhatsAppService::class));

    $delivery = ReportDelivery::query()->firstOrFail();
    $artifact = ReportArtifact::query()->findOrFail($delivery->report_artifact_id);
    expect($delivery->status)->toBe(ReportDeliveryStatus::Sent)
        ->and($delivery->period_start->toDateString())->toBe('2026-06-01')
        ->and($delivery->period_end->toDateString())->toBe('2026-06-30')
        ->and(ReportDelivery::query()->count())->toBe(1)
        ->and($artifact->filters)->toMatchArray(['date_from' => '2026-06-01', 'date_to' => '2026-06-30']);

    Mail::assertSentTimes(ScheduledReportMail::class, 1);
    Mail::assertSent(ScheduledReportMail::class, fn (ScheduledReportMail $mail): bool => str_contains($mail->downloadUrl, 'signature='));
    Storage::disk('local')->assertExists($artifact->path);

    $mail = new ScheduledReportMail($artifact, 'https://example.test/signed-report');
    expect($mail->envelope()->subject)->toContain($artifact->type->label(), $family->name)
        ->and($mail->content()->markdown)->toBe('mail.reports.scheduled')
        ->and($mail->attachments())->toHaveCount(1);
});

it('validates channels recipients and schedule ownership', function () {
    [$family, $admin] = phaseThreeScheduleFixture();
    $member = User::factory()->member()->create(['family_id' => $family->id]);

    $this->actingAs($admin)->post(route('reports.schedules.store', ['current_family' => $family->slug]), [
        'name' => '',
        'report_type' => 'unknown',
        'format' => 'docx',
        'filters' => [],
        'channels' => [],
        'recipients' => [],
        'frequency' => 'hourly',
        'timezone' => 'not-a-timezone',
        'next_run_at' => now()->subDay(),
    ])->assertSessionHasErrors(['name', 'report_type', 'format', 'filters.date_from', 'channels', 'recipients', 'frequency', 'timezone']);

    $this->actingAs($member)->post(route('reports.schedules.store', ['current_family' => $family->slug]), [])->assertForbidden();

    $schedule = ReportSchedule::factory()->create(['family_id' => $family->id, 'created_by' => $admin->id]);
    $policy = app(ReportSchedulePolicy::class);
    expect($policy->viewAny($admin))->toBeTrue()
        ->and($policy->create($admin))->toBeTrue()
        ->and($policy->delete($admin, $schedule))->toBeTrue()
        ->and($policy->viewAny($member))->toBeFalse()
        ->and($policy->create($member))->toBeFalse()
        ->and($policy->delete($member, $schedule))->toBeFalse();

    $this->actingAs($admin)
        ->delete(route('reports.schedules.destroy', ['current_family' => $family->slug, 'reportSchedule' => $schedule]))
        ->assertRedirect();
    expect(ReportSchedule::query()->find($schedule->id))->toBeNull();
});

it('requires a same-family member for scheduled member statements', function () {
    Date::setTestNow('2026-07-31 00:00:00 UTC');
    [$family, $admin] = phaseThreeScheduleFixture();
    $member = User::factory()->member()->create(['family_id' => $family->id]);
    $foreignMember = User::factory()->member()->create();
    $payload = [
        'name' => 'Member statement',
        'report_type' => ReportType::MemberStatement->value,
        'format' => ReportFormat::Pdf->value,
        'filters' => ['date_from' => '2026-06-01', 'date_to' => '2026-06-30'],
        'channels' => ['email'],
        'recipients' => ['member@example.test'],
        'frequency' => ReportScheduleFrequency::Monthly->value,
        'timezone' => 'Asia/Qatar',
        'next_run_at' => '2026-08-01T09:00',
    ];
    $route = route('reports.schedules.store', ['current_family' => $family->slug]);

    $this->actingAs($admin)->post($route, [...$payload, 'next_run_at' => '2026-07-30T09:00'])
        ->assertSessionHasErrors(['next_run_at']);
    $this->actingAs($admin)->post($route, $payload)
        ->assertSessionHasErrors(['filters.member_id']);
    $this->post($route, [...$payload, 'filters' => [...$payload['filters'], 'member_id' => $foreignMember->id]])
        ->assertSessionHasErrors(['filters.member_id']);
    $this->post($route, [...$payload, 'filters' => [...$payload['filters'], 'member_id' => $member->id]])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $schedule = ReportSchedule::query()->firstOrFail();
    expect($schedule->filters['member_id'])->toBe($member->id)
        ->and($schedule->timezone)->toBe('Asia/Qatar')
        ->and($schedule->next_run_at->utc()->format('Y-m-d H:i:s'))->toBe('2026-08-01 06:00:00');

    Date::setTestNow();
});

it('uses the schedule family role when deleting across memberships', function () {
    $currentFamily = Family::factory()->create();
    $scheduleFamily = Family::factory()->create();
    $currentOfficer = User::factory()->financialSecretary()->create(['family_id' => $currentFamily->id]);
    $currentOfficer->ensureFamilyMembership($scheduleFamily, Role::Member);
    $scheduleFamilyOfficer = User::factory()->member()->create(['family_id' => $currentFamily->id]);
    $scheduleFamilyOfficer->ensureFamilyMembership($scheduleFamily, Role::FinancialSecretary);
    $schedule = ReportSchedule::factory()->create(['family_id' => $scheduleFamily->id]);
    $policy = app(ReportSchedulePolicy::class);

    expect($policy->delete($currentOfficer, $schedule))->toBeFalse()
        ->and($policy->delete($scheduleFamilyOfficer, $schedule))->toBeTrue();

    $this->actingAs($currentOfficer)
        ->delete(route('reports.schedules.destroy', [
            'current_family' => $currentFamily->slug,
            'reportSchedule' => $schedule,
        ]))
        ->assertForbidden();

    expect($schedule->fresh())->not->toBeNull();
});

it('skips unavailable and completed jobs and records whatsapp success and failure', function () {
    Storage::fake('local');
    Mail::fake();
    [$family, $admin] = phaseThreeScheduleFixture();

    (new GenerateScheduledReport(999999, '2026-06-01', '2026-06-30'))
        ->handle(app(ReportArtifactService::class), app(WhatsAppService::class));

    $completedSchedule = ReportSchedule::factory()->create([
        'family_id' => $family->id,
        'created_by' => $admin->id,
        'channels' => ['email'],
        'recipients' => ['completed@example.test'],
    ]);
    $completedArtifact = ReportArtifact::factory()->create([
        'family_id' => $family->id,
        'requested_by' => $admin->id,
    ]);
    $completedDelivery = ReportDelivery::factory()->create([
        'report_schedule_id' => $completedSchedule->id,
        'report_artifact_id' => $completedArtifact->id,
        'period_start' => '2026-06-01',
        'period_end' => '2026-06-30',
        'channel' => 'email',
        'recipient' => 'completed@example.test',
        'status' => ReportDeliveryStatus::Sent,
    ]);
    expect($completedSchedule->channels)->toBe(['email'])
        ->and($completedSchedule->recipients)->toBe(['completed@example.test'])
        ->and($completedDelivery->period_start->toDateString())->toBe('2026-06-01')
        ->and($completedDelivery->period_end->toDateString())->toBe('2026-06-30')
        ->and($completedDelivery->status)->toBe(ReportDeliveryStatus::Sent)
        ->and(ReportDelivery::query()
            ->where('report_schedule_id', $completedSchedule->id)
            ->whereDate('period_start', '2026-06-01')
            ->whereDate('period_end', '2026-06-30')
            ->count())->toBe(1);
    (new GenerateScheduledReport($completedSchedule->id, '2026-06-01', '2026-06-30'))
        ->handle(app(ReportArtifactService::class), app(WhatsAppService::class));
    expect($completedSchedule->refresh()->last_run_at)->toBeNull();

    $whatsApp = typedMock(WhatsAppService::class);
    $whatsApp->shouldReceive('sendText')
        ->andReturn(
            ['success' => true, 'wa_message_id' => 'wamid.phase3', 'error' => null],
            ['success' => false, 'wa_message_id' => null, 'error' => null],
        );
    $whatsAppSchedule = ReportSchedule::factory()->create([
        'family_id' => $family->id,
        'created_by' => $admin->id,
        'report_type' => ReportType::ContributionRegister,
        'format' => ReportFormat::Csv,
        'channels' => ['whatsapp'],
        'recipients' => ['97450000001', '97450000002'],
        'next_run_at' => now(),
    ]);

    (new GenerateScheduledReport($whatsAppSchedule->id, '2026-06-01', '2026-06-30'))
        ->handle(app(ReportArtifactService::class), $whatsApp);

    $sentDelivery = $whatsAppSchedule->deliveries()->where('recipient', '97450000001')->firstOrFail();
    $failedDelivery = $whatsAppSchedule->deliveries()->where('recipient', '97450000002')->firstOrFail();
    expect($whatsAppSchedule->deliveries()->count())->toBe(2)
        ->and($sentDelivery->status)->toBe(ReportDeliveryStatus::Sent)
        ->and($failedDelivery->status)->toBe(ReportDeliveryStatus::Failed)
        ->and($failedDelivery->error)->toBe('WhatsApp delivery failed.');
});
