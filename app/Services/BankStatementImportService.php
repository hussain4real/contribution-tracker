<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\BankTransactionDirection;
use App\Enums\ReconciliationImportStatus;
use App\Enums\ReconciliationPeriodStatus;
use App\Enums\ReconciliationStatus;
use App\Models\BankTransaction;
use App\Models\Family;
use App\Models\ReconciliationImport;
use App\Models\ReconciliationPeriod;
use App\Models\User;
use App\Support\AuditEventRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class BankStatementImportService
{
    public function __construct(
        private readonly ReconciliationMatchingService $matchingService,
        private readonly AuditEventRecorder $audit,
    ) {}

    public function preview(Family $family, User $actor, UploadedFile $file): ReconciliationImport
    {
        $realPath = $file->getRealPath();

        if (! is_string($realPath) || $realPath === '') {
            throw new InvalidArgumentException('The uploaded statement could not be read.');
        }

        $fingerprint = @hash_file('sha256', $realPath);

        if (! is_string($fingerprint)) {
            throw new InvalidArgumentException('The uploaded statement could not be fingerprinted.');
        }

        $existing = ReconciliationImport::query()
            ->where('family_id', $family->id)
            ->where('file_fingerprint', $fingerprint)
            ->first();

        if ($existing instanceof ReconciliationImport) {
            return $existing;
        }

        $delimiter = $this->detectDelimiter($realPath);
        [$headers, $previewRows] = $this->readPreview($realPath, $delimiter);
        $path = $file->storeAs(
            "reconciliation/{$family->id}",
            Str::uuid().'.csv',
            ['disk' => 'local'],
        );

        if (! is_string($path)) {
            throw new RuntimeException('The bank statement could not be stored.');
        }

        $import = ReconciliationImport::query()->create([
            'family_id' => $family->id,
            'uploaded_by' => $actor->id,
            'disk' => 'local',
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType() ?? 'text/csv',
            'size' => $file->getSize(),
            'file_fingerprint' => $fingerprint,
            'delimiter' => $delimiter,
            'headers' => $headers,
            'preview_rows' => $previewRows,
            'status' => ReconciliationImportStatus::Previewed,
        ]);

        $this->audit->record($import, 'reconciliation.import.previewed', $family->id, $actor->id, after: [
            'original_name' => $import->original_name,
            'file_fingerprint' => $import->file_fingerprint,
        ]);

        return $import;
    }

    /**
     * @param  array{date: string, amount?: string|null, direction?: string|null, credit?: string|null, debit?: string|null, reference?: string|null, description?: string|null, source_account?: string|null}  $mapping
     * @return array{rows: int, imported: int, duplicates: int}
     */
    public function import(ReconciliationImport $import, User $actor, array $mapping): array
    {
        if ($import->status === ReconciliationImportStatus::Imported) {
            return [
                'rows' => $import->row_count,
                'imported' => $import->imported_count,
                'duplicates' => $import->duplicate_count,
            ];
        }

        $stream = Storage::disk($import->disk)->readStream($import->path);

        if (! is_resource($stream)) {
            throw new RuntimeException('The stored bank statement could not be read.');
        }

        $rows = 0;
        $imported = 0;
        $duplicates = 0;
        $fingerprintOccurrences = [];

        try {
            DB::transaction(function () use ($stream, $import, $actor, $mapping, &$rows, &$imported, &$duplicates, &$fingerprintOccurrences): void {
                $headers = $this->readCsvRow($stream, $import->delimiter);

                if ($headers === null) {
                    throw new InvalidArgumentException('The bank statement is empty.');
                }

                $headers = $this->normalizeHeaders($headers);
                $rowNumber = 1;

                while (($values = $this->readCsvRow($stream, $import->delimiter)) !== null) {
                    $rowNumber++;

                    if ($this->rowIsEmpty($values)) {
                        continue;
                    }

                    $rows++;
                    $raw = $this->combineRow($headers, $values);
                    $normalized = $this->normalizeRow($import->family_id, $raw, $mapping);
                    $baseFingerprint = $normalized['row_fingerprint'];
                    $occurrence = ($fingerprintOccurrences[$baseFingerprint] ?? 0) + 1;
                    $fingerprintOccurrences[$baseFingerprint] = $occurrence;
                    $normalized['row_fingerprint'] = hash('sha256', "{$baseFingerprint}|{$occurrence}");
                    $this->assertDateIsImportable($import->family_id, $normalized['transacted_at']);
                    $transaction = BankTransaction::query()->firstOrCreate(
                        [
                            'family_id' => $import->family_id,
                            'row_fingerprint' => $normalized['row_fingerprint'],
                        ],
                        [
                            ...$normalized,
                            'reconciliation_import_id' => $import->id,
                            'row_number' => $rowNumber,
                            'raw_data' => $raw,
                            'status' => ReconciliationStatus::Unmatched,
                        ],
                    );

                    if (! $transaction->wasRecentlyCreated) {
                        $duplicates++;

                        continue;
                    }

                    $imported++;
                    $this->matchingService->autoMatch($transaction, $actor);
                }

                $import->forceFill([
                    'mapping' => $mapping,
                    'status' => ReconciliationImportStatus::Imported,
                    'row_count' => $rows,
                    'imported_count' => $imported,
                    'duplicate_count' => $duplicates,
                    'imported_at' => now(),
                    'failed_at' => null,
                    'error' => null,
                ])->save();

                $this->audit->record($import, 'reconciliation.import.completed', $import->family_id, $actor->id, after: [
                    'rows' => $rows,
                    'imported' => $imported,
                    'duplicates' => $duplicates,
                ]);
            }, attempts: 3);
        } catch (Throwable $exception) {
            $import->forceFill([
                'status' => ReconciliationImportStatus::Failed,
                'failed_at' => now(),
                'error' => Str::limit($exception->getMessage(), 1000),
            ])->save();

            throw $exception;
        } finally {
            fclose($stream);
        }

        return ['rows' => $rows, 'imported' => $imported, 'duplicates' => $duplicates];
    }

    private function assertDateIsImportable(int $familyId, string $date): void
    {
        $period = ReconciliationPeriod::query()
            ->where('family_id', $familyId)
            ->whereDate('starts_at', '<=', $date)
            ->whereDate('ends_at', '>=', $date)
            ->lockForUpdate()
            ->first();

        if ($period?->status === ReconciliationPeriodStatus::Closed) {
            throw new InvalidArgumentException('Statement rows cannot be imported into a closed reconciliation period. Reopen the period first.');
        }
    }

    private function detectDelimiter(string $path): string
    {
        $stream = @fopen($path, 'rb');

        if (! is_resource($stream)) {
            throw new InvalidArgumentException('The uploaded statement could not be opened.');
        }

        try {
            $firstLine = fgets($stream) ?: '';
        } finally {
            fclose($stream);
        }

        $counts = [',' => substr_count($firstLine, ','), ';' => substr_count($firstLine, ';'), "\t" => substr_count($firstLine, "\t")];
        arsort($counts);
        $delimiter = array_key_first($counts);

        return $counts[$delimiter] > 0 ? $delimiter : ',';
    }

    /** @return array{list<string>, list<array<string, string|null>>} */
    private function readPreview(string $path, string $delimiter): array
    {
        $stream = @fopen($path, 'rb');

        if (! is_resource($stream)) {
            throw new InvalidArgumentException('The uploaded statement could not be opened.');
        }

        try {
            $headers = $this->readCsvRow($stream, $delimiter);

            if ($headers === null) {
                throw new InvalidArgumentException('The bank statement is empty.');
            }

            $headers = $this->normalizeHeaders($headers);
            $preview = [];

            while (count($preview) < 5 && ($row = $this->readCsvRow($stream, $delimiter)) !== null) {
                if (! $this->rowIsEmpty($row)) {
                    $preview[] = $this->combineRow($headers, $row);
                }
            }

            return [$headers, $preview];
        } finally {
            fclose($stream);
        }
    }

    /**
     * @param  resource  $stream
     * @return list<string|null>|null
     */
    private function readCsvRow($stream, string $delimiter): ?array
    {
        $row = fgetcsv($stream, separator: $delimiter, escape: '');

        if (! is_array($row)) {
            return null;
        }

        $values = [];

        foreach ($row as $value) {
            $values[] = is_string($value) ? $value : null;
        }

        return $values;
    }

    /**
     * @param  list<string|null>  $headers
     * @return list<string>
     */
    private function normalizeHeaders(array $headers): array
    {
        $normalized = [];

        foreach ($headers as $index => $header) {
            $value = $index === 0 ? preg_replace('/^\xEF\xBB\xBF/', '', (string) $header) : $header;
            $normalized[] = trim((string) $value) ?: 'Column '.($index + 1);
        }

        return $normalized;
    }

    /** @param list<string|null> $values */
    private function rowIsEmpty(array $values): bool
    {
        return collect($values)->every(fn (?string $value): bool => trim((string) $value) === '');
    }

    /**
     * @param  list<string>  $headers
     * @param  list<string|null>  $values
     * @return array<string, string|null>
     */
    private function combineRow(array $headers, array $values): array
    {
        $values = array_pad(array_slice($values, 0, count($headers)), count($headers), null);
        $row = [];

        foreach ($headers as $index => $header) {
            $row[$header] = $values[$index] ?? null;
        }

        return $row;
    }

    /**
     * @param  array<string, string|null>  $row
     * @param  array<string, string|null>  $mapping
     * @return array{direction: BankTransactionDirection, transacted_at: string, amount: int, reference: string|null, description: string|null, source_account: string|null, row_fingerprint: string}
     */
    private function normalizeRow(int $familyId, array $row, array $mapping): array
    {
        $dateValue = $this->mappedValue($row, $mapping, 'date');
        $credit = $this->amount($this->mappedValue($row, $mapping, 'credit', false), true);
        $debit = $this->amount($this->mappedValue($row, $mapping, 'debit', false), true);
        $amount = $this->amount($this->mappedValue($row, $mapping, 'amount', false), true);

        if ($credit > 0 && $debit > 0) {
            throw new InvalidArgumentException('A statement row cannot contain both a credit and a debit amount.');
        }

        if ($credit > 0 || $debit > 0) {
            $direction = $credit > 0 ? BankTransactionDirection::Credit : BankTransactionDirection::Debit;
            $amount = max($credit, $debit);
        } else {
            $directionValue = strtolower($this->mappedValue($row, $mapping, 'direction'));
            $direction = match ($directionValue) {
                'credit', 'cr', 'c', 'in', 'deposit' => BankTransactionDirection::Credit,
                'debit', 'dr', 'd', 'out', 'withdrawal' => BankTransactionDirection::Debit,
                default => throw new InvalidArgumentException("Unsupported transaction direction [{$directionValue}]."),
            };
        }

        if ($amount < 1) {
            throw new InvalidArgumentException('Every statement row must have an amount greater than zero.');
        }

        $date = CarbonImmutable::parse($dateValue)->toDateString();
        $reference = $this->nullableMappedValue($row, $mapping, 'reference');
        $description = $this->nullableMappedValue($row, $mapping, 'description');
        $sourceAccount = $this->nullableMappedValue($row, $mapping, 'source_account');
        $fingerprintParts = [$familyId, $date, $direction->value, $amount, Str::lower($reference ?? ''), Str::lower($description ?? ''), Str::lower($sourceAccount ?? '')];

        return [
            'direction' => $direction,
            'transacted_at' => $date,
            'amount' => $amount,
            'reference' => $reference,
            'description' => $description,
            'source_account' => $sourceAccount,
            'row_fingerprint' => hash('sha256', implode('|', $fingerprintParts)),
        ];
    }

    /**
     * @param  array<string, string|null>  $row
     * @param  array<string, string|null>  $mapping
     */
    private function mappedValue(array $row, array $mapping, string $field, bool $required = true): string
    {
        $column = $mapping[$field] ?? null;
        $value = is_string($column) ? trim((string) ($row[$column] ?? '')) : '';

        if ($required && $value === '') {
            throw new InvalidArgumentException("The mapped {$field} value is missing from a statement row.");
        }

        return $value;
    }

    /**
     * @param  array<string, string|null>  $row
     * @param  array<string, string|null>  $mapping
     */
    private function nullableMappedValue(array $row, array $mapping, string $field): ?string
    {
        $value = $this->mappedValue($row, $mapping, $field, false);

        return $value === '' ? null : $value;
    }

    private function amount(string $value, bool $emptyAllowed): int
    {
        if ($value === '' && $emptyAllowed) {
            return 0;
        }

        $normalized = preg_replace('/[^0-9.\-]/', '', $value);

        if (! is_string($normalized) || ! is_numeric($normalized)) {
            throw new InvalidArgumentException("Invalid amount [{$value}].");
        }

        $numeric = abs((float) $normalized);

        if (floor($numeric) !== $numeric) {
            throw new InvalidArgumentException('Statement amounts must use whole Naira values.');
        }

        return (int) $numeric;
    }
}
