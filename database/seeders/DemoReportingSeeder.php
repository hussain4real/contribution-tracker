<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ReportDeliveryStatus;
use App\Enums\ReportFormat;
use App\Enums\ReportScheduleFrequency;
use App\Enums\ReportType;
use App\Models\Family;
use App\Models\PaymentBatch;
use App\Models\ReportArtifact;
use App\Models\ReportDelivery;
use App\Models\ReportSchedule;
use App\Models\User;
use App\Services\ReportArtifactService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

class DemoReportingSeeder extends Seeder
{
    public function __construct(private readonly ReportArtifactService $artifactService) {}

    public function run(): void
    {
        $family = Family::query()->where('slug', 'demo-family')->firstOrFail();
        $archivedFamily = Family::query()->where('slug', 'archived-family')->firstOrFail();
        $admin = User::query()->where('email', 'admin@family.test')->firstOrFail();
        $member = User::query()->where('email', 'member@family.test')->firstOrFail();
        $periodStart = CarbonImmutable::now()->startOfYear();
        $periodEnd = CarbonImmutable::now()->endOfYear();
        $formats = [
            ReportType::ContributionRegister->value => ReportFormat::Pdf,
            ReportType::ContributionAging->value => ReportFormat::Csv,
            ReportType::MemberStatement->value => ReportFormat::Pdf,
            ReportType::CategoryPerformance->value => ReportFormat::Csv,
            ReportType::FundStatement->value => ReportFormat::Pdf,
            ReportType::CashFlow->value => ReportFormat::Csv,
            ReportType::ExpenseTotals->value => ReportFormat::Pdf,
            ReportType::Reversals->value => ReportFormat::Csv,
            ReportType::AuditActivity->value => ReportFormat::Pdf,
        ];
        $artifacts = [];

        foreach ($formats as $typeValue => $format) {
            $type = ReportType::from($typeValue);
            $filters = [
                'date_from' => $periodStart->toDateString(),
                'date_to' => $periodEnd->toDateString(),
            ];

            if ($type === ReportType::MemberStatement) {
                $filters['member_id'] = $member->id;
            }

            $artifacts[$type->value] = $this->reportArtifact($family, $admin, $type, $format, $filters);
        }

        $receiptBatch = PaymentBatch::query()
            ->where('family_id', $family->id)
            ->effective()
            ->oldest('receipt_number')
            ->firstOrFail();
        $receipt = ReportArtifact::query()
            ->where('family_id', $family->id)
            ->where('filename', 'demo-payment-receipt.pdf')
            ->first();

        if (! $receipt instanceof ReportArtifact) {
            $receipt = $this->artifactService->receipt($family, $receiptBatch, $admin);
            $receipt->forceFill(['filename' => 'demo-payment-receipt.pdf'])->save();
        }

        $monthlySchedule = ReportSchedule::query()->updateOrCreate(
            ['family_id' => $family->id, 'name' => 'Monthly finance pack'],
            [
                'created_by' => $admin->id,
                'report_type' => ReportType::FundStatement,
                'format' => ReportFormat::Pdf,
                'filters' => ['date_from' => $periodStart->toDateString(), 'date_to' => $periodEnd->toDateString()],
                'channels' => ['email', 'whatsapp'],
                'recipients' => ['finance@family.test', '+97455000001'],
                'frequency' => ReportScheduleFrequency::Monthly,
                'timezone' => 'Asia/Qatar',
                'next_run_at' => CarbonImmutable::now()->addMonth()->startOfMonth()->setTime(8, 0),
                'last_run_at' => CarbonImmutable::now()->startOfMonth()->setTime(8, 0),
                'is_active' => true,
            ],
        );
        $weeklySchedule = ReportSchedule::query()->updateOrCreate(
            ['family_id' => $family->id, 'name' => 'Weekly contribution aging'],
            [
                'created_by' => $admin->id,
                'report_type' => ReportType::ContributionAging,
                'format' => ReportFormat::Csv,
                'filters' => [],
                'channels' => ['email'],
                'recipients' => ['admin@family.test'],
                'frequency' => ReportScheduleFrequency::Weekly,
                'timezone' => 'Asia/Qatar',
                'next_run_at' => CarbonImmutable::now()->next('Monday')->setTime(8, 0),
                'last_run_at' => CarbonImmutable::now()->previous('Monday')->setTime(8, 0),
                'is_active' => true,
            ],
        );
        $dailySchedule = ReportSchedule::query()->updateOrCreate(
            ['family_id' => $family->id, 'name' => 'Daily cash flow'],
            [
                'created_by' => $admin->id,
                'report_type' => ReportType::CashFlow,
                'format' => ReportFormat::Pdf,
                'filters' => [],
                'channels' => ['email'],
                'recipients' => ['finance@family.test'],
                'frequency' => ReportScheduleFrequency::Daily,
                'timezone' => 'Asia/Qatar',
                'next_run_at' => CarbonImmutable::now()->addDay()->setTime(7, 30),
                'last_run_at' => CarbonImmutable::now()->subDay()->setTime(7, 30),
                'is_active' => true,
            ],
        );
        ReportSchedule::query()->updateOrCreate(
            ['family_id' => $family->id, 'name' => 'Paused audit digest'],
            [
                'created_by' => $admin->id,
                'report_type' => ReportType::AuditActivity,
                'format' => ReportFormat::Pdf,
                'filters' => [],
                'channels' => ['email'],
                'recipients' => ['admin@family.test'],
                'frequency' => ReportScheduleFrequency::Weekly,
                'timezone' => 'Asia/Qatar',
                'next_run_at' => CarbonImmutable::now()->addWeek(),
                'last_run_at' => null,
                'is_active' => false,
            ],
        );
        ReportSchedule::query()->updateOrCreate(
            ['family_id' => $archivedFamily->id, 'name' => 'Archived family schedule'],
            [
                'created_by' => $archivedFamily->created_by,
                'report_type' => ReportType::ContributionRegister,
                'format' => ReportFormat::Pdf,
                'filters' => [],
                'channels' => ['email'],
                'recipients' => ['archived.admin@family.test'],
                'frequency' => ReportScheduleFrequency::Monthly,
                'timezone' => 'Asia/Qatar',
                'next_run_at' => CarbonImmutable::now()->addMonth(),
                'last_run_at' => CarbonImmutable::now()->subMonth(),
                'is_active' => false,
            ],
        );

        $deliveryDefinitions = [
            ['00000000-0000-4000-8000-000000000101', $monthlySchedule, 'email', 'finance@family.test', ReportDeliveryStatus::Sent, $artifacts[ReportType::FundStatement->value], null],
            ['00000000-0000-4000-8000-000000000102', $monthlySchedule, 'whatsapp', '+97455000001', ReportDeliveryStatus::Failed, $artifacts[ReportType::FundStatement->value], 'Demo WhatsApp delivery failure.'],
            ['00000000-0000-4000-8000-000000000103', $weeklySchedule, 'email', 'admin@family.test', ReportDeliveryStatus::Pending, null, null],
            ['00000000-0000-4000-8000-000000000104', $dailySchedule, 'email', 'finance@family.test', ReportDeliveryStatus::Processing, $artifacts[ReportType::CashFlow->value], null],
        ];

        foreach ($deliveryDefinitions as [$uuid, $schedule, $channel, $recipient, $status, $artifact, $error]) {
            ReportDelivery::query()->updateOrCreate(
                ['idempotency_key' => hash('sha256', $uuid)],
                [
                    'uuid' => $uuid,
                    'report_schedule_id' => $schedule->id,
                    'report_artifact_id' => $artifact?->id,
                    'period_start' => CarbonImmutable::now()->subMonth()->startOfMonth(),
                    'period_end' => CarbonImmutable::now()->subMonth()->endOfMonth(),
                    'channel' => $channel,
                    'recipient' => $recipient,
                    'status' => $status,
                    'attempted_at' => $status === ReportDeliveryStatus::Pending ? null : now()->subHour(),
                    'sent_at' => $status === ReportDeliveryStatus::Sent ? now()->subHour() : null,
                    'failed_at' => $status === ReportDeliveryStatus::Failed ? now()->subHour() : null,
                    'error' => $error,
                ],
            );
        }

        $receipt->refresh();
    }

    /** @param array<string, mixed> $filters */
    private function reportArtifact(
        Family $family,
        User $requester,
        ReportType $type,
        ReportFormat $format,
        array $filters,
    ): ReportArtifact {
        $filename = "demo-{$type->value}.{$format->value}";
        $artifact = ReportArtifact::query()
            ->where('family_id', $family->id)
            ->where('filename', $filename)
            ->first();

        if ($artifact instanceof ReportArtifact) {
            return $artifact;
        }

        $artifact = $this->artifactService->generate($family, $type, $format, $filters, $requester);
        $artifact->forceFill(['filename' => $filename])->save();

        return $artifact;
    }
}
