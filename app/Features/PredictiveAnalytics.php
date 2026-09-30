<?php

declare(strict_types=1);

namespace App\Features;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class PredictiveAnalytics
{
    public function resolve(User $user): bool
    {
        return DB::table('features')
            ->where('name', static::class)
            ->where('scope', '')
            ->where('value', 'true')
            ->exists();
    }
}
