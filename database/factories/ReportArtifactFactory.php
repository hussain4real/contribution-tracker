<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ReportFormat;
use App\Enums\ReportType;
use App\Models\Family;
use App\Models\ReportArtifact;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ReportArtifact> */
class ReportArtifactFactory extends Factory
{
    /** @return array<model-property<ReportArtifact>, mixed> */
    public function definition(): array
    {
        $uuid = (string) Str::uuid();

        return [
            'uuid' => $uuid,
            'family_id' => Family::factory(),
            'requested_by' => null,
            'type' => ReportType::ContributionRegister,
            'format' => ReportFormat::Pdf,
            'filters' => ['date_from' => now()->startOfYear()->toDateString(), 'date_to' => now()->endOfYear()->toDateString()],
            'filter_hash' => hash('sha256', $uuid),
            'disk' => 'local',
            'path' => "reports/factory/{$uuid}.pdf",
            'filename' => 'report.pdf',
            'mime_type' => 'application/pdf',
            'size' => 100,
            'expires_at' => now()->addDays(30),
        ];
    }
}
