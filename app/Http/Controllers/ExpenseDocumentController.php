<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreExpenseDocumentRequest;
use App\Models\Expense;
use App\Models\ExpenseDocument;
use App\Models\ExpenseRequest;
use App\Models\Family;
use App\Support\AuditEventRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExpenseDocumentController extends Controller
{
    public function store(StoreExpenseDocumentRequest $request): RedirectResponse
    {
        $user = $this->authUser();
        $family = $user->currentFamily ?? $user->family;
        abort_unless($family instanceof Family && $user->canRecordPayments(), 403);
        $file = $request->file('document');
        abort_unless($file instanceof UploadedFile, 422);
        $requestId = $request->filled('expense_request_id') ? $request->integer('expense_request_id') : null;
        $expenseId = $request->filled('expense_id') ? $request->integer('expense_id') : null;
        if ($requestId !== null) {
            ExpenseRequest::query()->where('family_id', $family->id)->findOrFail($requestId);
        } else {
            Expense::query()->where('family_id', $family->id)->findOrFail($expenseId);
        }
        $bytes = $file->getContent();
        $hash = hash('sha256', $bytes);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        $extension = match ($mime) {
            'application/pdf' => 'pdf', 'image/png' => 'png', 'image/jpeg' => 'jpg', default => throw ValidationException::withMessages(['document' => 'Choose a PDF, JPG or PNG document with matching file contents.'])
        };
        $allowedExtensions = $extension === 'jpg' ? ['jpg', 'jpeg'] : [$extension];
        if (! in_array(strtolower($file->getClientOriginalExtension()), $allowedExtensions, true)) {
            throw ValidationException::withMessages(['document' => 'The filename extension must match the document contents.']);
        }
        $filename = preg_replace('/[[:cntrl:]]/', '', basename(str_replace('\\', '/', $file->getClientOriginalName()))) ?: 'document.'.$extension;
        $document = DB::transaction(function () use ($request, $requestId, $expenseId, $user, $family, $file, $hash, $mime, $extension, $filename): ExpenseDocument {
            Family::query()->lockForUpdate()->findOrFail($family->id);
            $existing = ExpenseDocument::query()->where('family_id', $family->id)
                ->where('idempotency_key', $request->string('idempotency_key')->toString())->first();
            if ($existing instanceof ExpenseDocument) {
                if ($existing->sha256 !== $hash || $existing->expense_request_id !== $requestId
                    || $existing->expense_id !== $expenseId || $existing->uploaded_by !== $user->id) {
                    throw ValidationException::withMessages(['idempotency_key' => 'This upload key was already used for another document.']);
                }

                return $existing;
            }

            return ExpenseDocument::query()->create([
                'family_id' => $family->id, 'expense_request_id' => $requestId, 'expense_id' => $expenseId,
                'uploaded_by' => $user->id, 'idempotency_key' => $request->string('idempotency_key')->toString(),
                'disk' => 'local', 'path' => 'expense-documents/'.$family->id.'/'.Str::uuid().'.'.$extension,
                'filename' => Str::limit($filename, 200, ''),
                'mime_type' => $mime, 'size' => $file->getSize(), 'sha256' => $hash, 'ready' => false,
            ]);
        }, 3);
        if (! $document->ready) {
            if (! Storage::disk($document->disk)->put($document->path, $bytes, ['visibility' => 'private'])) {
                throw ValidationException::withMessages(['document' => 'The document could not be stored. Retry this upload.']);
            }
            DB::transaction(function () use ($document, $user): void {
                $family = Family::query()->lockForUpdate()->find($document->family_id);
                if (! $family instanceof Family) {
                    Storage::disk($document->disk)->delete($document->path);
                    abort(404);
                }
                abort_if($family->isArchived(), 403);
                $locked = ExpenseDocument::query()->lockForUpdate()->findOrFail($document->id);
                if (! $locked->ready) {
                    $locked->update(['ready' => true]);
                    app(AuditEventRecorder::class)->record($locked, 'expense_document.added', $locked->family_id, $user->id);
                }
            }, 3);
        }

        return back()->with('success', 'Private supporting document uploaded.');
    }

    public function download(ExpenseDocument $expenseDocument): StreamedResponse
    {
        $user = $this->authUser();
        abort_unless($user->canRecordPayments()
            && ($user->current_family_id ?? $user->family_id) === $expenseDocument->family_id
            && $user->membershipForFamilyId($expenseDocument->family_id) !== null, 403);
        abort_unless($expenseDocument->ready, 404);
        $stream = Storage::disk($expenseDocument->disk)->readStream($expenseDocument->path);
        abort_unless(is_resource($stream), 404);

        return response()->streamDownload(function () use ($stream): void {
            try {
                fpassthru($stream);
            } finally {
                fclose($stream);
            }
        }, $expenseDocument->filename, [
            'Content-Type' => 'application/octet-stream',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
