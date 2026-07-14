<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ReportFormat;
use App\Enums\ReportType;
use App\Models\Family;
use App\Models\PaymentBatch;
use App\Models\ReportArtifact;
use App\Models\User;
use App\Support\CsvCellSanitizer;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\LaravelPdf\Facades\Pdf;
use SplTempFileObject;

class ReportArtifactService
{
    public function __construct(
        private readonly FamilyContributionReviewService $reviewService,
        private readonly CsvCellSanitizer $cellSanitizer,
    ) {}

    /** @param array<string, mixed> $filters */
    public function generate(
        Family $family,
        ReportType $type,
        ReportFormat $format,
        array $filters,
        ?User $requester = null,
    ): ReportArtifact {
        $filters = $this->normalizedFilters($filters);
        $report = $this->reviewService->report($family, $type, $filters);
        $contents = $format === ReportFormat::Pdf
            ? $this->renderPdf($family, $report)
            : $this->renderCsv($report);

        return $this->store($family, $type, $format, $filters, $contents, $requester);
    }

    public function receipt(Family $family, PaymentBatch $batch, ?User $requester = null): ReportArtifact
    {
        $batch->loadMissing(['membership.user', 'allocations.contribution', 'recorder']);
        $contents = Pdf::view('pdf.receipt', [
            'family' => $family,
            'batch' => $batch,
            'allocations' => $batch->allocations,
            'generatedAt' => now(),
        ])->driver('dompdf')->format('a4')->generatePdfContent();

        return $this->store(
            $family,
            ReportType::Receipt,
            ReportFormat::Pdf,
            ['payment_batch_id' => $batch->id, 'member_id' => $batch->membership?->user_id],
            $contents,
            $requester,
            sprintf('receipt-%06d.pdf', $batch->receipt_number),
        );
    }

    /**
     * @param  array{title: string, columns: array<string, string>, rows: array<int, array<string, mixed>>, totals: array<string, int|float|string>, filters: array<string, mixed>}  $report
     */
    public function renderCsv(array $report): string
    {
        $stream = new SplTempFileObject;
        $stream->setCsvControl(escape: '\\');
        $stream->fputcsv(array_values($report['columns']));

        foreach ($report['rows'] as $row) {
            $stream->fputcsv(array_map(
                fn (string $key): string => $this->cellSanitizer->sanitize($row[$key] ?? null),
                array_keys($report['columns']),
            ));
        }

        $stream->rewind();
        $contents = '';
        while (! $stream->eof()) {
            $contents .= $stream->fgets();
        }

        return "\xEF\xBB\xBF".$contents;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function normalizedFilters(array $filters): array
    {
        $normalized = [];

        foreach ($filters as $key => $value) {
            $normalized[$key] = $value;
        }

        ksort($normalized);

        return $normalized;
    }

    /**
     * @param  array{title: string, columns: array<string, string>, rows: array<int, array<string, mixed>>, totals: array<string, int|float|string>, filters: array<string, mixed>}  $report
     */
    private function renderPdf(Family $family, array $report): string
    {
        return Pdf::view('pdf.report', [
            'family' => $family,
            'report' => $report,
            'generatedAt' => now(),
        ])->driver('dompdf')->format('a4')->generatePdfContent();
    }

    /** @param array<string, mixed> $filters */
    private function store(
        Family $family,
        ReportType $type,
        ReportFormat $format,
        array $filters,
        string $contents,
        ?User $requester,
        ?string $filename = null,
    ): ReportArtifact {
        $uuid = (string) Str::uuid();
        $filename ??= Str::slug($type->label()).'-'.now()->format('Ymd-His').'.'.$format->value;
        $path = "reports/{$family->id}/{$uuid}.{$format->value}";
        $stored = Storage::disk('local')->put($path, $contents);

        throw_unless($stored, RuntimeException::class, 'Unable to store the generated report artifact.');

        return ReportArtifact::query()->create([
            'uuid' => $uuid,
            'family_id' => $family->id,
            'requested_by' => $requester?->id,
            'type' => $type,
            'format' => $format,
            'filters' => $filters,
            'filter_hash' => hash('sha256', json_encode($filters, JSON_THROW_ON_ERROR)),
            'disk' => 'local',
            'path' => $path,
            'filename' => $filename,
            'mime_type' => $format->mimeType(),
            'size' => strlen($contents),
            'expires_at' => now()->addDays(30),
        ]);
    }
}
