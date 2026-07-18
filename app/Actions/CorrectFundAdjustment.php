<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\FundAdjustment;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

class CorrectFundAdjustment
{
    public function __construct(private readonly ReverseFundAdjustment $reverseFundAdjustment) {}

    public function handle(
        FundAdjustment $original,
        User $actor,
        string $reason,
        int $amount,
        string $description,
        DateTimeInterface|string $recordedAt,
    ): FundAdjustment {
        return DB::transaction(function () use (
            $original,
            $actor,
            $reason,
            $amount,
            $description,
            $recordedAt,
        ): FundAdjustment {
            $replacement = FundAdjustment::query()->create([
                'family_id' => $original->family_id,
                'amount' => $amount,
                'description' => $description,
                'recorded_at' => $recordedAt,
                'recorded_by' => $actor->id,
            ]);
            $this->reverseFundAdjustment->handle($original, $actor, $reason, $replacement);

            return $replacement;
        }, 3);
    }
}
