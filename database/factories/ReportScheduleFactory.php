<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ReportFormat;
use App\Enums\ReportScheduleFrequency;
use App\Enums\ReportType;
use App\Models\Family;
use App\Models\ReportSchedule;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ReportSchedule> */
class ReportScheduleFactory extends Factory
{
    /** @return array<model-property<ReportSchedule>, mixed> */
    public function definition(): array
    {
        return [
            'family_id' => Family::factory(),
            'created_by' => null,
            'name' => fake()->words(3, true),
            'report_type' => ReportType::ContributionRegister,
            'format' => ReportFormat::Pdf,
            'filters' => ['date_from' => now()->startOfMonth()->toDateString(), 'date_to' => now()->endOfMonth()->toDateString()],
            'channels' => ['email'],
            'recipients' => [fake()->safeEmail()],
            'frequency' => ReportScheduleFrequency::Monthly,
            'timezone' => 'Asia/Qatar',
            'next_run_at' => now()->addHour(),
            'last_run_at' => null,
            'is_active' => true,
        ];
    }
}
