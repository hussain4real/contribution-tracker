<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ReportArtifact;
use App\Models\ReportDelivery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportArtifactController extends Controller
{
    public function show(Request $request, ReportArtifact $reportArtifact): StreamedResponse
    {
        $this->authorize('view', $reportArtifact);

        return $this->download($reportArtifact);
    }

    public function delivery(Request $request, ReportDelivery $reportDelivery): StreamedResponse
    {
        abort_unless((bool) $request->hasValidSignature(), 403);
        $artifact = $reportDelivery->artifact;
        abort_unless($artifact instanceof ReportArtifact, 404);
        abort_if($artifact->expires_at?->isPast() === true, 410);

        return $this->download($artifact);
    }

    private function download(ReportArtifact $artifact): StreamedResponse
    {
        abort_unless(Storage::disk($artifact->disk)->exists($artifact->path), 404);

        return response()->streamDownload(function () use ($artifact): void {
            $stream = Storage::disk($artifact->disk)->readStream($artifact->path);
            throw_unless(is_resource($stream), RuntimeException::class, 'The report artifact is unavailable.');
            fpassthru($stream);
            fclose($stream);
        }, $artifact->filename, ['Content-Type' => $artifact->mime_type]);
    }
}
