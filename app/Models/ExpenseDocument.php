<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $family_id
 * @property int|null $expense_request_id
 * @property int|null $expense_id
 * @property int|null $uploaded_by
 * @property string $idempotency_key
 * @property string $disk
 * @property string $path
 * @property string $filename
 * @property string $mime_type
 * @property int $size
 * @property string $sha256
 * @property bool $ready
 */
class ExpenseDocument extends Model
{
    protected $fillable = ['family_id', 'expense_request_id', 'expense_id', 'uploaded_by', 'idempotency_key', 'disk', 'path', 'filename', 'mime_type', 'size', 'sha256', 'ready'];

    protected $attributes = ['ready' => false];

    /** @return array<string,string> */
    protected function casts(): array
    {
        return ['ready' => 'boolean', 'size' => 'integer'];
    }
}
