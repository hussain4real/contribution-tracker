<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;

class TransactionalNotificationQueue
{
    public function connection(): string
    {
        $default = config('queue.default');
        if (is_string($default) && in_array($default, ['d1', 'd1-staging'], true)) {
            return $default;
        }
        $database = config('queue.connections.database.connection');
        if ($database !== null && $database !== '' && $database !== DB::getDefaultConnection()) {
            throw new RuntimeException('Financial notifications require the database queue on the application database.');
        }

        return 'database';
    }
}
