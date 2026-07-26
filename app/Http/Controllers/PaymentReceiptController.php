<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\PaymentBatch;
use App\Services\ReportArtifactService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PaymentReceiptController extends Controller
{
    public function __invoke(Request $request, PaymentBatch $paymentBatch, ReportArtifactService $artifacts): StreamedResponse
    {
        $this->authorize('view', $paymentBatch);
        $family = $paymentBatch->family;

        $artifact = $artifacts->receipt($family, $paymentBatch, $request->user());

        return response()->streamDownload(function () use ($artifact): void {
            $stream = Storage::disk($artifact->disk)->readStream($artifact->path);
            throw_unless(is_resource($stream), RuntimeException::class, 'The receipt artifact is unavailable.');
            fpassthru($stream);
            fclose($stream);
        }, $artifact->filename, ['Content-Type' => $artifact->mime_type]);
    }
}
