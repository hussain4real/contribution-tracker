<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\GenerateReportRequest;
use App\Models\Family;
use App\Services\ReportArtifactService;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportExportController extends Controller
{
    public function __invoke(GenerateReportRequest $request, ReportArtifactService $artifacts): StreamedResponse
    {
        $user = $this->user($request);
        $family = $user->currentFamily ?? $user->family;
        abort_unless($family instanceof Family, 403);

        $artifact = $artifacts->generate(
            $family,
            $request->reportType(),
            $request->reportFormat(),
            $request->filters(),
            $user,
        );

        return response()->streamDownload(function () use ($artifact): void {
            $stream = Storage::disk($artifact->disk)->readStream($artifact->path);
            throw_unless(is_resource($stream), RuntimeException::class, 'The report artifact is unavailable.');
            fpassthru($stream);
            fclose($stream);
        }, $artifact->filename, ['Content-Type' => $artifact->mime_type]);
    }
}
