<?php

declare(strict_types=1);

namespace App\Enums;

enum ReconciliationImportStatus: string
{
    case Previewed = 'previewed';
    case Imported = 'imported';
    case Failed = 'failed';
}
