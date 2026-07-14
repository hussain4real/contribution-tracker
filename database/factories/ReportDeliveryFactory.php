<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ReportDeliveryStatus;
use App\Models\ReportDelivery;
use App\Models\ReportSchedule;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ReportDelivery> */
class ReportDeliveryFactory extends Factory
{
    /** @return array<model-property<ReportDelivery>, mixed> */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'report_schedule_id' => ReportSchedule::factory(),
            'report_artifact_id' => null,
            'period_start' => now()->startOfMonth(),
            'period_end' => now()->endOfMonth(),
            'channel' => 'email',
            'recipient' => fake()->safeEmail(),
            'status' => ReportDeliveryStatus::Pending,
            'idempotency_key' => hash('sha256', (string) Str::uuid()),
            'attempted_at' => null,
            'sent_at' => null,
            'failed_at' => null,
            'error' => null,
        ];
    }
}
