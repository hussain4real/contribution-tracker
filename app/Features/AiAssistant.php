<?php

namespace App\Features;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class AiAssistant
{
    /**
     * Resolve the feature's initial value.
     *
     * Checks for a global activation record first.
     * Super admins control activation via the platform UI.
     */
    public function resolve(User $user): bool
    {
        return DB::table('features')
            ->where('name', static::class)
            ->where('scope', '')
            ->where('value', 'true')
            ->exists();
    }
}
